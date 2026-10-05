<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Linear DOCX → ordered blocks (text / image), preserving document order 1:1.
 */
class DocxSequentialParser
{
    /** @var list<array<string,mixed>> */
    private array $footerBlocks = [];

    private int $tocEntriesSinceFooter = 0;

    private int $tocEntriesTotal = 0;

    /** @var array<string,string> rId => absolute/external URL */
    private array $ridToUrl = [];

    private string $currentParagraphStyle = '';

    /** TOC1 lines that typically fit above the footer on one Word page (A4). */
    private const TOC_ENTRIES_PER_PAGE = 8;

    /**
     * @return array{source:string,blocks:list<array<string,mixed>>,images:list<string>,format:string}
     */
    public function parse(string $docxPath, string $mediaDestDir, string $publicRelPrefix): array
    {
        if (! is_dir($mediaDestDir)) {
            mkdir($mediaDestDir, 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new \RuntimeException('Cannot open '.$docxPath);
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($documentXml === false) {
            $zip->close();
            throw new \RuntimeException('No document.xml');
        }

        $ridToTarget = [];
        $footerTargets = [];
        $this->ridToUrl = [];
        if ($relsXml !== false) {
            $rels = new DOMDocument();
            $rels->loadXML($relsXml);
            foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                /** @var DOMElement $rel */
                $id = $rel->getAttribute('Id');
                $target = str_replace('\\', '/', $rel->getAttribute('Target'));
                $type = $rel->getAttribute('Type');
                if ($id === '' || $target === '') {
                    continue;
                }

                // External hyperlinks (http/https/mailto…)
                if (str_contains($type, '/hyperlink')) {
                    $this->ridToUrl[$id] = html_entity_decode($target, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    continue;
                }

                if (str_starts_with($target, 'http')) {
                    continue;
                }

                $zipTarget = str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'word/'.ltrim($target, '/');
                $ridToTarget[$id] = $zipTarget;
                if (str_contains($type, '/footer')) {
                    $footerTargets[] = $zipTarget;
                }
            }
        }

        $this->footerBlocks = $this->loadFooterBlocks($zip, $footerTargets);
        $this->tocEntriesSinceFooter = 0;
        $this->tocEntriesTotal = 0;
        $this->currentParagraphStyle = '';

        $savedImages = [];
        $imageIndex = 0;
        $blocks = [];

        $dom = new DOMDocument();
        @$dom->loadXML($documentXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $xpath->registerNamespace('v', 'urn:schemas-microsoft-com:vml');

        $body = $xpath->query('//w:body')->item(0);
        if (! $body) {
            $zip->close();
            throw new \RuntimeException('No w:body');
        }

        $this->walkContainer($body, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);

        // Last page footer (no trailing page-break marker)
        if ($this->footerBlocks !== []) {
            $this->emitFooterBlocks($blocks);
        }

        $zip->close();

        return [
            'source' => basename($docxPath),
            'blocks' => $blocks,
            'images' => array_values(array_unique(array_values($savedImages))),
            'format' => 'docx_sequential_v1',
        ];
    }

    /**
     * Recursively walk any container preserving child order.
     *
     * @param  array<string,string>  $ridToTarget
     * @param  array<string,string>  $savedImages
     * @param  list<array<string,mixed>>  $blocks
     */
    private function walkContainer(
        DOMElement $container,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $ridToTarget,
        string $mediaDestDir,
        string $publicRelPrefix,
        array &$savedImages,
        int &$imageIndex,
        array &$blocks
    ): void {
        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            switch ($child->localName) {
                case 'sectPr':
                    break;

                case 'p':
                    $style = $this->paragraphStyle($child, $xpath);
                    $this->parseParagraph($child, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
                    if ($style !== '' && str_contains(strtolower($style), 'toc')) {
                        $this->tocEntriesTotal++;
                        $this->tocEntriesSinceFooter++;
                        if ($this->tocEntriesSinceFooter >= self::TOC_ENTRIES_PER_PAGE) {
                            $this->emitFooterBlocks($blocks);
                            $this->tocEntriesSinceFooter = 0;
                        }
                    }
                    break;

                case 'tbl':
                    $this->parseTable($child, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
                    break;

                case 'sdt':
                    // Content control / TOC wrapper
                    $content = null;
                    foreach ($child->childNodes as $sdtChild) {
                        if ($sdtChild instanceof DOMElement && $sdtChild->localName === 'sdtContent') {
                            $content = $sdtChild;
                            break;
                        }
                    }
                    if ($content) {
                        $tocBefore = $this->tocEntriesTotal;
                        $this->walkContainer($content, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
                        // Footer at bottom of last TOC page (no LRPB inside TOC field)
                        if ($this->tocEntriesTotal > $tocBefore && $this->tocEntriesSinceFooter > 0) {
                            $this->emitFooterBlocks($blocks);
                            $this->tocEntriesSinceFooter = 0;
                        }
                    }
                    break;

                case 'customXml':
                case 'altChunk':
                    $this->walkContainer($child, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
                    break;

                default:
                    // Unknown wrapper — still descend if it looks like a container with p/tbl/sdt
                    if ($xpath->query('.//w:p|.//w:tbl|.//w:sdt', $child)->length > 0
                        && in_array($child->localName, ['txbxContent', 'tc', 'tr', 'body', 'comment', 'hdr', 'ftr'], true) === false
                    ) {
                        // avoid double-walking table cells here; tables handled above
                    }
                    break;
            }
        }
    }

    /**
     * @param  array<string,string>  $ridToTarget
     * @param  array<string,string>  $savedImages
     * @param  list<array<string,mixed>>  $blocks
     */
    private function parseTable(
        DOMElement $tbl,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $ridToTarget,
        string $mediaDestDir,
        string $publicRelPrefix,
        array &$savedImages,
        int &$imageIndex,
        array &$blocks
    ): void {
        foreach ($tbl->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->localName !== 'tr') {
                continue;
            }
            foreach ($node->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->localName !== 'tc') {
                    continue;
                }
                $this->walkContainer($cell, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
            }
        }
    }

    /**
     * @param  array<string,string>  $ridToTarget
     * @param  array<string,string>  $savedImages
     * @param  list<array<string,mixed>>  $blocks
     */
    private function parseParagraph(
        DOMElement $p,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $ridToTarget,
        string $mediaDestDir,
        string $publicRelPrefix,
        array &$savedImages,
        int &$imageIndex,
        array &$blocks
    ): void {
        $style = '';
        $styleNode = $xpath->query('./w:pPr/w:pStyle', $p)->item(0);
        if ($styleNode instanceof DOMElement) {
            $style = $styleNode->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')
                ?: $styleNode->getAttribute('w:val')
                ?: $styleNode->getAttribute('val');
        }
        $this->currentParagraphStyle = $style;

        $pendingText = '';
        $flushText = function (?string $url = null) use (&$pendingText, &$blocks, $style) {
            $text = $pendingText;
            $pendingText = '';
            $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
            $text = trim($text);
            if ($text === '') {
                return;
            }
            // Soft-break leftovers + TOC wrap after ", אופציה" (Word auto-wrap, no w:br)
            foreach (preg_split("/\n+/u", $text) ?: [$text] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                foreach ($this->visualLinesForParagraph($style, $line) as $visual) {
                    $block = [
                        'type' => $url ? 'link' : $this->blockTypeForStyle($style, $visual),
                        'text' => $visual,
                        'style' => $style !== '' ? $style : null,
                    ];
                    if ($url) {
                        $block['url'] = $url;
                    }
                    $blocks[] = $block;
                }
            }
        };

        $this->walkParagraphNodes(
            $p,
            $xpath,
            $zip,
            $ridToTarget,
            $mediaDestDir,
            $publicRelPrefix,
            $savedImages,
            $imageIndex,
            $blocks,
            $pendingText,
            $flushText
        );

        $flushText();
    }

    /**
     * Walk paragraph-level children in order (runs, hyperlinks, nested sdt, fields).
     *
     * @param  array<string,string>  $ridToTarget
     * @param  array<string,string>  $savedImages
     * @param  list<array<string,mixed>>  $blocks
     * @param  callable():void  $flushText
     */
    private function walkParagraphNodes(
        DOMElement $parent,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $ridToTarget,
        string $mediaDestDir,
        string $publicRelPrefix,
        array &$savedImages,
        int &$imageIndex,
        array &$blocks,
        string &$pendingText,
        callable $flushText
    ): void {
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $name = $node->localName;

            if ($name === 'pPr') {
                continue;
            }

            if ($name === 'r') {
                $this->walkRun($node, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks, $pendingText, $flushText);
            } elseif ($name === 'hyperlink') {
                $flushText();
                $url = $this->resolveHyperlinkUrl($node);
                $linkText = '';
                foreach ($xpath->query('.//w:t', $node) as $tNode) {
                    $linkText .= $tNode->textContent;
                }
                // Images inside hyperlink (rare)
                $this->extractImagesFromNode($node, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);

                $linkText = preg_replace("/[ \t]+/u", ' ', $linkText) ?? $linkText;
                $linkText = trim($linkText);
                if ($linkText !== '') {
                    if ($url !== null && $url !== '') {
                        $blocks[] = [
                            'type' => 'link',
                            'text' => $linkText,
                            'url' => $url,
                            'style' => $this->currentParagraphStyle !== '' ? $this->currentParagraphStyle : null,
                        ];
                    } else {
                        // Internal TOC anchors etc. — keep as normal text/heading
                        $pendingText .= $linkText;
                        $flushText();
                    }
                }
            } elseif ($name === 'sdt') {
                foreach ($node->childNodes as $sdtChild) {
                    if ($sdtChild instanceof DOMElement && $sdtChild->localName === 'sdtContent') {
                        $this->walkParagraphNodes($sdtChild, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks, $pendingText, $flushText);
                    }
                }
            } elseif (in_array($name, ['drawing', 'pict', 'object'], true)) {
                $flushText();
                $this->extractImagesFromNode($node, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
            } elseif ($name === 'fldSimple') {
                $this->walkParagraphNodes($node, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks, $pendingText, $flushText);
            } elseif ($name === 'smartTag' || $name === 'dir' || $name === 'bdo' || $name === 'customXml') {
                $this->walkParagraphNodes($node, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks, $pendingText, $flushText);
            }
        }
    }

    /**
     * @param  array<string,string>  $ridToTarget
     * @param  array<string,string>  $savedImages
     * @param  list<array<string,mixed>>  $blocks
     * @param  callable():void  $flushText
     */
    private function walkRun(
        DOMElement $run,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $ridToTarget,
        string $mediaDestDir,
        string $publicRelPrefix,
        array &$savedImages,
        int &$imageIndex,
        array &$blocks,
        string &$pendingText,
        callable $flushText
    ): void {
        $skipInstr = false;
        foreach ($run->childNodes as $runChild) {
            if (! $runChild instanceof DOMElement) {
                continue;
            }
            $rc = $runChild->localName;

            // Skip TOC/field instructions, keep visible result text
            if ($rc === 'fldChar') {
                $fld = $runChild->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'fldCharType')
                    ?: $runChild->getAttribute('w:fldCharType')
                    ?: $runChild->getAttribute('fldCharType');
                if ($fld === 'begin') {
                    // peek: if next instr is TOC we still want result after separate
                }
                continue;
            }
            if ($rc === 'instrText') {
                $skipInstr = true;
                continue;
            }

            if ($rc === 'drawing' || $rc === 'pict' || $rc === 'object') {
                $flushText();
                $this->extractImagesFromNode($runChild, $xpath, $zip, $ridToTarget, $mediaDestDir, $publicRelPrefix, $savedImages, $imageIndex, $blocks);
            } elseif ($rc === 't') {
                $pendingText .= $runChild->textContent;
            } elseif ($rc === 'tab') {
                $pendingText .= "\t";
            } elseif ($rc === 'lastRenderedPageBreak') {
                // Word page boundary from last layout → footer sits between pages
                $flushText();
                $this->emitFooterBlocks($blocks);
            } elseif ($rc === 'br' || $rc === 'cr') {
                $brType = $runChild->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'type')
                    ?: $runChild->getAttribute('w:type')
                    ?: $runChild->getAttribute('type');
                $flushText();
                if ($brType === 'page') {
                    $this->emitFooterBlocks($blocks);
                }
            } elseif ($rc === 'sym') {
                // symbol font char
                $char = $runChild->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'char')
                    ?: $runChild->getAttribute('w:char')
                    ?: $runChild->getAttribute('char');
                if ($char !== '') {
                    $pendingText .= '['.$char.']';
                }
            }
        }
    }

    private function paragraphStyle(DOMElement $p, DOMXPath $xpath): string
    {
        $styleNode = $xpath->query('./w:pPr/w:pStyle', $p)->item(0);
        if (! $styleNode instanceof DOMElement) {
            return '';
        }

        return $styleNode->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val')
            ?: $styleNode->getAttribute('w:val')
            ?: $styleNode->getAttribute('val')
            ?: '';
    }

    private function resolveHyperlinkUrl(DOMElement $hyperlink): ?string
    {
        $rid = $hyperlink->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
            ?: $hyperlink->getAttribute('r:id')
            ?: $hyperlink->getAttribute('id');

        if ($rid !== '' && isset($this->ridToUrl[$rid])) {
            return $this->ridToUrl[$rid];
        }

        $anchor = $hyperlink->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'anchor')
            ?: $hyperlink->getAttribute('w:anchor')
            ?: $hyperlink->getAttribute('anchor');

        if ($anchor !== '') {
            return '#'.$anchor;
        }

        return null;
    }

    /**
     * @param  list<string>  $footerTargets
     * @return list<array<string,mixed>>
     */
    private function loadFooterBlocks(ZipArchive $zip, array $footerTargets): array
    {
        $blocks = [];
        foreach (array_values(array_unique($footerTargets)) as $target) {
            $xml = $zip->getFromName($this->normalizeZipPath($target));
            if ($xml === false) {
                continue;
            }
            $dom = new DOMDocument();
            @$dom->loadXML($xml);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach ($xpath->query('//w:p') as $p) {
                if (! $p instanceof DOMElement) {
                    continue;
                }
                $text = '';
                foreach ($xpath->query('.//w:t', $p) as $t) {
                    $text .= $t->textContent;
                }
                $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                $blocks[] = [
                    'type' => 'footer',
                    'text' => $text,
                    'style' => 'footer',
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function emitFooterBlocks(array &$blocks): void
    {
        if ($this->footerBlocks === []) {
            return;
        }

        $last = $blocks !== [] ? $blocks[array_key_last($blocks)] : null;
        $firstFooter = $this->footerBlocks[0];
        if (is_array($last)
            && ($last['type'] ?? '') === 'footer'
            && ($last['text'] ?? '') === ($firstFooter['text'] ?? '')
        ) {
            return;
        }

        foreach ($this->footerBlocks as $footer) {
            $blocks[] = $footer;
        }
    }

    private function blockTypeForStyle(string $style, string $text): string
    {
        $s = strtolower($style);
        if ($s !== '' && (
            str_contains($s, 'heading')
            || str_contains($s, 'title')
            || str_contains($s, 'subtitle')
            || str_contains($s, 'toc')
        )) {
            return 'heading';
        }
        if (preg_match('/^(יום|day)\s*\d+/iu', $text)) {
            return 'heading';
        }

        return 'text';
    }

    /**
     * Extra visual-line splits for eye comparison when Word wraps without w:br.
     * TOC often wraps the option subtitle onto its own line: "…קאפרי, אופציה - …"
     * while the page number stays on the first visual line (with the title).
     *
     * @return list<string>
     */
    private function visualLinesForParagraph(string $style, string $line): array
    {
        $s = strtolower($style);
        if ($s === '' || ! str_contains($s, 'toc')) {
            return [$line];
        }

        $page = '';
        if (preg_match('/^(.*\S)\s+(\d+)$/u', $line, $pm)) {
            $line = $pm[1];
            $page = $pm[2];
        }

        if (preg_match('/^(.+?),(\s*אופציה\b.*)$/u', $line, $m)) {
            $first = trim($m[1].',');
            $second = trim($m[2]);
            if ($first !== '' && $second !== '') {
                // Word: page number on first wrapped line; subtitle alone below
                if ($page !== '') {
                    $first .= ' '.$page;
                }

                return [$first, $second];
            }
        }

        return [$page !== '' ? $line.' '.$page : $line];
    }

    /**
     * @param  array<string,string>  $ridToTarget
     * @param  array<string,string>  $savedImages
     * @param  list<array<string,mixed>>  $blocks
     */
    private function extractImagesFromNode(
        DOMElement $node,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $ridToTarget,
        string $mediaDestDir,
        string $publicRelPrefix,
        array &$savedImages,
        int &$imageIndex,
        array &$blocks
    ): void {
        $embeds = [];

        foreach ($xpath->query('.//a:blip', $node) as $blip) {
            if ($blip instanceof DOMElement) {
                $embed = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')
                    ?: $blip->getAttribute('r:embed')
                    ?: $blip->getAttribute('embed');
                if ($embed !== '') {
                    $embeds[] = $embed;
                }
            }
        }

        foreach ($xpath->query('.//v:imagedata|.//*', $node) as $v) {
            if (! $v instanceof DOMElement) {
                continue;
            }
            if ($v->localName !== 'imagedata' && ! $v->hasAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')) {
                continue;
            }
            $id = $v->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
                ?: $v->getAttribute('r:id');
            if ($id !== '' && isset($ridToTarget[$id])) {
                $embeds[] = $id;
            }
        }

        foreach (array_values(array_unique($embeds)) as $rid) {
            if (! isset($ridToTarget[$rid])) {
                continue;
            }

            if (isset($savedImages[$rid])) {
                $blocks[] = [
                    'type' => 'image',
                    'src' => $savedImages[$rid],
                    'rId' => $rid,
                ];
                continue;
            }

            $zipPath = $this->normalizeZipPath($ridToTarget[$rid]);
            $bin = $zip->getFromName($zipPath);
            if ($bin === false || strlen($bin) < 20) {
                continue;
            }

            $ext = strtolower(pathinfo($zipPath, PATHINFO_EXTENSION) ?: 'bin');
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            $imageIndex++;
            $fileName = sprintf('img_%04d.%s', $imageIndex, $ext);
            file_put_contents($mediaDestDir.'/'.$fileName, $bin);
            $rel = trim($publicRelPrefix, '/').'/'.$fileName;
            $savedImages[$rid] = $rel;

            $blocks[] = [
                'type' => 'image',
                'src' => $rel,
                'rId' => $rid,
                'bytes' => strlen($bin),
            ];
        }
    }

    private function normalizeZipPath(string $zipPath): string
    {
        $parts = explode('/', $zipPath);
        $norm = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($norm);
                continue;
            }
            $norm[] = $part;
        }

        return implode('/', $norm);
    }
}
