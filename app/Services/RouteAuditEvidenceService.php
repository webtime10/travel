<?php

namespace App\Services;

use App\Models\Location;
use App\Models\RouteSkeleton;

/**
 * По issues аудита ищет РЕАЛЬНЫЕ варианты в БД + именах из исторических конструкций.
 * Gemini не создаёт POI — только search intent.
 */
class RouteAuditEvidenceService
{
    public function __construct(
        private RouteSkeletonMatcher $matcher,
        private LocationDurationResolver $durations,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $locations
     * @param  list<RouteSkeleton>  $skeletons
     * @return array{
     *   by_day: array<string, list<array<string, mixed>>>,
     *   trace: array<string, array<string, mixed>>
     * }
     */
    public function candidatesForIssues(
        array $issues,
        array $dayPlan,
        array $locations,
        array $skeletons,
    ): array {
        $byId = [];
        foreach ($locations as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $byId[(int) $row['id']] = $row;
            }
        }
        $used = $this->usedIds($dayPlan);
        $nightByDate = $this->nightsByDate($dayPlan, $byId);
        $out = [];
        $trace = [];

        foreach ($issues as $issue) {
            $day = (string) ($issue['day'] ?? '');
            if ($day === '') {
                continue;
            }
            $scope = is_array($issue['search_scope'] ?? null) ? $issue['search_scope'] : [];
            $night = $nightByDate[$day] ?? null;
            if ($night === null) {
                $trace[$day] = [
                    'searched' => false,
                    'candidates_found' => 0,
                    'accepted' => [],
                    'rejected' => [['id' => null, 'reason' => 'no_overnight_coords']],
                ];
                continue;
            }
            $avoid = array_map('strtolower', (array) ($scope['avoid_types'] ?? []));
            $preferred = array_map('strtolower', (array) ($scope['preferred_types'] ?? []));
            $maxHours = isset($scope['max_hours']) ? (float) $scope['max_hours'] : null;
            $histNames = array_merge(
                (array) ($scope['historical_candidates'] ?? []),
                $this->matcher->placeNamesForHub(
                    $skeletons,
                    $this->hubKeyFromBase((string) ($scope['base'] ?? $this->name($night)))
                )
            );

            $found = [];
            $rejected = [];
            $rejectSeen = [];

            $reject = function (array $row, string $reason) use (&$rejected, &$rejectSeen): void {
                $id = (int) ($row['id'] ?? 0);
                $key = $id.'|'.$reason;
                if (isset($rejectSeen[$key])) {
                    return;
                }
                $rejectSeen[$key] = true;
                $rejected[] = [
                    'id' => $id > 0 ? $id : null,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'reason' => $reason,
                ];
            };

            // 1) historical names → catalog
            foreach ($histNames as $want) {
                $want = trim((string) $want);
                if ($want === '') {
                    continue;
                }
                $row = $this->resolveName($want, $byId, $night, $used);
                if ($row === null) {
                    $rejected[] = ['id' => null, 'name' => $want, 'reason' => 'not_found_in_db'];
                    continue;
                }
                if ($this->matchesAvoid($row, $avoid)) {
                    $reject($row, 'avoid_type');
                    continue;
                }
                if ($this->isOvernightPseudo($row, $night)) {
                    $reject($row, 'overnight_pseudo');
                    continue;
                }
                if ($this->isJunkPoi($row)) {
                    $reject($row, 'junk_poi');
                    continue;
                }
                if ($preferred !== [] && ! $this->matchesPreferred($row, $preferred)) {
                    $reject($row, 'not_preferred_type');
                    continue;
                }
                $found[] = $this->scoreCandidate($row, $night, $histNames, $preferred, true);
            }

            // 2) catalog near overnight
            $nearLimit = ($maxHours !== null && $maxHours <= 2.5) ? 25 : 12;
            foreach ($this->catalogNear($night, $used, $nearLimit) as $row) {
                if ($this->matchesAvoid($row, $avoid)) {
                    $reject($row, 'avoid_type');
                    continue;
                }
                if ($this->isOvernightPseudo($row, $night)) {
                    $reject($row, 'overnight_pseudo');
                    continue;
                }
                if ($this->isJunkPoi($row)) {
                    $reject($row, 'junk_poi');
                    continue;
                }
                if (! in_array('adventure', $preferred, true)
                    && preg_match('/rope|חבלים|emozion|col\s*de\s*flam|skyscraper|x-?treme|funbob|מגלש/ui', $this->name($row))) {
                    $reject($row, 'extreme_or_rope');
                    continue;
                }
                if ($preferred !== [] && ! $this->matchesPreferred($row, $preferred)) {
                    $reject($row, 'not_preferred_type');
                    continue;
                }
                if ($maxHours !== null && $maxHours > 0) {
                    $h = $this->durations->hoursForId((int) ($row['id'] ?? 0), $this->name($row));
                    if ($h > $maxHours + 1.5) {
                        $reject($row, 'too_long');
                        continue;
                    }
                }
                $found[] = $this->scoreCandidate($row, $night, $histNames, $preferred, false);
            }

            foreach ($found as &$c) {
                $h = (float) ($c['hours'] ?? 99);
                if ($maxHours !== null && $maxHours > 0 && $h <= $maxHours) {
                    $c['score'] = ($c['score'] ?? 0) + 25;
                    $c['fits_max_hours'] = true;
                } elseif ($maxHours !== null && $h > $maxHours) {
                    $c['score'] = ($c['score'] ?? 0) - 20;
                    $c['fits_max_hours'] = false;
                    $reject(['id' => $c['id'] ?? 0, 'name' => $c['name'] ?? ''], 'over_max_hours_soft');
                } else {
                    $c['fits_max_hours'] = $h <= 2.5;
                }
            }
            unset($c);

            usort($found, fn ($a, $b) => ($b['score'] <=> $a['score']));
            $uniq = [];
            $seen = [];
            foreach ($found as $c) {
                $id = (int) ($c['id'] ?? 0);
                if ($id <= 0 || isset($seen[$id]) || isset($used[$id])) {
                    continue;
                }
                if (preg_match('/funbob|מגלש|skyscraper|x-?treme/ui', (string) ($c['name'] ?? ''))) {
                    $reject($c, 'extreme_or_rope');
                    continue;
                }
                $seen[$id] = true;
                if (count($uniq) >= 3) {
                    $reject($c, 'beyond_top3');
                    continue;
                }
                // soft: слишком длинный остаётся в suggest, но помечаем
                $uniq[] = $c;
            }
            $out[$day] = $uniq;
            $trace[$day] = [
                'searched' => true,
                'scope' => [
                    'base' => (string) ($scope['base'] ?? ''),
                    'direction' => (string) ($scope['direction'] ?? ''),
                    'preferred_types' => $preferred,
                    'max_hours' => $maxHours,
                ],
                'candidates_found' => count($uniq),
                'accepted' => array_map(
                    fn ($c) => [
                        'id' => $c['id'] ?? null,
                        'name' => $c['name'] ?? null,
                        'hours' => $c['hours'] ?? null,
                        'detour_km' => $c['detour_km'] ?? null,
                        'fits_max_hours' => $c['fits_max_hours'] ?? null,
                        'score' => $c['score'] ?? null,
                    ],
                    $uniq
                ),
                'rejected' => array_slice($rejected, 0, 12),
            ];
        }

        return ['by_day' => $out, 'trace' => $trace];
    }

