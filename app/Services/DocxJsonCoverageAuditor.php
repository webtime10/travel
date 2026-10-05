<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Compare raw visible DOCX text against imported sequential JSON blocks.
 */
class DocxJsonCoverageAuditor
{
    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function audit(string $docxPath, array $meta): array
    {
        if (! is_file($docxPath)) {
            throw new \RuntimeException('DOCX not found: '.$docxPath);
        }

        $wordUnits = $this->extractWordTextUnits($docxPath);
        $jsonUnits = [];
        $jsonImages = 0;
        foreach ($meta['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'image') {
                $jsonImages++;
                continue;
            }
            $t = $this->normalize((string) ($block['text'] ?? ''));
            if ($t !== '') {
                $jsonUnits[] = $t;
            }
        }

        $haystack = ' '.implode(' ', $jsonUnits).' ';
        $missing = [];
        $found = 0;

        foreach ($wordUnits as $unit) {
            $norm = $this->normalize($unit);
            if ($norm === '' || mb_strlen($norm) < 3) {
                continue;
            }
            // Skip pure page-number-like leftovers
            if (preg_match('/^\d{1,4}$/u', $norm)) {
                continue;
            }

            if ($this->unitFoundInHaystack($norm, $haystack, $jsonUnits)) {
                $found++;
            } else {
                $missing[] = mb_strlen($unit) > 220 ? mb_substr($unit, 0, 220).'…' : $unit;
            }
        }

        $checked = $found + count($missing);
        $coverage = $checked > 0 ? round(100 * $found / $checked, 1) : 100.0;

        $wordImages = $this->countWordImages($docxPath);

        return [
            'ok' => true,
            'source_file' => basename($docxPath),
            'word_text_units' => $checked,
            'word_images' => $wordImages,
            'json_text_blocks' => count($jsonUnits),
            'json_image_blocks' => $jsonImages,
            'found' => $found,
            'missing_count' => count($missing),
            'coverage_percent' => $coverage,
            'images_ok' => $jsonImages >= $wordImages,
            'images_delta' => $jsonImages - $wordImages,
            'missing' => array_slice($missing, 0, 80),
            'missing_truncated' => count($missing) > 80,
            'verdict' => $this->verdict($coverage, count($missing), $jsonImages, $wordImages),
        ];
    }

    public function resolveDocxPath(array $meta): ?string
    {
        $docDir = base_path('doc');
        $source = (string) ($meta['source_file'] ?? '');
        if ($source !== '') {
            $direct = $docDir.DIRECTORY_SEPARATOR.$source;
            if (is_file($direct)) {
                return $direct;
            }
        }

        $files = glob($docDir.DIRECTORY_SEPARATOR.'*.docx') ?: [];
        usort($files, fn ($a, $b) => strcasecmp(basename($a), basename($b)));
        $index = (int) ($meta['doc_index'] ?? 0);
        $n = 0;
        foreach ($files as $file) {
            if (filesize($file) < 1000) {
                continue;
            }
            $n++;
            if ($index > 0 && $n === $index) {
                return $file;
            }
            if ($source !== '' && strcasecmp(basename($file), $source) === 0) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractWordTextUnits(string $docxPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException('Cannot open DOCX');
        }

        $units = [];
        $documentXml = $zip->getFromName('word/document.xml');
        if (is_string($documentXml)) {
            $units = array_merge($units, $this->paragraphsFromXml($documentXml));
        }

        // Footers / headers — visible content Word shows
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! preg_match('#^word/(footer|header)\\d*\\.xml$#', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if (is_string($xml)) {
                $units = array_merge($units, $this->paragraphsFromXml($xml));
            }
        }

        $zip->close();

        // Unique while keeping order (footer repeats in Word visually but once in parts)
        $out = [];
        $seen = [];
        foreach ($units as $u) {
            $key = $this->normalize($u);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $u;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function paragraphsFromXml(string $xml): array
    {
        $dom = new DOMDocument();
        @$dom->loadXML($xml);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $units = [];
        foreach ($xp->query('//w:p') as $p) {
            if (! $p instanceof DOMElement) {
                continue;
            }
            $text = '';
            foreach ($xp->query('.//w:t', $p) as $t) {
                $text .= $t->textContent;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text !== '') {
                $units[] = $text;
            }
        }

        return $units;
    }

    private function countWordImages(string $docxPath): int
    {
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return 0;
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/media/.+#', $name)) {
                $n++;
            }
        }
        $zip->close();

        return $n;
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }

    /**
     * @param  list<string>  $jsonUnits
     */
    private function unitFoundInHaystack(string $norm, string $haystack, array $jsonUnits): bool
    {
        if (str_contains($haystack, $norm)) {
            return true;
        }

        // Soft match: long units may be split across JSON blocks (soft-breaks / TOC wrap)
        if (mb_strlen($norm) >= 40) {
            $parts = preg_split('/[,:;.\-–—]\s+/u', $norm) ?: [];
            $hit = 0;
            $need = 0;
            foreach ($parts as $part) {
                $part = trim($part);
                if (mb_strlen($part) < 12) {
                    continue;
                }
                $need++;
                if (str_contains($haystack, $part)) {
                    $hit++;
                }
            }
            if ($need >= 2 && $hit >= (int) ceil($need * 0.7)) {
                return true;
            }

            // Sliding windows of ~24 chars
            $len = mb_strlen($norm);
            $win = 24;
            $windows = 0;
            $ok = 0;
            for ($i = 0; $i + $win <= $len; $i += 18) {
                $windows++;
                $slice = mb_substr($norm, $i, $win);
                if (str_contains($haystack, $slice)) {
                    $ok++;
                }
            }
            if ($windows >= 3 && $ok >= (int) ceil($windows * 0.75)) {
                return true;
            }
        }

        // Short unit: exact against any json unit or contained
        foreach ($jsonUnits as $ju) {
            if ($ju === $norm || str_contains($ju, $norm) || str_contains($norm, $ju)) {
                return true;
            }
        }

        return false;
    }

    private function verdict(float $coverage, int $missingCount, int $jsonImages, int $wordImages): string
    {
        if ($coverage >= 99.5 && $missingCount === 0 && $jsonImages >= $wordImages) {
            return 'OK — текст Word покрыт JSON, картинки на месте.';
        }
        if ($coverage >= 97) {
            return 'Почти OK — мелкие расхождения (переносы/TOC/пробелы). Проверьте список упущенного.';
        }
        if ($coverage >= 90) {
            return 'Есть упущения — смотрите missing; часть смысла могла не попасть.';
        }

        return 'Слабое покрытие — JSON заметно не полный относительно Word.';
    }
}
