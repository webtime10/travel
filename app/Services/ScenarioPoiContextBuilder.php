<?php

namespace App\Services;

/**
 * Минимальный контекст для Gemini (Test 2): duration, physical_load, stay-кластеры.
 * Без выдуманных parking/queue; только то, что реально известно + эвристика имени.
 */
class ScenarioPoiContextBuilder
{
    public function __construct(
        private LocationDurationResolver $durations,
        private GeoDistance $geo,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $pois
     * @return list<array<string, mixed>>
     */
    public function enrichPois(array $pois): array
    {
        $ids = [];
        foreach ($pois as $p) {
            $id = (int) ($p['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $durMap = $this->durations->forIds($ids);

        $out = [];
        foreach ($pois as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            $name = (string) ($p['name'] ?? '');
            $dur = $durMap[$id] ?? $this->durations->resolve(0, $name);
            $hours = ! empty($dur['multi_day']) ? 8.0 : (float) ($dur['hours'] ?? 1.5);
            // DB иногда занижает (Мармолада 1.5ч) — для STAY/load берём max с эвристикой имени
            $byName = $this->durations->resolve(0, $name);
            $nameH = ! empty($byName['multi_day']) ? 8.0 : (float) ($byName['hours'] ?? 0);
            if ($nameH > $hours) {
                $hours = $nameH;
                $dur['source'] = trim(($dur['source'] ?? '').' +name_heuristic');
            }
            $p['poi_duration'] = round($hours, 1);
            $p['duration_source'] = (string) ($dur['source'] ?? '');
            $p['physical_load'] = $this->physicalLoad($name, $hours);
            $out[] = $p;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates  from TransferRouteFitService
     * @param  list<array<string, mixed>>  $enrichedPois
     * @return list<array<string, mixed>>
     */
    public function enrichTransferCandidates(array $candidates, array $enrichedPois): array
    {
        $byId = [];
        foreach ($enrichedPois as $p) {
            $byId[(int) ($p['id'] ?? 0)] = $p;
        }
        foreach ($candidates as &$c) {
            $id = (int) ($c['id'] ?? 0);
            $src = $byId[$id] ?? null;
            $c['poi_duration'] = $src['poi_duration'] ?? null;
            $c['physical_load'] = $src['physical_load'] ?? $this->physicalLoad((string) ($c['name'] ?? ''), 2.0);
        }
        unset($c);

        return $candidates;
    }

    /**
     * Макс. Haversine-радиус STAY для POI: короткие — stay_radius_km; ≥long_hours / heavy — уже.
     */
    public static function maxStayRadiusKm(?float $durationHours, ?string $physicalLoad = null): float
    {
        $default = (float) config('travel_route_fit.stay_radius_km', 70.0);
        $longHours = (float) config('travel_route_fit.stay_long_poi_hours', 3.0);
        $longRadius = (float) config('travel_route_fit.stay_long_poi_radius_km', 35.0);
        $dur = $durationHours ?? 0.0;
        $load = (string) ($physicalLoad ?? '');
        $isLong = $dur >= $longHours;
        $isHeavyLong = $isLong && in_array($load, ['heavy', 'very_heavy'], true);

        if ($isLong || $isHeavyLong) {
            return min($default, $longRadius);
        }

        return $default;
    }

    /**
     * @return string|null null = accepted
     */
    public static function stayRejectReason(
        float $haversineKm,
        float $maxKm,
        ?float $durationHours,
        ?string $physicalLoad = null,
    ): ?string {
        if ($haversineKm <= $maxKm) {
            return null;
        }
        $outer = (float) config('travel_route_fit.stay_radius_km', 70.0);
        if ($haversineKm > $outer) {
            return 'rejected because outside stay_radius';
        }
        $longHours = (float) config('travel_route_fit.stay_long_poi_hours', 3.0);
        $dur = $durationHours ?? 0.0;
        $load = (string) ($physicalLoad ?? '');
        if ($dur >= $longHours || in_array($load, ['heavy', 'very_heavy'], true)) {
            return 'rejected because long POI is too far from base';
        }

        return 'rejected because outside stay_radius';
    }

    /**
     * Кластеры stay-POI вокруг баз (связность дня).
     *
     * @param  list<array<string, mixed>>  $pois  enriched
     * @param  array<int, list<int>>  $priorBaseIdsByBase  baseId => prior overnight ids (prev-zone pool)
     * @return array{prompt_block: string, debug_lines: list<string>, clusters: list<array<string, mixed>>}
     */
    public function buildStayClusters(array $pois, array $priorBaseIdsByBase = []): array
    {
        $bases = [];
        $places = [];
        $poisById = [];
        foreach ($pois as $p) {
            if (! is_array($p) || ! isset($p['lat'], $p['lng']) || ! is_numeric($p['lat']) || ! is_numeric($p['lng'])) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            if ($id > 0) {
                $poisById[$id] = $p;
            }
            if (! empty($p['is_base']) || ($p['role'] ?? '') === 'base') {
                $bases[] = $p;
            } else {
                $places[] = $p;
            }
        }

        $linkKm = (float) config('travel_route_fit.cluster_link_km', 18.0);
        $stayR = (float) config('travel_route_fit.stay_radius_km', 70.0);
        $longH = (float) config('travel_route_fit.stay_long_poi_hours', 3.0);
        $longR = (float) config('travel_route_fit.stay_long_poi_radius_km', 35.0);
        $prevZoneKm = (float) config('travel_route_fit.stay_prev_zone_km', 25.0);
        $clusters = [];
        $debug = [];
        $promptParts = [];

        foreach ($bases as $base) {
            $baseId = (int) ($base['id'] ?? 0);
            $baseLabel = ($base['name'] ?? '?').' #'.$baseId;
            $priors = $priorBaseIdsByBase[$baseId] ?? [];
            $near = [];
            $debug[] = sprintf(
                'STAY filter near %s (short≤%.0f km; POI≥%.0fч / heavy → ≤%.0f km; prev-zone≤%.0f vs priors [%s]):',
                $baseLabel,
                $stayR,
                $longH,
                $longR,
                $prevZoneKm,
                $priors !== [] ? implode(',', $priors) : '—'
            );

            foreach ($places as $pl) {
                $km = $this->geo->km($base, $pl);
                if ($km === null) {
                    continue;
                }
                $poiId = (int) ($pl['id'] ?? 0);
                $dur = is_numeric($pl['poi_duration'] ?? null) ? (float) $pl['poi_duration'] : null;
                $load = isset($pl['physical_load']) ? (string) $pl['physical_load'] : null;
                $maxR = self::maxStayRadiusKm($dur, $load);
                $reason = self::stayRejectReason($km, $maxR, $dur, $load);

                // Дальше внешнего радиуса — не шумим в debug
                if ($km > $stayR) {
                    continue;
                }

                $line = sprintf(
                    '  %s base_id=%d poi_id=%d name=%s haversine_km=%s duration=%s physical_load=%s max_r=%s route_fit=n/a(stay)',
                    $reason === null ? 'ok' : 'rejected',
                    $baseId,
                    $poiId,
                    $pl['name'] ?? '?',
                    round($km, 1),
                    $dur ?? '?',
                    $load ?? '?',
                    round($maxR, 1)
                );
                if ($reason !== null) {
                    $line .= ' reason_rejected='.$reason;
                    $debug[] = $line;
                    continue;
                }

                // Тот же prev-zone, что scrubStayDaySequences: не отдавать в prompt то, что scrub снимет
                $hitPrev = TransferRouteFitService::firstPriorBaseZoneHit(
                    $km,
                    $pl,
                    $baseId,
                    $priors,
                    $poisById,
                    $this->geo,
                    $prevZoneKm
                );
                if ($hitPrev !== null) {
                    $debug[] = $line.' reason_rejected=prev-zone vs base #'.$hitPrev
                        .' (same rule as scrubStayDaySequences)';
                    continue;
                }

                $debug[] = $line;
                $near[] = $pl + ['_km_base' => round($km, 1)];
            }

            if ($near === []) {
                $debug[] = '  (нет STAY candidates после duration-aware фильтра)';
                continue;
            }

            $groups = $this->clusterByDistance($near, $linkKm);
            $debug[] = 'STAY clusters near '.$baseLabel.' (link≤'.$linkKm.' km):';
            $blockLines = ['STAY clusters near '.$baseLabel.':'];

            foreach ($groups as $gi => $group) {
                $names = [];
                $ids = [];
                foreach ($group as $g) {
                    $names[] = ($g['name'] ?? '').' #'.$g['id']
                        .' (~'.$g['poi_duration'].'ч, load='.$g['physical_load']
                        .', от базы '.$g['_km_base'].' км)';
                    $ids[] = (int) $g['id'];
                }
                $clusters[] = [
                    'base_id' => $baseId,
                    'base_name' => (string) ($base['name'] ?? ''),
                    'cluster_index' => $gi + 1,
                    'poi_ids' => $ids,
                ];
                $debug[] = '  cluster_'.($gi + 1).': '.implode(' | ', $names);
                $blockLines[] = '  cluster_'.($gi + 1).' (собирай день из одной связки, не смешивай далёкие кластеры):';
                foreach ($group as $g) {
                    $blockLines[] = sprintf(
                        '    - id=%d | %s | duration=%sч | load=%s | km_from_base=%s',
                        $g['id'],
                        $g['name'] ?? '',
                        $g['poi_duration'] ?? '?',
                        $g['physical_load'] ?? '?',
                        $g['_km_base'] ?? '?'
                    );
                }
            }
            $promptParts[] = implode("\n", $blockLines);
        }

        $promptBlock = '';
        if ($promptParts !== []) {
            $promptBlock = "STAY CLUSTERS — географическая связность (PHP):\n"
                ."Один stay-день = предпочтительно один cluster (или соседние).\n"
                ."Не склеивай POI из далёких cluster_* в один день без причины.\n"
                ."Кандидаты уже отфильтрованы PHP (короткие scenic ≤{$stayR} км;"
                ." POI≥{$longH}ч / heavy → ≤{$longR} км). Не придумывай географию.\n\n"
                .implode("\n\n", $promptParts);
        }

        return [
            'prompt_block' => $promptBlock,
            'debug_lines' => $debug,
            'clusters' => $clusters,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return list<list<array<string, mixed>>>
     */
    private function clusterByDistance(array $points, float $linkKm): array
    {
        $n = count($points);
        if ($n === 0) {
            return [];
        }
        $parent = range(0, $n - 1);
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $km = $this->geo->km($points[$i], $points[$j]);
                if ($km !== null && $km <= $linkKm) {
                    $union($i, $j);
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $find($i);
            $groups[$r][] = $points[$i];
        }

        return array_values($groups);
    }

    public function physicalLoad(string $name, float $hours): string
    {
        $n = mb_strtolower($name);
        if (preg_match('/via\s*ferrata|феррат|ferrato|canyon|каньон|קניון|canyoning/ui', $n)) {
            return 'very_heavy';
        }
        if (preg_match('/tre\s*cime|drei\s*zinnen|3\s*פסג|три\s*пик|тре-чиме|sorapis|сорпис|cinque\s*torri|5\s*אגמים|пять\s*оз/ui', $n)
            || ($hours >= 4.5 && preg_match('/מסלול|hike|טיול|trek|трек|маршрут/ui', $n))) {
            return 'heavy';
        }
        if (preg_match('/zip|omega|rope|חבל|верёв|raft|רפטינג|рафт|ski\s*lift|канать/ui', $n)
            || ($hours >= 3.0 && preg_match('/מסלול|hike|טיול|trek|пеш/ui', $n))) {
            return 'moderate';
        }
        if (preg_match('/lake|אגם|озеро|waterfall|מפל|водопад|boat|שייט|кораб|town|עיר|деревн|centro|набереж/ui', $n)) {
            return 'light';
        }
        if ($hours >= 4.0) {
            return 'moderate';
        }
        if ($hours <= 1.5) {
            return 'light';
        }

        return 'moderate';
    }
}
