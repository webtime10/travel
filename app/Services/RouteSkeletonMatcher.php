<?php

namespace App\Services;

use App\Models\RouteSkeleton;

/**
 * Поиск похожих каркасов: хабы ночёвок из плана + токены запроса.
 */
class RouteSkeletonMatcher
{
    /** Латиница/иврит/русский → поисковые ключи хаба */
    private const HUB_ALIASES = [
        'riva' => ['riva', 'ריבה', 'рива'],
        'ortisei' => ['ortisei', 'אורטיז', 'ортизе', 'ortizei', 'gardena'],
        'brunico' => ['brunico', 'bruneck', 'ברוניקו', 'бруник', 'kronplatz', 'corones', 'plan de corones', 'קרונפ'],
        'braies' => ['braies', 'pragser', 'בראייס', 'браес'],
        'misurina' => ['misurina', 'מיזורינ', 'мизурин', 'cadini', 'dobbiaco', 'דוביאק', 'cinque torri', '5 פסגות', 'auronzo', 'אורונצ', 'sorapis'],
        'cortina' => ['cortina', 'קורטינ', 'кортин', 'ampezzo'],
        'molveno' => ['molveno', 'מולבנ', 'мольвен'],
        'garda' => ['garda', 'גארד', 'гарда', 'limone', 'לימונ', 'malcesine', 'מלצס', 'riva', 'ריבה', 'tenno', 'טנו', 'varone', 'וארנ', 'busatte', 'בוסאט', 'ampola', 'אמפול', 'ledro', 'לדרו'],
        'dolomiti' => ['dolomit', 'דולומיט', 'доломит'],
        'milan' => ['milan', 'milano', 'מילאנ', 'милан', 'mxp'],
        'verona' => ['verona', 'ורונ', 'верона', 'vrn'],
        'campiglio' => ['nardis', 'נארדיס', 'campiglio', 'кампиль', 'genova', 'גנובה'],
    ];

