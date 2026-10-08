<?php

namespace App\Services;

/**
 * Helpers for docx_sequential_v1 meta (HE source / RU copy).
 */
class DocxMetaHelper
{
    /**
     * Strip translation leftovers so HE meta stays Hebrew-only.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function purifyHebrew(array $meta): array
    {
        $blocks = $meta['blocks'] ?? [];
        if (! is_array($blocks)) {
            $blocks = [];
        }

        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            unset($blocks[$i]['text_ru'], $blocks[$i]['ru_skip']);
        }
        $meta['blocks'] = array_values($blocks);

        $stats = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];
        unset($stats['translated_ru']);
        $meta['stats'] = $stats;
        $meta['language'] = 'he';

        return $meta;
    }

    /**
     * Empty RU skeleton cloned from HE (same structure; text empty until translated).
     *
     * @param  array<string, mixed>  $heMeta
     * @return array<string, mixed>
     */
    public static function skeletonRussian(array $heMeta): array
    {
        $blocks = [];
        foreach ($heMeta['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'image') {
                $blocks[] = [
                    'type' => 'image',
                    'src' => $block['src'] ?? null,
                ];

                continue;
            }
            $row = [
                'type' => $type !== '' ? $type : 'text',
                'text' => '',
            ];
            if (! empty($block['style'])) {
                $row['style'] = $block['style'];
            }
            if (! empty($block['url'])) {
                $row['url'] = $block['url'];
            }
            $blocks[] = $row;
        }

        return [
            'format' => 'docx_sequential_v1',
            'language' => 'ru',
            'doc_index' => $heMeta['doc_index'] ?? null,
            'source_file' => $heMeta['source_file'] ?? null,
            'blocks' => $blocks,
            'images' => $heMeta['images'] ?? [],
            'stats' => [
                'blocks' => count($blocks),
                'images' => count($heMeta['images'] ?? []),
                'text_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') !== 'image')),
                'image_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'image')),
                'link_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'link')),
                'translated' => 0,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $heBlocks
     */
    public static function countTranslatable(array $heBlocks): int
    {
        $n = 0;
        foreach ($heBlocks as $block) {
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
     * Count RU blocks that already have text for HE positions that need translation.
     *
     * @param  list<mixed>  $heBlocks
     * @param  list<mixed>  $ruBlocks
     */
    public static function countTranslated(array $heBlocks, array $ruBlocks): int
    {
        $n = 0;
        foreach ($heBlocks as $i => $he) {
            if (! is_array($he) || ($he['type'] ?? '') === 'image') {
                continue;
            }
            if (trim((string) ($he['text'] ?? '')) === '') {
                continue;
            }
            $ru = $ruBlocks[$i] ?? null;
            if (is_array($ru) && trim((string) ($ru['text'] ?? '')) !== '') {
                $n++;
            }
        }

        return $n;
    }
}
