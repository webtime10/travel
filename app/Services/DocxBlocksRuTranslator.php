<?php

namespace App\Services;

use Throwable;

class DocxBlocksRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * Translate a slice of sequential DOCX blocks. Mutates and returns meta.
     * Skips blocks that already have text_ru. On hard Gemini failures, marks
     * those ids (ru_skip) and continues with the next pending blocks.
     *
     * @param  array<string,mixed>  $meta
     * @return array{meta: array<string,mixed>, translated: int, remaining: int, total: int, done: bool, already: int, warning?: string}
     */
    public function translateBatch(array $meta, int $limit = 12): array
    {
        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            $blocks = [];
        }

        $total = $this->countTranslatable($blocks);
        $already = $this->countTranslated($blocks);

        $skipIds = [];
        $translated = 0;
        $lastError = null;
        $rounds = 0;

        while ($rounds < 4 && $translated === 0) {
            $rounds++;
            $pending = $this->collectPending($blocks, $limit, $skipIds);

            if ($pending === []) {
                break;
            }

            try {
                $map = $this->translatePendingWithFallback($pending);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                foreach (array_keys($pending) as $id) {
                    $i = (int) $id;
                    $skipIds[$i] = true;
                    if (isset($blocks[$i]) && is_array($blocks[$i])) {
                        $blocks[$i]['ru_skip'] = true;
                    }
                }
                continue;
            }

            foreach ($map as $id => $ru) {
                $i = (int) $id;
                if (! isset($blocks[$i]) || ! is_array($blocks[$i])) {
                    continue;
                }
                $blocks[$i]['text_ru'] = $ru;
                unset($blocks[$i]['ru_skip']);
                $translated++;
            }

            foreach ($pending as $id => $_text) {
                $i = (int) $id;
                if (isset($map[$i]) || isset($map[(string) $i])) {
                    continue;
                }
                $skipIds[$i] = true;
                if (isset($blocks[$i]) && is_array($blocks[$i])) {
                    $blocks[$i]['ru_skip'] = true;
                }
            }
        }

        $meta['blocks'] = $blocks;
        $translatedTotal = $this->countTranslated($blocks);
        $meta['stats'] = array_merge($meta['stats'] ?? [], [
            'translated_ru' => $translatedTotal,
        ]);

        $remaining = max(0, $total - $translatedTotal);

        $out = [
            'meta' => $meta,
            'translated' => $translated,
            'remaining' => $remaining,
            'total' => $total,
            'already' => $already,
            'done' => $remaining === 0,
        ];

        if ($translated === 0 && $remaining > 0) {
            $out['warning'] = $lastError
                ?: 'Gemini returned non-JSON — сохранённые '.$already.' не тронуты, сбойные блоки пропущены. Нажмите ещё раз.';
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $blocks
     * @param  array<int, bool>  $skipIds
     * @return array<int, string>
     */
    private function collectPending(array &$blocks, int $limit, array $skipIds): array
    {
        $pending = [];
        foreach ($blocks as $i => $block) {
            if (! is_array($block) || ($block['type'] ?? '') === 'image') {
                continue;
            }
            $i = (int) $i;
            if (! empty($skipIds[$i]) || ! empty($block['ru_skip'])) {
                continue;
            }
            $text = trim((string) ($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (trim((string) ($block['text_ru'] ?? '')) !== '') {
                continue;
            }
            $pending[$i] = $text;
            if (count($pending) >= $limit) {
                return $pending;
            }
        }

        // Only previously skipped left — retry a wave
        if ($pending === []) {
            foreach ($blocks as $i => $block) {
                if (! is_array($block) || ($block['type'] ?? '') === 'image') {
                    continue;
                }
                $i = (int) $i;
                if (empty($block['ru_skip'])) {
                    continue;
                }
                $text = trim((string) ($block['text'] ?? ''));
                if ($text === '' || trim((string) ($block['text_ru'] ?? '')) !== '') {
                    continue;
                }
                unset($blocks[$i]['ru_skip']);
                $pending[$i] = $text;
                if (count($pending) >= $limit) {
                    break;
                }
            }
        }

        return $pending;
    }

    /**
     * @param  array<int|string, string>  $pending
     * @return array<int|string, string>
     */
    private function translatePendingWithFallback(array $pending): array
    {
        try {
            return $this->gemini->translateMap($pending);
        } catch (Throwable $e) {
            if (count($pending) <= 1) {
                throw $e;
            }
        }

        $map = [];
        $chunks = array_chunk($pending, max(1, (int) ceil(count($pending) / 2)), true);
        foreach ($chunks as $chunk) {
            try {
                $part = $this->gemini->translateMap($chunk);
                foreach ($part as $id => $ru) {
                    $map[$id] = $ru;
                }
            } catch (Throwable) {
                if (count($chunk) === 1) {
                    continue;
                }
                foreach (array_chunk($chunk, 1, true) as $one) {
                    try {
                        $part = $this->gemini->translateMap($one);
                        foreach ($part as $id => $ru) {
                            $map[$id] = $ru;
                        }
                    } catch (Throwable) {
                        // leave for ru_skip
                    }
                }
            }
        }

        if ($map === []) {
            throw new \RuntimeException('Gemini returned non-JSON (batch failed even after split)');
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $blocks
     */
    private function countTranslatable(array $blocks): int
    {
        $n = 0;
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') === 'image') {
                continue;
            }
            if (trim((string) ($block['text'] ?? '')) !== '') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<mixed>  $blocks
     */
    private function countTranslated(array $blocks): int
    {
        $n = 0;
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') === 'image') {
                continue;
            }
            if (trim((string) ($block['text'] ?? '')) === '') {
                continue;
            }
            if (trim((string) ($block['text_ru'] ?? '')) !== '') {
                $n++;
            }
        }

        return $n;
    }
}
