<?php

namespace App\Services\Itinerary;

use App\Services\GeminiTranslator;
use RuntimeException;

/**
 * Translate full-tour preview texts HE→RU in chunks (button-triggered).
 */
class FullTourPreviewTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public function translateAll(array $sources): array
    {
        $out = [];
        foreach ($sources as $src) {
            if (! is_array($src)) {
                continue;
            }
            if (! empty($src['preview_text_ru']) && is_string($src['preview_text_ru'])) {
                $out[] = $src;

                continue;
            }
            $he = trim((string) ($src['preview_text'] ?? ''));
            if ($he === '' || $he === '(нет текстовых блоков в meta)') {
                $src['preview_text_ru'] = $he;
                $out[] = $src;

                continue;
            }
            $src['preview_text_ru'] = $this->translateLongText($he);
            $out[] = $src;
        }

        return $out;
    }

    private function translateLongText(string $text): string
    {
        $chunks = $this->splitForTranslation($text, 1800);
        if ($chunks === []) {
            return '';
        }

        $translated = [];
        foreach (array_chunk($chunks, 4, true) as $batch) {
            /** @var array<string, string> $batch */
            $map = $this->gemini->translateMap($batch);
            foreach ($batch as $id => $_) {
                $ru = $map[(string) $id] ?? null;
                if (! is_string($ru) || trim($ru) === '') {
                    throw new RuntimeException('Не удалось перевести фрагмент #'.$id);
                }
                $translated[(int) $id] = trim($ru);
            }
        }

        ksort($translated);

        return trim(implode("\n\n", $translated));
    }

    /**
     * @return array<int, string>
     */
    private function splitForTranslation(string $text, int $maxLen): array
    {
        $parts = preg_split('/\n{2,}/u', $text) ?: [$text];
        $chunks = [];
        $buf = '';
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($part) + 2 > $maxLen) {
                $chunks[] = $buf;
                $buf = $part;
            } elseif ($buf === '') {
                $buf = $part;
            } else {
                $buf .= "\n\n".$part;
            }
            // Hard-split oversized single paragraph
            while (mb_strlen($buf) > $maxLen) {
                $chunks[] = mb_substr($buf, 0, $maxLen);
                $buf = ltrim(mb_substr($buf, $maxLen));
            }
        }
        if ($buf !== '') {
            $chunks[] = $buf;
        }

        $out = [];
        foreach ($chunks as $i => $c) {
            $out[$i] = $c;
        }

        return $out;
    }
}