    /**
     * @param  list<string>  $dayPlan
     * @return array<int, true>
     */
    private function usedIds(array $dayPlan): array
    {
        $used = [];
        foreach ($dayPlan as $line) {
            if (preg_match_all('/\[id=(\d+)\]/', $line, $m)) {
                foreach ($m[1] as $id) {
                    $used[(int) $id] = true;
                }
            }
        }

        return $used;
    }

    /**
     * @param  list<string>  $dayPlan
     * @param  array<int, array<string, mixed>>  $byId
     * @return array<string, array<string, mixed>>
     */
    private function nightsByDate(array $dayPlan, array $byId): array
    {
        $out = [];
        foreach ($dayPlan as $line) {
            if (! preg_match('/^(\d{2}\.\d{2})/u', $line, $dm)) {
                continue;
            }
            if (! preg_match('/Ночёвка:[^\[]*\[id=(\d+)\]/u', $line, $nm)) {
                continue;
            }
            $id = (int) $nm[1];
            if (isset($byId[$id])) {
                $out[$dm[1]] = $byId[$id];
            }
        }

        return $out;
    }

    private function hubKeyFromBase(string $base): string
    {
        $hubs = $this->matcher->detectHubs($base);

        return $hubs[0] ?? 'garda';
    }

    /**
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $night
     * @param  array<int, true>  $used
     * @return array<string, mixed>|null
     */
    private function resolveName(string $want, array $byId, array $night, array $used): ?array
    {
        $wantL = mb_strtolower($want);
        $best = null;
        $bestScore = 0.0;
        $pool = array_merge(array_values($byId), $this->catalogNear($night, $used, 25));
        foreach ($pool as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($used[$id]) || $id === (int) ($night['id'] ?? 0)) {
                continue;
            }
            $name = mb_strtolower($this->name($row));
            $score = 0.0;
            if ($name === $wantL || str_contains($name, $wantL) || str_contains($wantL, $name)) {
                $score = 10.0;
            } elseif (mb_strlen($wantL) >= 4 && (str_contains($name, mb_substr($wantL, 0, 5)) || str_contains($wantL, mb_substr($name, 0, 5)))) {
                $score = 6.0;
            }
            if ($score <= 0) {
                continue;
            }
            $km = $this->km($night, $row);
            if ($km !== null && $km > 60) {
                continue;
            }
            if ($km !== null) {
                $score += max(0, 25 - $km) * 0.15;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        if ($best !== null) {
            return $best;
        }

        $needle = mb_substr($want, 0, 24);
        if (mb_strlen($needle) < 4) {
            return null;
        }
        $loc = Location::query()
            ->whereNotNull('lat')
            ->where(function ($q) use ($needle) {
                $q->where('meta->title', 'like', '%'.$needle.'%');
            })
            ->limit(8)
            ->get(['id', 'lat', 'lng', 'trip_role', 'meta']);
        foreach ($loc as $l) {
            if ($l->trip_role === 'base' || isset($used[(int) $l->id])) {
                continue;
            }
            $meta = is_array($l->meta) ? $l->meta : [];
            $row = [
                'id' => (int) $l->id,
                'name' => (string) ($meta['title'] ?? ''),
                'lat' => (float) $l->lat,
                'lng' => (float) $l->lng,
                'role' => 'place',
                'is_base' => false,
            ];
            $km = $this->km($night, $row);
            if ($km !== null && $km <= 60) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $night
     * @param  array<int, true>  $used
     * @return list<array<string, mixed>>
     */
    private function catalogNear(array $night, array $used, int $limit): array
    {
        if (! isset($night['lat'], $night['lng'])) {
            return [];
        }
        $nLat = (float) $night['lat'];
        $nLng = (float) $night['lng'];
        $rows = Location::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [$nLat - 0.45, $nLat + 0.45])
            ->whereBetween('lng', [$nLng - 0.55, $nLng + 0.55])
            ->where(function ($q) {
                $q->whereNull('trip_role')->orWhere('trip_role', '!=', 'base');
            })
            ->limit(120)
            ->get(['id', 'lat', 'lng', 'trip_role', 'meta']);

        $out = [];
        foreach ($rows as $l) {
            $id = (int) $l->id;
            if (isset($used[$id]) || $id === (int) ($night['id'] ?? 0)) {
                continue;
            }
            $meta = is_array($l->meta) ? $l->meta : [];
            $name = (string) ($meta['title'] ?? '');
            if ($name === '' || preg_match('/hotel|מלון|מסעד|michel|restaurant/ui', $name)) {
                continue;
            }
            $row = [
                'id' => $id,
                'name' => $name,
                'lat' => (float) $l->lat,
                'lng' => (float) $l->lng,
                'role' => 'place',
                'is_base' => false,
            ];
            $km = $this->km($night, $row);
            if ($km === null || $km > 45) {
                continue;
            }
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => ($this->km($night, $a) ?? 99) <=> ($this->km($night, $b) ?? 99));

        return array_slice($out, 0, $limit);
    }

    /**
     * Отели / spa / прокат — не природа для REPLACE.
     *
     * @param  array<string, mixed>  $row
     */
    private function isJunkPoi(array $row): bool
    {
        $n = mb_strtolower($this->name($row));

        return (bool) preg_match(
            '/hotel|מלון|spa|sauna|pool|בריכ|שחייה|swimming|restaurant|מסעד|michel|rent|השכר|parking|חניון/ui',
            $n
        );
    }

    /**
     * Прогулка/«город ночёвки» как активность — не кандидат (validator overnight-as-activity).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $night
     */
    private function isOvernightPseudo(array $row, array $night): bool
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && $id === (int) ($night['id'] ?? 0)) {
            return true;
        }
        $n = mb_strtolower($this->name($row));
        $hub = mb_strtolower($this->name($night));
        if ($hub === '' || $n === '') {
            return false;
        }
        // «ריבה … טיול», «прогулка по Ортизею» и т.п.
        $hubTok = preg_split('/[\s,.\-\/]+/u', $hub) ?: [];
        $hubTok = array_values(array_filter($hubTok, fn ($t) => mb_strlen($t) >= 4));
        $looksWalk = (bool) preg_match('/טיול|walk|прогул|пассео|passeg|centro|מרכז/ui', $n);
        foreach ($hubTok as $tok) {
            if (str_contains($n, $tok) && $looksWalk) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $avoid
     */
    private function matchesAvoid(array $row, array $avoid): bool
    {
        if ($avoid === []) {
            return false;
        }
        $n = mb_strtolower($this->name($row));
        foreach ($avoid as $a) {
            if ($a === 'rope_park' && preg_match('/rope|חבלים|col\s*de\s*flam|emozion|adventure\s*park/ui', $n)) {
                return true;
            }
            if ($a === 'extreme' && preg_match(
                '/skyscraper|x-?treme|extreme|אקסטרים|canyon|каньон|קניון|bueno|via\s*ferrata|ferrata|פראט|raft|ראפט|рафтинг|quad|квадро/ui',
                $n
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $preferred
     */
    private function matchesPreferred(array $row, array $preferred): bool
    {
        if ($preferred === []) {
            return true;
        }
        $n = mb_strtolower($this->name($row));
        foreach ($preferred as $p) {
            if ($p === 'nature' && preg_match('/lago|אגם|cascata|מפל|monte|מונטה|panoram|cadini|braies|forest|парк(?!\s*חבל)/ui', $n)) {
                return true;
            }
            if ($p === 'panorama' && preg_match('/panoram|viewpoint|cadini|baldo|מונטה/ui', $n)) {
                return true;
            }
            if ($p === 'short_hike' && preg_match('/מסלול|hike|טיול|walk|bulla/ui', $n)) {
                return true;
            }
            if ($p === 'lake' && preg_match('/lago|אגם|озеро|braies|molveno|dobbiaco|misurina/ui', $n)) {
                return true;
            }
            if ($p === 'town_walk' && preg_match('/טיול|walk|ortisei|אורטיז|chiusa|קיוז/ui', $n)) {
                return true;
            }
            if ($p === 'waterfall' && preg_match('/cascata|מפל|waterfall|varone|tervela/ui', $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $night
     * @param  list<string>  $histNames
     * @param  list<string>  $preferred
     * @return array<string, mixed>
     */
    private function scoreCandidate(array $row, array $night, array $histNames, array $preferred, bool $fromHist): array
    {
        $id = (int) $row['id'];
        $h = $this->durations->hoursForId($id, $this->name($row));
        $km = $this->km($night, $row) ?? 99.0;
        $histScore = 0.12;
        $nl = mb_strtolower($this->name($row));
        foreach ($histNames as $hn) {
            $hn = mb_strtolower(trim((string) $hn));
            if ($hn !== '' && (str_contains($nl, $hn) || str_contains($hn, mb_substr($nl, 0, 6)))) {
                $histScore = max($histScore, 0.85);
            }
        }
        if ($fromHist) {
            $histScore = max($histScore, 0.9);
        }
        $score = $histScore * 40 + max(0, 30 - $km) + ($this->matchesPreferred($row, $preferred) ? 8 : 0);
        if ($h >= 5) {
            $score -= 15; // не подставлять второй full-day
        }

        return [
            'id' => $id,
            'name' => $this->name($row),
            'lat' => $row['lat'] ?? null,
            'lng' => $row['lng'] ?? null,
            'hours' => $h,
            'detour_km' => round($km, 1),
            'historical_score' => round($histScore, 2),
            'score' => round($score, 2),
            'row' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function km(array $a, array $b): ?float
    {
        if (! isset($a['lat'], $a['lng'], $b['lat'], $b['lng'])) {
            return null;
        }
        $lat1 = (float) $a['lat'];
        $lng1 = (float) $a['lng'];
        $lat2 = (float) $b['lat'];
        $lng2 = (float) $b['lng'];
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $x = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($x)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function name(array $row): string
    {
        return trim((string) ($row['name'] ?? ''));
    }
}
