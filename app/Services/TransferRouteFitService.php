<?php

namespace App\Services;

use App\Models\RouteSkeleton;
use Illuminate\Support\Facades\Log;

/**
 * Геофильтр POI для transfer days (Test 2).
 * LLM получает только corridor-кандидатов; bad отсекается на PHP.
 */
class TransferRouteFitService
{
    public function __construct(
        private GeoDistance $geo,
        private RouteSkeletonMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $fromBase
     * @param  array<string, mixed>  $toBase
     * @param  list<array<string, mixed>>  $pois
     * @return list<array<string, mixed>>
     */
    public function getTransferCandidates(array $fromBase, array $toBase, array $pois): array
    {
        $direct = $this->geo->km($fromBase, $toBase);
        if ($direct === null || $direct < (float) config('travel_route_fit.min_transfer_direct_km', 35)) {
            return [];
        }

        $fromId = (int) ($fromBase['id'] ?? 0);
        $toId = (int) ($toBase['id'] ?? 0);
        $out = [];

        foreach ($pois as $poi) {
            if (! is_array($poi)) {
                continue;
            }
            $id = (int) ($poi['id'] ?? 0);
            if ($id <= 0 || $id === $fromId || $id === $toId) {
                continue;
            }
            if (! empty($poi['is_base']) || ($poi['role'] ?? '') === 'base') {
                continue;
            }
            $dStart = $this->geo->km($fromBase, $poi);
            $dEnd = $this->geo->km($poi, $toBase);
            if ($dStart === null || $dEnd === null) {
                continue;
            }

            $via = $dStart + $dEnd;
            $detour = max(0.0, $via - $direct);
            $ratio = $direct > 0.01 ? ($via / $direct) : 99.0;
            $axis = $this->axisMetrics($fromBase, $toBase, $poi);
            $fit = $this->classifyFit($detour, $ratio, $dStart, $dEnd, $direct, $axis);

            $dur = is_numeric($poi['poi_duration'] ?? null) ? (float) $poi['poi_duration'] : null;
            $load = (string) ($poi['physical_load'] ?? '');
            $short = $this->isShortStop($dur, $load);

            $out[] = [
                'id' => $id,
                'name' => (string) ($poi['name'] ?? ''),
                'lat' => is_numeric($poi['lat'] ?? null) ? (float) $poi['lat'] : null,
                'lng' => is_numeric($poi['lng'] ?? null) ? (float) $poi['lng'] : null,
                'distance_from_start_km' => round($dStart, 1),
                'distance_to_destination_km' => round($dEnd, 1),
                'direct_route_km' => round($direct, 1),
                'via_poi_km' => round($via, 1),
                'detour_km' => round($detour, 1),
                'detour_ratio' => round($ratio, 2),
                'cross_track_km' => round((float) ($axis['cross_track_km'] ?? 0), 1),
                'axis_progress' => round((float) ($axis['progress'] ?? 0), 2),
                'route_fit' => $fit,
                'poi_duration' => $dur,
                'physical_load' => $load !== '' ? $load : null,
                'is_short_stop' => $short,
            ];
        }

        foreach ($out as &$row) {
            // Подсказка для LLM, не бан: lighter_ok = легче вписать; evaluate = оценить улучшение дня
            $row['prefer'] = ! empty($row['is_short_stop']) ? 'lighter_ok' : 'evaluate';
        }
        unset($row);

        // Сортировка: качество corridor (fit → detour), не «часы вниз»
        usort($out, function (array $a, array $b) {
            $order = ['excellent' => 0, 'good' => 1, 'acceptable' => 2, 'bad' => 3];
            $fa = $order[$a['route_fit']] ?? 9;
            $fb = $order[$b['route_fit']] ?? 9;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }

            return ($a['detour_km'] ?? 99) <=> ($b['detour_km'] ?? 99);
        });

        return $out;
    }