    /**
     * @param  list<string>  $dayPlan  optional packed lines for hub extraction
     * @return list<RouteSkeleton>
     */
    public function findSimilar(string $query, int $dayCount, int $limit = 5, array $dayPlan = []): array
    {
        $limit = max(1, min(20, $limit));
        $hubs = $this->detectHubs($query."\n".implode("\n", $dayPlan));
        $tokens = $this->tokens($query);
        $dayMin = max(1, $dayCount - 3);
        $dayMax = $dayCount + 3;

        $candidates = RouteSkeleton::query()
            ->with('days')
            ->whereBetween('days_count', [$dayMin, $dayMax])
            ->orderByDesc('id')
            ->limit(800)
            ->get();

        if ($hubs !== []) {
            $hubQuery = RouteSkeleton::query()->with('days')->whereBetween('days_count', [$dayMin, $dayMax]);
            $hubQuery->where(function ($w) use ($hubs) {
                foreach ($hubs as $hub) {
                    foreach (self::HUB_ALIASES[$hub] ?? [$hub] as $alias) {
                        $w->orWhere('search_blob', 'like', '%'.$alias.'%')
                            ->orWhere('title', 'like', '%'.$alias.'%');
                    }
                }
            });
            $extra = $hubQuery->orderByDesc('id')->limit(400)->get();
            $candidates = $candidates->concat($extra)->unique('id')->values();
        }

        if ($candidates->isEmpty()) {
            $candidates = RouteSkeleton::query()->with('days')->orderByDesc('id')->limit(400)->get();
        }

        $scored = $candidates->map(function (RouteSkeleton $s) use ($tokens, $hubs, $dayCount) {
            $blob = mb_strtolower((string) ($s->search_blob ?? '').' '.(string) ($s->title ?? ''));
            $score = 0.0;
            foreach ($tokens as $t) {
                if ($t !== '' && str_contains($blob, $t)) {
                    $score += 1.0;
                }
            }
            foreach ($hubs as $hub) {
                $hit = false;
                foreach (self::HUB_ALIASES[$hub] ?? [$hub] as $alias) {
                    if ($alias !== '' && str_contains($blob, mb_strtolower($alias))) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $score += 8.0;
                }
            }
            $score -= abs(((int) $s->days_count) - $dayCount) * 0.2;

            return ['s' => $s, 'score' => $score];
        })
            ->filter(fn (array $r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($scored->isEmpty()) {
            return $candidates->take($limit)->all();
        }

        return $scored->map(fn (array $r) => $r['s'])->all();
    }

    /**
     * Имена мест из дней образцов, где ночёвка ≈ наш хаб.
     *
     * @param  list<RouteSkeleton>  $skeletons
     * @return list<string> lowercase names
     */
    public function placeNamesForHub(array $skeletons, string $hubKey): array
    {
        $names = [];
        foreach ($this->dayConstructions($skeletons) as $c) {
            if (! in_array($hubKey, $c['hubs'], true)) {
                continue;
            }
            foreach ($c['places'] as $n) {
                $names[] = $n;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Готовые дневные связки директора: упорядоченный список мест + хабы оси дня.
     * Это «конструкция» (A → озеро → B), а не просто bag of names.
     *
     * @param  list<RouteSkeleton>  $skeletons
     * @return list<array{
     *   hubs: list<string>,
     *   places: list<string>,
     *   axis: string,
     *   source_route_id: int,
     *   day_index: int
     * }>
     */
    public function dayConstructions(array $skeletons): array
    {
        $out = [];
        foreach ($skeletons as $s) {
            $sid = (int) ($s->source_route_id ?? $s->id ?? 0);
            foreach ($s->days as $day) {
                $axis = trim((string) ($day->start_point ?? '').' '.(string) ($day->end_point ?? '').' '.(string) ($day->title ?? ''));
                $hubs = $this->detectHubs($axis);
                $raw = [];
                if (is_array($day->places_ru) && $day->places_ru !== []) {
                    $raw = $day->places_ru;
                } elseif (is_array($day->places)) {
                    $raw = $day->places;
                }
                $places = [];
                $seen = [];
                foreach ($raw as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $n = mb_strtolower(trim((string) ($p['name'] ?? '')));
                    if (mb_strlen($n) < 3 || isset($seen[$n])) {
                        continue;
                    }
                    // Ночёвка/отель в списке мест — не часть туристической связки
                    if (preg_match('/hotel|מלון|resort|overnight|ночев/ui', $n)) {
                        continue;
                    }
                    $seen[$n] = true;
                    $places[] = $n;
                }
                if ($places === []) {
                    continue;
                }
                $out[] = [
                    'hubs' => $hubs,
                    'places' => $places,
                    'axis' => mb_strtolower($axis),
                    'source_route_id' => $sid,
                    'day_index' => (int) ($day->day_index ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * Конструкции, релевантные хабам ночёвки (с пересечением hub-ключей или осью).
     *
     * @param  list<RouteSkeleton>  $skeletons
     * @param  list<string>  $nightHubs
     * @return list<array{hubs: list<string>, places: list<string>, axis: string, source_route_id: int, day_index: int}>
     */
    public function constructionsForHubs(array $skeletons, array $nightHubs): array
    {
        if ($nightHubs === []) {
            return [];
        }
        $out = [];
        foreach ($this->dayConstructions($skeletons) as $c) {
            if ($c['hubs'] !== [] && array_intersect($nightHubs, $c['hubs']) !== []) {
                $out[] = $c;

                continue;
            }
            $blob = $c['axis'].' '.implode(' ', $c['places']);
            foreach ($nightHubs as $hub) {
                foreach (self::HUB_ALIASES[$hub] ?? [$hub] as $alias) {
                    $a = mb_strtolower($alias);
                    if ($a !== '' && str_contains($blob, $a)) {
                        $out[] = $c;
                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<RouteSkeleton>  $skeletons
     */
    public function formatForPrompt(array $skeletons): string
    {
        if ($skeletons === []) {
            return '';
        }
        $blocks = [];
        foreach ($skeletons as $i => $s) {
            $blocks[] = '=== Образец '.($i + 1).' (source_route_id='.$s->source_route_id.") ===\n"
                .$s->toBriefText();
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @return list<string> hub keys
     */
    public function detectHubs(string $text): array
    {
        $t = mb_strtolower($text);
        $found = [];
        foreach (self::HUB_ALIASES as $hub => $aliases) {
            foreach ($aliases as $a) {
                if ($a !== '' && str_contains($t, mb_strtolower($a))) {
                    $found[] = $hub;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
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
