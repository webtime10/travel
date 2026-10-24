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
            $direct = $all[0]['direct_route_km'] ?? $this->geo->km($from, $to);
            $directKm = is_numeric($direct) ? round((float) $direct, 1) : null;
            $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120);
            $isLong = $directKm !== null && $directKm >= $longKm;
            $maxCrossLong = (float) config('travel_route_fit.thin_enrich_max_cross_km', 22.0);
            $blockLong = array_fill_keys(
                array_map('intval', (array) config('travel_route_fit.long_transfer_block_poi_ids', [303, 132])),
                true
            );
            $forLlm = array_values(array_filter(
                $all,
                function (array $c) use ($allowed, $isLong, $maxCrossLong, $blockLong) {
                    if (! isset($allowed[$c['route_fit'] ?? ''])) {
                        return false;
                    }
                    $cid = (int) ($c['id'] ?? 0);
                    if ($cid > 0 && isset($blockLong[$cid])) {
                        return false;
                    }
                    if (! $isLong) {
                        return true;
                    }
                    if (is_numeric($c['cross_track_km'] ?? null)
                        && (float) $c['cross_track_km'] > $maxCrossLong) {
                        return false;
                    }

                    return true;
                }
            ));

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
                $leg = $this->legForNightChange($legs, $prevNightId, $nightId);
                $direct = is_array($leg) && is_numeric($leg['direct_km'] ?? null)
                    ? (float) $leg['direct_km'] : null;
                $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120.0);
                $isLong = $direct !== null && $direct >= $longKm;
                $maxCrossLong = (float) config('travel_route_fit.thin_enrich_max_cross_km', 22.0);
                $blockLong = array_fill_keys(
                    array_map('intval', (array) config('travel_route_fit.long_transfer_block_poi_ids', [303, 132])),
                    true
                );
                $fromFull = is_array($leg['from_full'] ?? null) ? $leg['from_full'] : null;
                $toFull = is_array($leg['to_full'] ?? null) ? $leg['to_full'] : null;
                if ($allowed !== [] || $isLong) {
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
                        // Nardis/Val Genova — всегда с transfer, даже если плечо «короткое» в метриках
                        if ($pid > 0 && isset($blockLong[$pid])) {
                            $removed[] = 'день '.($i + 1).': убран #'.$pid.' '
                                .($place['name'] ?? '').' (transfer block: Nardis/Val Genova-крюк)';
                            continue;
                        }
                        if ($isLong && $fromFull !== null && $toFull !== null
                            && isset($poisById[$pid]['lat'], $poisById[$pid]['lng'])) {
                            $axis = $this->axisMetrics($fromFull, $toFull, $poisById[$pid]);
                            $cross = (float) ($axis['cross_track_km'] ?? 0);
                            if ($cross > $maxCrossLong) {
                                $removed[] = 'день '.($i + 1).': убран #'.$pid.' '
                                    .($place['name'] ?? '').' (long transfer cross '
                                    .round($cross, 1).' км > '.$maxCrossLong.')';
                                continue;
                            }
                        }
                        if ($allowed !== [] && $pid > 0 && ! isset($allowed[$pid])) {
                            $removed[] = 'день '.($i + 1).': убран off-corridor #'
                                .$pid.' '.($place['name'] ?? '').' (не в TRANSFER candidates)';
                            continue;
                        }
                        $keep[] = $place;
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

        // Заезд ≠ Мармолада; якорь+Федая в одном stay
        $marmoCoh = $this->scrubMarmoladaPackageCohesion($plan, $poisById);
        $plan = $marmoCoh['plan'];
        $removed = array_merge($removed, $marmoCoh['removed']);

        // Braies с transfer прибытия → следующий stay той же базы (потом pair с Misurina)
        $movePair = $this->relocateStayPairAnchorsFromTransfer($plan, $poisById);
        $plan = $movePair['plan'];
        $removed = array_merge($removed, $movePair['removed']);

        $stayScrub = $this->scrubStayDaySequences($plan, $poisById);
        $plan = $stayScrub['plan'];
        $removed = array_merge($removed, $stayScrub['removed']);

        // Tervela-класс не клеить к Sella/Pordoi/Marmolada дню
        $valley = $this->scrubValleyShortOnMountainDay($plan, $poisById);
        $plan = $valley['plan'];
        $removed = array_merge($removed, $valley['removed']);

        // Brunico: Braies ↔ Misurina в одном stay
        $pair = $this->enrichStayLinkedPairs($plan, $poisById);
        $plan = $pair['plan'];
        $removed = array_merge($removed, $pair['removed']);

        $dedup = $this->scrubDuplicatePoisAcrossDays($plan);
        $plan = $dedup['plan'];
        $removed = array_merge($removed, $dedup['removed']);

        // 1) Повтор гео-объекта между днями (Pordoi #200/#218 и т.п.)
        $geoDup = $this->scrubGeographicDuplicates($plan, $poisById);
        $plan = $geoDup['plan'];
        $removed = array_merge($removed, $geoDup['removed']);

        // После всех срезов: пустой/тонкий transfer (~1.5ч base) → corridor из CANDIDATES
        $thin = $this->enrichThinTransferDays($plan, $legs, $poisById);
        $plan = $thin['plan'];
        $removed = array_merge($removed, $thin['removed']);

        // Enrich не должен вернуть Sass Pordoi после гео-дубля (RU≠HE имена)
        $geoDup2 = $this->scrubGeographicDuplicates($plan, $poisById);
        $plan = $geoDup2['plan'];
        $removed = array_merge($removed, $geoDup2['removed']);

        // Короткий transfer всё ещё пустой → вернуть Braies-якорь со следующего stay
        $borrow = $this->borrowStayPairAnchorToThinShortTransfer($plan, $poisById);
        $plan = $borrow['plan'];
        $removed = array_merge($removed, $borrow['removed']);

        // После thin-enrich: снова выкинуть Nardis-блок (enrich мог вернуть на «short» 31 км)
        $blockAgain = $this->scrubBlockedTransferPois($plan);
        $plan = $blockAgain['plan'];
        $removed = array_merge($removed, $blockAgain['removed']);

        // Stay > max ч с 2+ activity → вынести самый длинный на соседний stay с запасом
        $over = $this->scrubStayDayOverload($plan, $poisById);
        $plan = $over['plan'];
        $removed = array_merge($removed, $over['removed']);

        // 2) Порядок stop на переезде: по axis progress OLD→NEW
        $ord = $this->reorderTransferStopsByAxis($plan, $legs, $poisById);
        $plan = $ord['plan'];
        $removed = array_merge($removed, $ord['removed']);

        // 3) Лишняя переправа: near-base до crossing; не два crossing подряд
        $cross = $this->scrubLakeCrossingWaste($plan, $poisById);
        $plan = $cross['plan'];
        $removed = array_merge($removed, $cross['removed']);

        $ferry = $this->scrubFerryLimoneCompositeHours($plan);
        $plan = $ferry['plan'];
        $removed = array_merge($removed, $ferry['removed']);

        $depHeavy = $this->scrubDepartureHeavyTrek($plan, $poisById);
        $plan = $depHeavy['plan'];
        $removed = array_merge($removed, $depHeavy['removed']);

        $dep = $this->scrubDepartureDayHours($plan, $query);
        $plan = $dep['plan'];
        $removed = array_merge($removed, $dep['removed']);

        $plan = $this->scrubHourBalanceNotes($plan);

        // Carezza + Sella/Pordoi в одном stay → убрать Carezza (Ferragosto-перегруз)
        $carezza = $this->scrubCarezzaOnHighPassDay($plan);
        $plan = $carezza['plan'];
        $removed = array_merge($removed, $carezza['removed']);

        // Повтор: Marmolada могла остаться на D1, если theme≠заезд
        $marmoCoh2 = $this->scrubMarmoladaPackageCohesion($plan, $poisById);
        $plan = $marmoCoh2['plan'];
        $removed = array_merge($removed, $marmoCoh2['removed']);

        // Long transfer: стоп > short_stop_max → обрезать часы (Molveno 4ч на переезде)
        $trimLong = $this->scrubLongTransferStopHours($plan, $legs, $poisById);
        $plan = $trimLong['plan'];
        $removed = array_merge($removed, $trimLong['removed']);

        $plan = $this->alignDaySummariesWithPlaces($plan, $removed);

        // Иврит из каталога / stay-pair → кириллица в theme/summary/places
        $ruNames = $this->scrubDisplayNamesRu($plan);
        $plan = $ruNames['plan'];
        $removed = array_merge($removed, $ruNames['removed']);

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
            // «Браес» в тексте, а #116 нет в places (уже был на переезде)
            if (! $needsFix && $summary !== ''
                && preg_match('/браес|braies|брайес/ui', $summary)) {
                $has116 = false;
                foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $pl) {
                    if (is_array($pl) && (int) ($pl['id'] ?? 0) === 116) {
                        $has116 = true;
                        break;
                    }
                }
                if (! $has116) {
                    $needsFix = true;
                }
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
     * Часы activity/transfer_stop без base (для ёмкости дня).
     *
     * @param  array<string, mixed>  $day
     */
    private function activityHoursOfDay(array $day): float
    {
        $oid = (int) ($day['overnight']['id'] ?? 0);
        $sum = 0.0;
        foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pid = (int) ($p['id'] ?? 0);
            $role = (string) ($p['role'] ?? '');
            if ($pid === $oid || $role === 'base') {
                continue;
            }
            if (isset($p['hours']) && is_numeric($p['hours'])) {
                $sum += (float) $p['hours'];
            }
        }

        return $sum;
    }

    /**
     * Индексы stay/заезд у базы до transferIdx (не переезд/выезд).
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<int>
     */
    private function stayIndicesAtBaseBefore(array $days, int $baseId, int $transferIdx): array
    {
        $out = [];
        for ($j = 0; $j < $transferIdx; $j++) {
            $pd = $days[$j] ?? null;
            if (! is_array($pd) || (int) ($pd['overnight']['id'] ?? 0) !== $baseId) {
                continue;
            }
            $pt = mb_strtolower((string) ($pd['theme'] ?? ''));
            if (str_contains($pt, 'переезд') || str_contains($pt, 'transfer')
                || str_contains($pt, 'выезд') || str_contains($pt, 'departure')) {
                continue;
            }
            $out[] = $j;
        }

        return $out;
    }

    /**
     * Stay с запасом часов под addHours; иначе null → нужен новый день.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  list<int>  $stayIndices
     */
    private function pickStayIdxWithSpareHours(array $days, array $stayIndices, float $addHours, float $maxDayH): ?int
    {
        $best = null;
        $bestLoad = 999.0;
        foreach ($stayIndices as $idx) {
            $day = $days[$idx] ?? null;
            if (! is_array($day)) {
                continue;
            }
            // Заезд никогда не принимает Marmolada-пакет («запас часов» на arrival — ловушка)
            if ($this->isArrivalDay($day)) {
                continue;
            }
            $load = $this->activityHoursOfDay($day);
            if ($load + $addHours <= $maxDayH + 0.05 && $load < $bestLoad) {
                $best = $idx;
                $bestLoad = $load;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function isArrivalDay(array $day): bool
    {
        $t = mb_strtolower((string) ($day['theme'] ?? ''));

        return str_contains($t, 'заезд') || str_contains($t, 'arrival');
    }

    /**
     * @param  list<array<string, mixed>>  $packagePlaces
     * @param  array<string, mixed>  $overnight
     * @return array<string, mixed>
     */
    private function makePackageStayDay(array $packagePlaces, array $overnight, string $dateHint = ''): array
    {
        $places = [];
        foreach ($packagePlaces as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pid = (int) ($p['id'] ?? 0);
            $p['role'] = 'activity';
            $p['name'] = $this->displayNameRu($pid, (string) ($p['name'] ?? ''));
            $places[] = $p;
        }
        $oid = (int) ($overnight['id'] ?? 0);

        return [
            'date' => $dateHint !== '' ? $dateHint : 'день',
            'theme' => 'stay',
            'summary' => 'Высокогорный день: канатная дорога Мармолада и озеро Федая.',
            'overnight' => [
                'id' => $oid,
                'name' => $this->displayNameRu($oid, (string) ($overnight['name'] ?? '')),
            ],
            'places' => $places,
            'total_hours' => $this->sumPlaceHours($places),
        ];
    }

    /**
     * Короткий transfer: Marmolada/Fedaia → stay у OLD с запасом часов (не на перегруженный день).
     * Нет места → новый stay-день перед переездом.
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
        $packageIds = array_map('intval', (array) config('travel_route_fit.short_transfer_heavy_poi_ids', [187, 456, 129, 652]));
        $packageSet = array_fill_keys(array_filter($packageIds, fn ($id) => $id > 0), true);
        $anchors = array_map('intval', (array) config('travel_route_fit.short_transfer_package_anchor_ids', [456, 187]));
        $companions = array_map('intval', (array) config('travel_route_fit.short_transfer_package_companion_ids', [652]));
        $radius = (float) config('travel_route_fit.stay_radius_km', 70.0);
        $maxDayH = (float) config('travel_route_fit.package_stay_max_day_hours', 7.0);
        $removed = [];
        $days = $plan['days'];
        $prevNightId = null;

        for ($i = 0; $i < count($days); $i++) {
            $day = $days[$i];
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
                    $oldBaseId = $prevNightId;
                    $oldBase = $poisById[$oldBaseId] ?? null;
                    $oldOvernight = is_array($days[$i - 1]['overnight'] ?? null)
                        ? $days[$i - 1]['overnight']
                        : ['id' => $oldBaseId, 'name' => (string) ($oldBase['name'] ?? '')];
                    // overnight с предыдущего stay той же базы
                    $stayIndices = $this->stayIndicesAtBaseBefore($days, $oldBaseId, $i);
                    if ($stayIndices !== []) {
                        $lastStay = $stayIndices[count($stayIndices) - 1];
                        $oldOvernight = is_array($days[$lastStay]['overnight'] ?? null)
                            ? $days[$lastStay]['overnight']
                            : $oldOvernight;
                    }

                    $places = is_array($day['places'] ?? null) ? $day['places'] : [];
                    $package = [];
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
                        if (isset($packageSet[$pid])) {
                            $package[] = $p;
                        } else {
                            $other[] = $p;
                        }
                    }

                    $pkgHours = 0.0;
                    foreach ($package as $h) {
                        $pkgHours += is_numeric($h['hours'] ?? null) ? (float) $h['hours'] : 0.0;
                    }

                    if ($package !== []) {
                        $stayIdx = $this->pickStayIdxWithSpareHours($days, $stayIndices, $pkgHours, $maxDayH);
                        if ($stayIdx !== null) {
                            $stayPlaces = is_array($days[$stayIdx]['places'] ?? null)
                                ? $days[$stayIdx]['places'] : [];
                            $have = [];
                            foreach ($stayPlaces as $sp) {
                                if (is_array($sp)) {
                                    $have[(int) ($sp['id'] ?? 0)] = true;
                                }
                            }
                            foreach ($package as $h) {
                                $hid = (int) ($h['id'] ?? 0);
                                if (isset($have[$hid])) {
                                    $removed[] = 'день '.($i + 1).': убран package #'
                                        .$hid.' с короткого transfer (уже в stay день '.($stayIdx + 1).')';
                                    continue;
                                }
                                $h['role'] = 'activity';
                                $stayPlaces[] = $h;
                                $have[$hid] = true;
                                $removed[] = 'день '.($i + 1).'→'.($stayIdx + 1).': #'
                                    .$hid.' '.($h['name'] ?? '')
                                    .' перенесён на stay OLD с запасом часов (не corridor)';
                            }
                            $days[$stayIdx]['places'] = $stayPlaces;
                            $days[$stayIdx]['total_hours'] = $this->sumPlaceHours($stayPlaces);
                        } else {
                            $newDay = $this->makePackageStayDay($package, $oldOvernight, (string) ($day['date'] ?? ''));
                            array_splice($days, $i, 0, [$newDay]);
                            $removed[] = 'день '.($i + 1).': вставлен stay Marmolada/Fedaia перед переездом '
                                .'(существующие stay перегружены, лимит '.$maxDayH.' ч)';
                            $i++; // transfer сдвинулся
                        }
                        $days[$i]['places'] = array_merge($other, $bases);
                        $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
                        // обновить индексы stay после возможного splice
                        $stayIndices = $this->stayIndicesAtBaseBefore($days, $oldBaseId, $i);
                    }

                    // Пакет нигде у OLD не использован → добить stay с запасом / новый день
                    if ($oldBase !== null) {
                        $usedInOldStays = [];
                        foreach ($this->stayIndicesAtBaseBefore($days, $oldBaseId, $i) as $j) {
                            foreach (is_array($days[$j]['places'] ?? null) ? $days[$j]['places'] : [] as $sp) {
                                if (is_array($sp)) {
                                    $usedInOldStays[(int) ($sp['id'] ?? 0)] = true;
                                }
                            }
                        }
                        $hasAnchor = false;
                        foreach ($anchors as $aid) {
                            if (isset($usedInOldStays[$aid])) {
                                $hasAnchor = true;
                                break;
                            }
                        }
                        $hasCompanion = false;
                        foreach ($companions as $cid) {
                            if (isset($usedInOldStays[$cid])) {
                                $hasCompanion = true;
                                break;
                            }
                        }
                        if (! $hasAnchor || ! $hasCompanion) {
                            $toAdd = [];
                            $addHours = 0.0;
                            if (! $hasAnchor) {
                                foreach ($anchors as $aid) {
                                    if ($aid > 0 && isset($poisById[$aid]) && ! isset($usedInOldStays[$aid])) {
                                        $toAdd[] = $aid;
                                        $addHours += is_numeric($poisById[$aid]['poi_duration'] ?? null)
                                            ? (float) $poisById[$aid]['poi_duration'] : 4.0;
                                        break;
                                    }
                                }
                            }
                            if (! $hasCompanion) {
                                foreach ($companions as $cid) {
                                    if ($cid > 0 && isset($poisById[$cid]) && ! isset($usedInOldStays[$cid])) {
                                        $toAdd[] = $cid;
                                        $addHours += is_numeric($poisById[$cid]['poi_duration'] ?? null)
                                            ? (float) $poisById[$cid]['poi_duration'] : 1.5;
                                        break;
                                    }
                                }
                            }
                            $addPlaces = [];
                            foreach ($toAdd as $tid) {
                                $src = $poisById[$tid];
                                $km = $this->geo->km($oldBase, $src);
                                if ($km === null || $km > $radius) {
                                    continue;
                                }
                                $hours = is_numeric($src['poi_duration'] ?? null)
                                    ? (float) $src['poi_duration'] : 2.0;
                                $addPlaces[] = [
                                    'id' => $tid,
                                    'name' => (string) ($src['name'] ?? ''),
                                    'hours' => $hours,
                                    'role' => 'activity',
                                    'note' => 'PHP: Marmolada-пакет на stay OLD до короткого transfer',
                                ];
                            }
                            if ($addPlaces !== []) {
                                $stayIndices = $this->stayIndicesAtBaseBefore($days, $oldBaseId, $i);
                                $realAddH = 0.0;
                                foreach ($addPlaces as $ap) {
                                    $realAddH += (float) ($ap['hours'] ?? 0);
                                }
                                $stayIdx = $this->pickStayIdxWithSpareHours($days, $stayIndices, $realAddH, $maxDayH);
                                if ($stayIdx !== null) {
                                    $stayPlaces = is_array($days[$stayIdx]['places'] ?? null)
                                        ? $days[$stayIdx]['places'] : [];
                                    foreach ($addPlaces as $ap) {
                                        $stayPlaces[] = $ap;
                                        $removed[] = 'день '.($stayIdx + 1).': добавлен #'.$ap['id'].' '
                                            .$ap['name']
                                            .' (Marmolada-пакет на stay с запасом часов)';
                                    }
                                    $days[$stayIdx]['places'] = $stayPlaces;
                                    $days[$stayIdx]['total_hours'] = $this->sumPlaceHours($stayPlaces);
                                } else {
                                    $newDay = $this->makePackageStayDay($addPlaces, $oldOvernight, (string) ($day['date'] ?? ''));
                                    array_splice($days, $i, 0, [$newDay]);
                                    $removed[] = 'день '.($i + 1).': вставлен stay под Marmolada-пакет '
                                        .'(лимит '.$maxDayH.' ч на существующих днях)';
                                    $i++;
                                }
                            }
                        }
                    }

                    // Также: если какой-то stay уже перегружен пакетом+долиной — вынести пакет в отдельный день
                    $split = $this->splitOverloadedPackageStay($days, $oldBaseId, $i, $packageSet, $oldOvernight, $maxDayH);
                    $days = $split['days'];
                    $removed = array_merge($removed, $split['removed']);
                    $i = $split['transfer_idx'];

                    $placesNow = is_array($days[$i]['places'] ?? null) ? $days[$i]['places'] : [];
                    $hasNewBase = false;
                    foreach ($placesNow as $p) {
                        if (is_array($p) && (int) ($p['id'] ?? 0) === $nightId) {
                            $hasNewBase = true;
                            break;
                        }
                    }
                    if (! $hasNewBase && isset($poisById[$nightId])) {
                        $nb = $poisById[$nightId];
                        $placesNow[] = [
                            'id' => $nightId,
                            'name' => (string) ($day['overnight']['name'] ?? $nb['name'] ?? ''),
                            'hours' => 1.5,
                            'role' => 'base',
                        ];
                        $days[$i]['places'] = $placesNow;
                        $days[$i]['total_hours'] = $this->sumPlaceHours($placesNow);
                        $removed[] = 'день '.($i + 1).': добавлен base #'.$nightId
                            .' (прибытие на короткий transfer)';
                    }
                }
            }
            $nightId = (int) (($days[$i]['overnight']['id'] ?? 0));
            if ($nightId > 0) {
                $prevNightId = $nightId;
            }
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Stay у OLD: activity > max и есть Marmolada-пакет + другие POI → вынести пакет в новый день.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, true>  $packageSet
     * @param  array<string, mixed>  $oldOvernight
     * @return array{days: list<array<string, mixed>>, removed: list<string>, transfer_idx: int}
     */
    private function splitOverloadedPackageStay(
        array $days,
        int $oldBaseId,
        int $transferIdx,
        array $packageSet,
        array $oldOvernight,
        float $maxDayH,
    ): array {
        $removed = [];
        foreach ($this->stayIndicesAtBaseBefore($days, $oldBaseId, $transferIdx) as $j) {
            $day = $days[$j];
            if (! is_array($day)) {
                continue;
            }
            $load = $this->activityHoursOfDay($day);
            if ($load <= $maxDayH + 0.05) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $pkg = [];
            $rest = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $oid || $role === 'base') {
                    $rest[] = $p;
                    continue;
                }
                if (isset($packageSet[$pid])) {
                    $pkg[] = $p;
                } else {
                    $rest[] = $p;
                }
            }
            if ($pkg === [] || $rest === []) {
                continue;
            }
            // есть и пакет, и другие activity
            $hasOtherAct = false;
            foreach ($rest as $p) {
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid !== $oid && $role !== 'base') {
                    $hasOtherAct = true;
                    break;
                }
            }
            if (! $hasOtherAct) {
                continue;
            }
            $days[$j]['places'] = $rest;
            $days[$j]['total_hours'] = $this->sumPlaceHours($rest);
            $newDay = $this->makePackageStayDay($pkg, $oldOvernight, (string) ($day['date'] ?? ''));
            array_splice($days, $j + 1, 0, [$newDay]);
            $transferIdx++;
            $removed[] = 'день '.($j + 1).': Marmolada-пакет вынесен в новый stay (было ~'
                .round($load, 1).' ч > лимит '.$maxDayH.' ч)';
            break;
        }

        return ['days' => $days, 'removed' => $removed, 'transfer_idx' => $transferIdx];
    }

    /**
     * Marmolada-пакет: не на заезд; якорь (#456/#187) и Федая (#652) в одном stay-дне.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubMarmoladaPackageCohesion(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $anchors = array_fill_keys(
            array_filter(array_map('intval', (array) config('travel_route_fit.short_transfer_package_anchor_ids', [456, 187]))),
            true
        );
        $companions = array_fill_keys(
            array_filter(array_map('intval', (array) config('travel_route_fit.short_transfer_package_companion_ids', [652]))),
            true
        );
        $packageSet = $anchors + $companions;
        if ($packageSet === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $maxDayH = (float) config('travel_route_fit.package_stay_max_day_hours', 7.0);
        $removed = [];
        $days = $plan['days'];
        $n = count($days);

        // 1) Снять пакет с заезда / первого дня / дня «долина+ледник»
        $valleyShort = array_fill_keys(
            array_map('intval', (array) config('travel_route_fit.arrival_light_poi_ids', [779, 646, 156, 348, 362])),
            true
        );
        $orphans = [];
        for ($i = 0; $i < $n; $i++) {
            $day = $days[$i];
            if (! is_array($day)) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $hasPackage = false;
            $hasValley = false;
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                if (isset($packageSet[$pid])) {
                    $hasPackage = true;
                }
                if (isset($valleyShort[$pid])) {
                    $hasValley = true;
                }
            }
            $stripHere = $this->isArrivalDay($day)
                || $i === 0 // первый день тура — всегда лёгкий
                || ($hasPackage && $hasValley);
            if (! $stripHere || ! $hasPackage) {
                continue;
            }
            $keep = [];
            $moved = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid > 0 && isset($packageSet[$pid]) && $role !== 'base' && $pid !== $oid) {
                    $p['role'] = 'activity';
                    $moved[] = $p;
                    $why = $this->isArrivalDay($day) || $i === 0
                        ? 'с заезда/дня 1 (Marmolada ≠ прибытие)'
                        : 'с дня долина+ледник (Tervela/Alpacas ≠ Marmolada)';
                    $removed[] = 'день '.($i + 1).': убран #'.$pid.' '.($p['name'] ?? '').' '.$why;
                    continue;
                }
                $keep[] = $p;
            }
            if ($moved === []) {
                continue;
            }
            $days[$i]['places'] = $keep;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
            $orphans[$oid] = array_merge($orphans[$oid] ?? [], $moved);
        }

        // 2) Собрать, где сейчас лежат куски пакета (по overnight)
        /** @var array<int, array{anchor: list<array>, companion: list<array>, days: array<int, true>}> */
        $byBase = [];
        for ($i = 0; $i < count($days); $i++) {
            $day = $days[$i];
            if (! is_array($day)) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                if (! isset($packageSet[$pid])) {
                    continue;
                }
                if (! isset($byBase[$oid])) {
                    $byBase[$oid] = ['anchor' => [], 'companion' => [], 'days' => []];
                }
                $byBase[$oid]['days'][$i] = true;
                if (isset($anchors[$pid])) {
                    $byBase[$oid]['anchor'][] = ['day' => $i, 'place' => $p];
                }
                if (isset($companions[$pid])) {
                    $byBase[$oid]['companion'][] = ['day' => $i, 'place' => $p];
                }
            }
        }
        foreach ($orphans as $oid => $plist) {
            if (! isset($byBase[$oid])) {
                $byBase[$oid] = ['anchor' => [], 'companion' => [], 'days' => []];
            }
            foreach ($plist as $p) {
                $pid = (int) ($p['id'] ?? 0);
                if (isset($anchors[$pid])) {
                    $byBase[$oid]['anchor'][] = ['day' => -1, 'place' => $p];
                }
                if (isset($companions[$pid])) {
                    $byBase[$oid]['companion'][] = ['day' => -1, 'place' => $p];
                }
            }
        }

        foreach ($byBase as $oid => $bag) {
            $hasA = $bag['anchor'] !== [];
            $hasC = $bag['companion'] !== [];
            if (! $hasA && ! $hasC) {
                continue;
            }
            // Цель: один non-arrival stay с обоими; иначе день с companion / с spare / новый
            $target = $this->pickMarmoladaPackageTargetDay($days, $oid, $bag, $maxDayH);
            if ($target === null) {
                $overnight = ['id' => $oid, 'name' => $this->displayNameRu($oid, (string) ($poisById[$oid]['name'] ?? ''))];
                $packPlaces = [];
                $seen = [];
                foreach (array_merge($bag['anchor'], $bag['companion']) as $item) {
                    $p = $item['place'];
                    $pid = (int) ($p['id'] ?? 0);
                    if ($pid <= 0 || isset($seen[$pid])) {
                        continue;
                    }
                    $seen[$pid] = true;
                    $p['name'] = $this->displayNameRu($pid, (string) ($p['name'] ?? ''));
                    $p['role'] = 'activity';
                    $packPlaces[] = $p;
                }
                // добрать недостающее из каталога
                if (! $hasA) {
                    foreach (array_keys($anchors) as $aid) {
                        if (isset($poisById[$aid]) && ! isset($seen[$aid])) {
                            $packPlaces[] = [
                                'id' => $aid,
                                'name' => $this->displayNameRu($aid, (string) ($poisById[$aid]['name'] ?? '')),
                                'hours' => is_numeric($poisById[$aid]['poi_duration'] ?? null)
                                    ? (float) $poisById[$aid]['poi_duration'] : 4.0,
                                'role' => 'activity',
                                'note' => 'PHP: Marmolada к Федае',
                            ];
                            break;
                        }
                    }
                }
                if (! $hasC) {
                    foreach (array_keys($companions) as $cid) {
                        if (isset($poisById[$cid]) && ! isset($seen[$cid])) {
                            $packPlaces[] = [
                                'id' => $cid,
                                'name' => $this->displayNameRu($cid, (string) ($poisById[$cid]['name'] ?? '')),
                                'hours' => is_numeric($poisById[$cid]['poi_duration'] ?? null)
                                    ? (float) $poisById[$cid]['poi_duration'] : 1.5,
                                'role' => 'activity',
                                'note' => 'PHP: Федая к Мармоладе',
                            ];
                            break;
                        }
                    }
                }
                // снять куски со старых дней
                $days = $this->stripPackageIdsFromDays($days, $oid, $packageSet, $removed);
                $insertAt = $this->firstTransferOrEndAtBase($days, $oid);
                $newDay = $this->makePackageStayDay($packPlaces, $overnight);
                array_splice($days, $insertAt, 0, [$newDay]);
                $removed[] = 'день '.($insertAt + 1).': собран stay Marmolada+Федая (пакет был разорван / на заезде)';
                continue;
            }

            // Снять пакет со всех дней этой базы и собрать на target
            $collect = [];
            $seen = [];
            for ($i = 0; $i < count($days); $i++) {
                $day = $days[$i];
                if (! is_array($day) || (int) ($day['overnight']['id'] ?? 0) !== $oid) {
                    continue;
                }
                $keep = [];
                foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $pid = (int) ($p['id'] ?? 0);
                    $role = (string) ($p['role'] ?? '');
                    if ($pid > 0 && isset($packageSet[$pid]) && $role !== 'base') {
                        if (! isset($seen[$pid])) {
                            $p['name'] = $this->displayNameRu($pid, (string) ($p['name'] ?? ''));
                            $p['role'] = 'activity';
                            $collect[] = $p;
                            $seen[$pid] = true;
                            if ($i !== $target) {
                                $removed[] = 'день '.($i + 1).'→'.($target + 1).': #'.$pid
                                    .' '.($p['name'] ?? '').' в пакет Marmolada+Федая';
                            }
                        }
                        continue;
                    }
                    $keep[] = $p;
                }
                $days[$i]['places'] = $keep;
                $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
            }
            // orphans already stripped from arrival; merge into collect
            foreach ($orphans[$oid] ?? [] as $p) {
                $pid = (int) ($p['id'] ?? 0);
                if ($pid > 0 && ! isset($seen[$pid])) {
                    $p['name'] = $this->displayNameRu($pid, (string) ($p['name'] ?? ''));
                    $collect[] = $p;
                    $seen[$pid] = true;
                    $removed[] = 'день '.($target + 1).': #'.$pid.' с заезда → пакет Marmolada+Федая';
                }
            }
            $haveAnchor = false;
            foreach (array_keys($anchors) as $aid) {
                if (isset($seen[$aid])) {
                    $haveAnchor = true;
                    break;
                }
            }
            if (! $haveAnchor) {
                foreach (array_keys($anchors) as $aid) {
                    if (! isset($poisById[$aid])) {
                        continue;
                    }
                    $collect[] = [
                        'id' => $aid,
                        'name' => $this->displayNameRu($aid, (string) ($poisById[$aid]['name'] ?? '')),
                        'hours' => is_numeric($poisById[$aid]['poi_duration'] ?? null)
                            ? (float) $poisById[$aid]['poi_duration'] : 4.0,
                        'role' => 'activity',
                        'note' => 'PHP: Marmolada к Федае',
                    ];
                    $seen[$aid] = true;
                    $removed[] = 'день '.($target + 1).': добавлен #'.$aid.' (склейка пакета с Федаей)';
                    break;
                }
            }
            $haveCompanion = false;
            foreach (array_keys($companions) as $cid) {
                if (isset($seen[$cid])) {
                    $haveCompanion = true;
                    break;
                }
            }
            if (! $haveCompanion) {
                foreach (array_keys($companions) as $cid) {
                    if (! isset($poisById[$cid])) {
                        continue;
                    }
                    $collect[] = [
                        'id' => $cid,
                        'name' => $this->displayNameRu($cid, (string) ($poisById[$cid]['name'] ?? '')),
                        'hours' => is_numeric($poisById[$cid]['poi_duration'] ?? null)
                            ? (float) $poisById[$cid]['poi_duration'] : 1.5,
                        'role' => 'activity',
                        'note' => 'PHP: Федая к Мармоладе',
                    ];
                    $removed[] = 'день '.($target + 1).': добавлен #'.$cid.' (склейка пакета с Мармоладой)';
                    break;
                }
            }
            $tp = is_array($days[$target]['places'] ?? null) ? $days[$target]['places'] : [];
            $haveOnTarget = [];
            foreach ($tp as $p) {
                if (is_array($p)) {
                    $haveOnTarget[(int) ($p['id'] ?? 0)] = true;
                }
            }
            foreach ($collect as $p) {
                $pid = (int) ($p['id'] ?? 0);
                if ($pid <= 0 || isset($haveOnTarget[$pid])) {
                    continue;
                }
                $tp[] = $p;
                $haveOnTarget[$pid] = true;
            }
            $days[$target]['places'] = $tp;
            $days[$target]['total_hours'] = $this->sumPlaceHours($tp);
            if ($this->isArrivalDay($days[$target])) {
                // safety: should not happen
                $days[$target]['theme'] = 'stay';
            }
            // summary без «Браес» если его нет — align later; here fix package day blurb
            $days[$target]['summary'] = 'Высокогорный день: канатная дорога Мармолада и озеро Федая.';
            $days[$target]['theme'] = 'stay';
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array{anchor: list<array>, companion: list<array>, days: array<int, true>}  $bag
     */
    private function pickMarmoladaPackageTargetDay(array $days, int $oid, array $bag, float $maxDayH): ?int
    {
        // Предпочесть день, где уже Федая (companion), не заезд
        foreach ($bag['companion'] as $item) {
            $di = (int) ($item['day'] ?? -1);
            if ($di >= 0 && isset($days[$di]) && ! $this->isArrivalDay($days[$di])) {
                return $di;
            }
        }
        // День с якорем, если не заезд и не перегружен другими
        foreach ($bag['anchor'] as $item) {
            $di = (int) ($item['day'] ?? -1);
            if ($di >= 0 && isset($days[$di]) && ! $this->isArrivalDay($days[$di])) {
                return $di;
            }
        }
        // Любой stay у базы с запасом
        $indices = [];
        foreach ($days as $i => $day) {
            if (! is_array($day) || (int) ($day['overnight']['id'] ?? 0) !== $oid) {
                continue;
            }
            $t = mb_strtolower((string) ($day['theme'] ?? ''));
            if (str_contains($t, 'переезд') || str_contains($t, 'transfer')
                || str_contains($t, 'выезд') || str_contains($t, 'departure')
                || $this->isArrivalDay($day)) {
                continue;
            }
            $indices[] = $i;
        }
        $needH = 5.5; // ~456+652

        return $this->pickStayIdxWithSpareHours($days, $indices, $needH, $maxDayH);
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, true>  $packageSet
     * @param  list<string>  $removed
     * @return list<array<string, mixed>>
     */
    private function stripPackageIdsFromDays(array $days, int $oid, array $packageSet, array &$removed): array
    {
        foreach ($days as $i => $day) {
            if (! is_array($day) || (int) ($day['overnight']['id'] ?? 0) !== $oid) {
                continue;
            }
            $keep = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid > 0 && isset($packageSet[$pid]) && $role !== 'base') {
                    $removed[] = 'день '.($i + 1).': снят #'.$pid.' перед сборкой пакета Marmolada+Федая';
                    continue;
                }
                $keep[] = $p;
            }
            $days[$i]['places'] = $keep;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function firstTransferOrEndAtBase(array $days, int $oid): int
    {
        $lastAtBase = 0;
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            if ((int) ($day['overnight']['id'] ?? 0) === $oid) {
                $lastAtBase = $i + 1;
            }
            $t = mb_strtolower((string) ($day['theme'] ?? ''));
            if ((str_contains($t, 'переезд') || str_contains($t, 'transfer'))
                && (int) ($day['overnight']['id'] ?? 0) !== $oid) {
                return $i;
            }
        }

        return $lastAtBase > 0 ? $lastAtBase : count($days);
    }

    /**
     * Якорь stay-pair (Braies) с transfer-дня прибытия → следующий stay той же overnight.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function relocateStayPairAnchorsFromTransfer(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $moveIds = array_map('intval', (array) config('travel_route_fit.stay_pair_move_from_transfer', [116]));
        $moveSet = array_fill_keys(array_filter($moveIds, fn ($id) => $id > 0), true);
        if ($moveSet === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120.0);
        $removed = [];
        $days = $plan['days'];
        $n = count($days);
        $prevOid = null;

        for ($i = 0; $i < $n; $i++) {
            $day = $days[$i];
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (! str_contains($theme, 'переезд') && ! str_contains($theme, 'transfer')) {
                $oidSkip = (int) ($day['overnight']['id'] ?? 0);
                if ($oidSkip > 0) {
                    $prevOid = $oidSkip;
                }
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            $keep = [];
            $moved = [];
            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid > 0 && isset($moveSet[$pid]) && $role !== 'base' && $pid !== $oid) {
                    $moved[] = $p;
                    continue;
                }
                $keep[] = $p;
            }
            if ($moved === []) {
                $prevOid = $oid;
                continue;
            }
            // Короткий NE-переезд (Ortisei→Brunico): Braies и есть corridor.
            // Унос на stay + geo-dup Pordoi → пустой день (~1.5ч base).
            $direct = null;
            if ($prevOid !== null && isset($poisById[$prevOid], $poisById[$oid])) {
                $direct = $this->geo->km($poisById[$prevOid], $poisById[$oid]);
            }
            if ($direct !== null && $direct < $longKm) {
                $removed[] = 'день '.($i + 1).': Braies-класс оставлен на коротком transfer (direct '
                    .round($direct, 1).' км; иначе пустой коридор после geo-dup)';
                $prevOid = $oid;
                continue;
            }
            // Не оставлять transfer совсем без corridor stop (base ≠ stop)
            $corridorLeft = 0;
            foreach ($keep as $kp) {
                if (! is_array($kp)) {
                    continue;
                }
                $kpid = (int) ($kp['id'] ?? 0);
                $krole = (string) ($kp['role'] ?? '');
                if ($kpid > 0 && $krole !== 'base' && $kpid !== $oid) {
                    $corridorLeft++;
                }
            }
            if ($corridorLeft === 0) {
                $removed[] = 'день '.($i + 1).': Braies-класс оставлен на transfer (единственный corridor stop; иначе пустой переезд)';
                $prevOid = $oid;
                continue;
            }
            // следующий stay с той же ночёвкой
            $stayIdx = null;
            for ($j = $i + 1; $j < $n; $j++) {
                $nd = $days[$j];
                if (! is_array($nd)) {
                    continue;
                }
                $nt = mb_strtolower((string) ($nd['theme'] ?? ''));
                if (str_contains($nt, 'переезд') || str_contains($nt, 'transfer')
                    || str_contains($nt, 'выезд') || str_contains($nt, 'departure')) {
                    continue;
                }
                if ((int) ($nd['overnight']['id'] ?? 0) === $oid) {
                    $stayIdx = $j;
                    break;
                }
            }
            if ($stayIdx === null) {
                $prevOid = $oid;
                continue;
            }
            $days[$i]['places'] = $keep;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
            $stayPlaces = is_array($days[$stayIdx]['places'] ?? null) ? $days[$stayIdx]['places'] : [];
            $have = [];
            foreach ($stayPlaces as $sp) {
                if (is_array($sp)) {
                    $have[(int) ($sp['id'] ?? 0)] = true;
                }
            }
            foreach ($moved as $m) {
                $mid = (int) ($m['id'] ?? 0);
                if (isset($have[$mid])) {
                    $removed[] = 'день '.($i + 1).': убран duplicate #'.$mid
                        .' с transfer (уже в stay день '.($stayIdx + 1).')';
                    continue;
                }
                $m['role'] = 'activity';
                $stayPlaces[] = $m;
                $have[$mid] = true;
                $removed[] = 'день '.($i + 1).'→'.($stayIdx + 1).': #'
                    .$mid.' '.($m['name'] ?? '')
                    .' перенесён с transfer на stay (связка Braies/Misurina у базы #'.$oid.')';
            }
            $days[$stayIdx]['places'] = $stayPlaces;
            $days[$stayIdx]['total_hours'] = $this->sumPlaceHours($stayPlaces);
            $prevOid = $oid;
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * После geo-dup/enrich: короткий transfer без corridor → забрать Braies-якорь
     * со следующего stay той же overnight (Misurina может остаться на stay).
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function borrowStayPairAnchorToThinShortTransfer(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $anchorIds = array_map('intval', (array) config('travel_route_fit.stay_pair_move_from_transfer', [116]));
        $anchorSet = array_fill_keys(array_filter($anchorIds, fn ($id) => $id > 0), true);
        if ($anchorSet === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120.0);
        $minActH = (float) config('travel_route_fit.thin_transfer_min_activity_hours', 2.0);
        $removed = [];
        $days = $plan['days'];
        $n = count($days);
        $prevOid = null;

        for ($i = 0; $i < $n; $i++) {
            $day = $days[$i];
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            if (! $isTransfer) {
                if ($oid > 0) {
                    $prevOid = $oid;
                }
                continue;
            }
            if ($oid <= 0 || $prevOid === null || ! isset($poisById[$prevOid], $poisById[$oid])) {
                if ($oid > 0) {
                    $prevOid = $oid;
                }
                continue;
            }
            $direct = $this->geo->km($poisById[$prevOid], $poisById[$oid]);
            if ($direct === null || $direct >= $longKm) {
                $prevOid = $oid;
                continue;
            }
            $actH = $this->activityHoursOfDay($day);
            if ($actH + 0.05 >= $minActH) {
                $prevOid = $oid;
                continue;
            }
            // уже есть якорь на transfer
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            foreach ($places as $p) {
                if (is_array($p) && isset($anchorSet[(int) ($p['id'] ?? 0)])) {
                    $prevOid = $oid;
                    continue 2;
                }
            }
            $stayIdx = null;
            for ($j = $i + 1; $j < $n; $j++) {
                $nd = $days[$j];
                if (! is_array($nd)) {
                    continue;
                }
                $nt = mb_strtolower((string) ($nd['theme'] ?? ''));
                if (str_contains($nt, 'переезд') || str_contains($nt, 'transfer')
                    || str_contains($nt, 'выезд') || str_contains($nt, 'departure')) {
                    continue;
                }
                if ((int) ($nd['overnight']['id'] ?? 0) === $oid) {
                    $stayIdx = $j;
                    break;
                }
            }
            if ($stayIdx === null) {
                $prevOid = $oid;
                continue;
            }
            $stayPlaces = is_array($days[$stayIdx]['places'] ?? null) ? $days[$stayIdx]['places'] : [];
            $borrow = null;
            $stayKeep = [];
            foreach ($stayPlaces as $sp) {
                if (! is_array($sp)) {
                    continue;
                }
                $spid = (int) ($sp['id'] ?? 0);
                if ($borrow === null && isset($anchorSet[$spid])) {
                    $borrow = $sp;
                    continue;
                }
                $stayKeep[] = $sp;
            }
            if ($borrow === null) {
                $prevOid = $oid;
                continue;
            }
            // stay не оставлять пустым activity (Misurina должна остаться)
            $stayAct = $this->activityHoursOfDay([
                'overnight' => $days[$stayIdx]['overnight'] ?? [],
                'places' => $stayKeep,
            ]);
            if ($stayAct < 0.05) {
                $prevOid = $oid;
                continue;
            }
            $borrow['role'] = 'transfer_stop';
            $places[] = $borrow;
            $days[$i]['places'] = $places;
            $days[$i]['total_hours'] = $this->sumPlaceHours($places);
            $days[$stayIdx]['places'] = $stayKeep;
            $days[$stayIdx]['total_hours'] = $this->sumPlaceHours($stayKeep);
            $removed[] = 'день '.($i + 1).'←'.($stayIdx + 1).': #'
                .(int) ($borrow['id'] ?? 0).' '.($borrow['name'] ?? '')
                .' возвращён на короткий thin transfer (stay сохраняет пару/остаток)';
            $prevOid = $oid;
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Valley-short у базы + high-pass в том же дне → убрать short (Tervela ≠ Sella/Pordoi).
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubValleyShortOnMountainDay(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $poisById === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $maxH = (float) config('travel_route_fit.valley_short_max_hours', 1.0);
        $maxKm = (float) config('travel_route_fit.valley_short_max_km_from_base', 10.0);
        $highIds = array_fill_keys(
            array_map('intval', (array) config('travel_route_fit.high_pass_poi_ids', [])),
            true
        );
        if ($highIds === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $removed = [];
        $days = $plan['days'];

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (str_contains($theme, 'переезд') || str_contains($theme, 'transfer')
                || str_contains($theme, 'выезд') || str_contains($theme, 'departure')
                || str_contains($theme, 'заезд') || str_contains($theme, 'arrival')) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $base = $poisById[$oid] ?? null;
            if ($oid <= 0 || $base === null) {
                continue;
            }
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            $hasHigh = false;
            foreach ($places as $p) {
                if (is_array($p) && isset($highIds[(int) ($p['id'] ?? 0)])) {
                    $hasHigh = true;
                    break;
                }
            }
            if (! $hasHigh) {
                continue;
            }
            $keep = [];
            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $oid || $role === 'base' || isset($highIds[$pid])) {
                    $keep[] = $p;
                    continue;
                }
                $src = $poisById[$pid] ?? null;
                $hours = is_numeric($p['hours'] ?? null) ? (float) $p['hours']
                    : (is_numeric($src['poi_duration'] ?? null) ? (float) $src['poi_duration'] : 99);
                $km = ($src !== null) ? $this->geo->km($base, $src) : null;
                if ($hours <= $maxH && $km !== null && $km <= $maxKm) {
                    $removed[] = 'день '.($i + 1).': убран #'.$pid.' '.($p['name'] ?? '')
                        .' (valley-short у базы + high-pass в том же дне; Tervela≠Sella/Pordoi)';
                    continue;
                }
                $keep[] = $p;
            }
            $days[$i]['places'] = $keep;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Long transfer с <N stop → добить лучший unused candidate из leg.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $legs
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    /**
     * Любой тонкий transfer (часто ~1.5ч только base): добить corridor из CANDIDATES.
     * Short → ≥1 stop; long → ≥2. Не package, не id/имя-дубль уже использованного.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $legs
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function enrichThinTransferDays(array $plan, array $legs, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $legs === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120.0);
        $minLong = max(1, (int) config('travel_route_fit.long_transfer_enrich_min_stops', 2));
        $minShort = max(1, (int) config('travel_route_fit.short_transfer_enrich_min_stops', 1));
        $minActH = (float) config('travel_route_fit.thin_transfer_min_activity_hours', 2.0);
        $heavy = array_fill_keys(
            array_map('intval', (array) config('travel_route_fit.short_transfer_heavy_poi_ids', [])),
            true
        );
        $dupKm = (float) config('travel_route_fit.geo_duplicate_km', 2.0);

        /** @var list<array{id: int, name: string, pt: ?array<string, mixed>}> */
        $usedMeta = [];
        $used = [];
        foreach ($plan['days'] as $day) {
            if (! is_array($day)) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid <= 0 || $pid === $oid || $role === 'base') {
                    continue;
                }
                $used[$pid] = true;
                $src = $poisById[$pid] ?? null;
                $usedMeta[] = [
                    'id' => $pid,
                    'name' => (string) ($p['name'] ?? ($src['name'] ?? '')),
                    'pt' => ($src !== null && isset($src['lat'], $src['lng'])) ? $src : null,
                ];
            }
        }

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
                    ? (float) $leg['direct_km'] : null;
                if ($direct === null) {
                    $from = $poisById[$prevNightId] ?? null;
                    $to = $poisById[$nightId] ?? null;
                    if ($from !== null && $to !== null) {
                        $direct = $this->geo->km($from, $to);
                    }
                }
                if ($direct === null || ! is_array($leg)) {
                    if ($nightId > 0) {
                        $prevNightId = $nightId;
                    }
                    continue;
                }
                $minStops = $direct >= $longKm ? $minLong : $minShort;
                $places = is_array($day['places'] ?? null) ? $day['places'] : [];
                $stops = 0;
                $stopIds = [];
                $actH = 0.0;
                foreach ($places as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $pid = (int) ($p['id'] ?? 0);
                    $role = (string) ($p['role'] ?? '');
                    if ($pid === $nightId || $role === 'base') {
                        continue;
                    }
                    $stops++;
                    $stopIds[$pid] = true;
                    if (isset($p['hours']) && is_numeric($p['hours'])) {
                        $actH += (float) $p['hours'];
                    }
                }
                $needByStops = max(0, $minStops - $stops);
                $needByHours = ($actH + 0.05 < $minActH) ? max(1, $needByStops) : $needByStops;
                if ($needByHours <= 0) {
                    if ($nightId > 0) {
                        $prevNightId = $nightId;
                    }
                    continue;
                }
                $cands = is_array($leg['candidates_llm'] ?? null) ? $leg['candidates_llm'] : [];
                if ($cands === [] && is_array($leg['candidates_all'] ?? null)) {
                    $allowed = array_fill_keys(
                        (array) config('travel_route_fit.llm_allowed_fits', ['excellent', 'good', 'acceptable']),
                        true
                    );
                    foreach ($leg['candidates_all'] as $c) {
                        if (is_array($c) && isset($allowed[(string) ($c['route_fit'] ?? '')])) {
                            $cands[] = $c;
                        }
                    }
                }
                $maxCross = (float) config('travel_route_fit.thin_enrich_max_cross_km', 22.0);
                $blockKm = (float) config('travel_route_fit.thin_enrich_block_km', 2.0);
                $blockLong = array_fill_keys(
                    array_map('intval', (array) config('travel_route_fit.long_transfer_block_poi_ids', [303, 132])),
                    true
                );
                // short: lighter; long: меньший cross/detour (Molveno лучше Nardis-крюка)
                usort($cands, function (array $a, array $b) use ($direct, $longKm) {
                    if ($direct >= $longKm) {
                        $ca = is_numeric($a['cross_track_km'] ?? null) ? (float) $a['cross_track_km'] : 99;
                        $cb = is_numeric($b['cross_track_km'] ?? null) ? (float) $b['cross_track_km'] : 99;
                        if ($ca !== $cb) {
                            return $ca <=> $cb;
                        }
                        $da = is_numeric($a['detour_km'] ?? null) ? (float) $a['detour_km'] : 99;
                        $db = is_numeric($b['detour_km'] ?? null) ? (float) $b['detour_km'] : 99;

                        return $da <=> $db;
                    }
                    $pa = (($a['prefer'] ?? '') === 'lighter_ok') ? 0 : 1;
                    $pb = (($b['prefer'] ?? '') === 'lighter_ok') ? 0 : 1;
                    if ($pa !== $pb) {
                        return $pa <=> $pb;
                    }
                    $ha = is_numeric($a['poi_duration'] ?? null) ? (float) $a['poi_duration'] : 9;
                    $hb = is_numeric($b['poi_duration'] ?? null) ? (float) $b['poi_duration'] : 9;

                    return $ha <=> $hb;
                });
                $need = $needByHours;
                $added = 0;
                foreach ($cands as $c) {
                    if ($need <= 0) {
                        break;
                    }
                    if (! is_array($c)) {
                        continue;
                    }
                    $cid = (int) ($c['id'] ?? 0);
                    $cname = (string) ($c['name'] ?? ($poisById[$cid]['name'] ?? ''));
                    if ($cid <= 0 || isset($used[$cid]) || isset($stopIds[$cid])) {
                        continue;
                    }
                    if (isset($heavy[$cid])) {
                        continue;
                    }
                    // Nardis и аналоги — никогда не thin-enrich (даже на «short» 31 км артефакте)
                    if (isset($blockLong[$cid])) {
                        continue;
                    }
                    if ($direct >= $longKm && is_numeric($c['cross_track_km'] ?? null)
                        && (float) $c['cross_track_km'] > $maxCross) {
                        continue;
                    }
                    // На коротком плече не тащить POI с огромным detour (Nardis-класс)
                    if ($direct < $longKm && is_numeric($c['detour_km'] ?? null)
                        && (float) $c['detour_km'] > max(40.0, $direct * 1.5)) {
                        continue;
                    }
                    $src = $poisById[$cid] ?? null;
                    $blocked = false;
                    foreach ($usedMeta as $um) {
                        if ($this->isConfiguredSameSite((int) $um['id'], $cid)) {
                            $blocked = true;
                            break;
                        }
                        if ($um['pt'] !== null && $src !== null && isset($src['lat'], $src['lng'])) {
                            $km = $this->geo->km($um['pt'], $src);
                            // Не возвращать тот же объект после гео-дубля (Pordoi 0 км, RU≠HE)
                            if ($km !== null && $km <= $blockKm) {
                                $blocked = true;
                                break;
                            }
                        }
                        if ($this->namesSuggestSameSite($um['name'], $cname)) {
                            $blocked = true;
                            break;
                        }
                    }
                    if ($blocked) {
                        continue;
                    }
                    $hours = is_numeric($c['poi_duration'] ?? null) ? (float) $c['poi_duration']
                        : (is_numeric($src['poi_duration'] ?? null) ? (float) $src['poi_duration'] : 2.0);
                    // short: не тащить 4ч stop
                    if ($direct < $longKm && $hours > 2.5) {
                        continue;
                    }
                    $ruName = $this->displayNameRu($cid, $cname);
                    $places[] = [
                        'id' => $cid,
                        'name' => $ruName,
                        'hours' => $hours,
                        'role' => 'transfer_stop',
                        'note' => 'PHP thin-transfer enrich (fit='.($c['route_fit'] ?? '?').')',
                    ];
                    $used[$cid] = true;
                    $stopIds[$cid] = true;
                    $usedMeta[] = [
                        'id' => $cid,
                        'name' => $ruName,
                        'pt' => ($src !== null && isset($src['lat'], $src['lng'])) ? $src : null,
                    ];
                    $need--;
                    $added++;
                    $actH += $hours;
                    $removed[] = 'день '.($i + 1).': добавлен corridor #'.$cid.' '.$ruName
                        .' (тонкий transfer: было '.$stops.' stop / '.round($actH - $hours, 1)
                        .' ч → enrich, direct '.round($direct, 1).' км)';
                    $stops++;
                }
                if ($added > 0) {
                    // base прибытия
                    $hasBase = false;
                    foreach ($places as $p) {
                        if (is_array($p) && ((int) ($p['id'] ?? 0) === $nightId || ($p['role'] ?? '') === 'base')) {
                            $hasBase = true;
                            break;
                        }
                    }
                    if (! $hasBase && isset($poisById[$nightId])) {
                        $places[] = [
                            'id' => $nightId,
                            'name' => (string) ($day['overnight']['name'] ?? $poisById[$nightId]['name'] ?? ''),
                            'hours' => 1.5,
                            'role' => 'base',
                        ];
                    }
                    $days[$i]['places'] = $places;
                    $days[$i]['total_hours'] = $this->sumPlaceHours($places);
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
     * Stay с activity > max и ≥2 POI → перенести самый длинный на соседний stay с запасом.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubStayDayOverload(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $maxH = (float) config('travel_route_fit.package_stay_max_day_hours', 7.0);
        $removed = [];
        $days = $plan['days'];
        $n = count($days);

        for ($i = 0; $i < $n; $i++) {
            $day = $days[$i];
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (str_contains($theme, 'переезд') || str_contains($theme, 'transfer')
                || str_contains($theme, 'выезд') || str_contains($theme, 'departure')) {
                continue;
            }
            $load = $this->activityHoursOfDay($day);
            if ($load <= $maxH + 0.05) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $acts = [];
            $bases = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $oid || $role === 'base') {
                    $bases[] = $p;
                } else {
                    $acts[] = $p;
                }
            }
            if (count($acts) < 2) {
                continue;
            }
            usort($acts, function (array $a, array $b) {
                $ha = is_numeric($a['hours'] ?? null) ? (float) $a['hours'] : 0;
                $hb = is_numeric($b['hours'] ?? null) ? (float) $b['hours'] : 0;

                return $hb <=> $ha;
            });
            $move = array_shift($acts);
            $moveH = is_numeric($move['hours'] ?? null) ? (float) $move['hours'] : 0;
            // соседний stay той же базы с запасом
            $target = null;
            for ($j = $i + 1; $j < $n; $j++) {
                $nd = $days[$j];
                if (! is_array($nd) || (int) ($nd['overnight']['id'] ?? 0) !== $oid) {
                    continue;
                }
                $nt = mb_strtolower((string) ($nd['theme'] ?? ''));
                if (str_contains($nt, 'переезд') || str_contains($nt, 'transfer')
                    || str_contains($nt, 'выезд') || str_contains($nt, 'departure')) {
                    continue;
                }
                if ($this->activityHoursOfDay($nd) + $moveH <= $maxH + 0.05) {
                    $target = $j;
                    break;
                }
            }
            if ($target === null) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    $nd = $days[$j];
                    if (! is_array($nd) || (int) ($nd['overnight']['id'] ?? 0) !== $oid) {
                        continue;
                    }
                    $nt = mb_strtolower((string) ($nd['theme'] ?? ''));
                    if (str_contains($nt, 'переезд') || str_contains($nt, 'transfer')
                        || str_contains($nt, 'выезд') || str_contains($nt, 'departure')) {
                        continue;
                    }
                    if ($this->activityHoursOfDay($nd) + $moveH <= $maxH + 0.05) {
                        $target = $j;
                        break;
                    }
                }
            }
            if ($target === null) {
                // новый stay после текущего
                $newDay = [
                    'date' => (string) ($day['date'] ?? ''),
                    'theme' => 'stay',
                    'summary' => (string) ($move['name'] ?? 'Продолжение программы'),
                    'overnight' => is_array($day['overnight'] ?? null) ? $day['overnight'] : ['id' => $oid, 'name' => ''],
                    'places' => [$move],
                    'total_hours' => $this->sumPlaceHours([$move]),
                ];
                array_splice($days, $i + 1, 0, [$newDay]);
                $n++;
                $days[$i]['places'] = array_merge($acts, $bases);
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
                $removed[] = 'день '.($i + 1).': #'
                    .(int) ($move['id'] ?? 0).' '.($move['name'] ?? '')
                    .' вынесен в новый stay (перегруз ~'.round($load, 1).' ч > '.$maxH.' ч)';
                continue;
            }
            $tp = is_array($days[$target]['places'] ?? null) ? $days[$target]['places'] : [];
            $tp[] = $move;
            $days[$target]['places'] = $tp;
            $days[$target]['total_hours'] = $this->sumPlaceHours($tp);
            $days[$i]['places'] = array_merge($acts, $bases);
            $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
            $removed[] = 'день '.($i + 1).'→'.($target + 1).': #'
                .(int) ($move['id'] ?? 0).' '.($move['name'] ?? '')
                .' перенесён (перегруз stay ~'.round($load, 1).' ч)';
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Выезд: убрать тяжёлый трек; если пусто — поставить Limone/ferry из каталога у базы.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubDepartureHeavyTrek(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $heavyH = (float) config('travel_route_fit.departure_heavy_min_hours', 2.8);
        $prefer = array_map('intval', (array) config('travel_route_fit.departure_prefer_poi_ids', [32, 395, 333]));
        $removed = [];
        $days = $plan['days'];
        $used = [];
        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (is_array($p)) {
                    $used[(int) ($p['id'] ?? 0)] = true;
                }
            }
        }

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (! str_contains($theme, 'выезд') && ! str_contains($theme, 'departure')) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $base = $poisById[$oid] ?? null;
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            $acts = [];
            $bases = [];
            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $oid || $role === 'base') {
                    $bases[] = $p;
                } else {
                    $acts[] = $p;
                }
            }
            $kept = [];
            foreach ($acts as $a) {
                $pid = (int) ($a['id'] ?? 0);
                $hours = is_numeric($a['hours'] ?? null) ? (float) $a['hours'] : 0;
                $load = (string) (($poisById[$pid]['physical_load'] ?? '') ?: '');
                $isPrefer = in_array($pid, $prefer, true);
                if (! $isPrefer && ($hours >= $heavyH || in_array($load, ['heavy', 'very_heavy'], true))) {
                    $removed[] = 'день '.($i + 1).': убран #'.$pid.' '.($a['name'] ?? '')
                        .' (выезд: тяжёлый/длинный trek — лучше Limone-класс)';
                    unset($used[$pid]);
                    continue;
                }
                $kept[] = $a;
            }
            // Не добавлять вторую переправу, если water-crossing уже был в туре
            $planHasCrossing = false;
            foreach ($days as $dj => $dd) {
                if ($dj === $i || ! is_array($dd)) {
                    continue;
                }
                foreach (is_array($dd['places'] ?? null) ? $dd['places'] : [] as $pp) {
                    if (! is_array($pp)) {
                        continue;
                    }
                    $ppid = (int) ($pp['id'] ?? 0);
                    $psrc = $poisById[$ppid] ?? null;
                    if ($this->isWaterCrossingPlace((string) ($pp['name'] ?? ''), $psrc)) {
                        $planHasCrossing = true;
                        break 2;
                    }
                }
            }
            if ($kept === [] && $base !== null) {
                $radius = (float) config('travel_route_fit.stay_radius_km', 70.0);
                foreach ($prefer as $pid) {
                    if ($pid <= 0 || isset($used[$pid]) || ! isset($poisById[$pid])) {
                        continue;
                    }
                    $src = $poisById[$pid];
                    $nm = (string) ($src['name'] ?? '');
                    if ($planHasCrossing && $this->isWaterCrossingPlace($nm, $src)) {
                        continue;
                    }
                    $km = $this->geo->km($base, $src);
                    if ($km === null || $km > $radius) {
                        continue;
                    }
                    $hours = is_numeric($src['poi_duration'] ?? null) ? (float) $src['poi_duration'] : 2.0;
                    // Имя из каталога; если иврит — всё равно id, UI/LLM уже на русском в других днях
                    $kept[] = [
                        'id' => $pid,
                        'name' => $nm !== '' ? $nm : ('POI #'.$pid),
                        'hours' => min($hours, 2.5),
                        'role' => 'activity',
                        'note' => 'PHP departure prefer (лёгкий финал)',
                    ];
                    $used[$pid] = true;
                    $removed[] = 'день '.($i + 1).': добавлен #'.$pid.' '
                        .$nm.' (выезд: лёгкий финал без повторной переправы)';
                    break;
                }
            }
            $days[$i]['places'] = array_merge($kept, $bases);
            $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
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
                $ruName = $this->displayNameRu($pairId, (string) ($pair['name'] ?? 'Misurina'));
                $days[$i]['places'][] = [
                    'id' => $pairId,
                    'name' => $ruName,
                    'hours' => $hours,
                    'role' => 'activity',
                    'note' => 'Связка с якорем #'.$anchorId.' (PHP stay pair)',
                ];
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
                $removed[] = 'день '.($i + 1).': добавлен #'.$pairId.' '
                    .$ruName.' (stay pair к #'.$anchorId.' у базы #'.$oid.')';
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
     * Повтор того же географического объекта в разных днях (разные id, близко по координатам).
     * Первый день выигрывает — поздний stop/activity снимается.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubGeographicDuplicates(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null) || $poisById === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $dupKm = (float) config('travel_route_fit.geo_duplicate_km', 2.0);
        if ($dupKm <= 0) {
            return ['plan' => $plan, 'removed' => []];
        }
        /** @var list<array{id: int, day: int, name: string, pt: array<string, mixed>}> */
        $keptPrevDays = [];
        $removed = [];
        $days = $plan['days'];

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $nightId = (int) ($day['overnight']['id'] ?? 0);
            $keepPlaces = [];
            /** @var list<array{id: int, day: int, name: string, pt: array<string, mixed>}> */
            $keptThisDay = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $place) {
                if (! is_array($place)) {
                    continue;
                }
                $pid = (int) ($place['id'] ?? 0);
                $role = (string) ($place['role'] ?? '');
                $pname = (string) ($place['name'] ?? '');
                if ($pid <= 0 || $pid === $nightId || $role === 'base') {
                    $keepPlaces[] = $place;
                    continue;
                }
                $src = $poisById[$pid] ?? null;
                if ($src === null || ! isset($src['lat'], $src['lng'])) {
                    $keepPlaces[] = $place;
                    continue;
                }
                if ($pname === '') {
                    $pname = (string) ($src['name'] ?? '');
                }
                // Против ПРЕДЫДУЩИХ дней: пары id (200↔218) или имя/латиница + km.
                $hit = null;
                $hitKm = null;
                foreach ($keptPrevDays as $prev) {
                    if ($this->isConfiguredSameSite((int) $prev['id'], $pid)) {
                        $hit = $prev;
                        $hitKm = $this->geo->km($prev['pt'], $src) ?? 0.0;
                        break;
                    }
                    $km = $this->geo->km($prev['pt'], $src);
                    if ($km === null || $km > $dupKm) {
                        continue;
                    }
                    if (! $this->namesSuggestSameSite($prev['name'], $pname)) {
                        continue;
                    }
                    $hit = $prev;
                    $hitKm = $km;
                    break;
                }
                if ($hit !== null) {
                    $removed[] = 'день '.($i + 1).': убран #'.$pid.' '.$pname
                        .' (гео-дубль ~'.round((float) $hitKm, 1)
                        .' км от #'.$hit['id'].' «'.$hit['name'].'» в дне '.$hit['day']
                        .' — тот же географический объект)';
                    continue;
                }
                $keptThisDay[] = ['id' => $pid, 'day' => $i + 1, 'name' => $pname, 'pt' => $src];
                $keepPlaces[] = $place;
            }
            $days[$i]['places'] = $keepPlaces;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keepPlaces);
            foreach ($keptThisDay as $row) {
                $keptPrevDays[] = $row;
            }
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Явные пары одного объекта (Sass Pordoi #200 ↔ Funivia #218), независимо от языка имени.
     */
    private function isConfiguredSameSite(int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }
        $map = (array) config('travel_route_fit.geo_same_site_ids', []);
        $list = array_map('intval', (array) ($map[$a] ?? []));

        return in_array($b, $list, true);
    }

    /**
     * Sass Pordoi / Funivia Sass Pordoi — да; Тервела vs Селла в одной долине — нет.
     * Достаёт и латиницу из смешанных HE/RU строк («… Funivia Sass Pordoi»).
     */
    private function namesSuggestSameSite(string $a, string $b): bool
    {
        $tokens = function (string $s): array {
            $s = mb_strtolower($s);
            $out = [];
            // латинские куски (pordoi из ивритского названия канатки)
            if (preg_match_all('/[a-z]{4,}/', $s, $lm)) {
                foreach ($lm[0] as $lat) {
                    $out[$lat] = true;
                }
            }
            $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
            $parts = preg_split('/\s+/u', trim($s)) ?: [];
            $stop = [
                'the', 'and', 'del', 'della', 'delle', 'di', 'da', 'a', 'al', 'la', 'le', 'il', 'lo',
                'озеро', 'lago', 'lake', 'waterfall', 'водопад', 'cascata', 'מפל',
                'перевал', 'passo', 'pass', 'тропа', 'маршрут', 'пешеходн', 'прогулка',
                'канатка', 'funivia', 'cable', 'day', 'יום', 'רכבל', 'המומלץ',
            ];
            foreach ($parts as $p) {
                $p = trim($p);
                if (mb_strlen($p) < 4) {
                    continue;
                }
                foreach ($stop as $st) {
                    if ($p === $st || str_starts_with($p, $st)) {
                        continue 2;
                    }
                }
                $out[$p] = true;
            }

            return array_keys($out);
        };
        $ta = $tokens($a);
        $tb = $tokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }
        foreach ($ta as $t) {
            foreach ($tb as $u) {
                if ($t === $u) {
                    return true;
                }
                if (mb_strlen($t) >= 5 && mb_strlen($u) >= 5
                    && (str_contains($t, $u) || str_contains($u, $t))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Transfer: упорядочить stop по axis progress OLD→NEW (не путать порядок Нардис/Мольвено).
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $legs
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function reorderTransferStopsByAxis(array $plan, array $legs, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
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
                $from = $poisById[$prevNightId] ?? null;
                $to = $poisById[$nightId] ?? null;
                if ($from !== null && $to !== null
                    && isset($from['lat'], $from['lng'], $to['lat'], $to['lng'])) {
                    $stops = [];
                    $bases = [];
                    foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                        if (! is_array($p)) {
                            continue;
                        }
                        $pid = (int) ($p['id'] ?? 0);
                        $role = (string) ($p['role'] ?? '');
                        if ($pid === $nightId || $role === 'base') {
                            $bases[] = $p;
                            continue;
                        }
                        $src = $poisById[$pid] ?? null;
                        $progress = 0.5;
                        if ($src !== null && isset($src['lat'], $src['lng'])) {
                            $axis = $this->axisMetrics($from, $to, $src);
                            $progress = (float) ($axis['progress'] ?? 0.5);
                        }
                        $stops[] = $p + ['_progress' => $progress];
                    }
                    if (count($stops) >= 2) {
                        $before = array_map(fn ($s) => (int) ($s['id'] ?? 0), $stops);
                        usort($stops, fn ($a, $b) => ($a['_progress'] <=> $b['_progress']));
                        $after = array_map(fn ($s) => (int) ($s['id'] ?? 0), $stops);
                        if ($before !== $after) {
                            $removed[] = 'день '.($i + 1).': порядок transfer_stop по оси OLD→NEW: '
                                .implode('→', $before).' → '.implode('→', $after);
                        }
                        foreach ($stops as &$s) {
                            unset($s['_progress']);
                        }
                        unset($s);
                        $days[$i]['places'] = array_merge($stops, $bases);
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
     * Лишняя переправа через озеро: near-base прогулка до crossing в том же дне;
     * не повторять water-crossing в соседних днях.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubLakeCrossingWaste(array $plan, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $nearKm = (float) config('travel_route_fit.lake_crossing_near_base_km', 18.0);
        $removed = [];
        $days = $plan['days'];
        $hadCrossingPrev = false;

        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                $hadCrossingPrev = false;
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $base = $poisById[$oid] ?? null;
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            $crossings = [];
            $nearBase = [];
            $other = [];
            $bases = [];
            $dayHasCrossing = false;

            foreach ($places as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid === $oid || $role === 'base') {
                    $bases[] = $p;
                    continue;
                }
                $src = $poisById[$pid] ?? null;
                $name = (string) ($p['name'] ?? ($src['name'] ?? ''));
                if ($this->isWaterCrossingPlace($name, $src)) {
                    $dayHasCrossing = true;
                    $crossings[] = $p;
                    continue;
                }
                $km = ($base !== null && $src !== null) ? $this->geo->km($base, $src) : null;
                if ($km !== null && $km <= $nearKm) {
                    $nearBase[] = $p;
                } else {
                    $other[] = $p;
                }
            }

            // Соседний день уже имел переправу — снять crossing с текущего
            if ($hadCrossingPrev && $crossings !== []) {
                foreach ($crossings as $c) {
                    $removed[] = 'день '.($i + 1).': убран #'.(int) ($c['id'] ?? 0).' '
                        .($c['name'] ?? '')
                        .' (повторная переправа: water-crossing уже был в предыдущем дне)';
                }
                $crossings = [];
                $dayHasCrossing = false;
            }

            // Переставлять только если в дне есть переправа; иначе не трогать stay (Carezza/Bulla)
            if ($crossings !== []) {
                $origNonBase = [];
                foreach ($places as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $pid = (int) ($p['id'] ?? 0);
                    $role = (string) ($p['role'] ?? '');
                    if ($pid !== $oid && $role !== 'base') {
                        $origNonBase[] = $pid;
                    }
                }
                $orderedNonBase = array_merge($nearBase, $other, $crossings);
                $after = [];
                foreach ($orderedNonBase as $p) {
                    $after[] = (int) ($p['id'] ?? 0);
                }
                if ($origNonBase !== $after) {
                    $removed[] = 'день '.($i + 1).': порядок дня — near-base до переправы через озеро ('
                        .implode('→', $origNonBase).' → '.implode('→', $after).')';
                }
                $days[$i]['places'] = array_merge($orderedNonBase, $bases);
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
            } elseif ($hadCrossingPrev && ! $dayHasCrossing) {
                // crossings сняты как повтор — уже собрали nearBase/other выше без crossings
                $days[$i]['places'] = array_merge($nearBase, $other, $bases);
                $days[$i]['total_hours'] = $this->sumPlaceHours($days[$i]['places']);
            }

            $hadCrossingPrev = $dayHasCrossing;
        }

        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * @param  array<string, mixed>|null  $src
     */
    private function isWaterCrossingPlace(string $name, ?array $src): bool
    {
        $blob = mb_strtolower($name.' '.(string) ($src['name'] ?? '').' '.(string) ($src['snippet'] ?? ''));
        if ($blob === '' || $blob === '  ') {
            return false;
        }

        return (bool) preg_match(
            '/ferry|traghett|battello|vaporetto|motonave|water\s*taxi|boat\s*trip|cruise|'
            .'שייט|מעבורת|ספינ|'
            .'переправ|паром|катер|корабл|круиз|водн\w*\s*прогул|водн\w*\s*переправ/ui',
            $blob
        );
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

    /**
     * Убрать blocklist POI (Nardis) с любого transfer-дня.
     *
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubBlockedTransferPois(array $plan): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $block = array_fill_keys(
            array_map('intval', (array) config('travel_route_fit.long_transfer_block_poi_ids', [303, 132])),
            true
        );
        if ($block === []) {
            return ['plan' => $plan, 'removed' => []];
        }
        $removed = [];
        $days = $plan['days'];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            if (! str_contains($theme, 'переезд') && ! str_contains($theme, 'transfer')) {
                continue;
            }
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $keep = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $role = (string) ($p['role'] ?? '');
                if ($pid > 0 && isset($block[$pid]) && $role !== 'base' && $pid !== $oid) {
                    $removed[] = 'день '.($i + 1).': убран #'.$pid.' '
                        .($p['name'] ?? '').' (transfer block после enrich: Nardis-крюк)';
                    continue;
                }
                $keep[] = $p;
            }
            $days[$i]['places'] = $keep;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
        }
        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * На длинном переезде обрезать часы тяжёлого stop (Molveno 4ч → short_stop_max).
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $legs
     * @param  array<int, array<string, mixed>>  $poisById
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubLongTransferStopHours(array $plan, array $legs, array $poisById): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120.0);
        $maxH = (float) config('travel_route_fit.short_stop_max_hours', 2.0);
        $removed = [];
        $days = $plan['days'];
        $prevOid = null;
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $theme = mb_strtolower((string) ($day['theme'] ?? ''));
            $oid = (int) ($day['overnight']['id'] ?? 0);
            $isTransfer = str_contains($theme, 'переезд') || str_contains($theme, 'transfer');
            if ($isTransfer && $oid > 0 && $prevOid !== null) {
                $direct = null;
                $leg = $this->legForNightChange($legs, $prevOid, $oid);
                if (is_array($leg) && is_numeric($leg['direct_km'] ?? null)) {
                    $direct = (float) $leg['direct_km'];
                } elseif (isset($poisById[$prevOid], $poisById[$oid])) {
                    $direct = $this->geo->km($poisById[$prevOid], $poisById[$oid]);
                }
                if ($direct !== null && $direct >= $longKm) {
                    $places = is_array($day['places'] ?? null) ? $day['places'] : [];
                    foreach ($places as $j => $p) {
                        if (! is_array($p)) {
                            continue;
                        }
                        $pid = (int) ($p['id'] ?? 0);
                        $role = (string) ($p['role'] ?? '');
                        if ($pid === $oid || $role === 'base') {
                            continue;
                        }
                        $h = isset($p['hours']) && is_numeric($p['hours']) ? (float) $p['hours'] : 0.0;
                        if ($h > $maxH + 0.05) {
                            $places[$j]['hours'] = $maxH;
                            $removed[] = 'день '.($i + 1).': hours #'.$pid.' '
                                .($p['name'] ?? '').' обрезаны '.$h.'→'.$maxH
                                .' ч (long transfer: stop ≠ stay)';
                        }
                    }
                    $days[$i]['places'] = $places;
                    $days[$i]['total_hours'] = $this->sumPlaceHours($places);
                }
            }
            if ($oid > 0) {
                $prevOid = $oid;
            }
        }
        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * Carezza (#774) в одном stay с Sella/Pordoi → убрать Carezza (длинный южный крюк).
     *
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubCarezzaOnHighPassDay(array $plan): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $high = array_fill_keys(
            array_map('intval', (array) config('travel_route_fit.high_pass_poi_ids', [200, 218, 651])),
            true
        );
        $carezzaId = 774;
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
            $hasHigh = false;
            $hasCarezza = false;
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                if (isset($high[$pid])) {
                    $hasHigh = true;
                }
                if ($pid === $carezzaId) {
                    $hasCarezza = true;
                }
            }
            if (! $hasHigh || ! $hasCarezza) {
                continue;
            }
            $keep = [];
            foreach (is_array($day['places'] ?? null) ? $day['places'] : [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                if ((int) ($p['id'] ?? 0) === $carezzaId) {
                    $removed[] = 'день '.($i + 1).': убран #774 Озеро Карецца (крюк с Sella/Pordoi; лучше отдельный день или без него)';
                    continue;
                }
                $keep[] = $p;
            }
            $days[$i]['places'] = $keep;
            $days[$i]['total_hours'] = $this->sumPlaceHours($keep);
        }
        $plan['days'] = $days;

        return ['plan' => $plan, 'removed' => $removed];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, removed: list<string>}
     */
    private function scrubDisplayNamesRu(array $plan): array
    {
        if (! is_array($plan['days'] ?? null)) {
            return ['plan' => $plan, 'removed' => []];
        }
        $map = (array) config('travel_route_fit.display_names_ru', []);
        $removed = [];
        $days = $plan['days'];
        $fixed = 0;
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            if (is_array($day['overnight'] ?? null)) {
                $oid = (int) ($day['overnight']['id'] ?? 0);
                $on = (string) ($day['overnight']['name'] ?? '');
                $ru = $this->displayNameRu($oid, $on);
                if ($ru !== $on && $ru !== '') {
                    $days[$i]['overnight']['name'] = $ru;
                    $fixed++;
                }
            }
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            foreach ($places as $j => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $pid = (int) ($p['id'] ?? 0);
                $nm = (string) ($p['name'] ?? '');
                $ru = $this->displayNameRu($pid, $nm);
                if ($ru !== $nm && $ru !== '') {
                    $places[$j]['name'] = $ru;
                    $fixed++;
                }
            }
            $days[$i]['places'] = $places;

            foreach (['theme', 'summary'] as $field) {
                $text = (string) ($days[$i][$field] ?? '');
                if ($text === '' || ! $this->containsHebrew($text)) {
                    continue;
                }
                $new = $text;
                foreach ($map as $id => $ruLabel) {
                    $id = (int) $id;
                    if ($id <= 0 || $ruLabel === '') {
                        continue;
                    }
                    // замена ивритского куска рядом с #id или известного имени из map
                    $new = preg_replace(
                        '/[\x{0590}-\x{05FF}\s\-–—]*#\s*'.$id.'\b/u',
                        $ruLabel.' #'.$id,
                        $new
                    ) ?? $new;
                }
                $new = $this->stripHebrewKeepLatin($new);
                if ($new !== $text) {
                    $days[$i][$field] = trim(preg_replace('/\s{2,}/u', ' ', $new) ?? $new);
                    $fixed++;
                }
            }
        }
        if ($fixed > 0) {
            $removed[] = 'display names: '.$fixed.' полей переведены/очищены от иврита → RU';
        }
        $plan['days'] = $days;
        if (isset($plan['title']) && is_string($plan['title']) && $this->containsHebrew($plan['title'])) {
            $plan['title'] = $this->stripHebrewKeepLatin($plan['title']);
        }

        return ['plan' => $plan, 'removed' => $removed];
    }

    private function displayNameRu(int $id, string $fallback = ''): string
    {
        $map = (array) config('travel_route_fit.display_names_ru', []);
        if ($id > 0 && isset($map[$id]) && trim((string) $map[$id]) !== '') {
            return trim((string) $map[$id]);
        }
        if ($fallback !== '' && ! $this->containsHebrew($fallback)) {
            return $fallback;
        }
        $latin = $this->latinFromParens($fallback);
        if ($latin !== '') {
            return $latin;
        }
        $stripped = trim($this->stripHebrewKeepLatin($fallback));

        return $stripped !== '' ? $stripped : ($fallback !== '' ? $fallback : ('POI #'.$id));
    }

    private function containsHebrew(string $s): bool
    {
        return (bool) preg_match('/\p{Hebrew}/u', $s);
    }

    private function stripHebrewKeepLatin(string $s): string
    {
        $s = preg_replace('/\p{Hebrew}+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s{2,}/u', ' ', $s) ?? $s);
    }

    private function latinFromParens(string $s): string
    {
        if (preg_match('/\(([A-Za-z][^)]{1,80})\)/u', $s, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b([A-Za-z][A-Za-z0-9 .\'\-]{2,60})\b/u', $s, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