    private function isShortStop(?float $durationHours, string $load): bool
    {
        $maxH = (float) config('travel_route_fit.short_stop_max_hours', 2.0);
        if ($durationHours === null || $durationHours > $maxH) {
            return false;
        }
        if (in_array($load, ['heavy', 'very_heavy'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $pois
     * @param  list<RouteSkeleton>  $skeletons
     * @return array{
     *   legs: list<array<string, mixed>>,
     *   prompt_block: string,
     *   debug_lines: list<string>
     * }
     */
    public function buildTransferContext(array $pois, array $skeletons = []): array
    {
        $bases = $this->basesFromPois($pois);
        $legsMeta = $this->inferTransferLegs($bases, $skeletons);
        $allowed = array_flip((array) config('travel_route_fit.llm_allowed_fits', ['excellent', 'good', 'acceptable']));

        $legs = [];
        $debug = [];
        $promptParts = [];

        foreach ($legsMeta as $leg) {
            $from = $leg['from'];
            $to = $leg['to'];
            $all = $this->getTransferCandidates($from, $to, $pois);
            $forLlm = array_values(array_filter(
                $all,
                fn (array $c) => isset($allowed[$c['route_fit'] ?? ''])
            ));

            $direct = $all[0]['direct_route_km'] ?? $this->geo->km($from, $to);
            $directKm = is_numeric($direct) ? round((float) $direct, 1) : null;
            $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120);
            $isLong = $directKm !== null && $directKm >= $longKm;

            $debug[] = sprintf(
                '%s → %s  direct: %s km%s',
                $from['name'] ?? '?',
                $to['name'] ?? '?',
                $directKm ?? '?',
                $isLong ? ' [LONG TRANSFER → evaluate candidates, hours≠goal]' : ''
            );
            foreach ($all as $c) {
                $debug[] = sprintf(
                    '  %s (#%d): detour %s km, ratio %s, cross %s km, progress %s, duration %sч, load %s, prefer=%s, fit: %s%s',
                    $c['name'],
                    $c['id'],
                    $c['detour_km'],
                    $c['detour_ratio'],
                    $c['cross_track_km'] ?? '?',
                    $c['axis_progress'] ?? '?',
                    $c['poi_duration'] ?? '?',
                    $c['physical_load'] ?? '?',
                    $c['prefer'] ?? '—',
                    $c['route_fit'],
                    isset($allowed[$c['route_fit']]) ? '' : ' [excluded from LLM]'
                );
            }

            $legs[] = [
                'from' => ['id' => (int) $from['id'], 'name' => (string) $from['name']],
                'to' => ['id' => (int) $to['id'], 'name' => (string) $to['name']],
                'from_full' => [
                    'id' => (int) $from['id'],
                    'name' => (string) $from['name'],
                    'lat' => (float) $from['lat'],
                    'lng' => (float) $from['lng'],
                ],
                'to_full' => [
                    'id' => (int) $to['id'],
                    'name' => (string) $to['name'],
                    'lat' => (float) $to['lat'],
                    'lng' => (float) $to['lng'],
                ],
                'direct_km' => $directKm,
                'long_transfer' => $isLong,
                'candidates_all' => $all,
                'candidates_llm' => $forLlm,
            ];

            $candLines = [];
            foreach (array_slice($forLlm, 0, 20) as $c) {
                $prefer = $c['prefer'] ?? null;
                $candLines[] = sprintf(
                    '  - id=%d | %s | fit=%s | detour_km=%s | duration=%sч | load=%s | prefer=%s',
                    $c['id'],
                    $c['name'],
                    $c['route_fit'],
                    $c['detour_km'],
                    $c['poi_duration'] ?? '?',
                    $c['physical_load'] ?? '?',
                    $prefer ?? '—'
                );
            }
            if ($candLines === []) {
                $candLines[] = '  (нет географически валидных кандидатов — только переезд / база)';
            }
            $longNote = "\nCORRIDOR: оценивай OLD→stop(s)→NEW vs OLD→NEW (не только POI↔база)."
                ." Обычно 1 сильный stop ИЛИ компактная связанная группа без чрезмерного path-detour."
                ." Компактная Мармолада+Федайя на подходящем плече — ок; не смешивай с посторонним stop (напр. Тувель) на одном long transfer."
                ." Если есть хороший corridor stop — не оставляй пустой переезд. summary = фактические places."
                ." Не оптимизируй transfer по часам.";
            if ($isLong) {
                $longNote .= " [LONG TRANSFER direct≥{$longKm} km] дорога уже часть дня;"
                    ." не набирай 2–3 разрозненных stop.";
            }
            $promptParts[] = 'TRANSFER: from '.$from['name'].' #'.$from['id']
                .' → to '.$to['name'].' #'.$to['id']
                .' (direct≈'.($directKm ?? '?').' km)'
                .($isLong ? ' [LONG TRANSFER]' : '')
                .$longNote."\n"
                ."CANDIDATES (use ONLY these ids as transfer stops):\n"
                .implode("\n", $candLines);
        }

        if ($debug !== []) {
            Log::info("transfer_route_fit\n".implode("\n", $debug));
        }

        $stayBlock = $this->buildStayAreasBlock($bases, $pois);

        $promptBlock = '';
        if ($promptParts !== []) {
            $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120);
            $promptBlock .= "TRANSFER DAYS — географически отфильтровано PHP:\n"
                .implode("\n\n", $promptParts)."\n\n"
                ."ОБЩЕЕ ПРАВИЛО (любой маршрут, не точечные имена):\n"
                ."- На theme=переезд stop'ы ТОЛЬКО из CANDIDATES этого плеча (id из списка).\n"
                ."- «В радиусе базы» ≠ «хорошо для corridor». Смотри OLD→POIs→NEW.\n"
                ."- Обычно: 1 сильный stop ИЛИ компактная связанная группа без существенного path-detour.\n"
                ."- Не пакуй полноценный горный день (несколько тяжёлых/длинных) + посторонний stop на один long transfer.\n"
                ."- Не используй один и тот же transfer_stop повторно в другом дне без явной причины.\n"
                ."- Часы не цель. Stay day ≠ transfer.\n"
                ."For transfer days, use only backend CANDIDATES. Decision: corridor quality + scenario — not hour fill.\n";
        }
        if ($stayBlock !== '') {
            $promptBlock .= ($promptBlock !== '' ? "\n" : '').$stayBlock;
        }

        return [
            'legs' => $legs,
            'prompt_block' => trim($promptBlock),
            'debug_lines' => $debug,
            'allowed_ids_by_leg' => $this->allowedIdsByLeg($legs),
        ];
    }

    /**
     * Гео-scrub плана: corridor candidates + path OLD→stops→NEW + stay BASE→POIs→BASE + dedup.
     * STAY_RADIUS / candidates — только пул; последовательность дня проверяется отдельно.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $transferCtx
     * @param  list<array<string, mixed>>  $pois  enriched with lat/lng
     * @param  string  $query  исходный запрос (время выезда/вылета для departure)
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    public function scrubTransferStopsInPlan(array $plan, array $transferCtx, array $pois = [], string $query = ''): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }

        $legs = is_array($transferCtx['legs'] ?? null) ? $transferCtx['legs'] : [];
        $poisById = $this->indexPoisById($pois);
        $removed = [];
        $days = $plan['days'];
        $prevNightId = null;

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            $nightId = (int) ($day['overnight']['id'] ?? 0);

            if ($isTransfer && $nightId > 0 && $legs !== []) {
                $allowed = $this->allowedIdsForNightChange($legs, $prevNightId, $nightId);
                if ($allowed !== []) {
                    $keep = [];
                    foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                        if (! is_array($place)) {
                            continue;
                        }
                        $pid = (int) ($place['id'] ?? 0);
                        $role = (string) ($place['role'] ?? '');
                        if ($pid === $nightId || $role === 'base') {
                            $keep[] = $place;
                            continue;
                        }
                        if ($pid > 0 && isset($allowed[$pid])) {
                            $keep[] = $place;
                            continue;
                        }
                        $removed[] = 'день '.($i + 1).': убран off-corridor #'
                            .$pid.' '.($place['name'] ?? '').' (не в TRANSFER candidates)';
                    }
                    $days[$i]['places'] = $keep;
                    $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
                }
            }

            if ($nightId > 0) {
                $prevNightId = $nightId;
            }
        }

        $plan['days'] = $days;

        // Макроось: вторичный overnight (Molveno) при финальном Garda → схлопнуть в переезд к Garda-базе
        $hubScrub = $this->scrubSecondaryHubOvernight($plan, $poisById);
        $plan = $hubScrub['plan'];
        $removed = array_merge($removed, $hubScrub['removed']);

        // Stay: overnight ближе к другой базе тура (Tenno/Varone при ночёвке Molveno → Riva)
        $ovMismatch = $this->scrubStayOvernightMismatch($plan, $poisById);
        $plan = $ovMismatch['plan'];
        $removed = array_merge($removed, $ovMismatch['removed']);

        $pathScrub = $this->scrubTransferMultiStopPath($plan, $legs, $poisById);
        $plan = $pathScrub['plan'];
        $removed = array_merge($removed, $pathScrub['removed']);

        // Короткий NE-переезд: не оставлять полный Marmolada-пакет (stay Val Gardena)
        $marmo = $this->scrubHeavyPackageOnShortTransfer($plan, $legs, $poisById);
        $plan = $marmo['plan'];
        $removed = array_merge($removed, $marmo['removed']);

        $stayScrub = $this->scrubStayDaySequences($plan, $poisById);
        $plan = $stayScrub['plan'];
        $removed = array_merge($removed, $stayScrub['removed']);

        // Brunico: Braies → добить Misurina, если в каталоге/радиусе
        $pair = $this->enrichStayLinkedPairs($plan, $poisById);
        $plan = $pair['plan'];
        $removed = array_merge($removed, $pair['removed']);

        $dedup = $this->scrubDuplicatePoisAcrossDays($plan);
        $plan = $dedup['plan'];
        $removed = array_merge($removed, $dedup['removed']);

        $ferry = $this->scrubFerryLimoneCompositeHours($plan);
        $plan = $ferry['plan'];
        $removed = array_merge($removed, $ferry['removed']);

        $dep = $this->scrubDepartureDayHours($plan, $query);
        $plan = $dep['plan'];
        $removed = array_merge($removed, $dep['removed']);

        $plan = $this->scrubHourBalanceNotes($plan);
        $plan = $this->alignDaySummariesWithPlaces($plan, $removed);

        if ($removed !== []) {
            Log::info("transfer_route_fit_scrub\n".implode("\n", $removed));
            $notes = is_array($plan['notes'] ?? null) ? $plan['notes'] : [];
            $notes[] = 'PHP geo scrub: '.implode('; ', $removed);
            $plan['notes'] = $notes;
        }

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Summary/note дня должны описывать фактические places, не удалённые stop'ы.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $removed
     * @return array<string, mixed>
     */
    private function alignDaySummariesWithPlaces(array $plan, array $removed): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return $plan;
        }

        $touchedDays = [];
        foreach ($removed as $line) {
            if (preg_match('/день\s+(\d+)/ui', $line, $m)) {
                $touchedDays[(int) $m[1]] = true;
            }
        }

        foreach ($plan['days'] as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $dayNum = $i + 1;
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            $isDeparture = str_contains($theme, 'выезд') || str_contains($theme, 'departure');
            $overnightName = trim((string) ($day['overnight']['name'] ?? ''));
            $acts = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                if (! is_array($place)) {
                    continue;
                }
                $role = (string) ($place['role'] ?? '');
                $pid = (int) ($place['id'] ?? 0);
                $oid = (int) ($day['overnight']['id'] ?? 0);
                if ($role === 'base' || ($oid > 0 && $pid === $oid)) {
                    continue;
                }
                $n = trim((string) ($place['name'] ?? ''));
                // hours=0 (Limone внутри парома) — всё равно в narrative
                if ($n !== '') {
                    $acts[] = $n;
                }
            }

            $summary = trim((string) ($day['summary'] ?? ''));
            $needsFix = isset($touchedDays[$dayNum]);
            if (! $needsFix && $summary !== '' && $acts !== []) {
                // summary упоминает «через X», но X нет среди places
                if (preg_match_all('/через\s+([^.,;]+)/ui', $summary, $mm)) {
                    foreach ($mm[1] as $chunk) {
                        $chunk = trim($chunk);
                        $hit = false;
                        foreach ($acts as $a) {
                            if (mb_stripos($chunk, $a) !== false || mb_stripos($a, $chunk) !== false) {
                                $hit = true;
                                break;
                            }
                            $tokens = preg_split('/\s+/u', $chunk) ?: [];
                            foreach ($tokens as $tok) {
                                if (mb_strlen($tok) >= 4 && mb_stripos($a, $tok) !== false) {
                                    $hit = true;
                                    break 2;
                                }
                            }
                        }
                        if (! $hit) {
                            $needsFix = true;
                            break;
                        }
                    }
                }
            }
            if (! $needsFix && $summary !== '' && $acts === [] && $isTransfer
                && preg_match('/через|via|остановк|marmolada|мармолад|fedaia|феда|monte\s*pana|alpacas/ui', $summary)) {
                $needsFix = true;
            }

            if (! $needsFix) {
                continue;
            }

            if ($isTransfer) {
                if ($acts === []) {
                    $plan['days'][$i]['summary'] = $overnightName !== ''
                        ? 'Переезд в '.$overnightName.'.'
                        : 'Прямой переезд без остановок по пути.';
                } else {
                    $via = implode(' и ', $acts);
                    $plan['days'][$i]['summary'] = $overnightName !== ''
                        ? 'Переезд в '.$overnightName.' через '.$via.'.'
                        : 'Переезд через '.$via.'.';
                }
            } elseif ($isDeparture) {
                if ($acts === []) {
                    $plan['days'][$i]['summary'] = 'Выезд'
                        .($overnightName !== '' ? ' ('.$overnightName.')' : '').'.';
                } else {
                    $plan['days'][$i]['summary'] = 'Выезд: '.implode(', ', $acts).'.';
                }
            } elseif ($acts !== []) {
                $plan['days'][$i]['summary'] = 'День вокруг '
                    .($overnightName !== '' ? $overnightName.': ' : '')
                    .implode(', ', $acts).'.';
            }
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $pois
     * @return array<int, array<string, mixed>>
     */
    private function indexPoisById(array $pois): array
    {
        $out = [];
        foreach ($pois as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            if ($id > 0 && isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])) {
                $out[$id] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $places
     */
    private function sumPlaceHours(array $places): float
    {
        $sum = 0.0;
        foreach ($places as $p) {
            if (isset($p['hours']) && is_numeric($p['hours'])) {
                $sum += (float) $p['hours'];
            }
        }

        return round($sum, 1);
    }

    /**
     * Убрать notes вида «маршрут сбалансирован» / hour-only.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function scrubHourBalanceNotes(array $plan): array
    {
        if (! is_array($plan['notes'] ?? null)) {
            return $plan;
        }
        $plan['notes'] = array_values(array_filter(
            $plan['notes'],
            function ($n) {
                $t = mb_strtolower((string) $n);

                return ! preg_match(
                    '/сбалансир|balanced.*(hour|час)|равномерн.*(час|нагруз)|все дни.*(5|одинак)/ui',
                    $t
                );
            }
        ));

        return $plan;
    }

    /**
     * Transfer: OLD→S1→S2→NEW vs OLD→NEW (даже если плечо короткое и leg не в candidates).
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $legs
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubTransferMultiStopPath(array $plan, array $legs, array $poisById = []): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }

        $maxDetour = (float) config('travel_route_fit.max_transfer_path_detour_km', 32);
        $maxRatio = (float) config('travel_route_fit.max_transfer_path_ratio', 1.55);
        $linkKm = (float) config('travel_route_fit.transfer_compact_link_km', 18);
        $removed = [];
        $days = $plan['days'];
        $prevNightId = null;

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            $nightId = (int) ($day['overnight']['id'] ?? 0);

            if ($isTransfer && $nightId > 0 && $prevNightId !== null) {
                $leg = $this->legForNightChange($legs, $prevNightId, $nightId);
                $candById = [];
                foreach ($leg['candidates_all'] ?? [] as $c) {
                    if (is_array($c) && (int) ($c['id'] ?? 0) > 0) {
                        $candById[(int) $c['id']] = $c;
                    }
                }
                $fromPt = is_array($leg['from_full'] ?? null) ? $leg['from_full'] : ($poisById[$prevNightId] ?? null);
                $toPt = is_array($leg['to_full'] ?? null) ? $leg['to_full'] : ($poisById[$nightId] ?? null);
                if (! isset($fromPt['lat'], $fromPt['lng'])) {
                    $fromPt = $poisById[$prevNightId] ?? null;
                }
                if (! isset($toPt['lat'], $toPt['lng'])) {
                    $toPt = $poisById[$nightId] ?? null;
                }

                $stops = [];
                $bases = [];
                foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                    if (! is_array($place)) {
                        continue;
                    }
                    $pid = (int) ($place['id'] ?? 0);
                    $role = (string) ($place['role'] ?? '');
                    if ($pid === $nightId || $role === 'base') {
                        $bases[] = $place;
                        continue;
                    }
                    $src = $candById[$pid] ?? $poisById[$pid] ?? null;
                    if ($src === null || ! isset($src['lat'], $src['lng'])) {
                        $stops[] = $place + ['_cand' => $src];
                        continue;
                    }
                    $stops[] = $place + [
                        '_cand' => $src,
                        'lat' => (float) $src['lat'],
                        'lng' => (float) $src['lng'],
                    ];
                }

                if ($stops !== [] && $fromPt !== null && $toPt !== null
                    && isset($fromPt['lat'], $fromPt['lng'], $toPt['lat'], $toPt['lng'])) {
                    $originScrub = $this->trimOriginZoneTransferStops($stops, $fromPt, $toPt);
                    foreach ($originScrub['removed'] as $r) {
                        $removed[] = 'день '.($i + 1).': '.$r;
                    }
                    $stops = $originScrub['keep'];

                    $trimmed = $this->trimTransferStopsToCorridor(
                        $stops,
                        $fromPt,
                        $toPt,
                        $maxDetour,
                        $maxRatio,
                        $linkKm
                    );
                    foreach ($trimmed['removed'] as $r) {
                        $removed[] = 'день '.($i + 1).': '.$r;
                    }
                    $stops = $trimmed['keep'];
                } elseif (count($stops) >= 2) {
                    $trimmed = $this->trimTransferStopsByCandidateMetrics($stops, $linkKm);
                    foreach ($trimmed['removed'] as $r) {
                        $removed[] = 'день '.($i + 1).': '.$r;
                    }
                    $stops = $trimmed['keep'];
                }

                $keep = [];
                foreach ($stops as $s) {
                    unset($s['_cand'], $s['lat'], $s['lng']);
                    $keep[] = $s;
                }
                $days[$i]['places'] = array_merge($keep, $bases);
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
            }

            if ($nightId > 0) {
                $prevNightId = $nightId;
            }
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Stay: BASE→POI1→POI2→BASE — один компактный кластер / одно направление; не возврат к prev base.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubStayDaySequences(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $poisById === []) {
            return ['plan' => $plan, 'removed' => []];
        }

        $linkKm = (float) config('travel_route_fit.cluster_link_km', 18);
        $maxBearing = (float) config('travel_route_fit.stay_max_bearing_spread_deg', 75);
        $prevZoneKm = (float) config('travel_route_fit.stay_prev_zone_km', 25);
        $maxLoopRatio = (float) config('travel_route_fit.stay_max_loop_ratio', 1.55);
        $removed = [];
        $days = $plan['days'];
        /** @var list<int> */
        $priorBaseIds = [];

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            $nightId = (int) ($day['overnight']['id'] ?? 0);
            $base = $poisById[$nightId] ?? null;

            if (! $isTransfer && $nightId > 0 && $base !== null) {
                $acts = [];
                $bases = [];
                foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                    if (! is_array($place)) {
                        continue;
                    }
                    $pid = (int) ($place['id'] ?? 0);
                    $role = (string) ($place['role'] ?? '');
                    if ($pid === $nightId || $role === 'base') {
                        $bases[] = $place;
                        continue;
                    }
                    $src = $poisById[$pid] ?? null;
                    if ($src === null) {
                        $acts[] = $place;
                        continue;
                    }
                    $acts[] = $place + [
                        'lat' => (float) $src['lat'],
                        'lng' => (float) $src['lng'],
                        '_km_base' => $this->geo->km($base, $src),
                    ];
                }

                // Возврат в зону ЛЮБОЙ предыдущей базы тура (Bulla→Ortisei при stay Brunico)
                if ($priorBaseIds !== []) {
                    $keepActs = [];
                    foreach ($acts as $a) {
                        if (! isset($a['lat'], $a['lng'])) {
                            $keepActs[] = $a;
                            continue;
                        }
                        $kmCur = $this->geo->km($base, $a);
                        $hitPrevId = self::firstPriorBaseZoneHit(
                            $kmCur,
                            $a,
                            $nightId,
                            $priorBaseIds,
                            $poisById,
                            $this->geo,
                            $prevZoneKm
                        );
                        if ($hitPrevId !== null) {
                            $removed[] = 'день '.($i + 1).': убран #'
                                .(int) ($a['id'] ?? 0).' '.($a['name'] ?? '')
                                .' (возврат в зону предыдущей базы #'.$hitPrevId
                                .', не связка текущего stay)';
                            continue;
                        }
                        $keepActs[] = $a;
                    }
                    $acts = $keepActs;
                }

                $withCoords = array_values(array_filter(
                    $acts,
                    fn ($a) => isset($a['lat'], $a['lng'])
                ));
                if (count($withCoords) >= 2) {
                    $clusters = $this->clusterPointsByLink($withCoords, $linkKm);
                    $split = count($clusters) >= 2;
                    $spread = 0.0;
                    if ($split) {
                        $centroids = [];
                        foreach ($clusters as $ci => $cl) {
                            $centroids[$ci] = $this->centroidOfPoints($cl);
                        }
                        $spread = $this->maxBearingSpreadDeg($base, array_values($centroids));
                    }
                    // также: длинная петля BASE→все→BASE vs лучший одиночный кластер out-back
                    $loopBad = false;
                    if ($split) {
                        $fullLoop = $this->stayLoopKm($base, $withCoords);
                        $bestSolo = null;
                        foreach ($clusters as $cl) {
                            $solo = $this->stayLoopKm($base, $cl);
                            if ($bestSolo === null || $solo < $bestSolo) {
                                $bestSolo = $solo;
                            }
                        }
                        if ($bestSolo !== null && $bestSolo > 1
                            && $fullLoop > $bestSolo * $maxLoopRatio) {
                            $loopBad = true;
                        }
                    }
                    if ($split && ($spread >= $maxBearing || $loopBad)) {
                        usort($clusters, function (array $a, array $b) use ($base) {
                            if (count($a) !== count($b)) {
                                return count($b) <=> count($a);
                            }
                            $da = $this->geo->km($base, $this->centroidOfPoints($a)) ?? 99;
                            $db = $this->geo->km($base, $this->centroidOfPoints($b)) ?? 99;

                            return $da <=> $db;
                        });
                        $keepCluster = $clusters[0];
                        $keepIds = [];
                        foreach ($keepCluster as $p) {
                            $keepIds[(int) ($p['id'] ?? 0)] = true;
                        }
                        $newActs = [];
                        foreach ($acts as $a) {
                            $pid = (int) ($a['id'] ?? 0);
                            if (! isset($a['lat'], $a['lng']) || isset($keepIds[$pid])) {
                                unset($a['lat'], $a['lng'], $a['_km_base']);
                                $newActs[] = $a;
                                continue;
                            }
                            $why = $loopBad
                                ? 'разные оси stay-петли (длинный BASE→POIs→BASE крюк)'
                                : 'противоположное направление stay-петли';
                            $removed[] = 'день '.($i + 1).': убран #'
                                .$pid.' '.($a['name'] ?? '')
                                .' ('.$why.'; STAY-radius ≠ одна ось дня)';
                        }
                        $acts = $newActs;
                    }
                }

                $clean = [];
                foreach ($acts as $a) {
                    unset($a['lat'], $a['lng'], $a['_km_base']);
                    $clean[] = $a;
                }
                $days[$i]['places'] = array_merge($clean, $bases);
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
            }

            if ($nightId > 0 && ! in_array($nightId, $priorBaseIds, true)) {
                $priorBaseIds[] = $nightId;
            }
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Правило prev-zone из scrubStayDaySequences: POI ближе к prior base, чем к текущей.
     * kmPrev ≤ stay_prev_zone_km И kmPrev + 8 < kmCur.
     */
    public static function hitsPriorBaseZone(
        ?float $kmToCurrentBase,
        ?float $kmToPriorBase,
        ?float $prevZoneKm = null,
    ): bool {
        $zone = $prevZoneKm ?? (float) config('travel_route_fit.stay_prev_zone_km', 25.0);

        return $kmToPriorBase !== null && $kmToCurrentBase !== null
            && $kmToPriorBase <= $zone
            && $kmToPriorBase + 8.0 < $kmToCurrentBase;
    }

    /**
     * Первый prior base id, из-за которого POI считается возвратом; иначе null.
     *
     * @param  array<string, mixed>  $poi  lat/lng
     * @param  list<int>  $priorBaseIds
     * @param  array<int, array<string, mixed>>  $poisById
     */
    public static function firstPriorBaseZoneHit(
        ?float $kmToCurrentBase,
        array $poi,
        int $currentBaseId,
        array $priorBaseIds,
        array $poisById,
        GeoDistance $geo,
        ?float $prevZoneKm = null,
    ): ?int {
        foreach ($priorBaseIds as $pbId) {
            $pbId = (int) $pbId;
            if ($pbId <= 0 || $pbId === $currentBaseId || ! isset($poisById[$pbId])) {
                continue;
            }
            $kmPrev = $geo->km($poisById[$pbId], $poi);
            if (self::hitsPriorBaseZone($kmToCurrentBase, $kmPrev, $prevZoneKm)) {
                return $pbId;
            }
        }

        return null;
    }

    /**
     * Для каждой overnight-базы — id других баз тура (кандидаты «предыдущих» для prev-zone пула).
     * Порядок как у scrub: все прочие overnight из $pois; конкретный day-order scrub всё ещё валидирует.
     *
     * @param  list<array<string, mixed>>  $pois
     * @return array<int, list<int>>  baseId => priorBaseIds
     */
    public function priorBaseIdsForStayPool(array $pois): array
    {
        $bases = $this->basesFromPois($pois);
        $ids = [];
        foreach ($bases as $b) {
            $id = (int) ($b['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = array_values(array_filter($ids, fn (int $x) => $x !== $id));
        }

        return $out;
    }

    /**
     * Длина петли BASE → points (по порядку) → BASE.
     *
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $points
     */
    private function stayLoopKm(array $base, array $points): float
    {
        if ($points === []) {
            return 0.0;
        }
        // кратчайший порядок: сортировка по bearing/distance — берём как есть + замкнуть
        $sum = 0.0;
        $prev = $base;
        foreach ($points as $p) {
            $km = $this->geo->km($prev, $p);
            if ($km !== null) {
                $sum += $km;
            }
            $prev = $p;
        }
        $back = $this->geo->km($prev, $base);

        return $sum + ($back ?? 0.0);
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return list<list<array<string, mixed>>>
     */
    private function clusterPointsByLink(array $points, float $linkKm): array
    {
        $n = count($points);
        if ($n === 0) {
            return [];
        }
        $parent = range(0, $n - 1);
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $km = $this->geo->km($points[$i], $points[$j]);
                if ($km !== null && $km <= $linkKm) {
                    $ri = $find($i);
                    $rj = $find($j);
                    if ($ri !== $rj) {
                        $parent[$rj] = $ri;
                    }
                }
            }
        }
        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $groups[$find($i)][] = $points[$i];
        }

        return array_values($groups);
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return array{lat: float, lng: float}
     */
    private function centroidOfPoints(array $points): array
    {
        $lat = 0.0;
        $lng = 0.0;
        $n = max(1, count($points));
        foreach ($points as $p) {
            $lat += (float) $p['lat'];
            $lng += (float) $p['lng'];
        }

        return ['lat' => $lat / $n, 'lng' => $lng / $n];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  list<array{lat: float, lng: float}>  $points
     */
    private function maxBearingSpreadDeg(array $base, array $points): float
    {
        if (count($points) < 2) {
            return 0.0;
        }
        $bearings = [];
        foreach ($points as $p) {
            $bearings[] = $this->bearingDeg($base, $p);
        }
        $max = 0.0;
        for ($i = 0; $i < count($bearings); $i++) {
            for ($j = $i + 1; $j < count($bearings); $j++) {
                $d = abs($bearings[$i] - $bearings[$j]);
                if ($d > 180) {
                    $d = 360 - $d;
                }
                $max = max($max, $d);
            }
        }

        return $max;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function bearingDeg(array $a, array $b): float
    {
        $mid = deg2rad(((float) $a['lat'] + (float) $b['lat']) / 2);
        $dx = ((float) $b['lng'] - (float) $a['lng']) * 111.32 * cos($mid);
        $dy = ((float) $b['lat'] - (float) $a['lat']) * 110.57;

        return rad2deg(atan2($dx, $dy));
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array{keep: list<array<string, mixed>>, removed: list<string>}
     */
    private function trimTransferStopsToCorridor(
        array $stops,
        array $from,
        array $to,
        float $maxDetour,
        float $maxRatio,
        float $linkKm,
    ): array {
        $direct = $this->geo->km($from, $to);
        if ($direct === null || $direct < 1) {
            return ['keep' => $stops, 'removed' => []];
        }

        // Сортировка по progress вдоль оси
        usort($stops, function (array $a, array $b) use ($from, $to) {
            $pa = $this->axisMetrics($from, $to, $a)['progress'] ?? 0.5;
            $pb = $this->axisMetrics($from, $to, $b)['progress'] ?? 0.5;

            return $pa <=> $pb;
        });

        $pathKm = function (array $subset) use ($from, $to): float {
            $pts = [$from];
            foreach ($subset as $s) {
                $pts[] = $s;
            }
            $pts[] = $to;
            $sum = 0.0;
            for ($i = 0; $i < count($pts) - 1; $i++) {
                $km = $this->geo->km($pts[$i], $pts[$i + 1]);
                if ($km !== null) {
                    $sum += $km;
                }
            }

            return $sum;
        };

        $removed = [];
        $keep = $stops;
        $via = $pathKm($keep);
        $detour = max(0.0, $via - $direct);
        $compactMax = (float) config('travel_route_fit.max_transfer_compact_path_detour_km', 40);
        $acceptKm = (float) config('travel_route_fit.acceptable_detour_km', 36);

        $okPath = function (float $viaKm, array $subset) use ($direct, $maxDetour, $maxRatio, $linkKm, $compactMax, $acceptKm): bool {
            $d = max(0.0, $viaKm - $direct);
            $r = $direct > 0.01 ? ($viaKm / $direct) : 99.0;
            if ($d <= $maxDetour && $r <= $maxRatio) {
                return true;
            }
            $compact = $this->stopsAreCompactOrSingle($subset, $linkKm);
            // Короткий direct: ratio врёт; компактная связка — по абсолютному detour
            if ($compact && count($subset) >= 2 && $d <= $compactMax) {
                return true;
            }
            // Один stop: до acceptable даже при высоком ratio
            if (count($subset) === 1 && $d <= $acceptKm) {
                return true;
            }

            return false;
        };

        // Path OLD→stops→NEW уже в лимите — не резать до 1 stop из‑за «не компактны по linkKm».
        // Compact нужен только как ослабление, когда path выше лимита.
        if ($okPath($via, $keep)) {
            return ['keep' => $keep, 'removed' => []];
        }

        // 1) Компактный кластер, если path ещё в норме
        $bestCluster = $this->bestCompactCluster($keep, $from, $to, $linkKm, $pathKm, $direct);
        if ($bestCluster !== null) {
            $clusterVia = $pathKm($bestCluster);
            if ($okPath($clusterVia, $bestCluster)) {
                foreach ($keep as $s) {
                    $sid = (int) ($s['id'] ?? 0);
                    $in = false;
                    foreach ($bestCluster as $c) {
                        if ((int) ($c['id'] ?? 0) === $sid) {
                            $in = true;
                            break;
                        }
                    }
                    if (! $in) {
                        $removed[] = 'убран stop #'.$sid.' '.($s['name'] ?? '')
                            .' (не в компактном corridor-кластере / path detour)';
                    }
                }

                return ['keep' => $bestCluster, 'removed' => $removed];
            }
        }

        // 2) Один лучший stop — только если OLD→stop→NEW сам по себе не крюк
        usort($keep, function (array $a, array $b) use ($pathKm, $direct) {
            $va = $pathKm([$a]);
            $vb = $pathKm([$b]);
            $da = max(0.0, $va - $direct);
            $db = max(0.0, $vb - $direct);
            if ($da !== $db) {
                return $da <=> $db;
            }
            $ca = (float) (($a['_cand']['detour_km'] ?? 99));
            $cb = (float) (($b['_cand']['detour_km'] ?? 99));

            return $ca <=> $cb;
        });
        $best = [$keep[0]];
        $bestVia = $pathKm($best);
        if (! $okPath($bestVia, $best)) {
            foreach ($keep as $s) {
                $removed[] = 'убран stop #'.(int) ($s['id'] ?? 0).' '.($s['name'] ?? '')
                    .' (path OLD→stop→NEW detour '.round(max(0, $bestVia - $direct), 1)
                    .' km / ratio '.round($bestVia / max(0.01, $direct), 2)
                    .' — крюк относительно прямого OLD→NEW; оставлен чистый переезд)';
            }

            return ['keep' => [], 'removed' => $removed];
        }
        foreach (array_slice($keep, 1) as $s) {
            $removed[] = 'убран stop #'.(int) ($s['id'] ?? 0).' '.($s['name'] ?? '')
                .' (multi-stop path detour '.round($detour, 1).' km; оставлен 1 corridor stop)';
        }

        return ['keep' => $best, 'removed' => $removed];
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     */
    private function stopsAreCompactOrSingle(array $stops, float $linkKm): bool
    {
        if (count($stops) <= 1) {
            return true;
        }
        for ($i = 0; $i < count($stops); $i++) {
            for ($j = $i + 1; $j < count($stops); $j++) {
                if (! isset($stops[$i]['lat'], $stops[$j]['lat'])) {
                    return false;
                }
                $km = $this->geo->km($stops[$i], $stops[$j]);
                if ($km === null || $km > $linkKm) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @param  callable(list<array<string, mixed>>): float  $pathKm
     * @return list<array<string, mixed>>|null
     */
    private function bestCompactCluster(
        array $stops,
        array $from,
        array $to,
        float $linkKm,
        callable $pathKm,
        float $direct,
    ): ?array {
        $n = count($stops);
        if ($n < 2) {
            return null;
        }
        $best = null;
        $bestScore = 9999.0;
        for ($i = 0; $i < $n; $i++) {
            $cluster = [$stops[$i]];
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j || ! isset($stops[$i]['lat'], $stops[$j]['lat'])) {
                    continue;
                }
                $km = $this->geo->km($stops[$i], $stops[$j]);
                if ($km !== null && $km <= $linkKm) {
                    $cluster[] = $stops[$j];
                }
            }
            // unique by id
            $uniq = [];
            foreach ($cluster as $c) {
                $uniq[(int) ($c['id'] ?? 0)] = $c;
            }
            $cluster = array_values($uniq);
            if (count($cluster) < 2) {
                continue;
            }
            $via = $pathKm($cluster);
            $detour = max(0.0, $via - $direct);
            if ($detour < $bestScore) {
                $bestScore = $detour;
                $best = $cluster;
            }
        }

        return $best;
    }

    /**
     * Fallback без lat баз: не мешать «полный горный» stop с далёким чужим stop.
     *
     * @param  list<array<string, mixed>>  $stops
     * @return array{keep: list<array<string, mixed>>, removed: list<string>}
     */
    private function trimTransferStopsByCandidateMetrics(array $stops, float $linkKm): array
    {
        $removed = [];
        $heavy = [];
        $light = [];
        foreach ($stops as $s) {
            $dur = is_numeric($s['_cand']['poi_duration'] ?? null)
                ? (float) $s['_cand']['poi_duration']
                : (is_numeric($s['hours'] ?? null) ? (float) $s['hours'] : 0);
            $name = (string) ($s['name'] ?? '');
            $isHeavy = $dur >= 3.0 || (bool) preg_match('/marmolada|מרמולד|мармолад|fedaia|פאדיה|феда/ui', $name);
            if ($isHeavy) {
                $heavy[] = $s;
            } else {
                $light[] = $s;
            }
        }
        if ($heavy !== [] && $light !== []) {
            foreach ($light as $s) {
                $removed[] = 'убран stop #'.(int) ($s['id'] ?? 0).' '.($s['name'] ?? '')
                    .' (не смешивать с полноценным горным сценарием на transfer)';
            }

            return ['keep' => $heavy, 'removed' => $removed];
        }
        if (count($stops) > 2) {
            usort($stops, fn ($a, $b) => ((float) ($a['_cand']['detour_km'] ?? 99)) <=> ((float) ($b['_cand']['detour_km'] ?? 99)));
            $keep = array_slice($stops, 0, 2);
            foreach (array_slice($stops, 2) as $s) {
                $removed[] = 'убран лишний transfer stop #'.(int) ($s['id'] ?? 0).' '.($s['name'] ?? '');
            }

            return ['keep' => $keep, 'removed' => $removed];
        }

        return ['keep' => $stops, 'removed' => []];
    }

    /**
     * Stop в зоне OLD base без progress вдоль OLD→NEW — не transfer corridor.
     *
     * @param  list<array<string, mixed>>  $stops
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array{keep: list<array<string, mixed>>, removed: list<string>}
     */
    private function trimOriginZoneTransferStops(array $stops, array $from, array $to): array
    {
        $originZone = (float) config('travel_route_fit.transfer_origin_zone_km', 12);
        $minProg = (float) config('travel_route_fit.transfer_min_corridor_progress', 0.12);
        $keep = [];
        $removed = [];
        foreach ($stops as $s) {
            if (! isset($s['lat'], $s['lng'])) {
                $keep[] = $s;
                continue;
            }
            $dStart = $this->geo->km($from, $s);
            $axis = $this->axisMetrics($from, $to, $s);
            $prog = (float) ($axis['progress'] ?? 0);
            if ($dStart !== null && $dStart <= $originZone && $prog < $minProg) {
                $removed[] = 'убран stop #'.(int) ($s['id'] ?? 0).' '.($s['name'] ?? '')
                    .' (зона OLD base / Val Gardena-класс: progress='
                    .round($prog, 2).', dStart='.round($dStart, 1)
                    .' км — не направление к NEW; это stay у старой базы, не transfer corridor)';
                continue;
            }
            $keep[] = $s;
        }

        return ['keep' => $keep, 'removed' => $removed];
    }

    /**
     * Вторичный overnight-хаб (Molveno) при более позднем primary (Garda) → overnight = Garda-база.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubSecondaryHubOvernight(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $poisById === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $secondary = array_map('strval', (array) config('travel_route_fit.overnight_secondary_hubs', ['molveno']));
        $primary = array_map('strval', (array) config('travel_route_fit.overnight_primary_hubs', ['garda']));
        $days = $plan['days'];
        $removed = [];

        $hubOf = function (int $id) use ($poisById): array {
            $p = $poisById[$id] ?? null;
            if (! is_array($p)) {
                return [];
            }

            return $this->matcher->detectHubs((string) ($p['name'] ?? ''));
        };

        // Первый primary overnight ПОСЛЕ каждого дня (для схлопывания)
        $n = count($days);
        $nextPrimaryBase = array_fill(0, $n, null);
        $lastPrimary = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            $oid = (int) ($days[$i]['overnight']['id'] ?? 0);
            $hubs = $oid > 0 ? $hubOf($oid) : [];
            if ($oid > 0 && array_intersect($hubs, $primary) !== []) {
                $lastPrimary = [
                    'id' => $oid,
                    'name' => (string) ($days[$i]['overnight']['name'] ?? ($poisById[$oid]['name'] ?? '')),
                ];
            }
            $nextPrimaryBase[$i] = $lastPrimary;
        }

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $hubs = $hubOf($oid);
            if (array_intersect($hubs, $secondary) === []) {
                continue;
            }
            $target = $nextPrimaryBase[$i] ?? null;
            // target на этом же дне = сам день уже primary — ищем строго позже
            if ($target !== null && (int) $target['id'] === $oid) {
                $target = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($nextPrimaryBase[$j] !== null && (int) $nextPrimaryBase[$j]['id'] !== $oid) {
                        $target = $nextPrimaryBase[$j];
                        break;
                    }
                }
            }
            if ($target === null || (int) $target['id'] === $oid) {
                // нет позднего primary в плане — взять любую Garda-базу из каталога
                foreach ($poisById as $pid => $p) {
                    if (! is_array($p) || (empty($p['is_base']) && ($p['role'] ?? '') !== 'base')) {
                        continue;
                    }
                    $h = $this->matcher->detectHubs((string) ($p['name'] ?? ''));
                    if (in_array('garda', $h, true)) {
                        $target = ['id' => (int) $pid, 'name' => (string) ($p['name'] ?? '')];
                        break;
                    }
                }
            }
            if ($target === null || (int) $target['id'] === $oid) {
                continue;
            }

            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            $days[$i]['overnight'] = ['id' => (int) $target['id'], 'name' => (string) $target['name']];
            if (! $isTransfer && ! str_contains($theme, 'выезд') && ! str_contains($theme, 'departure')) {
                $days[$i]['theme'] = 'переезд';
            }
            // activity у старой базы → transfer_stop; не дублировать новую базу как activity
            $newPlaces = [];
            $tid = (int) $target['id'];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                if (! is_array($place)) {
                    continue;
                }
                $pid = (int) ($place['id'] ?? 0);
                $role = (string) ($place['role'] ?? '');
                if ($pid === $oid || $role === 'base') {
                    continue;
                }
                if ($pid === $tid) {
                    $place['role'] = 'base';
                    $newPlaces[] = $place;
                    continue;
                }
                if ($role === 'activity' || $role === '') {
                    $place['role'] = 'transfer_stop';
                }
                $newPlaces[] = $place;
            }
            // base прибытия в places
            $hasTarget = false;
            foreach ($newPlaces as $p) {
                if ((int) ($p['id'] ?? 0) === $tid) {
                    $hasTarget = true;
                    break;
                }
            }
            if (! $hasTarget) {
                $newPlaces[] = [
                    'id' => $tid,
                    'name' => (string) $target['name'],
                    'hours' => 2.0,
                    'role' => 'base',
                ];
            }
            $days[$i]['places'] = $newPlaces;
            $days[$i]['total_hours'] = $this->sumPlaceHours($newPlaces);
            $removed[] = 'день '.($i + 1).': overnight #'.$oid.' → #'.$tid
                .' (вторичный хаб Molveno-класс; overnight = primary Garda/цель, не промежуточное озеро)';
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Stay: если activity ближе к другой overnight-базе тура — перенести ночёвку.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubStayOvernightMismatch(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $poisById === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $tourOvernights = [];
        foreach ($plan['days'] as $day) {
            if (! is_array($day)) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            if ($oid > 0 && isset($poisById[$oid])) {
                $tourOvernights[$oid] = $poisById[$oid];
            }
        }
        if (count($tourOvernights) < 2) {
            return ['plan' => $plan, 'removed' => []];
        }

        $removed = [];
        $days = $plan['days'];
        $marginKm = 12.0;

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (str_contains($theme, 'переезд') || str_contains($theme, 'transfer')
                || str_contains($theme, 'выезд') || str_contains($theme, 'departure')) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $base = $poisById[$oid] ?? null;
            if ($oid <= 0 || $base === null) {
                continue;
            }

            $acts = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                if (! is_array($place)) {
                    continue;
                }
                $pid = (int) ($place['id'] ?? 0);
                $role = (string) ($place['role'] ?? '');
                if ($pid === $oid || $role === 'base' || $pid <= 0) {
                    continue;
                }
                $src = $poisById[$pid] ?? null;
                if ($src === null || ! isset($src['lat'], $src['lng'])) {
                    continue;
                }
                $acts[] = $src;
            }
            if ($acts === []) {
                continue;
            }

            $avgTo = function (array $b) use ($acts): ?float {
                $sum = 0.0;
                $n = 0;
                foreach ($acts as $a) {
                    $km = $this->geo->km($b, $a);
                    if ($km === null) {
                        continue;
                    }
                    $sum += $km;
                    $n++;
                }

                return $n > 0 ? $sum / $n : null;
            };

            $curAvg = $avgTo($base);
            if ($curAvg === null) {
                continue;
            }
            $bestId = $oid;
            $bestAvg = $curAvg;
            $bestName = (string) ($day['overnight']['name'] ?? '');
            foreach ($tourOvernights as $cid => $cand) {
                if ((int) $cid === $oid) {
                    continue;
                }
                $a = $avgTo($cand);
                if ($a !== null && $a + $marginKm < $bestAvg) {
                    $bestAvg = $a;
                    $bestId = (int) $cid;
                    $bestName = (string) ($cand['name'] ?? '');
                }
            }
            if ($bestId === $oid) {
                continue;
            }
            $days[$i]['overnight'] = ['id' => $bestId, 'name' => $bestName];
            $days[$i]['total_hours'] = $this->sumPlaceHours($day['places'] ?? []);
            $removed[] = 'день '.($i + 1).': overnight #'.$oid.' → #'.$bestId
                .' (stay-activity ближе к другой базе тура: avg '
                .round($curAvg, 1).'→'.round($bestAvg, 1).' км)';
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Короткий transfer + тяжёлый Marmolada-пакет → оставить 1 лёгкий corridor stop.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $legs
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubHeavyPackageOnShortTransfer(array $plan, array $legs, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $maxDirect = (float) config('travel_route_fit.short_transfer_heavy_direct_km', 55.0);
        $heavyIds = array_map('intval', (array) config('travel_route_fit.short_transfer_heavy_poi_ids', [187, 456, 129]));
        $heavySet = array_fill_keys($heavyIds, true);
        $removed = [];
        $days = $plan['days'];
        $prevNightId = null;

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            $nightId = (int) ($day['overnight']['id'] ?? 0);
            if ($isTransfer && $nightId > 0 && $prevNightId !== null) {
                $leg = $this->legForNightChange($legs, $prevNightId, $nightId);
                $direct = is_array($leg) && is_numeric($leg['direct_km'] ?? null)
                    ? (float) $leg['direct_km']
                    : null;
                if ($direct === null) {
                    $from = $poisById[$prevNightId] ?? null;
                    $to = $poisById[$nightId] ?? null;
                    if ($from !== null && $to !== null) {
                        $direct = $this->geo->km($from, $to);
                    }
                }
                if ($direct !== null && $direct <= $maxDirect) {
                    $places = is_array($day['places'] ?? null) ? $day['places'] : [];
                    $heavy = [];
                    $other = [];
                    $bases = [];
                    foreach ($places as $p) {
                        if (! is_array($p)) {
                            continue;
                        }
                        $pid = (int) ($p['id'] ?? 0);
                        $role = (string) ($p['role'] ?? '');
                        if ($pid === $nightId || $role === 'base') {
                            $bases[] = $p;
                            continue;
                        }
                        if (isset($heavySet[$pid])) {
                            $heavy[] = $p;
                        } else {
                            $other[] = $p;
                        }
                    }
                    if ($heavy !== []) {
                        foreach ($heavy as $h) {
                            $removed[] = 'день '.($i + 1).': убран heavy #'
                                .(int) ($h['id'] ?? 0).' '.($h['name'] ?? '')
                                .' (короткий transfer '.round($direct, 1)
                                .' км: Marmolada-пакет = stay Val Gardena, не corridor)';
                        }
                        $days[$i]['places'] = array_merge($other, $bases);
                        $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
                    }
                }
            }
            if ($nightId > 0) {
                $prevNightId = $nightId;
            }
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Linked stay pairs (Braies→Misurina): если якорь есть, пары нет — добавить из каталога.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function enrichStayLinkedPairs(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $poisById === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $pairs = (array) config('travel_route_fit.stay_linked_pairs', [116 => 117]);
        if ($pairs === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $radius = (float) config('travel_route_fit.stay_radius_km', 70.0);
        $removed = [];
        $days = $plan['days'];

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (str_contains($theme, 'переезд') || str_contains($theme, 'transfer')
                || str_contains($theme, 'выезд') || str_contains($theme, 'departure')) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $base = $poisById[$oid] ?? null;
            if ($oid <= 0 || $base === null) {
                continue;
            }
            $placeIds = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (is_array($p)) {
                    $placeIds[(int) ($p['id'] ?? 0)] = true;
                }
            }
            foreach ($pairs as $anchorId => $pairId) {
                $anchorId = (int) $anchorId;
                $pairId = (int) $pairId;
                if ($anchorId <= 0 || $pairId <= 0) {
                    continue;
                }
                if (! isset($placeIds[$anchorId]) || isset($placeIds[$pairId])) {
                    continue;
                }
                $pair = $poisById[$pairId] ?? null;
                if ($pair === null || ! isset($pair['lat'], $pair['lng'])) {
                    continue;
                }
                $km = $this->geo->km($base, $pair);
                if ($km === null || $km > $radius) {
                    continue;
                }
                $hours = is_numeric($pair['poi_duration'] ?? null) ? (float) $pair['poi_duration'] : 2.0;
                $days[$i]['places'][] = [
                    'id' => $pairId,
                    'name' => (string) ($pair['name'] ?? 'Misurina'),
                    'hours' => $hours,
                    'role' => 'activity',
                    'note' => 'Связка с якорем #'.$anchorId.' (PHP stay pair)',
                ];
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
                $removed[] = 'день '.($i + 1).': добавлен #'.$pairId.' '
                    .($pair['name'] ?? '').' (stay pair к #'.$anchorId.' у базы #'.$oid.')';
            }
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * #395 переправа Malcesine→Limone и #32 Limone — один блок; не суммировать hours дважды.
     *
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubFerryLimoneCompositeHours(array $plan): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $removed = [];
        $days = $plan['days'];
        foreach ($days as $i => $day) {
            if (! is_array($day) || ! is_array($day['places'] ?? null)) {
                continue;
            }
            $ferryIdx = null;
            $limoneIdx = null;
            foreach ($day['places'] as $j => $place) {
                if (! is_array($place)) {
                    continue;
                }
                $pid = (int) ($place['id'] ?? 0);
                $name = (string) ($place['name'] ?? '');
                $isFerry = $pid === 395
                    || (bool) preg_match('/שייט|ferry|traghetto|переправ|катер|malcesine.*limon|מלצסינה.*לימונ/ui', $name);
                $isLimone = $pid === 32
                    || (bool) preg_match('/^limone|лимоне|לימונה/ui', $name);
                if ($isFerry && $ferryIdx === null) {
                    $ferryIdx = $j;
                }
                if ($isLimone && ! $isFerry && $limoneIdx === null) {
                    $limoneIdx = $j;
                }
            }
            if ($ferryIdx === null || $limoneIdx === null) {
                continue;
            }
            $ferryH = is_numeric($day['places'][$ferryIdx]['hours'] ?? null)
                ? (float) $day['places'][$ferryIdx]['hours'] : 0.0;
            $limH = is_numeric($day['places'][$limoneIdx]['hours'] ?? null)
                ? (float) $day['places'][$limoneIdx]['hours'] : 0.0;
            if ($limH <= 0) {
                continue;
            }
            // duration #395 уже покрывает часть Limone → обнуляем hours #32, место оставляем в сценарии
            $days[$i]['places'][$limoneIdx]['hours'] = 0;
            $days[$i]['places'][$limoneIdx]['note'] = trim(
                ((string) ($day['places'][$limoneIdx]['note'] ?? '')).
                ' (время визита входит в блок переправы #'
                .(int) ($day['places'][$ferryIdx]['id'] ?? 395).'; не суммировать отдельно)'
            );
            if ($ferryH < $limH) {
                $days[$i]['places'][$ferryIdx]['hours'] = $limH;
            }
            $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
            $removed[] = 'день '.($i + 1).': hours #'
                .(int) ($day['places'][$limoneIdx]['id'] ?? 0).' Limone обнулены — входят в пакет переправы #'
                .(int) ($day['places'][$ferryIdx]['id'] ?? 395)
                .' (было +'.round($limH, 1).' ч дубля)';
        }
        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Departure/выезд: не stay-density; при раннем выезде урезать программу.
     *
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubDepartureDayHours(array $plan, string $query = ''): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $maxEarly = (float) config('travel_route_fit.departure_max_hours_early', 2.0);
        $earlyBefore = (int) config('travel_route_fit.departure_early_before_hour', 12);
        $early = $this->querySuggestsEarlyDeparture($query, $earlyBefore);
        // Без раннего выезда/вылета в query — не резать программу выезда (иначе сносится Ledro-класс).
        if (! $early) {
            return ['plan' => $plan, 'removed' => []];
        }
        $cap = $maxEarly;
        $removed = [];
        $days = $plan['days'];

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (! str_contains($theme, 'выезд') && ! str_contains($theme, 'departure')) {
                continue;
            }

            $nightId = (int) ($day['overnight']['id'] ?? 0);
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            $actHours = 0.0;
            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $nightId || $role === 'base') {
                    continue;
                }
                if (isset($p['hours']) && is_numeric($p['hours'])) {
                    $actHours += (float) $p['hours'];
                }
            }
            if ($actHours <= $cap + 0.05) {
                continue;
            }

            // Убрать самые «лёгкие» лишние (с конца), пока не уложимся в cap
            $acts = [];
            $bases = [];
            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $nightId || $role === 'base') {
                    $bases[] = $p;
                } else {
                    $acts[] = $p;
                }
            }
            usort($acts, function (array $a, array $b) {
                $ha = is_numeric($a['hours'] ?? null) ? (float) $a['hours'] : 0;
                $hb = is_numeric($b['hours'] ?? null) ? (float) $b['hours'] : 0;

                return $ha <=> $hb; // сначала короткие
            });
            while ($acts !== [] && $this->sumPlaceHours($acts) > $cap + 0.05) {
                $drop = array_pop($acts); // убрать самый длинный лишний
                // Prefer dropping non-ferry first: if last is ferry and we have others, drop from front of heavy
                $removed[] = 'день '.($i + 1).': убран #'
                    .(int) ($drop['id'] ?? 0).' '.($drop['name'] ?? '')
                    .' (выезд: программа > '.round($cap, 1).' ч'
                    .($early ? ', ранний выезд/вылет' : '').')';
            }
            // если всё ещё больше cap (один жирный блок) — обрезать hours последнего
            if ($acts !== [] && $this->sumPlaceHours($acts) > $cap + 0.05) {
                $last = count($acts) - 1;
                $h = is_numeric($acts[$last]['hours'] ?? null) ? (float) $acts[$last]['hours'] : 0;
                $others = $this->sumPlaceHours(array_slice($acts, 0, $last));
                $allow = max(0.5, $cap - $others);
                if ($h > $allow) {
                    $acts[$last]['hours'] = round($allow, 1);
                    $removed[] = 'день '.($i + 1).': сокращены hours #'
                        .(int) ($acts[$last]['id'] ?? 0).' до '.$allow
                        .' ч (лимит выезда '.round($cap, 1).' ч)';
                }
            }
            $days[$i]['places'] = array_merge($acts, $bases);
            $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
        }
        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    private function querySuggestsEarlyDeparture(string $query, int $earlyBeforeHour): bool
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return false;
        }
        if (preg_match('/ранн(ий|яя|им)?\s*(выезд|вылет|отъезд)|early\s*(depart|flight)|выезд\s+утром|вылет\s+утром/ui', $q)) {
            return true;
        }
        // вылет/выезд в HH:MM или HH
        if (preg_match_all('/(?:вылет|выезд|flight|depart(?:ure)?|самолёт|самолет)\D{0,12}(\d{1,2})(?::(\d{2}))?/ui', $q, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $h = (int) $m[1];
                if ($h >= 0 && $h <= 23 && $h <= $earlyBeforeHour) {
                    return true;
                }
            }
        }
        if (preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*(?:=)?\s*(?:вылет|выезд|flight)/ui', $q, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $h = (int) $m[1];
                if ($h >= 0 && $h <= 23 && $h <= $earlyBeforeHour) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Не использовать один и тот же POI повторно в разных днях без нужды (первый день выигрывает).
     *
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubDuplicatePoisAcrossDays(array $plan): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $seen = [];
        $removed = [];
        $days = $plan['days'];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $nightId = (int) ($day['overnight']['id'] ?? 0);
            $keep = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                if (! is_array($place)) {
                    continue;
                }
                $pid = (int) ($place['id'] ?? 0);
                $role = (string) ($place['role'] ?? '');
                if ($pid <= 0 || $pid === $nightId || $role === 'base') {
                    $keep[] = $place;
                    continue;
                }
                if (isset($seen[$pid])) {
                    $removed[] = 'день '.($i + 1).': убран повтор #'
                        .$pid.' '.($place['name'] ?? '').' (уже в дне '.$seen[$pid].')';
                    continue;
                }
                $seen[$pid] = $i + 1;
                $keep[] = $place;
            }
            $days[$i]['places'] = $keep;
            $sum = 0.0;
            foreach ($keep as $p) {
                if (isset($p['hours']) && is_numeric($p['hours'])) {
                    $sum += (float) $p['hours'];
                }
            }
            $days[$i]['total_hours'] = round($sum, 1);
        }
        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * @param  list<array<string, mixed>>  $legs
     * @return array<string, mixed>|null
     */
    private function legForNightChange(array $legs, ?int $fromNightId, int $toNightId): ?array
    {
        foreach ($legs as $leg) {
            $fromId = (int) ($leg['from']['id'] ?? 0);
            $toId = (int) ($leg['to']['id'] ?? 0);
            if ($toId !== $toNightId) {
                continue;
            }
            if ($fromNightId !== null && $fromId !== $fromNightId) {
                continue;
            }

            return $leg;
        }
        foreach ($legs as $leg) {
            if ((int) ($leg['to']['id'] ?? 0) === $toNightId) {
                return $leg;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $legs
     * @return array<string, array<int, true>>
     */
    private function allowedIdsByLeg(array $legs): array
    {
        $out = [];
        foreach ($legs as $leg) {
            $fromId = (int) ($leg['from']['id'] ?? 0);
            $toId = (int) ($leg['to']['id'] ?? 0);
            $key = $fromId.'>'.$toId;
            $ids = [$fromId => true, $toId => true];
            foreach ($leg['candidates_llm'] ?? [] as $c) {
                $ids[(int) ($c['id'] ?? 0)] = true;
            }
            $out[$key] = $ids;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $legs
     * @return array<int, true>
     */
    private function allowedIdsForNightChange(array $legs, ?int $fromNightId, int $toNightId): array
    {
        foreach ($legs as $leg) {
            $fromId = (int) ($leg['from']['id'] ?? 0);
            $toId = (int) ($leg['to']['id'] ?? 0);
            if ($toId !== $toNightId) {
                continue;
            }
            if ($fromNightId !== null && $fromId !== $fromNightId) {
                continue;
            }
            $ids = [$fromId => true, $toId => true];
            foreach ($leg['candidates_llm'] ?? [] as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid > 0) {
                    $ids[$cid] = true;
                }
            }

            return $ids;
        }
        // fallback: любое плечо, ведущее в эту ночёвку
        foreach ($legs as $leg) {
            $toId = (int) ($leg['to']['id'] ?? 0);
            if ($toId !== $toNightId) {
                continue;
            }
            $fromId = (int) ($leg['from']['id'] ?? 0);
            $ids = [$fromId => true, $toId => true];
            foreach ($leg['candidates_llm'] ?? [] as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid > 0) {
                    $ids[$cid] = true;
                }
            }

            return $ids;
        }

        return [];
    }

    /**
     * Поперечное отклонение и прогресс вдоль оси A→B (эквиареальное приближение).
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  array<string, mixed>  $poi
     * @return array{cross_track_km: float, progress: float}
     */
    private function axisMetrics(array $from, array $to, array $poi): array
    {
        $lat1 = (float) $from['lat'];
        $lng1 = (float) $from['lng'];
        $lat2 = (float) $to['lat'];
        $lng2 = (float) $to['lng'];
        $latP = (float) $poi['lat'];
        $lngP = (float) $poi['lng'];
        $midLat = deg2rad(($lat1 + $lat2) / 2);
        $kx = 111.32 * cos($midLat);
        $ky = 110.57;

        $ax = ($lng2 - $lng1) * $kx;
        $ay = ($lat2 - $lat1) * $ky;
        $px = ($lngP - $lng1) * $kx;
        $py = ($latP - $lat1) * $ky;
        $ab2 = $ax * $ax + $ay * $ay;
        if ($ab2 < 0.01) {
            return ['cross_track_km' => 0.0, 'progress' => 0.0];
        }
        $t = ($px * $ax + $py * $ay) / $ab2;
        $cx = $px - $t * $ax;
        $cy = $py - $t * $ay;
        $cross = sqrt($cx * $cx + $cy * $cy);

        return ['cross_track_km' => $cross, 'progress' => $t];
    }

    /**
     * @param  array{cross_track_km?: float, progress?: float}  $axis
     */
    private function classifyFit(
        float $detour,
        float $ratio,
        float $dStart,
        float $dEnd,
        float $direct,
        array $axis = [],
    ): string {
        $cross = (float) ($axis['cross_track_km'] ?? 0);
        $progress = (float) ($axis['progress'] ?? 0.5);
        $maxCross = (float) config('travel_route_fit.max_cross_track_km', 26);
        $minProg = (float) config('travel_route_fit.min_axis_progress', -0.05);
        $maxProg = (float) config('travel_route_fit.max_axis_progress', 1.08);
        $exKm = (float) config('travel_route_fit.excellent_detour_km', 10);
        $exR = (float) config('travel_route_fit.excellent_ratio', 1.18);
        $gKm = (float) config('travel_route_fit.good_detour_km', 20);
        $gR = (float) config('travel_route_fit.good_ratio', 1.35);
        $aKm = (float) config('travel_route_fit.acceptable_detour_km', 36);
        $aR = (float) config('travel_route_fit.acceptable_ratio', 2.15);

        // Вне коридора оси A→B
        if ($cross > $maxCross) {
            return 'bad';
        }
        if ($progress < $minProg || $progress > $maxProg) {
            return 'bad';
        }
        // У OLD base без продвижения к NEW (Monte Pana/Alpacas при Ortisei→Brunico)
        $originZone = (float) config('travel_route_fit.transfer_origin_zone_km', 12);
        $minCorridorProg = (float) config('travel_route_fit.transfer_min_corridor_progress', 0.12);
        if ($dStart <= $originZone && $progress < $minCorridorProg) {
            return 'bad';
        }
        // Дальше обоих концов + крупный крюк (на коротком direct не резать scenic compact stops)
        if ($dStart > $direct * 1.15 && $dEnd > $direct * 1.15 && $detour > $aKm) {
            return 'bad';
        }

        if ($detour <= $exKm && $ratio <= $exR && $cross <= 10) {
            return 'excellent';
        }
        if ($detour <= $gKm && $ratio <= $gR && $cross <= 16) {
            return 'good';
        }
        if ($detour <= $aKm && $ratio <= $aR) {
            return 'acceptable';
        }

        return 'bad';
    }

    /**
     * @param  list<array<string, mixed>>  $pois
     * @return list<array<string, mixed>>
     */
    private function basesFromPois(array $pois): array
    {
        $bases = [];
        foreach ($pois as $p) {
            if (! is_array($p)) {
                continue;
            }
            if (empty($p['is_base']) && ($p['role'] ?? '') !== 'base') {
                continue;
            }
            if (! isset($p['lat'], $p['lng']) || ! is_numeric($p['lat']) || ! is_numeric($p['lng'])) {
                continue;
            }
            $bases[] = $p;
        }

        return $bases;
    }

    /**
     * @param  list<array<string, mixed>>  $bases
     * @param  list<RouteSkeleton>  $skeletons
     * @return list<array{from: array<string, mixed>, to: array<string, mixed>}>
     */
    private function inferTransferLegs(array $bases, array $skeletons): array
    {
        if (count($bases) < 2) {
            return [];
        }

        $byHub = [];
        foreach ($bases as $b) {
            foreach ($this->matcher->detectHubs((string) ($b['name'] ?? '')) as $hub) {
                $byHub[$hub] = $b;
            }
        }

        $seq = [];
        foreach ($skeletons as $sample) {
            if (! $sample instanceof RouteSkeleton) {
                continue;
            }
            $sample->loadMissing('days');
            $seq = [];
            foreach ($sample->days as $day) {
                $label = trim((string) ($day->end_point_ru ?: $day->end_point
                    ?: $day->start_point_ru ?: $day->start_point ?: ''));
                if ($label === '') {
                    continue;
                }
                foreach ($this->matcher->detectHubs($label) as $hub) {
                    if (isset($byHub[$hub])) {
                        $id = (int) $byHub[$hub]['id'];
                        if ($seq === [] || (int) end($seq)['id'] !== $id) {
                            $seq[] = $byHub[$hub];
                        }
                        break;
                    }
                }
            }
            if (count($seq) >= 2) {
                break;
            }
            $seq = [];
        }

        if (count($seq) < 2) {
            $seq = $bases;
            usort($seq, fn ($a, $b) => ((float) $b['lat']) <=> ((float) $a['lat']));
        }

        $legs = [];
        $minDirect = (float) config('travel_route_fit.min_transfer_direct_km', 35);
        $seen = [];
        for ($i = 0; $i < count($seq) - 1; $i++) {
            $from = $seq[$i];
            $to = $seq[$i + 1];
            if ((int) $from['id'] === (int) $to['id']) {
                continue;
            }
            $key = (int) $from['id'].'>'.(int) $to['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $d = $this->geo->km($from, $to);
            if ($d === null || $d < $minDirect) {
                continue;
            }
            $seen[$key] = true;
            $legs[] = ['from' => $from, 'to' => $to];
        }

        return $legs;
    }

    /**
     * @param  list<array<string, mixed>>  $bases
     * @param  list<array<string, mixed>>  $pois
     */
    private function buildStayAreasBlock(array $bases, array $pois): string
    {
        if ($bases === []) {
            return '';
        }
        $radius = (float) config('travel_route_fit.stay_radius_km', 70);
        $longH = (float) config('travel_route_fit.stay_long_poi_hours', 3.0);
        $longR = (float) config('travel_route_fit.stay_long_poi_radius_km', 35.0);
        $parts = [];
        $poisById = $this->indexPoisById($pois);
        $priorByBase = $this->priorBaseIdsForStayPool($pois);
        $prevZoneKm = (float) config('travel_route_fit.stay_prev_zone_km', 25.0);

        foreach ($bases as $base) {
            $baseId = (int) ($base['id'] ?? 0);
            $priors = $priorByBase[$baseId] ?? [];
            $ids = [];
            foreach ($pois as $p) {
                if (! is_array($p) || ! empty($p['is_base']) || ($p['role'] ?? '') === 'base') {
                    continue;
                }
                $km = $this->geo->km($base, $p);
                if ($km === null || $km > $radius) {
                    continue;
                }
                $dur = is_numeric($p['poi_duration'] ?? null) ? (float) $p['poi_duration'] : null;
                $load = isset($p['physical_load']) ? (string) $p['physical_load'] : null;
                $maxR = ScenarioPoiContextBuilder::maxStayRadiusKm($dur, $load);
                if ($km > $maxR) {
                    continue;
                }
                if (self::firstPriorBaseZoneHit($km, $p, $baseId, $priors, $poisById, $this->geo, $prevZoneKm) !== null) {
                    continue;
                }
                $ids[] = '#'.(int) $p['id'].' '.($p['name'] ?? '')
                    .' (~'.round($km, 0).' км, '.($dur ?? '?').'ч, load='.($load ?? '?').')';
            }
            if ($ids === []) {
                continue;
            }
            $parts[] = 'STAY near '.$base['name'].' #'.$base['id']
                ." (короткие ≤{$radius} км; POI≥{$longH}ч → ≤{$longR} км):\n  "
                .implode("\n  ", array_slice($ids, 0, 24));
        }

        if ($parts === []) {
            return '';
        }

        return "STAY DAYS — POI в радиусе базы (выбор комбинации за LLM, география уже сужена PHP):\n"
            .implode("\n\n", $parts);
    }
}
