<?php

namespace App\Services;

use Throwable;

class DocxBlocksRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * Translate a slice of HE blocks into a separate RU meta (text in Russian).
     * Does not mutate HE meta. Skips blocks already filled in RU. On hard failures
     * marks those ids (ru_skip on RU block) and continues.
     *
     * @param  array<string, mixed>  $heMeta
     * @param  array<string, mixed>|null  $ruMeta
     * @return array{
     *   he_meta: array<string,mixed>,
     *   ru_meta: array<string,mixed>,
     *   translated: int,
     *   remaining: int,
     *   total: int,
     *   done: bool,
     *   already: int,
     *   warning?: string
     * }
     */
    public function translateBatch(array $heMeta, ?array $ruMeta = null, int $limit = 12): array
    {
        $heMeta = DocxMetaHelper::purifyHebrew($heMeta);
        $heBlocks = $heMeta['blocks'] ?? [];
        if (! is_array($heBlocks)) {
            $heBlocks = [];
        }

        if (! is_array($ruMeta) || ($ruMeta['format'] ?? '') !== 'docx_sequential_v1') {
            $ruMeta = DocxMetaHelper::skeletonRussian($heMeta);
        }

        $ruBlocks = $ruMeta['blocks'] ?? [];
        if (! is_array($ruBlocks)) {
            $ruBlocks = [];
        }

        // Align RU skeleton length with HE if HE was re-imported
        if (count($ruBlocks) !== count($heBlocks)) {
            $ruMeta = DocxMetaHelper::skeletonRussian($heMeta);
            $ruBlocks = $ruMeta['blocks'];
        }

        $total = DocxMetaHelper::countTranslatable($heBlocks);
        $already = DocxMetaHelper::countTranslated($heBlocks, $ruBlocks);

        $skipIds = [];
        $translated = 0;
        $lastError = null;
        $rounds = 0;

        while ($rounds < 4 && $translated === 0) {
            $rounds++;
            $pending = $this->collectPending($heBlocks, $ruBlocks, $limit, $skipIds);

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
                    if (isset($ruBlocks[$i]) && is_array($ruBlocks[$i])) {
                        $ruBlocks[$i]['ru_skip'] = true;
                    }
                }

                continue;
            }

            foreach ($map as $id => $ru) {
                $i = (int) $id;
                if (! isset($ruBlocks[$i]) || ! is_array($ruBlocks[$i])) {
                    continue;
                }
                $ruBlocks[$i]['text'] = $ru;
                unset($ruBlocks[$i]['ru_skip'], $ruBlocks[$i]['text_ru']);
                $translated++;
            }

            foreach ($pending as $id => $_text) {
                $i = (int) $id;
                if (isset($map[$i]) || isset($map[(string) $i])) {
                    continue;
                }
                $skipIds[$i] = true;
                if (isset($ruBlocks[$i]) && is_array($ruBlocks[$i])) {
                    $ruBlocks[$i]['ru_skip'] = true;
                }
            }
        }

        $ruMeta['blocks'] = $ruBlocks;
        $translatedTotal = DocxMetaHelper::countTranslated($heBlocks, $ruBlocks);
        $ruMeta['language'] = 'ru';
        $ruMeta['stats'] = array_merge($ruMeta['stats'] ?? [], [
            'translated' => $translatedTotal,
            'blocks' => count($ruBlocks),
        ]);

        $remaining = max(0, $total - $translatedTotal);

        $out = [
            'he_meta' => $heMeta,
            'ru_meta' => $ruMeta,
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
     * @param  list<mixed>  $heBlocks
     * @param  list<mixed>  $ruBlocks
     * @param  array<int, bool>  $skipIds
     * @return array<int, string>
     */
    private function collectPending(array &$heBlocks, array &$ruBlocks, int $limit, array $skipIds): array
    {
        $pending = [];
        foreach ($heBlocks as $i => $he) {
            if (! is_array($he) || ($he['type'] ?? '') === 'image') {
                continue;
            }
            $i = (int) $i;
            if (! empty($skipIds[$i])) {
                continue;
            }
            $ru = $ruBlocks[$i] ?? null;
            if (is_array($ru) && ! empty($ru['ru_skip'])) {
                continue;
            }
            $text = trim((string) ($he['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (is_array($ru) && trim((string) ($ru['text'] ?? '')) !== '') {
                continue;
            }
            $pending[$i] = $text;
            if (count($pending) >= $limit) {
                return $pending;
            }
        }

        if ($pending === []) {
            foreach ($heBlocks as $i => $he) {
                if (! is_array($he) || ($he['type'] ?? '') === 'image') {
                    continue;
                }
                $i = (int) $i;
                $ru = $ruBlocks[$i] ?? null;
                if (! is_array($ru) || empty($ru['ru_skip'])) {
                    continue;
                }
                $text = trim((string) ($he['text'] ?? ''));
                if ($text === '' || trim((string) ($ru['text'] ?? '')) !== '') {
                    continue;
                }
                unset($ruBlocks[$i]['ru_skip']);
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
}
