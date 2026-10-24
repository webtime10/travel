<?php

namespace App\Services;

use App\Models\GoldenDay;
use Illuminate\Support\Collection;

/**
 * Pick active golden day templates for a client query (hub / tokens / region).
 */
class GoldenDayMatcher
{
    public function __construct(private RouteSkeletonMatcher $hubs) {}

    /**
     * @return list<GoldenDay>
     */
    public function findForQuery(string $query, int $limit = 12): array
    {
        $limit = max(1, min(30, $limit));
        $hubList = $this->hubs->detectHubs($query);
        $tokens = $this->tokens($query);

        $q = GoldenDay::query()
            ->with(['stops.location'])
            ->where('is_active', true)
            ->where('stops_count', '>=', 2)
            ->orderByDesc('usage_count')
            ->orderByDesc('id');

        if ($hubList !== []) {
            $q->where(function ($w) use ($hubList, $tokens) {
                $w->whereIn('hub_key', $hubList);
                foreach ($hubList as $hub) {
                    $w->orWhere('search_blob', 'like', '%'.$hub.'%');
                }
                foreach ($tokens as $t) {
                    if (mb_strlen($t) >= 4) {
                        $w->orWhere('search_blob', 'like', '%'.$t.'%')
                            ->orWhere('title', 'like', '%'.$t.'%');
                    }
                }
            });
        } elseif ($tokens !== []) {
            $q->where(function ($w) use ($tokens) {
                foreach ($tokens as $t) {
                    $w->orWhere('search_blob', 'like', '%'.$t.'%')
                        ->orWhere('title', 'like', '%'.$t.'%');
                }
            });
        }

        /** @var Collection<int, GoldenDay> $candidates */
        $candidates = $q->limit(400)->get();
        if ($candidates->isEmpty()) {
            $candidates = GoldenDay::query()
                ->with(['stops.location'])
                ->where('is_active', true)
                ->where('stops_count', '>=', 2)
                ->orderByDesc('usage_count')
                ->limit(80)
                ->get();
        }

        $scored = $candidates->map(function (GoldenDay $d) use ($hubList, $tokens) {
            $blob = mb_strtolower((string) ($d->search_blob ?? '').' '.(string) ($d->title ?? ''));
            $score = (float) min(20, (int) $d->usage_count) * 0.15;
            if ($d->hub_key && in_array($d->hub_key, $hubList, true)) {
                $score += 12.0;
            }
            foreach ($hubList as $hub) {
                if ($hub !== '' && str_contains($blob, $hub)) {
                    $score += 4.0;
                }
            }
            foreach ($tokens as $t) {
                if ($t !== '' && str_contains($blob, $t)) {
                    $score += 1.2;
                }
            }
            $score += min(5, (int) $d->stops_count) * 0.3;

            return ['d' => $d, 'score' => $score];
        })
            ->filter(fn (array $r) => $r['score'] > 0.5)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($scored->isEmpty()) {
            return $candidates->take($limit)->all();
        }

        return $scored->map(fn (array $r) => $r['d'])->all();
    }

    /**
     * @param  list<GoldenDay>  $days
     */
    public function formatForPrompt(array $days): string
    {
        if ($days === []) {
            return '';
        }
        $blocks = [];
        foreach ($days as $i => $d) {
            $hub = $d->hub_key ? ' hub='.$d->hub_key : '';
            $blocks[] = '=== ЗОЛОТОЙ ДЕНЬ '.($i + 1).' (id='.$d->id.$hub.', usage='.$d->usage_count.") ===\n"
                .$d->toBriefText()."\n"
                .'Связка (не разбивай): '.implode(' → ', $d->stopNames());
        }

        return "ГОТОВЫЕ ДНИ ДИРЕКТОРА (вставляй целиком как блок дня, не собирай точки в другом порядке):\n\n"
            .implode("\n\n", $blocks);
    }

    /**
     * @param  list<GoldenDay>  $days
     * @return list<array<string, mixed>>
     */
    public function toSampleRows(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            $out[] = [
                'id' => (int) $d->id,
                'source_route_id' => (int) ($d->source_skeleton_id ?? 0),
                'title' => '[GOLDEN DAY] '.$d->displayTitle(),
                'days_count' => 1,
                'brief' => $d->toBriefText()."\nСвязка: ".implode(' → ', $d->stopNames()),
                'kind' => 'golden_day',
                'hub_key' => $d->hub_key,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $q = mb_strtolower($query);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $q) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 3) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }
}
