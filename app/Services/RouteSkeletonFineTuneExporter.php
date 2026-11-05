<?php

namespace App\Services;

use App\Models\RouteSkeleton;
use Generator;

/**
 * Pack reverse_principle rows into Gemini Fine-Tuning JSONL
 * (User = synthetic query, Model = thoughts + route JSON).
 */
class RouteSkeletonFineTuneExporter
{
    /**
     * @return array{ready: int, skipped: int}
     */
    public function counts(): array
    {
        $ready = 0;
        $skipped = 0;
        RouteSkeleton::query()
            ->orderBy('id')
            ->select(['id', 'reverse_principle'])
            ->chunkById(300, function ($chunk) use (&$ready, &$skipped) {
                foreach ($chunk as $row) {
                    if ($this->buildExample($row) !== null) {
                        $ready++;
                    } else {
                        $skipped++;
                    }
                }
            });

        return ['ready' => $ready, 'skipped' => $skipped];
    }

    /**
     * Stream JSONL lines (each line = one tuning example).
     *
     * @return Generator<int, string>
     */
    public function jsonlLines(): Generator
    {
        $rows = RouteSkeleton::query()
            ->orderBy('id')
            ->select(['id', 'reverse_principle']);

        foreach ($rows->cursor() as $row) {
            $example = $this->buildExample($row);
            if ($example === null) {
                continue;
            }
            yield json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        }
    }

    /**
     * Gemini supervised tuning row:
     * user = abstract request; model = [THOUGHTS] logic + [ROUTE] days/ids.
     *
     * @return array{contents: list<array{role: string, parts: list<array{text: string}>}>}|null
     */
    public function buildExample(RouteSkeleton $route): ?array
    {
        $rp = $route->reverse_principle;
        if (! is_array($rp) || ! empty($rp['skipped'])) {
            return null;
        }

        $ft = is_array($rp['fine_tune'] ?? null) ? $rp['fine_tune'] : [];
        $question = trim((string) ($ft['question'] ?? $rp['abstract_user_prompt'] ?? $rp['synthetic_query'] ?? ''));
        if ($question === '') {
            return null;
        }

        $answer = is_array($ft['answer'] ?? null) ? $ft['answer'] : [];
        $days = is_array($answer['days'] ?? null) ? $answer['days'] : [];
        if ($days === []) {
            // Fallback: collect location_ids from principle.days stops.
            foreach ((array) ($rp['days'] ?? []) as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $ids = [];
                foreach ((array) ($d['stops'] ?? []) as $s) {
                    $lid = (int) ($s['location_id'] ?? 0);
                    if ($lid > 0) {
                        $ids[] = $lid;
                    }
                }
                if ($ids === []) {
                    continue;
                }
                $days[] = [
                    'day_index' => (int) ($d['day_index'] ?? count($days)),
                    'title' => null,
                    'location_ids' => $ids,
                ];
            }
        }
        if ($days === []) {
            return null;
        }

        $thoughts = [
            'macro_architecture' => is_array($rp['macro_architecture'] ?? null)
                ? $rp['macro_architecture']
                : [
                    'region_selection_logic' => (string) ($rp['principle'] ?? ''),
                    'routing_strategy' => (string) ($rp['geo_logic'] ?? ''),
                ],
            'micro_clustering' => is_array($rp['micro_clustering'] ?? null)
                ? $rp['micro_clustering']
                : [],
        ];

        $routePayload = [
            'summary' => (string) ($answer['summary'] ?? ''),
            'days' => array_values(array_map(static function ($d) {
                return [
                    'day_index' => (int) ($d['day_index'] ?? 0),
                    'title' => $d['title'] ?? null,
                    'location_ids' => array_values(array_map('intval', (array) ($d['location_ids'] ?? []))),
                ];
            }, $days)),
        ];

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $modelText = "[THOUGHTS]\n"
            .json_encode($thoughts, $flags)
            ."\n\n[ROUTE]\n"
            .json_encode($routePayload, $flags);

        return [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $question]],
                ],
                [
                    'role' => 'model',
                    'parts' => [['text' => $modelText]],
                ],
            ],
        ];
    }
}
