<?php

namespace App\Services;

use App\Models\Location;
use App\Models\RouteSkeleton;
use ReflectionMethod;

/**
 * Массовый прогон pack→refine без Gemini: статистика качества дней.
 */
class BatchRefineMetricsRunner
{
    private const NOT_ROUTE = '/מסעד|restaurant|ristorante|michel|מישל|hotel|מלון|hostel|כשרות\s*ב|gluten|גלוטן|gelat|גלידר/ui';

    private const HUB_KEYS = [
        'garda', 'riva', 'ortisei', 'brunico', 'misurina', 'molveno',
        'campiglio', 'dolomiti', 'cortina', 'braies', 'verona', 'milan',
    ];

    /** @var array<string, list<int>>|null */
    private ?array $nameIndex = null;

    public function __construct(
        private LocationCandidateFilter $filter,
        private LocationDayPacker $packer,
        private RouteSkeletonMatcher $matcher,
        private RouteSkeletonRefiner $refiner,
        private LocationDurationResolver $durations,
        private LocationPlanValidator $validator,
    ) {}

    /**
     * @return array{
     *   summary: array<string, mixed>,
     *   runs: list<array<string, mixed>>,
     *   limit: int,
     *   elapsed_ms: int
     * }
     */
    public function run(int $limit = 80, int $minDays = 5, int $maxDays = 16): array
    {
        $t0 = microtime(true);
        $skeletons = $this->sampleSkeletons($limit, $minDays, $maxDays);
        $runs = [];
        $agg = $this->emptyAgg();

        foreach ($skeletons as $skeleton) {
            $run = $this->runOne($skeleton);
            $runs[] = $run;
            $this->accumulate($agg, $run);
        }

        $n = max(1, count($runs));
        $summary = [
            'routes' => count($runs),
            'ok_pack' => $agg['ok_pack'],
            'failed' => $agg['failed'],
            'days_total' => $agg['days_total'],
            'empty_stay' => $agg['empty_stay'],
            'empty_transfer' => $agg['empty_transfer'],
            'thin_short' => $agg['thin_short'],
            'single_long_ok' => $agg['single_long_ok'],
            'normal_multi' => $agg['normal_multi'],
            'overloaded' => $agg['overloaded'],
            'transfer_with_activity' => $agg['transfer_with_activity'],
            'transfer_total' => $agg['transfer_total'],
            'not_route_poi_days' => $agg['not_route_poi_days'],
            'hub_mismatch_days' => $agg['hub_mismatch_days'],
            'duplicate_place_routes' => $agg['duplicate_place_routes'],
            'pct_empty_stay' => round(100 * $agg['empty_stay'] / max(1, $agg['days_total']), 1),
            'pct_empty_transfer' => round(100 * $agg['empty_transfer'] / max(1, $agg['transfer_total']), 1),
            'pct_thin_short' => round(100 * $agg['thin_short'] / max(1, $agg['days_total']), 1),
            'pct_normal_or_long' => round(100 * ($agg['normal_multi'] + $agg['single_long_ok']) / max(1, $agg['days_total']), 1),
            'pct_transfer_filled' => round(100 * $agg['transfer_with_activity'] / max(1, $agg['transfer_total']), 1),
            'pct_multi_activity' => round(100 * $agg['normal_multi'] / max(1, $agg['days_total']), 1),
            'avg_notes' => round($agg['notes_sum'] / $n, 1),
            'validator_ok_routes' => $agg['validator_ok'],
            'validator_critical' => $agg['v_critical'],
            'validator_major' => $agg['v_major'],
            'validator_minor' => $agg['v_minor'],
        ];

        return [
            'summary' => $summary,
            'runs' => $runs,
            'limit' => $limit,
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * @return list<RouteSkeleton>
     */
    private function sampleSkeletons(int $limit, int $minDays, int $maxDays): array
    {
        $limit = max(1, min(200, $limit));
        $perHub = max(2, (int) ceil($limit / count(self::HUB_KEYS)));
        $picked = [];
        foreach (self::HUB_KEYS as $hub) {
            $rows = RouteSkeleton::query()
                ->with('days')
                ->whereBetween('days_count', [$minDays, $maxDays])
                ->where(function ($w) use ($hub) {
                    $w->where('search_blob', 'like', '%'.$hub.'%')
                        ->orWhere('arrival_hub', 'like', '%'.$hub.'%')
                        ->orWhere('title', 'like', '%'.$hub.'%');
                })
                ->inRandomOrder()
                ->limit($perHub)
                ->get();
            foreach ($rows as $s) {
                $picked[$s->id] = $s;
            }
        }
        if (count($picked) < $limit) {
            $extra = RouteSkeleton::query()
                ->with('days')
                ->whereBetween('days_count', [$minDays, $maxDays])
                ->whereNotIn('id', array_keys($picked))
                ->inRandomOrder()
                ->limit($limit - count($picked))
                ->get();
            foreach ($extra as $s) {
                $picked[$s->id] = $s;
            }
        }
        $list = array_values($picked);
        shuffle($list);

        return array_slice($list, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function runOne(RouteSkeleton $skeleton): array
    {
        $hubs = $this->matcher->detectHubs(
            (string) ($skeleton->search_blob ?? '').' '.(string) ($skeleton->title ?? '')
        );
        $query = $this->buildQuery($skeleton, $hubs);
        $base = [
            'skeleton_id' => $skeleton->id,
            'source_route_id' => $skeleton->source_route_id,
            'title' => $skeleton->displayTitle(true) ?: (string) $skeleton->title,
            'days_count' => (int) $skeleton->days_count,
            'hubs' => $hubs,
            'query' => $query,
        ];

        try {
            $locs = $this->locationsForSkeleton($skeleton, $hubs);
            if (count($locs) < 3) {
                return $base + ['ok' => false, 'error' => 'too_few_locations:'.count($locs), 'metrics' => null];
            }
            $filtered = $this->filter->filter($locs);
            $locs = $filtered['locations'];
            $packed = $this->packer->pack($locs, $query);
            $dayPlan = $packed['day_plan'] ?? [];
            $locs = $packed['locations'] ?? $locs;
            if ($dayPlan === []) {
                return $base + ['ok' => false, 'error' => 'empty_pack', 'metrics' => null];
            }
            $similar = $this->matcher->findSimilar($query.' '.implode(' ', $dayPlan), count($dayPlan), 8, $dayPlan);
            $refined = $this->refiner->refine($dayPlan, $locs, $similar, $query);
            $plan = $refined['day_plan'] ?? [];
            $locsOut = $refined['locations'] ?? $locs;
            $metrics = $this->scorePlan($plan, $locsOut);
            $validated = $this->validator->validate(implode("\n", $plan), $locsOut, $plan);
            $vCounts = ['CRITICAL' => 0, 'MAJOR' => 0, 'MINOR' => 0];
            foreach ($validated['findings'] ?? [] as $f) {
                $sev = (string) ($f['severity'] ?? '');
                if (isset($vCounts[$sev])) {
                    $vCounts[$sev]++;
                }
            }

            return $base + [
                'ok' => true,
                'error' => null,
                'packed_days' => count($dayPlan),
                'refined_days' => count($plan),
                'notes_count' => count($refined['notes'] ?? []),
                'metrics' => $metrics,
                'validator_ok' => (bool) ($validated['ok'] ?? false),
                'validator_counts' => $vCounts,
                'validator_top' => array_slice(array_map(
                    fn ($f) => '['.($f['severity'] ?? '').'] '.($f['date'] ?? '').': '.($f['problem'] ?? ''),
                    $validated['findings'] ?? []
                ), 0, 5),
                'sample_plan' => array_slice($plan, 0, 3),
            ];
        } catch (\Throwable $e) {
            return $base + ['ok' => false, 'error' => $e->getMessage(), 'metrics' => null];
        }
    }

    /**
     * @param  list<string>  $hubs
     */
    private function buildQuery(RouteSkeleton $skeleton, array $hubs): string
    {
        $n = max(2, (int) $skeleton->days_count);
        $start = $skeleton->start_date;
        if ($start) {
            $from = $start->format('d.m');
            $to = $start->copy()->addDays($n - 1)->format('d.m');
        } else {
            $from = '01.08';
            $to = sprintf('%02d.08', min(31, $n));
        }
        $hubStr = $hubs !== [] ? implode(' ', $hubs) : (string) ($skeleton->arrival_hub ?? 'italy');
        $title = $skeleton->displayTitle(false) ?: (string) $skeleton->title;

        return $from.'-'.$to.' '.$hubStr.' '.$title;
    }

    /**
     * @param  list<string>  $hubs
     * @return list<array<string, mixed>>
     */
    private function locationsForSkeleton(RouteSkeleton $skeleton, array $hubs): array
    {
        $this->ensureNameIndex();
        $ids = [];
        foreach ($skeleton->days as $day) {
            $axis = trim((string) ($day->end_point ?? '').' '.(string) ($day->start_point ?? ''));
            foreach ($this->resolveNames($axis) as $id) {
                $ids[$id] = true;
            }
            $places = is_array($day->places) ? $day->places : [];
            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                foreach ($this->resolveNames((string) ($p['name'] ?? '')) as $id) {
                    $ids[$id] = true;
                }
            }
        }
        // базы по хабам + окрестности
        foreach ($this->basesForHubs($hubs) as $baseId) {
            $ids[$baseId] = true;
            foreach ($this->nearbyPlaceIds($baseId, 45.0, 18) as $nid) {
                $ids[$nid] = true;
            }
        }

        $out = [];
        foreach (array_keys($ids) as $id) {
            $l = Location::query()->find($id);
            if (! $l || $l->lat === null || $l->lng === null) {
                continue;
            }
            $meta = is_array($l->meta) ? $l->meta : [];
            $out[] = [
                'id' => (int) $l->id,
                'name' => (string) ($meta['title'] ?? '#'.$l->id),
                'lat' => (float) $l->lat,
                'lng' => (float) $l->lng,
                'role' => $l->trip_role === 'base' ? 'base' : 'place',
                'is_base' => $l->trip_role === 'base',
                'snippet' => '',
                'score' => 1,
            ];
        }

        return $out;
    }

    private function ensureNameIndex(): void
    {
        if ($this->nameIndex !== null) {
            return;
        }
        $this->nameIndex = [];
        Location::query()->whereNotNull('meta')->orderBy('id')->chunk(200, function ($chunk) {
            foreach ($chunk as $l) {
                $meta = is_array($l->meta) ? $l->meta : [];
                $title = (string) ($meta['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $key = $this->norm($title);
                if (mb_strlen($key) < 4) {
                    continue;
                }
                $this->nameIndex[$key][] = (int) $l->id;
                // короткий ключ (первые 12 букв)
                $short = mb_substr($key, 0, 12);
                if (mb_strlen($short) >= 6) {
                    $this->nameIndex[$short][] = (int) $l->id;
                }
            }
        });
    }

    /**
     * @return list<int>
     */
    private function resolveNames(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $key = $this->norm($text);
        $ids = [];
        if (isset($this->nameIndex[$key])) {
            foreach ($this->nameIndex[$key] as $id) {
                $ids[$id] = true;
            }
        }
        $short = mb_substr($key, 0, 12);
        if (isset($this->nameIndex[$short])) {
            foreach ($this->nameIndex[$short] as $id) {
                $ids[$id] = true;
            }
        }
        // подстрочный поиск по индексу — дорого; только если пусто и текст короткий
        if ($ids === [] && mb_strlen($key) >= 6 && mb_strlen($key) <= 40) {
            foreach ($this->nameIndex as $k => $list) {
                if (str_contains($k, $key) || str_contains($key, $k)) {
                    foreach ($list as $id) {
                        $ids[$id] = true;
                    }
                    if (count($ids) >= 5) {
                        break;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  list<string>  $hubs
     * @return list<int>
     */
    private function basesForHubs(array $hubs): array
    {
        if ($hubs === []) {
            return [];
        }
        $bases = Location::query()
            ->where('trip_role', 'base')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'meta', 'lat', 'lng']);
        $ids = [];
        foreach ($bases as $b) {
            $meta = is_array($b->meta) ? $b->meta : [];
            $name = (string) ($meta['title'] ?? '');
            $bh = $this->matcher->detectHubs($name);
            if (array_intersect($hubs, $bh) !== []) {
                $ids[] = (int) $b->id;
            }
        }

        return array_slice($ids, 0, 8);
    }

    /**
     * @return list<int>
     */
    private function nearbyPlaceIds(int $baseId, float $maxKm, int $limit): array
    {
        $base = Location::query()->find($baseId);
        if (! $base || $base->lat === null || $base->lng === null) {
            return [];
        }
        $dLat = $maxKm / 111.0;
        $dLng = $maxKm / (111.0 * max(0.2, cos(deg2rad((float) $base->lat))));
        $cands = Location::query()
            ->whereNotNull('lat')->whereNotNull('lng')
            ->where(function ($q) {
                $q->whereNull('trip_role')->orWhere('trip_role', '!=', 'base');
            })
            ->whereBetween('lat', [(float) $base->lat - $dLat, (float) $base->lat + $dLat])
            ->whereBetween('lng', [(float) $base->lng - $dLng, (float) $base->lng + $dLng])
            ->limit(80)
            ->get(['id', 'lat', 'lng', 'meta']);
        $scored = [];
        foreach ($cands as $c) {
            $km = $this->haversine((float) $base->lat, (float) $base->lng, (float) $c->lat, (float) $c->lng);
            if ($km > $maxKm) {
                continue;
            }
            $meta = is_array($c->meta) ? $c->meta : [];
            $name = (string) ($meta['title'] ?? '');
            if ($name !== '' && preg_match(self::NOT_ROUTE, $name)) {
                continue;
            }
            $scored[] = ['id' => (int) $c->id, 'km' => $km];
        }
        usort($scored, fn ($a, $b) => $a['km'] <=> $b['km']);

        return array_map(fn ($r) => $r['id'], array_slice($scored, 0, $limit));
    }

    /**
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, int|bool>
     */
    private function scorePlan(array $dayPlan, array $locations): array
    {
        $byId = [];
        foreach ($locations as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $byId[(int) $row['id']] = $row;
            }
        }
        $idsAll = [];
        foreach ($dayPlan as $line) {
            if (preg_match_all('/\[id=(\d+)\]/u', $line, $m)) {
                foreach ($m[1] as $id) {
                    $idsAll[] = (int) $id;
                }
            }
        }
        $this->durations->forIds(array_values(array_unique($idsAll)));

        $fit = new ReflectionMethod(RouteSkeletonRefiner::class, 'placeFitsOvernight');
        $fit->setAccessible(true);

        $m = [
            'days_total' => 0,
            'empty_stay' => 0,
            'empty_transfer' => 0,
            'thin_short' => 0,
            'single_long_ok' => 0,
            'normal_multi' => 0,
            'overloaded' => 0,
            'transfer_total' => 0,
            'transfer_with_activity' => 0,
            'not_route_poi_days' => 0,
            'hub_mismatch_days' => 0,
            'has_duplicate_places' => false,
        ];
        $seen = [];
        foreach ($dayPlan as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                continue;
            }
            $m['days_total']++;
            $placeIds = $parsed['place_ids'];
            $isTransfer = $parsed['is_transfer'];
            if ($isTransfer) {
                $m['transfer_total']++;
                if ($placeIds !== []) {
                    $m['transfer_with_activity']++;
                } else {
                    $m['empty_transfer']++;
                }
            } elseif ($placeIds === []) {
                $m['empty_stay']++;
            }

            $hours = 0.0;
            $long = false;
            $notRoute = false;
            $badHub = false;
            $night = $byId[$parsed['night_id']] ?? null;
            foreach ($placeIds as $pid) {
                if (isset($seen[$pid])) {
                    $m['has_duplicate_places'] = true;
                }
                $seen[$pid] = true;
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    $loc = Location::query()->find($pid);
                    if ($loc) {
                        $meta = is_array($loc->meta) ? $loc->meta : [];
                        $row = [
                            'id' => $pid,
                            'name' => (string) ($meta['title'] ?? ''),
                            'lat' => $loc->lat,
                            'lng' => $loc->lng,
                            'snippet' => '',
                        ];
                        $byId[$pid] = $row;
                    }
                }
                if ($row === null) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name !== '' && preg_match(self::NOT_ROUTE, $name)) {
                    $notRoute = true;
                }
                $h = (float) ($this->durations->forIds([$pid])[$pid]['hours'] ?? 1.5);
                $hours += $h;
                if ($h >= 3.5) {
                    $long = true;
                }
                if ($night !== null) {
                    try {
                        if (! $fit->invoke($this->refiner, $row, $night)) {
                            $badHub = true;
                        }
                    } catch (\Throwable) {
                    }
                }
            }
            if ($notRoute) {
                $m['not_route_poi_days']++;
            }
            if ($badHub) {
                $m['hub_mismatch_days']++;
            }
            if ($placeIds === []) {
                continue;
            }
            if ($hours > 8.0 || count($placeIds) > 3) {
                $m['overloaded']++;
            } elseif (count($placeIds) >= 2) {
                $m['normal_multi']++;
            } elseif ($long || $hours >= 3.5) {
                $m['single_long_ok']++;
            } elseif ($hours < 2.0) {
                $m['thin_short']++;
            } else {
                $m['single_long_ok']++; // 2–3.5ч одна активность — приемлемо
            }
        }

        return $m;
    }

    /**
     * @return array{date: string, place_ids: list<int>, night_id: int, is_transfer: bool}|null
     */
    private function parseLine(string $line): ?array
    {
        $line = trim($line);
        if (! preg_match('/^(\d{1,2}\.\d{1,2})\s*[—\-–]\s*(.+)$/u', $line, $m)) {
            return null;
        }
        $date = $m[1];
        $rest = $m[2];
        $isTransfer = (bool) preg_match('/(?:^|\.\s*)Переезд\s+в\s+/ui', $rest);
        $nightId = 0;
        if (preg_match('/Ночёвка:\s*(.+)$/ui', $rest, $nm) && preg_match('/\[id=(\d+)\]/u', $nm[1], $idm)) {
            $nightId = (int) $idm[1];
        }
        $placePart = preg_replace('/(?:^|\.\s*)Переезд\s+в\s+.+$/ui', '', $rest) ?? $rest;
        $placePart = preg_replace('/\.\s*Ночёвка:\s*.+$/ui', '', $placePart) ?? $placePart;
        $placePart = trim($placePart);
        if (preg_match('/^(?:лёгкий\s+)?день\s+у\s+базы$/ui', $placePart)) {
            $placePart = '';
        }
        $placeIds = [];
        if (preg_match_all('/\[id=(\d+)\]/u', $placePart, $pm)) {
            foreach ($pm[1] as $id) {
                $pid = (int) $id;
                if ($pid > 0 && $pid !== $nightId) {
                    $placeIds[] = $pid;
                }
            }
        }

        return [
            'date' => $date,
            'place_ids' => array_values(array_unique($placeIds)),
            'night_id' => $nightId,
            'is_transfer' => $isTransfer,
        ];
    }

    /** @return array<string, int|float> */
    private function emptyAgg(): array
    {
        return [
            'ok_pack' => 0,
            'failed' => 0,
            'days_total' => 0,
            'empty_stay' => 0,
            'empty_transfer' => 0,
            'thin_short' => 0,
            'single_long_ok' => 0,
            'normal_multi' => 0,
            'overloaded' => 0,
            'transfer_with_activity' => 0,
            'transfer_total' => 0,
            'not_route_poi_days' => 0,
            'hub_mismatch_days' => 0,
            'duplicate_place_routes' => 0,
            'notes_sum' => 0,
            'validator_ok' => 0,
            'v_critical' => 0,
            'v_major' => 0,
            'v_minor' => 0,
        ];
    }

    /**
     * @param  array<string, int|float>  $agg
     * @param  array<string, mixed>  $run
     */
    private function accumulate(array &$agg, array $run): void
    {
        if (! ($run['ok'] ?? false) || ! is_array($run['metrics'] ?? null)) {
            $agg['failed']++;

            return;
        }
        $agg['ok_pack']++;
        $agg['notes_sum'] += (int) ($run['notes_count'] ?? 0);
        $m = $run['metrics'];
        foreach ([
            'days_total', 'empty_stay', 'empty_transfer', 'thin_short', 'single_long_ok',
            'normal_multi', 'overloaded', 'transfer_with_activity', 'transfer_total',
            'not_route_poi_days', 'hub_mismatch_days',
        ] as $k) {
            $agg[$k] += (int) ($m[$k] ?? 0);
        }
        if (! empty($m['has_duplicate_places'])) {
            $agg['duplicate_place_routes']++;
        }
        if (! empty($run['validator_ok'])) {
            $agg['validator_ok']++;
        }
        $vc = $run['validator_counts'] ?? [];
        $agg['v_critical'] += (int) ($vc['CRITICAL'] ?? 0);
        $agg['v_major'] += (int) ($vc['MAJOR'] ?? 0);
        $agg['v_minor'] += (int) ($vc['MINOR'] ?? 0);
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\(.*?\)/u', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;

        return $s;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $R * asin(min(1.0, sqrt($a)));
    }
}
