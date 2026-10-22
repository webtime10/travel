<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Location;

/**
 * Расклад дней: приоритет area_id из каталога; иначе кластер по координатам.
 */
class LocationDayPacker
{
    public function __construct(
        private readonly LocationDurationResolver $durations,
    ) {}

    /** Место ближе этого км к центру района — в тот же район. */
    private const ASSIGN_KM = 35.0;

    /** Новый район, если дальше этого км от всех существующих центров. */
    private const SEED_KM = 28.0;

    /** Бюджет туристического дня (часы активностей + короткие hops). */
    private const DAY_HOURS_BUDGET = 6.5;

    /** Бюджет активностей в день межрайонного переезда (без самой дороги). */
    private const TRANSFER_ACT_HOURS = 2.5;

    /** POI дальше этого км от центра района — выброс (чужой регион). */
    private const CLUSTER_MAX_KM = 90.0;

    /** При доборе из каталога — ещё жёстче к центру района. */
    private const ENRICH_MAX_KM = 70.0;

    /** Точка ≥ этого — почти одна на день. */
    private const HEAVY_HOURS = 3.5;

    /** Оценка hop между соседними точками одного дня. */
    private const HOP_HOURS = 0.5;

    /** Макс. лёгких точек в tourist-дне (анти-свалка остатков). */
    private const MAX_LIGHTS_PER_DAY = 2;

    /** Ниже этого — день считаем недогруженным, тянем точку с перегруза. */
    private const MIN_DAY_HOURS = 3.0;

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array{
     *   locations: list<array<string, mixed>>,
     *   calendar: list<string>,
     *   day_plan: list<string>,
     *   blocks: list<array<string, mixed>>
     * }
     */
    public function pack(array $locations, string $query): array
    {
        $days = $this->parseTripDays($query);
        $locations = $this->enrichFromCatalog($locations);
        // Прогрев duration из фактов — тот же источник, что у validator
        $warmupIds = [];
        foreach ($locations as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $warmupIds[] = (int) $row['id'];
            }
        }
        $this->durations->forIds($warmupIds);
        $withCoords = [];
        $without = [];
        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;
            if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
                $withCoords[] = $row;
            } else {
                $without[] = $row;
            }
        }

        if ($withCoords === [] || count($days) < 2) {
            return [
                'locations' => $locations,
                'calendar' => [],
                'day_plan' => [],
                'blocks' => [],
            ];
        }

        $withCoords = array_map(fn (array $r) => $this->tagEndPoint($r), $withCoords);
        $clusters = $this->buildClusters($withCoords);
        $clusters = $this->pruneGeoOutliers($clusters);
        $clusters = $this->dedupeNearIdenticalCoords($clusters);
        $clusters = $this->dedupeTrekFamiliesAcrossTrip($clusters);
        // Хвостовые районы часто приходят только с базой — добираем POI из каталога (Braies, Tre Cime…)
        $clusters = $this->enrichThinClustersFromCatalog($clusters, count($days));
        $clusters = $this->dedupeTrekFamiliesAcrossTrip($clusters);
        $enrichedIds = [];
        foreach ($clusters as $c) {
            foreach ($c as $row) {
                if ((int) ($row['id'] ?? 0) > 0) {
                    $enrichedIds[] = (int) $row['id'];
                }
            }
        }
        $this->durations->forIds($enrichedIds);
        $spans = $this->allocateDays($days, $clusters);

        $packed = [];
        $calendar = [];
        $dayEntries = [];
        $blocksMeta = [];

        foreach ($clusters as $i => $cluster) {
            $span = $spans[$i] ?? ['from' => null, 'to' => null, 'days' => []];
            $label = $this->clusterLabel($cluster, $i + 1);
            $key = 'area_'.($i + 1);
            $base = $this->pickOvernight($cluster);
            $nextBase = null;
            $nextLabel = null;
            if ($i < count($clusters) - 1) {
                $nextBase = $this->pickOvernight($clusters[$i + 1]);
                $nextLabel = $this->clusterLabel($clusters[$i + 1], $i + 2);
            }
            $nextIsEnd = $i < count($clusters) - 1 && $this->clusterIsEndPoint($clusters[$i + 1]);
            $dayList = array_values($span['days'] ?? []);
            // Район без календарных дней — только метаданные/локации (ночёвка с прошлого переезда)
            if ($dayList === []) {
                foreach ($cluster as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $row['block'] = $key;
                    $row['block_label'] = $label;
                    $row['date_from'] = null;
                    $row['date_to'] = null;
                    if ($base && $id === (int) $base['id']) {
                        $row['role'] = 'base';
                        $row['is_base'] = true;
                    } elseif (($row['role'] ?? '') === 'base') {
                        $row['role'] = 'place';
                        $row['is_base'] = false;
                    }
                    $packed[] = $row;
                }
                $blocksMeta[] = [
                    'key' => $key,
                    'label' => $label,
                    'from' => null,
                    'to' => null,
                    'count' => count($cluster),
                    'base_id' => $base ? (int) $base['id'] : null,
                ];
                continue;
            }
            // База ночёвки ≠ активность: без туристических POI дни не печатаем
            if ($this->clusterTouristPlaces($cluster) === []) {
                foreach ($cluster as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $row['block'] = $key;
                    $row['block_label'] = $label;
                    $row['date_from'] = null;
                    $row['date_to'] = null;
                    if ($base && $id === (int) $base['id']) {
                        $row['role'] = 'base';
                        $row['is_base'] = true;
                    } elseif (($row['role'] ?? '') === 'base') {
                        $row['role'] = 'place';
                        $row['is_base'] = false;
                    }
                    $packed[] = $row;
                }
                $blocksMeta[] = [
                    'key' => $key,
                    'label' => $label,
                    'from' => null,
                    'to' => null,
                    'count' => count($cluster),
                    'base_id' => $base ? (int) $base['id'] : null,
                ];
                continue;
            }
            $daySlots = $this->assignPlacesToDays($cluster, $dayList, $base, $nextBase !== null, $nextIsEnd);
            $daySlots = $this->balanceDaySlots($daySlots, $dayList, $nextBase !== null, $nextIsEnd);

            // Слабый день переезда (1 точка) → в предыдущий, если влезает
            if ($nextBase !== null && count($dayList) >= 2) {
                $daySlots = $this->absorbWeakTransferDay($daySlots, $dayList);
            }
            // Пустые дни: не пересобирать balance (он сжимает переезд и теряет POI) —
            // только убрать пустые даты / слабый переезд.
            [$dayList, $daySlots, $spans] = $this->stripEmptyDaysFromCluster(
                $dayList,
                $daySlots,
                $spans,
                $clusters,
                $i,
                $nextBase !== null,
                $nextIsEnd,
            );

            $dayList = $this->sortDayList($dayList);
            $span['days'] = $dayList;
            $span['from'] = $dayList[0] ?? null;
            $span['to'] = $dayList !== [] ? $dayList[count($dayList) - 1] : null;
            $spans[$i] = $span;
            $lastDate = $dayList[count($dayList) - 1] ?? null;

            foreach ($cluster as $row) {
                $id = (int) ($row['id'] ?? 0);
                $row['block'] = $key;
                $row['block_label'] = $label;
                $row['date_from'] = $span['from'];
                $row['date_to'] = $span['to'];
                if ($base && $id === (int) $base['id']) {
                    $row['role'] = 'base';
                    $row['is_base'] = true;
                } elseif (($row['role'] ?? '') === 'base') {
                    $row['role'] = 'place';
                    $row['is_base'] = false;
                }
                $packed[] = $row;
            }

            $blocksMeta[] = [
                'key' => $key,
                'label' => $label,
                'from' => $span['from'],
                'to' => $span['to'],
                'count' => count($cluster),
                'base_id' => $base ? (int) $base['id'] : null,
            ];

            foreach ($dayList as $date) {
                $places = $daySlots[$date] ?? [];
                $isTransferDay = $nextBase !== null && $date === $lastDate;
                $isEndDeparture = $nextBase === null && $this->clusterIsEndPoint($cluster);
                $night = $isTransferDay ? $nextBase : $base;
                $names = [];
                foreach ($places as $p) {
                    $nm = trim((string) ($p['name'] ?? ''));
                    if ($nm === '') {
                        continue;
                    }
                    if ($base && (int) ($p['id'] ?? 0) === (int) $base['id'] && ! $isEndDeparture) {
                        continue;
                    }
                    $names[] = $nm.' [id='.(int) ($p['id'] ?? 0).']';
                }
                if ($names === [] && $isEndDeparture && $base) {
                    $names[] = trim((string) ($base['name'] ?? '')).' [id='.(int) $base['id'].']';
                }
                // Пустые дни («переезд» без точек) не печатаем — это и есть 21–23 «дыры»
                if ($names === []) {
                    continue;
                }
                $dayEntries[] = [
                    'names' => $names,
                    'transfer' => ($isTransferDay && $nextLabel) ? $nextLabel : null,
                    'night' => $night,
                    'block' => $label,
                ];
            }
        }

        // Даты подряд с первого дня поездки — без дыр вроде пропуска 19.08
        $dayPlan = $this->emitDayPlan($days, $dayEntries);
        $calendar = $this->calendarFromEntries($days, $dayEntries);

        foreach ($without as $row) {
            $row['block'] = $row['block'] ?? 'no_coords';
            $row['block_label'] = $row['block_label'] ?? 'без координат';
            $packed[] = $row;
        }

        return [
            'locations' => $packed,
            'calendar' => $calendar,
            'day_plan' => $dayPlan,
            'blocks' => $blocksMeta,
        ];
    }

    /**
     * Выкинуть точки слишком далеко от центра своего района (Сардиния в Альпах и т.п.).
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function pruneGeoOutliers(array $clusters): array
    {
        $out = [];
        foreach ($clusters as $cluster) {
            if ($this->clusterIsEndPoint($cluster) || count($cluster) <= 1) {
                $out[] = $cluster;
                continue;
            }
            $base = $this->pickOvernight($cluster);
            [$clat, $clng] = $base && isset($base['lat'], $base['lng'])
                ? [(float) $base['lat'], (float) $base['lng']]
                : $this->centroid($cluster);
            $kept = [];
            foreach ($cluster as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($base && $id === (int) ($base['id'] ?? 0)) {
                    $kept[] = $row;
                    continue;
                }
                if (! empty($row['is_end_point']) || ($row['role'] ?? '') === 'end_point') {
                    $kept[] = $row;
                    continue;
                }
                if (! isset($row['lat'], $row['lng']) || ! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
                    $kept[] = $row;
                    continue;
                }
                $km = $this->distanceKm($clat, $clng, (float) $row['lat'], (float) $row['lng']);
                if ($km > self::CLUSTER_MAX_KM) {
                    continue;
                }
                $kept[] = $row;
            }
            $out[] = $kept !== [] ? $kept : $cluster;
        }

        return $out;
    }

    /**
     * Разные id, одни координаты → один объект (верёвочный парк 215/216).
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function dedupeNearIdenticalCoords(array $clusters): array
    {
        $seen = [];
        $out = [];
        foreach ($clusters as $cluster) {
            $kept = [];
            foreach ($cluster as $row) {
                if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base' || ($row['trip_role'] ?? '') === 'base') {
                    $kept[] = $row;
                    continue;
                }
                if (! isset($row['lat'], $row['lng']) || ! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
                    $kept[] = $row;
                    continue;
                }
                $key = round((float) $row['lat'], 4).','.round((float) $row['lng'], 4);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = (int) ($row['id'] ?? 0);
                $kept[] = $row;
            }
            $out[] = $kept;
        }

        return $out;
    }

    /**
     * Один «семейный» трек на поездку: не два Tre Cime / не два «5 озёр».
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function dedupeTrekFamiliesAcrossTrip(array $clusters): array
    {
        $seen = [];
        $out = [];
        foreach ($clusters as $cluster) {
            $kept = [];
            foreach ($cluster as $row) {
                $fam = $this->trekFamily((string) ($row['name'] ?? ''));
                if ($fam === null) {
                    $kept[] = $row;
                    continue;
                }
                if (isset($seen[$fam])) {
                    // базы не трогаем; дубль-трек — выкинуть
                    if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base' || ($row['trip_role'] ?? '') === 'base') {
                        $kept[] = $row;
                    }
                    continue;
                }
                $seen[$fam] = (int) ($row['id'] ?? 0);
                $kept[] = $row;
            }
            $out[] = $kept;
        }

        return $out;
    }

    private function trekFamily(string $name): ?string
    {
        $n = mb_strtolower($name);
        if (preg_match('/tre cime|drei zinnen|3 פסגות|5 פסגות|три пик|פסגות דולומיטים/ui', $n)) {
            return 'tre_cime';
        }
        if (preg_match('/5\s*אגמים|חמש\s*אגמים|cinq(ue)?\s*lagh|пять оз|5\s*озёр|5\s*озер|5 lakes/ui', $n)) {
            return 'five_lakes';
        }
        if (preg_match('/cinq(ue)?\s*torri|чинкве\s*торри/ui', $n)) {
            return 'cinque_torri';
        }

        return null;
    }

    /**
     * Если в районе только база/мало POI — добрать точки из каталога того же area_id.
     * Ночёвка никогда не подставляется как «активность».
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function enrichThinClustersFromCatalog(array $clusters, int $tripDays): array
    {
        if ($clusters === [] || $tripDays < 2) {
            return $clusters;
        }
        $usedIds = [];
        $usedFamilies = [];
        foreach ($clusters as $c) {
            foreach ($c as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $usedIds[$id] = true;
                }
                $fam = $this->trekFamily((string) ($row['name'] ?? ''));
                if ($fam !== null) {
                    $usedFamilies[$fam] = true;
                }
            }
        }

        $out = [];
        foreach ($clusters as $ci => $cluster) {
            if ($this->clusterIsEndPoint($cluster)) {
                $out[] = $cluster;
                continue;
            }
            $tourist = $this->clusterTouristPlaces($cluster);
            $bounds = $this->areaDayBounds($cluster);
            $wantDays = $bounds !== null ? max(1, min(5, $bounds[1])) : 2;
            $wantPlaces = max(2, min(6, $wantDays));
            if (count($tourist) >= $wantPlaces) {
                $out[] = $cluster;
                continue;
            }
            $areaId = 0;
            foreach ($cluster as $row) {
                $areaId = (int) ($row['area_id'] ?? 0);
                if ($areaId > 0) {
                    break;
                }
            }
            if ($areaId <= 0) {
                $out[] = $cluster;
                continue;
            }
            $base = $this->pickOvernight($cluster);
            [$clat, $clng] = $base && isset($base['lat'], $base['lng'])
                ? [(float) $base['lat'], (float) $base['lng']]
                : $this->centroid($cluster);

            $need = $wantPlaces - count($tourist);
            $candidates = Location::query()
                ->with(['area.descriptions'])
                ->where('area_id', $areaId)
                ->where('status', true)
                ->whereNotIn('id', array_keys($usedIds) ?: [0])
                ->where(function ($q) {
                    $q->whereNull('trip_role')
                        ->orWhere('trip_role', 'place')
                        ->orWhere('trip_role', '');
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(30)
                ->get();

            $scored = [];
            foreach ($candidates as $loc) {
                if ($loc->trip_role === 'base' || $loc->trip_role === 'end') {
                    continue;
                }
                if ($loc->lat === null || $loc->lng === null) {
                    continue;
                }
                $km = $this->distanceKm($clat, $clng, (float) $loc->lat, (float) $loc->lng);
                if ($km > self::ENRICH_MAX_KM) {
                    continue;
                }
                $name = (string) ($loc->meta['title'] ?? $loc->meta_ru['title'] ?? ('loc-'.$loc->id));
                $fam = $this->trekFamily($name);
                if ($fam !== null && isset($usedFamilies[$fam])) {
                    continue;
                }
                $row = [
                    'id' => (int) $loc->id,
                    'name' => $name,
                    'lat' => (float) $loc->lat,
                    'lng' => (float) $loc->lng,
                    'snippet' => '',
                    'area_id' => $areaId,
                    'trip_role' => $loc->trip_role ?: 'place',
                    'role' => 'place',
                    'is_base' => false,
                    'area_min_days' => $loc->area?->min_days,
                    'area_max_days' => $loc->area?->max_days,
                    'area_name' => $loc->area ? $this->areaDisplayName($loc->area) : null,
                    'score' => $this->catalogPlacePriority($name) - ($km * 0.01),
                ];
                if ($this->looksLikeEndPoint($row)) {
                    continue;
                }
                $scored[] = $row;
            }
            usort($scored, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $added = 0;
            foreach ($scored as $row) {
                if ($added >= $need) {
                    break;
                }
                $id = (int) $row['id'];
                if (isset($usedIds[$id])) {
                    continue;
                }
                $fam = $this->trekFamily((string) ($row['name'] ?? ''));
                if ($fam !== null && isset($usedFamilies[$fam])) {
                    continue;
                }
                $cluster[] = $row;
                $usedIds[$id] = true;
                $added++;
                if ($fam !== null) {
                    $usedFamilies[$fam] = true;
                }
            }
            $out[] = $cluster;
        }

        return $out;
    }

    private function catalogPlacePriority(string $name): float
    {
        $n = mb_strtolower($name);
        $score = 1.0;
        if (preg_match('/tre cime|drei zinnen|3 פסגות|три пик|פסגות/ui', $n)) {
            $score += 10;
        }
        if (preg_match('/braies|בראייס|прагсер|прайес/ui', $n)) {
            $score += 9;
        }
        if (preg_match('/cadini|кадини/ui', $n)) {
            $score += 7;
        }
        if (preg_match('/dobbiaco|דוביאקו|доббиако/ui', $n)) {
            $score += 6;
        }
        if (preg_match('/sorapis|סורפיס|сооапис|misurina|מיזורינה/ui', $n)) {
            $score += 5;
        }
        if (preg_match('/cinq|5 אגמים|пять оз|cinque torri|5 פסגות/ui', $n)) {
            $score += 4;
        }
        if (preg_match('/funbob|zip|זיפ|מגחש|מגלש|junior|gimbor|פארק\s*חבלים|rope\s*park|col\s*de\s*flam|emozion|веревочн/ui', $n)) {
            $score -= 3; // развлечения — ниже приоритета, чем озёра/треки
        }

        return $score;
    }

    /**
     * @param  list<string>  $tripDays
     * @param  list<array{names: list<string>, transfer: ?string, night: ?array<string, mixed>, block?: string}>  $entries
     * @return list<string>
     */
    private function emitDayPlan(array $tripDays, array $entries): array
    {
        $out = [];
        foreach ($entries as $i => $e) {
            $date = $tripDays[$i] ?? null;
            if ($date === null) {
                break;
            }
            $names = $e['names'] ?? [];
            if ($names === []) {
                continue;
            }
            $line = $date.' — '.implode(', ', $names);
            if (! empty($e['transfer'])) {
                $line .= '. Переезд в '.$e['transfer'];
            }
            $night = $e['night'] ?? null;
            if ($night) {
                $line .= '. Ночёвка: '.trim((string) ($night['name'] ?? '')).' [id='.(int) $night['id'].']';
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param  list<string>  $tripDays
     * @param  list<array{block?: string}>  $entries
     * @return list<string>
     */
    private function calendarFromEntries(array $tripDays, array $entries): array
    {
        $calendar = [];
        $cur = null;
        $from = null;
        $to = null;
        $n = 0;
        foreach ($entries as $i => $e) {
            $date = $tripDays[$i] ?? null;
            if ($date === null) {
                break;
            }
            $block = (string) ($e['block'] ?? '');
            if ($cur === null) {
                $cur = $block;
                $from = $date;
                $to = $date;
                $n = 1;
                continue;
            }
            if ($block !== $cur) {
                $calendar[] = $cur.': '.$from.'–'.$to.' ('.$n.' дн.)';
                $cur = $block;
                $from = $date;
                $to = $date;
                $n = 1;
                continue;
            }
            $to = $date;
            $n++;
        }
        if ($cur !== null && $from !== null) {
            $calendar[] = $cur.': '.$from.'–'.$to.' ('.$n.' дн.)';
        }

        return $calendar;
    }

    /** Для календаря предпочитаем русское имя района, иначе nameFor(). */
    private function areaDisplayName(Area $area): string
    {
        $ru = $area->descriptions->first(function ($d) {
            return strtolower((string) ($d->language?->code ?? '')) === 'ru'
                || (int) $d->language_id === 2;
        });
        if (! $ru) {
            $ru = $area->descriptions->firstWhere('language_id', 2);
        }
        $name = trim((string) ($ru->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $area->nameFor();
    }

    /**
     * @param  list<string>  $days
     * @return list<string>
     */
    private function sortDayList(array $days): array
    {
        $days = array_values($days);
        usort($days, function (string $a, string $b) {
            [$da, $ma] = array_map('intval', explode('.', $a) + [0, 0]);
            [$db, $mb] = array_map('intval', explode('.', $b) + [0, 0]);

            return ($ma * 31 + $da) <=> ($mb * 31 + $db);
        });

        return $days;
    }

    /**
     * Подтянуть area_id / trip_role / метаданные района из каталога (assemble может не прислать).
     *
     * @param  list<array<string, mixed>>  $locations
     * @return list<array<string, mixed>>
     */
    private function enrichFromCatalog(array $locations): array
    {
        $ids = [];
        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $locations;
        }
        $byId = Location::query()
            ->with(['area.descriptions'])
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $loc = $byId->get((int) ($row['id'] ?? 0));
            if ($loc) {
                if (empty($row['area_id']) && $loc->area_id) {
                    $row['area_id'] = (int) $loc->area_id;
                }
                if (empty($row['trip_role']) && $loc->trip_role) {
                    $row['trip_role'] = $loc->trip_role;
                }
                if ($loc->area) {
                    $row['area_name'] = $row['area_name'] ?? $this->areaDisplayName($loc->area);
                    $row['area_sort'] = $row['area_sort'] ?? $loc->area->sort_order;
                    $row['area_min_days'] = $row['area_min_days'] ?? $loc->area->min_days;
                    $row['area_max_days'] = $row['area_max_days'] ?? $loc->area->max_days;
                    $row['area_base_id'] = $row['area_base_id'] ?? $loc->area->default_base_location_id;
                }
                if (($row['trip_role'] ?? '') === 'base' || $loc->trip_role === 'base') {
                    $row['role'] = 'base';
                    $row['is_base'] = true;
                }
                if (($row['trip_role'] ?? '') === 'end' || $loc->trip_role === 'end') {
                    $row['is_end_point'] = true;
                    $row['role'] = 'end_point';
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return list<list<array<string, mixed>>>
     */
    private function buildClusters(array $locations): array
    {
        $withArea = 0;
        foreach ($locations as $row) {
            if ((int) ($row['area_id'] ?? 0) > 0) {
                $withArea++;
            }
        }
        // Если ≥40% точек с area_id и есть хотя бы 2 района — собираем по справочнику
        $useAreas = $withArea >= max(2, (int) ceil(count($locations) * 0.4));
        if ($useAreas) {
            $clusters = $this->clustersFromAreas($locations);
            if (count($clusters) >= 2) {
                return $clusters;
            }
        }

        $clusters = $this->clusterAroundSeeds($locations);
        $clusters = $this->mergeOrphansIntoBases($clusters);
        $clusters = $this->mergeNearbyBaseClusters($clusters);
        $clusters = $this->orderForTrip($clusters);

        return $this->ensureEndPointLast($clusters);
    }

    /**
     * Группы строго по area_id; без area — к ближайшему району тура.
     *
     * @param  list<array<string, mixed>>  $locations
     * @return list<list<array<string, mixed>>>
     */
    private function clustersFromAreas(array $locations): array
    {
        $areaIds = [];
        foreach ($locations as $row) {
            $aid = (int) ($row['area_id'] ?? 0);
            if ($aid > 0) {
                $areaIds[$aid] = true;
            }
        }
        $areas = Area::query()
            ->with('descriptions')
            ->whereIn('id', array_keys($areaIds))
            ->get()
            ->keyBy('id');

        $buckets = [];
        $orphans = [];
        foreach ($locations as $row) {
            $aid = (int) ($row['area_id'] ?? 0);
            if ($aid > 0 && $areas->has($aid)) {
                $area = $areas->get($aid);
                $row['area_name'] = $row['area_name'] ?? $this->areaDisplayName($area);
                $row['area_sort'] = $row['area_sort'] ?? $area->sort_order;
                $row['area_min_days'] = $row['area_min_days'] ?? $area->min_days;
                $row['area_max_days'] = $row['area_max_days'] ?? $area->max_days;
                $row['area_base_id'] = $row['area_base_id'] ?? $area->default_base_location_id;
                $buckets[$aid][] = $row;
            } else {
                $orphans[] = $row;
            }
        }

        foreach ($orphans as $row) {
            $bestAid = null;
            $bestD = PHP_FLOAT_MAX;
            foreach ($buckets as $aid => $members) {
                $area = $areas->get($aid);
                $alat = $area?->lat;
                $alng = $area?->lng;
                if ($alat === null || $alng === null) {
                    [$alat, $alng] = $this->centroid($members);
                }
                $d = $this->distanceKm(
                    (float) $alat,
                    (float) $alng,
                    (float) $row['lat'],
                    (float) $row['lng'],
                );
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestAid = $aid;
                }
            }
            if ($bestAid !== null) {
                $area = $areas->get($bestAid);
                $row['area_id'] = $bestAid;
                $row['area_name'] = $area ? $this->areaDisplayName($area) : null;
                $row['area_sort'] = $area?->sort_order;
                $row['area_min_days'] = $area?->min_days;
                $row['area_max_days'] = $area?->max_days;
                $buckets[$bestAid][] = $row;
            }
        }

        $orderedIds = array_keys($buckets);
        usort($orderedIds, function ($a, $b) use ($areas, $buckets) {
            $aa = $areas->get($a);
            $bb = $areas->get($b);
            $endA = $this->clusterIsEndPoint($buckets[$a]) || str_starts_with((string) ($aa?->slug ?? ''), 'exit');
            $endB = $this->clusterIsEndPoint($buckets[$b]) || str_starts_with((string) ($bb?->slug ?? ''), 'exit');
            if ($endA !== $endB) {
                return $endA ? 1 : -1;
            }
            $sa = (int) ($aa?->sort_order ?? 0);
            $sb = (int) ($bb?->sort_order ?? 0);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return $a <=> $b;
        });

        $out = [];
        foreach ($orderedIds as $aid) {
            $out[] = $buckets[$aid];
        }

        return $out;
    }

    /**
     * Базы ночёвки — центры районов; остальные точки к ближайшей базе/центру.
     *
     * @param  list<array<string, mixed>>  $locations
     * @return list<list<array<string, mixed>>>
     */
    private function clusterAroundSeeds(array $locations): array
    {
        $seeds = [];
        foreach ($locations as $row) {
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                $seeds[] = $row;
            }
        }
        usort($locations, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        foreach ($locations as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($this->inSeeds($seeds, $id)) {
                continue;
            }
            $nearest = $this->nearestSeedKm($seeds, $row);
            if ($nearest === null || $nearest > self::SEED_KM) {
                $seeds[] = $row;
            }
        }
        if ($seeds === []) {
            $seeds[] = $locations[0];
        }

        $buckets = array_fill(0, count($seeds), []);
        foreach ($locations as $row) {
            $bestI = 0;
            $bestD = PHP_FLOAT_MAX;
            foreach ($seeds as $i => $seed) {
                $d = $this->distanceKm(
                    (float) $seed['lat'],
                    (float) $seed['lng'],
                    (float) $row['lat'],
                    (float) $row['lng'],
                );
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestI = $i;
                }
            }
            if ($bestD > self::ASSIGN_KM && ! $this->inSeeds($seeds, (int) ($row['id'] ?? 0))) {
                $seeds[] = $row;
                $buckets[] = [$row];
                continue;
            }
            $buckets[$bestI][] = $row;
        }

        return array_values(array_filter($buckets, fn ($g) => $g !== []));
    }

    /**
     * @param  list<array<string, mixed>>  $seeds
     */
    private function inSeeds(array $seeds, int $id): bool
    {
        foreach ($seeds as $s) {
            if ((int) ($s['id'] ?? 0) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $seeds
     * @param  array<string, mixed>  $row
     */
    private function nearestSeedKm(array $seeds, array $row): ?float
    {
        if ($seeds === []) {
            return null;
        }
        $best = PHP_FLOAT_MAX;
        foreach ($seeds as $seed) {
            $d = $this->distanceKm(
                (float) $seed['lat'],
                (float) $seed['lng'],
                (float) $row['lat'],
                (float) $row['lng'],
            );
            $best = min($best, $d);
        }

        return $best;
    }

    /**
     * Точки без своей базы ночёвки — в ближайший район с role=base, если ≤ 55 км.
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function mergeOrphansIntoBases(array $clusters): array
    {
        $baseIdx = [];
        foreach ($clusters as $i => $c) {
            foreach ($c as $row) {
                if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                    $baseIdx[] = $i;
                    break;
                }
            }
        }
        if ($baseIdx === []) {
            return $clusters;
        }

        $merged = $clusters;
        for ($i = 0; $i < count($clusters); $i++) {
            if (in_array($i, $baseIdx, true)) {
                continue;
            }
            if (! isset($merged[$i])) {
                continue;
            }
            // Промежуточный район из нескольких точек (Мольвено+Товел) не схлопывать в базу
            if (count($merged[$i]) >= 2) {
                continue;
            }
            [$lat, $lng] = $this->centroid($merged[$i]);
            $bestJ = null;
            $bestD = PHP_FLOAT_MAX;
            foreach ($baseIdx as $j) {
                if (! isset($merged[$j])) {
                    continue;
                }
                [$blat, $blng] = $this->centroid($merged[$j]);
                $d = $this->distanceKm($lat, $lng, $blat, $blng);
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestJ = $j;
                }
            }
            if ($bestJ !== null && $bestD <= 55.0) {
                [$blat] = $this->centroid($merged[$bestJ]);
                // Южный выезд не приклеивать к северной базе
                if ($lat < $blat - 0.28) {
                    continue;
                }
                $merged[$bestJ] = array_merge($merged[$bestJ], $merged[$i]);
                unset($merged[$i]);
            }
        }

        return array_values($merged);
    }

    /**
     * Соседние базы ночёвки (≤ 25 км) — один район (Рива+Ледро), чтобы было больше дней на точки.
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function mergeNearbyBaseClusters(array $clusters): array
    {
        if (count($clusters) <= 1) {
            return $clusters;
        }
        $merged = $clusters;
        $changed = true;
        while ($changed) {
            $changed = false;
            $n = count($merged);
            for ($i = 0; $i < $n; $i++) {
                if (! isset($merged[$i]) || $this->clusterIsEndPoint($merged[$i])) {
                    continue;
                }
                $baseA = $this->pickOvernight($merged[$i]);
                if (! $baseA) {
                    continue;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (! isset($merged[$j]) || $this->clusterIsEndPoint($merged[$j])) {
                        continue;
                    }
                    $baseB = $this->pickOvernight($merged[$j]);
                    if (! $baseB) {
                        continue;
                    }
                    $d = $this->distanceKm(
                        (float) $baseA['lat'],
                        (float) $baseA['lng'],
                        (float) $baseB['lat'],
                        (float) $baseB['lng'],
                    );
                    if ($d > 25.0) {
                        continue;
                    }
                    $merged[$i] = array_merge($merged[$i], $merged[$j]);
                    unset($merged[$j]);
                    $merged = array_values($merged);
                    $changed = true;
                    break 2;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * С запада на восток; одиночные южные точки (выезд) — в конец.
     *
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function orderForTrip(array $clusters): array
    {
        if (count($clusters) <= 1) {
            return $clusters;
        }
        $lats = [];
        foreach ($clusters as $c) {
            [$lat] = $this->centroid($c);
            $lats[] = $lat;
        }
        $meanLat = array_sum($lats) / count($lats);

        $main = [];
        $tail = [];
        foreach ($clusters as $c) {
            [$lat, $lng] = $this->centroid($c);
            $isSouthOutlier = count($c) <= 2 && $lat < ($meanLat - 0.25);
            if ($isSouthOutlier) {
                $tail[] = ['c' => $c, 'lng' => $lng, 'lat' => $lat];
            } else {
                $main[] = ['c' => $c, 'lng' => $lng, 'lat' => $lat];
            }
        }
        usort($main, function (array $a, array $b) {
            if (abs($a['lng'] - $b['lng']) > 0.08) {
                return $a['lng'] <=> $b['lng'];
            }

            return $a['lat'] <=> $b['lat'];
        });
        usort($tail, fn (array $a, array $b) => $a['lat'] <=> $b['lat']);

        return array_merge(
            array_column($main, 'c'),
            array_column($tail, 'c'),
        );
    }

    /**
     * Дни по числу точек района; финиш — 1 день; не раздувать район без мест.
     *
     * @param  list<string>  $days
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<array{from: ?string, to: ?string, days: list<string>}>
     */
    private function allocateDays(array $days, array $clusters): array
    {
        $n = count($clusters);
        $total = count($days);
        if ($n <= 0) {
            return [];
        }
        if ($n === 1) {
            return [[
                'from' => $days[0] ?? null,
                'to' => $days[$total - 1] ?? null,
                'days' => $days,
            ]];
        }

        $need = [];
        $capacity = [];
        foreach ($clusters as $i => $c) {
            if ($this->clusterIsEndPoint($c)) {
                $need[$i] = 1;
                $capacity[$i] = 1;
                continue;
            }
            // У каждого не-финишного района последний день — переезд дальше (кроме самого хвоста без end_point)
            $hasTransferOut = $i < $n - 1;
            $need[$i] = $this->daysNeededForCluster($c, $hasTransferOut);
            // Сколько дней район ещё способен наполнить без пустых «только база»
            $fillable = $this->maxFillableDays($c, $hasTransferOut);
            // Район только с базой (0 POI) — 0 дней: ночёвка уже с прошлого переезда
            $capacity[$i] = $fillable <= 0 ? 0 : min(5, max($need[$i], $fillable));
            $need[$i] = min($need[$i], $capacity[$i]);
        }
        // Если сумма need меньше total — докинуть только районам с запасом мест/часов
        $sumNeed = array_sum($need);
        if ($sumNeed < $total) {
            $extra = $total - $sumNeed;
            $order = array_keys($need);
            usort($order, function ($a, $b) use ($capacity, $need) {
                $sa = ($capacity[$a] ?? 0) - ($need[$a] ?? 0);
                $sb = ($capacity[$b] ?? 0) - ($need[$b] ?? 0);

                return $sb <=> $sa;
            });
            $k = 0;
            $guard = 0;
            while ($extra > 0 && $order !== [] && $guard < 80) {
                $i = $order[$k % count($order)];
                if (! $this->clusterIsEndPoint($clusters[$i])
                    && ($need[$i] ?? 0) < ($capacity[$i] ?? 0)) {
                    $need[$i]++;
                    $extra--;
                }
                $k++;
                $guard++;
                // Если никто не может принять — лишние дни всё равно придётся раздать
                // (ниже хвост уйдёт в последний не-финиш); не крутить бесконечно
                if ($guard >= count($order) && $extra === $total - array_sum($need)) {
                    break;
                }
            }
            // Остаток сверх fillable — см. padTailStayDays (дни у баз Брунико/Мизурина)
        }
        // area_min не должен раздувать need выше того, что реально заполняется
        foreach ($clusters as $i => $c) {
            if ($this->clusterIsEndPoint($c)) {
                continue;
            }
            $cap = max(0, (int) ($capacity[$i] ?? 0));
            $need[$i] = max(0, min((int) ($need[$i] ?? 0), $cap));
        }
        // Хвост поездки: районы только с базой (Брунико, Мизурина) получают дни пребывания
        $sumNeed = array_sum($need);
        if ($sumNeed < $total) {
            $extra = $total - $sumNeed;
            // С запада на восток: сначала Брунико (куда уже переезд), потом Мизурина
            $tail = [];
            for ($i = 0; $i < $n; $i++) {
                if ($this->clusterIsEndPoint($clusters[$i])) {
                    continue;
                }
                if (($capacity[$i] ?? 0) > 0) {
                    continue;
                }
                if ($this->pickOvernight($clusters[$i]) === null) {
                    continue;
                }
                $tail[] = $i;
            }
            foreach ($tail as $i) {
                if ($extra <= 0) {
                    break;
                }
                $need[$i] = 1;
                $capacity[$i] = 1;
                $extra--;
            }
            $guard = 0;
            while ($extra > 0 && $tail !== [] && $guard < 40) {
                $progress = false;
                // Докидываем с конца (больше дней у финальной базы — Мизурина)
                for ($ti = count($tail) - 1; $ti >= 0; $ti--) {
                    $i = $tail[$ti];
                    if ($extra <= 0) {
                        break;
                    }
                    $bounds = $this->areaDayBounds($clusters[$i]);
                    $maxStay = $bounds !== null ? max(1, min(5, $bounds[1])) : 2;
                    if (($need[$i] ?? 0) >= $maxStay) {
                        continue;
                    }
                    $need[$i]++;
                    $capacity[$i] = $need[$i];
                    $extra--;
                    $progress = true;
                }
                if (! $progress) {
                    break;
                }
                $guard++;
            }
        }
        // Если need больше total — урезать только «мягкий» запас выше минимума
        while (array_sum($need) > $total) {
            $cut = false;
            $order = [];
            foreach ($clusters as $i => $c) {
                if ($this->clusterIsEndPoint($c)) {
                    continue;
                }
                // Дни пребывания у базы без POI не режем в первую очередь
                if ($this->clusterTouristPlaces($c) === []) {
                    continue;
                }
                $hasTransferOut = $i < $n - 1;
                $min = $this->minDaysForCluster($c, $hasTransferOut);
                $spare = ($need[$i] ?? 0) - $min;
                if ($spare > 0) {
                    $order[] = ['i' => $i, 'spare' => $spare, 'heavy' => $this->countHeavy($this->clusterTouristPlaces($c))];
                }
            }
            usort($order, function (array $a, array $b) {
                if ($a['heavy'] !== $b['heavy']) {
                    return $a['heavy'] <=> $b['heavy'];
                }

                return $b['spare'] <=> $a['spare'];
            });
            if ($order !== []) {
                $need[$order[0]['i']]--;
                $cut = true;
            }
            if (! $cut) {
                break;
            }
        }
        // Давление: короткие переезды без тяжёлых (Ledro→Riva) сжимаем раньше,
        // чем районы с Marmolada / Tre Cime.
        while (array_sum($need) > $total) {
            $cut = false;
            foreach ($clusters as $i => $c) {
                if ($this->clusterIsEndPoint($c) || ($need[$i] ?? 0) <= 1) {
                    continue;
                }
                if ($this->countHeavy($this->clusterTouristPlaces($c)) > 0) {
                    continue;
                }
                if ($i >= $n - 1) {
                    continue;
                }
                [$alat, $alng] = $this->centroid($c);
                [$blat, $blng] = $this->centroid($clusters[$i + 1]);
                $hop = $this->distanceKm($alat, $alng, $blat, $blng);
                if ($hop <= 45.0) {
                    $need[$i]--;
                    $cut = true;
                    break;
                }
            }
            if (! $cut) {
                break;
            }
        }
        // Последний резерв: режем только районы без тяжёлых (подушку у Marmolada/Tre Cime не трогаем)
        while (array_sum($need) > $total) {
            $cut = false;
            for ($i = 0; $i < $n; $i++) {
                if ($this->clusterIsEndPoint($clusters[$i]) || ($need[$i] ?? 0) <= 1) {
                    continue;
                }
                if ($this->countHeavy($this->clusterTouristPlaces($clusters[$i])) > 0) {
                    continue;
                }
                $need[$i]--;
                $cut = true;
                break;
            }
            if (! $cut) {
                break;
            }
        }

        $sizes = array_values($need);
        $out = [];
        $offset = 0;
        foreach ($sizes as $size) {
            $size = max(0, (int) $size);
            if ($size === 0) {
                $out[] = ['from' => null, 'to' => null, 'days' => []];
                continue;
            }
            $chunk = array_slice($days, $offset, $size);
            $offset += $size;
            if ($chunk === []) {
                $out[] = ['from' => null, 'to' => null, 'days' => []];
                continue;
            }
            $out[] = [
                'from' => $chunk[0] ?? null,
                'to' => $chunk[count($chunk) - 1] ?? null,
                'days' => $chunk,
            ];
        }
        // Хвост сверх capacity не раздаём — пустые дни хуже «дыр» в календаре;
        // stripEmpty / эмит просто не печатают незаполненное.

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     */
    private function clusterIsEndPoint(array $cluster): bool
    {
        foreach ($cluster as $row) {
            if (! empty($row['is_end_point']) || ($row['role'] ?? '') === 'end_point') {
                return true;
            }
            if ($this->looksLikeEndPoint($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return list<list<array<string, mixed>>>
     */
    private function ensureEndPointLast(array $clusters): array
    {
        $main = [];
        $tail = [];
        foreach ($clusters as $c) {
            if ($this->clusterIsEndPoint($c)) {
                $tail[] = $c;
            } else {
                $main[] = $c;
            }
        }

        return array_merge($main, $tail);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function tagEndPoint(array $row): array
    {
        if ($this->looksLikeEndPoint($row)) {
            $row['is_end_point'] = true;
            if (($row['role'] ?? '') !== 'base') {
                $row['role'] = 'end_point';
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function looksLikeEndPoint(array $row): bool
    {
        if (($row['trip_role'] ?? '') === 'end' || ! empty($row['is_end_point'])) {
            return true;
        }
        $blob = mb_strtolower((string) ($row['name'] ?? '').' '.(string) ($row['snippet'] ?? ''));

        return (bool) preg_match(
            '/borghetto|боргетто|בורגטו|malpensa|мальпенса|airport|аэропорт|linate|линате/ui',
            $blob
        );
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     * @return array{0: int, 1: int}|null min,max из area
     */
    private function areaDayBounds(array $cluster): ?array
    {
        foreach ($cluster as $row) {
            $min = isset($row['area_min_days']) ? (int) $row['area_min_days'] : 0;
            $max = isset($row['area_max_days']) ? (int) $row['area_max_days'] : 0;
            if ($min > 0 || $max > 0) {
                $min = max(1, $min ?: 1);
                $max = max($min, $max ?: 5);

                return [$min, min(5, $max)];
            }
        }

        return null;
    }

    /**
     * Сколько дней район реально должен занять по часам/тяжёлым точкам.
     *
     * @param  list<array<string, mixed>>  $cluster
     */
    private function daysNeededForCluster(array $cluster, bool $hasTransferOut = false): int
    {
        $places = $this->clusterTouristPlaces($cluster);
        if ($places === []) {
            // Только база/ночёвка — календарный день не жрём (приезд уже вечером прошлого района)
            return 0;
        }
        $heavy = 0;
        $lights = 0;
        $lightHours = 0.0;
        foreach ($places as $p) {
            if ($this->isHeavyPlace($p)) {
                $heavy++;
            } else {
                $lights++;
                $lightHours += $this->estimateVisitHours($p) + self::HOP_HOURS;
            }
        }
        $lightDays = $lightHours > 0.01 ? (int) ceil($lightHours / self::DAY_HOURS_BUDGET) : 0;
        // Лёгкие влезают в один день вместе с переездом — не плодим пустой день «только переезд»
        if ($hasTransferOut && $heavy === 0 && $this->lightsFitWithTransfer($lightHours, $lights)) {
            $need = max(1, $lightDays);
        } else {
            $need = $heavy + max($lightDays, 0);
            if ($hasTransferOut) {
                $need = max($need, $heavy + 1);
                if ($lights > 0) {
                    $lightTourist = max(0, (int) ceil(max(0, $lights - 1) / 2));
                    $need = max($need, $heavy + $lightTourist + 1);
                }
            }
        }

        $need = max(1, min(5, $need));
        $fillable = $this->maxFillableDays($cluster, $hasTransferOut);
        $bounds = $this->areaDayBounds($cluster);
        if ($bounds !== null) {
            [$amin, $amax] = $bounds;
            // Не раздувать район пустыми днями ради area_min
            $amin = min($amin, $fillable);
            $amax = max($amin, min($amax, $fillable));
            $need = max($amin, min($amax, $need));
        }

        return max(1, min($need, $fillable));
    }

    /** Две лёгкие + межрайонный переезд (~2ч) ещё влезают в один день. */
    private function lightsFitWithTransfer(float $lightHours, int $lights): bool
    {
        if ($lights <= 0 || $lights > self::MAX_LIGHTS_PER_DAY) {
            return false;
        }

        return ($lightHours + 2.0) <= (self::DAY_HOURS_BUDGET + 0.5);
    }

    /**
     * Максимум дней, которые можно наполнить без пустых (1 точка / день).
     *
     * @param  list<array<string, mixed>>  $cluster
     */
    private function maxFillableDays(array $cluster, bool $hasTransferOut = false): int
    {
        $n = count($this->clusterTouristPlaces($cluster));
        if ($n <= 0) {
            return 0;
        }
        $max = $n;
        $bounds = $this->areaDayBounds($cluster);
        if ($bounds !== null) {
            $max = min($max, max(1, $bounds[1]));
        }

        return max(1, min(5, $max));
    }

    /**
     * Минимум дней: каждая тяжёлая — свой tourist-день; при переезде ещё +1.
     * Две лёгкие + переезд в один день — ок (Нардис+Товель+дорога), пустой день не нужен.
     *
     * @param  list<array<string, mixed>>  $cluster
     */
    private function minDaysForCluster(array $cluster, bool $hasTransferOut): int
    {
        $places = $this->clusterTouristPlaces($cluster);
        $heavy = $this->countHeavy($places);
        $lights = count($places) - $heavy;
        $lightHours = 0.0;
        foreach ($places as $p) {
            if (! $this->isHeavyPlace($p)) {
                $lightHours += $this->estimateVisitHours($p) + self::HOP_HOURS;
            }
        }
        $min = 1;
        if ($heavy > 0) {
            $min = max(1, min(5, $heavy + ($hasTransferOut ? 1 : 0)));
        } elseif ($hasTransferOut && $lights >= 2 && ! $this->lightsFitWithTransfer($lightHours, $lights)) {
            $min = 2;
        }
        $fillable = $this->maxFillableDays($cluster, $hasTransferOut);
        if ($fillable <= 0) {
            return 0;
        }
        $bounds = $this->areaDayBounds($cluster);
        if ($bounds !== null) {
            $min = max($min, min($bounds[0], $fillable));
        }

        return max(1, min($min, $fillable));
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     * @return list<array<string, mixed>>
     */
    private function clusterTouristPlaces(array $cluster): array
    {
        $base = $this->pickOvernight($cluster);
        $baseId = $base ? (int) $base['id'] : 0;

        return array_values(array_filter(
            $cluster,
            fn (array $r) => (int) ($r['id'] ?? 0) !== $baseId
                && empty($r['is_end_point'])
                && ($r['role'] ?? '') !== 'end_point'
                && empty($r['is_base'])
                && ($r['role'] ?? '') !== 'base'
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     * @param  list<string>  $days
     * @param  array<string, mixed>|null  $base
     * @return array<string, list<array<string, mixed>>>
     */
    private function assignPlacesToDays(
        array $cluster,
        array $days,
        ?array $base,
        bool $hasTransferOut = false,
        bool $transferToEndPoint = false,
    ): array {
        if ($days === []) {
            return [];
        }
        $places = $this->clusterTouristPlaces($cluster);
        usort($places, function (array $a, array $b) {
            $ha = $this->isHeavyPlace($a) ? 1 : 0;
            $hb = $this->isHeavyPlace($b) ? 1 : 0;
            if ($ha !== $hb) {
                return $hb <=> $ha;
            }
            $ea = $this->estimateVisitHours($a);
            $eb = $this->estimateVisitHours($b);
            if (abs($ea - $eb) > 0.2) {
                return $eb <=> $ea;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $slots = [];
        foreach ($days as $d) {
            $slots[$d] = [];
        }
        if ($places === []) {
            return $slots;
        }

        $nDays = count($days);
        $transferIdx = $hasTransferOut ? $nDays - 1 : -1;
        $dayIdx = array_values($days);

        $used = [];
        // 1) Тяжёлые — почти одни на туристический день (не на день переезда)
        foreach ($places as $p) {
            if (! $this->isHeavyPlace($p)) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            $target = $this->pickDayForHeavy($dayIdx, $slots, $transferIdx);
            if ($target === null) {
                continue;
            }
            $slots[$target][] = $p;
            $used[$id] = true;
        }

        // 2) Лёгкие — паром раньше (чтобы сесть с Лимоне), затем остальные
        $lightsOrdered = array_values(array_filter(
            $places,
            fn (array $p) => ! isset($used[(int) ($p['id'] ?? 0)]) && ! $this->isHeavyPlace($p)
        ));
        usort($lightsOrdered, function (array $a, array $b) {
            $fa = $this->isFerryPlace($a) || $this->isLimonePlace($a) ? 0 : 1;
            $fb = $this->isFerryPlace($b) || $this->isLimonePlace($b) ? 0 : 1;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        foreach ($lightsOrdered as $p) {
            $id = (int) ($p['id'] ?? 0);
            if (isset($used[$id])) {
                continue;
            }
            $target = $this->pickDayForLight($dayIdx, $slots, $transferIdx, $p, $transferToEndPoint, $base);
            if ($target === null) {
                continue;
            }
            $slots[$target][] = $p;
            $used[$id] = true;
        }

        // 3) На выезд в конечную точку — если день переезда пуст, ближайшая лёгкая к базе
        if ($transferIdx >= 0 && $transferToEndPoint && $base) {
            $td = $dayIdx[$transferIdx];
            if (($slots[$td] ?? []) === []) {
                $rest = array_values(array_filter(
                    $places,
                    fn (array $p) => ! isset($used[(int) ($p['id'] ?? 0)]) && ! $this->isHeavyPlace($p)
                ));
                $near = $this->nearestTo($rest !== [] ? $rest : array_values(array_filter(
                    $places,
                    fn (array $p) => ! isset($used[(int) ($p['id'] ?? 0)]) && ! $this->isHeavyPlace($p)
                )), $base);
                if ($near !== null) {
                    $slots[$td][] = $near;
                    $used[(int) $near['id']] = true;
                }
            }
        }

        // 4) Ничего не выбрасываем: остаток — в наименее загруженный tourist-день
        foreach ($places as $p) {
            $id = (int) ($p['id'] ?? 0);
            if (isset($used[$id])) {
                continue;
            }
            if ($this->isHeavyPlace($p)) {
                $target = $this->pickDayForHeavy($dayIdx, $slots, $transferIdx);
                // Лучше день переезда с одной тяжёлой, чем потерять Tre Cime / Marmolada
                if ($target === null) {
                    $target = $dayIdx[$transferIdx] ?? ($dayIdx[0] ?? null);
                }
                if ($target === null && $dayIdx !== []) {
                    $target = $dayIdx[0];
                }
                if ($target !== null) {
                    $slots[$target][] = $p;
                    $used[$id] = true;
                }
                continue;
            }
            $target = $this->pickDayForLight($dayIdx, $slots, $transferIdx, $p, $transferToEndPoint, $base);
            if ($target === null) {
                foreach ($dayIdx as $i => $d) {
                    $list = $slots[$d] ?? [];
                    if ($i === $transferIdx) {
                        if ($list !== [] || $this->estimateVisitHours($p) > self::TRANSFER_ACT_HOURS) {
                            continue;
                        }
                        $target = $d;
                        break;
                    }
                    $heavyInDay = null;
                    foreach ($list as $hp) {
                        if ($this->isHeavyPlace($hp)) {
                            $heavyInDay = $hp;
                            break;
                        }
                    }
                    if ($heavyInDay !== null) {
                        if (count($list) >= 2 || ! $this->canPairWithHeavy($heavyInDay, $p)) {
                            continue;
                        }
                    }
                    $needH = $this->estimateVisitHours($p) + self::HOP_HOURS;
                    if ($this->slotHours($list) + $needH > self::DAY_HOURS_BUDGET) {
                        continue;
                    }
                    $target = $d;
                    break;
                }
            }
            if ($target === null) {
                $target = $this->forceDayFor($slots, $dayIdx, $transferIdx, $p);
            }
            if ($target !== null) {
                $slots[$target][] = $p;
                $used[$id] = true;
            }
        }

        return $slots;
    }

    /**
     * @param  list<string>  $dayIdx
     * @param  array<string, list<array<string, mixed>>>  $slots
     */
    private function pickDayForHeavy(array $dayIdx, array $slots, int $transferIdx): ?string
    {
        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($dayIdx as $i => $d) {
            if ($i === $transferIdx) {
                continue;
            }
            $load = $this->slotHours($slots[$d] ?? []);
            $count = count($slots[$d] ?? []);
            // Предпочитаем пустой день; иначе день без другой тяжёлой и с запасом
            if ($count === 0) {
                return $d;
            }
            $hasHeavy = false;
            foreach ($slots[$d] as $p) {
                if ($this->isHeavyPlace($p)) {
                    $hasHeavy = true;
                    break;
                }
            }
            if ($hasHeavy || $load + self::HEAVY_HOURS > self::DAY_HOURS_BUDGET + 0.3) {
                continue;
            }
            if ($load < $bestScore) {
                $bestScore = $load;
                $best = $d;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $dayIdx
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>|null  $base
     */
    private function pickDayForLight(
        array $dayIdx,
        array $slots,
        int $transferIdx,
        array $place,
        bool $transferToEndPoint,
        ?array $base,
    ): ?string {
        $need = $this->estimateVisitHours($place) + self::HOP_HOURS;
        $best = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($dayIdx as $i => $d) {
            $isTransfer = $i === $transferIdx;
            $list = $slots[$d] ?? [];
            $load = $this->slotHours($list);

            if ($isTransfer) {
                // Единственный день района + переезд: бюджет как у transfer, не полный tourist-день
                // (иначе Braies+Kronplatz+Misurina влезают «как обычный день»)
                if (count($dayIdx) === 1) {
                    // Single-day район + переезд: максимум 1 активность (не Braies+Kronplatz)
                    if (count($list) >= 1) {
                        continue;
                    }
                    if ($this->estimateVisitHours($place) > self::TRANSFER_ACT_HOURS + 1.0) {
                        // одна тяжёлая — только если ≤ ~3.5ч
                        continue;
                    }
                    if ($load + $need > self::DAY_HOURS_BUDGET) {
                        continue;
                    }
                    $score = $load;
                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $best = $d;
                    }
                    continue;
                }
                // День переезда — бюджет активностей жёстко ≤ TRANSFER_ACT_HOURS
                if ($list !== []) {
                    continue;
                }
                if ($this->estimateVisitHours($place) > self::TRANSFER_ACT_HOURS) {
                    continue;
                }
                if ($load + $this->estimateVisitHours($place) > self::TRANSFER_ACT_HOURS) {
                    continue;
                }
                if ($this->hasThinTouristDay($dayIdx, $slots, $transferIdx)) {
                    continue;
                }
                $score = $load + 2.5;
                if ($transferToEndPoint && $base) {
                    $score += $this->distanceKm(
                        (float) $base['lat'],
                        (float) $base['lng'],
                        (float) ($place['lat'] ?? $base['lat']),
                        (float) ($place['lng'] ?? $base['lng']),
                    ) * 0.001;
                }
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = $d;
                }
                continue;
            }

            // К тяжёлой — только если бюджет часов позволяет (Busatte+короткая; не Tre Cime)
            $heavyInDay = null;
            foreach ($list as $p) {
                if ($this->isHeavyPlace($p)) {
                    $heavyInDay = $p;
                    break;
                }
            }
            if ($heavyInDay !== null) {
                if ($this->estimateVisitHours($heavyInDay) >= 4.5) {
                    continue;
                }
                // С тяжёлой — максимум один компаньон
                if (count($list) >= 2) {
                    continue;
                }
                if (! $this->canPairWithHeavy($heavyInDay, $place)) {
                    continue;
                }
            }
            if ($load + $need > self::DAY_HOURS_BUDGET) {
                continue;
            }
            // Две «длинные» лёгкие (Selva 3ч + Carezza 3ч) + переезд — не в один день
            if ($this->isLongLight($place) && $this->slotHasLongLight($list)) {
                continue;
            }
            // Паром: не с тяжёлой; максимум паром + 1 лёгкая (Лимоне)
            if ($this->isFerryPlace($place) || $this->slotHasFerry($list)) {
                if ($this->slotHasHeavy($list) || $this->isHeavyPlace($place)) {
                    continue;
                }
                if ($this->slotHasFerry($list) && count($list) >= 2) {
                    continue;
                }
                if ($this->isFerryPlace($place) && ($this->slotHasFerry($list) || count($list) >= 2)) {
                    continue;
                }
            }
            // Чуть предпочитаем дни, которые уже начаты (не плодить пустые), но не перегружать
            $score = $load + ($list === [] ? 0.35 : 0.0);
            if ($this->isFerryPlace($place) && $this->slotHasLimone($list) && count($list) === 1) {
                $score -= 3.0;
            }
            if ($this->isLimonePlace($place) && $this->slotHasFerry($list) && count($list) === 1) {
                $score -= 3.0;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }

        // Если никуда не влезло — в наименее загруженный tourist-день (лучше слегка плотнее, чем выкинуть)
        if ($best === null) {
            foreach ($dayIdx as $i => $d) {
                if ($i === $transferIdx) {
                    continue;
                }
                $hasHeavy = false;
                foreach ($slots[$d] ?? [] as $p) {
                    if ($this->isHeavyPlace($p)) {
                        $hasHeavy = true;
                        break;
                    }
                }
                $list = $slots[$d] ?? [];
                $heavyInDay = null;
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p)) {
                        $heavyInDay = $p;
                        break;
                    }
                }
                if ($heavyInDay !== null) {
                    if (count($list) >= 2 || ! $this->canPairWithHeavy($heavyInDay, $place)) {
                        continue;
                    }
                }
                $load = $this->slotHours($list);
                if ($load + $need > self::DAY_HOURS_BUDGET) {
                    continue;
                }
                if ($load < $bestScore) {
                    $bestScore = $load;
                    $best = $d;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $heavy
     * @param  array<string, mixed>  $light
     */
    private function canPairWithHeavy(array $heavy, array $light): bool
    {
        if ($this->isHeavyPlace($light)) {
            return false;
        }
        $hh = $this->estimateVisitHours($heavy);
        // Marmolada / Tre Cime — без компаньонов
        if ($hh >= 4.0) {
            return false;
        }

        return ($hh + $this->estimateVisitHours($light) + self::HOP_HOURS) <= self::DAY_HOURS_BUDGET;
    }

    /**
     * Пустые tourist-дни заполнить с соседей; день переезда — 1 лёгкая с предыдущего.
     *
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  list<string>  $dayList
     * @return array<string, list<array<string, mixed>>>
     */
    private function balanceDaySlots(array $slots, array $dayList, bool $hasTransferOut, bool $toEnd): array
    {
        if ($dayList === []) {
            return $slots;
        }
        $n = count($dayList);
        $transferIdx = $hasTransferOut ? $n - 1 : -1;

        // Single-day район + переезд: не больше 1 активности
        if ($transferIdx >= 0 && count($dayList) === 1) {
            $td = $dayList[0];
            $list = $slots[$td] ?? [];
            if (count($list) > 1) {
                $keep = null;
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p)) {
                        $keep = $p;
                        break;
                    }
                }
                if ($keep === null) {
                    foreach ($list as $p) {
                        if ($this->estimateVisitHours($p) <= self::TRANSFER_ACT_HOURS) {
                            $keep = $p;
                            break;
                        }
                    }
                }
                if ($keep === null) {
                    $keep = $list[0];
                }
                $slots[$td] = [$keep];
            }
        }

        // День переезда пуст → забрать одну лёгкую с самого загруженного предыдущего дня
        if ($transferIdx >= 0 && count($dayList) > 1) {
            $td = $dayList[$transferIdx];
            if (($slots[$td] ?? []) === []) {
                $donor = null;
                $donorLoad = -1.0;
                for ($j = 0; $j < $transferIdx; $j++) {
                    $d = $dayList[$j];
                    $list = $slots[$d] ?? [];
                    if (count($list) < 2) {
                        continue;
                    }
                    $light = null;
                    foreach (array_reverse($list) as $p) {
                        if (! $this->isHeavyPlace($p) && $this->estimateVisitHours($p) <= self::TRANSFER_ACT_HOURS) {
                            $light = $p;
                            break;
                        }
                    }
                    if ($light === null) {
                        continue;
                    }
                    $load = $this->slotHours($list);
                    if ($load > $donorLoad) {
                        $donorLoad = $load;
                        $donor = ['date' => $d, 'place' => $light];
                    }
                }
                if ($donor !== null) {
                    $did = (int) ($donor['place']['id'] ?? 0);
                    $slots[$donor['date']] = array_values(array_filter(
                        $slots[$donor['date']],
                        fn (array $p) => (int) ($p['id'] ?? 0) !== $did
                    ));
                    $slots[$td][] = $donor['place'];
                }
            } elseif (count($slots[$td]) > 1 || $this->slotHasHeavy($slots[$td])) {
                // С переезда убрать лишнее; тяжёлую — в предыдущий день, если он есть
                $hasTouristDay = $transferIdx > 0;
                $keep = null;
                $heavyKeep = null;
                $move = [];
                foreach ($slots[$td] as $p) {
                    if ($this->isHeavyPlace($p)) {
                        if ($hasTouristDay) {
                            $move[] = $p;
                        } elseif ($heavyKeep === null) {
                            $heavyKeep = $p; // единственный день района — оставляем тяжёлую одну
                        } else {
                            $move[] = $p;
                        }
                        continue;
                    }
                    if ($this->estimateVisitHours($p) > self::TRANSFER_ACT_HOURS) {
                        $move[] = $p;
                        continue;
                    }
                    if ($heavyKeep !== null) {
                        $move[] = $p; // с тяжёлой компаньонов не держим
                        continue;
                    }
                    if ($keep === null) {
                        $keep = $p;
                    } else {
                        $move[] = $p;
                    }
                }
                if ($heavyKeep !== null) {
                    $slots[$td] = [$heavyKeep];
                } else {
                    $slots[$td] = $keep ? [$keep] : [];
                }
                foreach ($move as $p) {
                    $this->pushToBestTouristDay($slots, $dayList, $transferIdx, $p);
                }
            }
        }

        // Пустые tourist-дни — забрать точку с самого загруженного дня (не трогая единственную тяжёлую)
        for ($pass = 0; $pass < $n; $pass++) {
            $emptyI = null;
            foreach ($dayList as $i => $d) {
                if ($i === $transferIdx) {
                    continue;
                }
                if (($slots[$d] ?? []) === []) {
                    $emptyI = $i;
                    break;
                }
            }
            if ($emptyI === null) {
                break;
            }
            $stolen = false;
            $order = [];
            foreach ($dayList as $i => $d) {
                if ($i === $transferIdx || $i === $emptyI) {
                    continue;
                }
                $order[] = $i;
            }
            usort($order, function ($a, $b) use ($dayList, $slots) {
                return $this->slotHours($slots[$dayList[$b]] ?? []) <=> $this->slotHours($slots[$dayList[$a]] ?? []);
            });
            foreach ($order as $i) {
                $d = $dayList[$i];
                $list = $slots[$d] ?? [];
                if (count($list) < 2) {
                    continue;
                }
                // Не оставлять donor пустым и не снимать единственную тяжёлую
                $candidate = null;
                foreach (array_reverse($list) as $p) {
                    if ($this->isHeavyPlace($p) && $this->countHeavy($list) <= 1 && count($list) === 1) {
                        continue;
                    }
                    if ($this->isHeavyPlace($p) && count($list) === 1) {
                        continue;
                    }
                    // Снимаем лёгкую в приоритете
                    if (! $this->isHeavyPlace($p)) {
                        $candidate = $p;
                        break;
                    }
                }
                if ($candidate === null) {
                    continue;
                }
                $cid = (int) ($candidate['id'] ?? 0);
                $slots[$d] = array_values(array_filter(
                    $list,
                    fn (array $p) => (int) ($p['id'] ?? 0) !== $cid
                ));
                if ($slots[$d] === [] || ($this->slotHours($slots[$d]) < 0.5 && $this->countHeavy($slots[$d]) === 0)) {
                    // откат — donor не должен стать пустым
                    $slots[$d] = $list;
                    continue;
                }
                $slots[$dayList[$emptyI]][] = $candidate;
                $stolen = true;
                break;
            }
            if (! $stolen) {
                break;
            }
        }

        // Тяжёлая + лишние компаньоны — оставить только тех, кто влезает по часам
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx) {
                continue;
            }
            $list = $slots[$d] ?? [];
            if (! $this->slotHasHeavy($list) || count($list) < 2) {
                continue;
            }
            $heavy = [];
            $lights = [];
            foreach ($list as $p) {
                if ($this->isHeavyPlace($p)) {
                    $heavy[] = $p;
                } else {
                    $lights[] = $p;
                }
            }
            if ($heavy === []) {
                continue;
            }
            $keep = [$heavy[0]];
            $move = array_slice($heavy, 1);
            foreach ($lights as $p) {
                if (count($keep) === 1 && $this->canPairWithHeavy($heavy[0], $p)) {
                    $keep[] = $p;
                } else {
                    $move[] = $p;
                }
            }
            $slots[$d] = $keep;
            foreach ($move as $p) {
                $this->pushToBestTouristDay($slots, $dayList, $transferIdx, $p);
            }
        }

        // Анти-свалка: не больше 2 лёгких / день; хвост — в недогруженные дни, иначе выкидываем из плана
        $slots = $this->redistributeLoad($slots, $dayList, $transferIdx);

        return $slots;
    }

    /**
     * Убрать пустые дни района: отдать дату следующему району или выкинуть.
     * Не оставляем «переезд в уже достигнутый X» и «переезд по району» без точек.
     *
     * @param  list<string>  $dayList
     * @param  array<string, list<array<string, mixed>>>  $daySlots
     * @param  list<array{from:?string,to:?string,days:list<string>}>  $spans
     * @param  list<list<array<string, mixed>>>  $clusters
     * @return array{0: list<string>, 1: array<string, list<array<string, mixed>>>, 2: list<array{from:?string,to:?string,days:list<string>}>}
     */
    private function stripEmptyDaysFromCluster(
        array $dayList,
        array $daySlots,
        array $spans,
        array $clusters,
        int $clusterIdx,
        bool $hasTransferOut,
        bool $nextIsEnd,
    ): array {
        if ($dayList === []) {
            return [$dayList, $daySlots, $spans];
        }

        $changed = true;
        $guard = 0;
        while ($changed && $guard < 24) {
            $changed = false;
            $guard++;
            $n = count($dayList);
            $transferIdx = $hasTransferOut ? $n - 1 : -1;

            foreach ($dayList as $i => $date) {
                if (($daySlots[$date] ?? []) !== []) {
                    continue;
                }
                // Единственный день района с переездом — оставляем слот (ночь + переезд),
                // но эмит всё равно не печатает пустую строку без точки.
                if ($n === 1) {
                    continue;
                }
                // Пустой день переезда при ≥2 днях — слабый; дату можно сжать
                // Пустой tourist-день — всегда убираем

                $newList = array_values(array_filter($dayList, fn (string $d) => $d !== $date));
                unset($daySlots[$date]);

                // Попробовать отдать следующему не-финишному району, если у того есть запас fillable
                $dest = $clusterIdx + 1;
                while ($dest < count($clusters) && $this->clusterIsEndPoint($clusters[$dest])) {
                    $dest++;
                }
                $gave = false;
                if ($dest < count($clusters) && isset($spans[$dest])) {
                    $destCluster = $clusters[$dest];
                    $destHasTransfer = $dest < count($clusters) - 1;
                    $destDays = $spans[$dest]['days'] ?? [];
                    $fillable = $this->maxFillableDays($destCluster, $destHasTransfer);
                    if (count($destDays) < $fillable) {
                        array_unshift($spans[$dest]['days'], $date);
                        $spans[$dest]['days'] = array_values($spans[$dest]['days']);
                        $spans[$dest]['from'] = $spans[$dest]['days'][0] ?? $spans[$dest]['from'];
                        $spans[$dest]['to'] = $spans[$dest]['days'][count($spans[$dest]['days']) - 1]
                            ?? $spans[$dest]['to'];
                        $gave = true;
                    }
                }

                // Дату убрали (или отдали дальше). Balance не вызываем —
                // повторный balance на укороченном списке сжимает новый «переезд» и теряет POI.
                $dayList = $newList;
                $changed = true;
                break;
            }
        }

        if ($dayList !== [] && $hasTransferOut && count($dayList) >= 2) {
            $daySlots = $this->absorbWeakTransferDay($daySlots, $dayList);
            $last = $dayList[count($dayList) - 1];
            if (($daySlots[$last] ?? []) === []) {
                // Пустой хвост переезда — дата уходит; активности остаются на новом last (он и есть переезд)
                array_pop($dayList);
                unset($daySlots[$last]);
                $dayList = array_values($dayList);
            }
        }

        return [$dayList, $daySlots, $spans];
    }

    /**
     * Одна точка на дне переезда → перенести на предыдущий день, если влезает по часам.
     *
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  list<string>  $dayList
     * @return array<string, list<array<string, mixed>>>
     */
    private function absorbWeakTransferDay(array $slots, array $dayList): array
    {
        $n = count($dayList);
        if ($n < 2) {
            return $slots;
        }
        $td = $dayList[$n - 1];
        $prev = $dayList[$n - 2];
        $tList = $slots[$td] ?? [];
        // Слабый переезд: 1 лёгкая (Ledro / Tenno) — втянуть в предыдущий день
        if (count($tList) !== 1 || $this->isHeavyPlace($tList[0])) {
            return $slots;
        }
        $p = $tList[0];
        // Не втягивать, если одна точка сама по себе уже «плотная» (Ponale и т.п.)
        if ($this->estimateVisitHours($p) > self::TRANSFER_ACT_HOURS) {
            return $slots;
        }
        $prevList = $slots[$prev] ?? [];
        if ($this->slotHasHeavy($prevList)) {
            $heavy = null;
            foreach ($prevList as $hp) {
                if ($this->isHeavyPlace($hp)) {
                    $heavy = $hp;
                    break;
                }
            }
            if ($heavy === null || count($prevList) >= 2 || ! $this->canPairWithHeavy($heavy, $p)) {
                return $slots;
            }
        } elseif (count($prevList) >= 2) {
            // Не втягивать 3-ю точку: иначе день переезда пустеет, а предыдущий перегружается
            return $slots;
        }
        if ($this->isLongLight($p) && $this->slotHasLongLight($prevList)) {
            return $slots;
        }
        $need = $this->estimateVisitHours($p) + self::HOP_HOURS;
        if ($this->slotHours($prevList) + $need > self::DAY_HOURS_BUDGET) {
            return $slots;
        }
        $slots[$prev][] = $p;
        $slots[$td] = [];

        return $slots;
    }

    /**
     * Снять перегруз (3+ POI / часы) → докинуть в короткие дни. Без свалки в последний день.
     *
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  list<string>  $dayList
     * @return array<string, list<array<string, mixed>>>
     */
    private function redistributeLoad(array $slots, array $dayList, int $transferIdx): array
    {
        if ($dayList === []) {
            return $slots;
        }

        // 1) Обрезать дни с > MAX_LIGHTS или сверх бюджета — лишнее снять в пул
        $pool = [];
        $singleTransferDay = $transferIdx === 0 && count($dayList) === 1;
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx && ! $singleTransferDay) {
                // переезд: 1 точка; 2 только если обе короткие (≤1.5ч)
                $list = $slots[$d] ?? [];
                if ($list === []) {
                    continue;
                }
                $shortOnly = true;
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p) || $this->isLongLight($p) || $this->estimateVisitHours($p) > 1.5) {
                        $shortOnly = false;
                        break;
                    }
                }
                if (count($list) === 1 && ! $this->slotHasHeavy($list)) {
                    continue;
                }
                if (count($list) === 2 && $shortOnly) {
                    continue;
                }
                // Оставить одну (предпочесть long/тяжёлую по score), остальное в пул
                usort($list, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
                $keep = null;
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p)) {
                        $pool[] = $p;
                        continue;
                    }
                    if ($keep === null) {
                        $keep = $p;
                    } else {
                        $pool[] = $p;
                    }
                }
                $slots[$d] = $keep ? [$keep] : [];
                continue;
            }

            $list = $slots[$d] ?? [];
            if ($list === []) {
                continue;
            }
            if ($this->slotHasHeavy($list)) {
                // тяжёлая уже почищена выше; на всякий — не больше 1 компаньона; паром — прочь
                $heavy = null;
                $lights = [];
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p) && $heavy === null) {
                        $heavy = $p;
                    } elseif ($this->isHeavyPlace($p)) {
                        $pool[] = $p;
                    } elseif ($this->isFerryPlace($p)) {
                        $pool[] = $p;
                    } else {
                        $lights[] = $p;
                    }
                }
                $keep = $heavy ? [$heavy] : [];
                foreach ($lights as $p) {
                    if ($heavy && count($keep) === 1 && $this->canPairWithHeavy($heavy, $p)) {
                        $keep[] = $p;
                    } else {
                        $pool[] = $p;
                    }
                }
                $slots[$d] = $keep;
                continue;
            }

            // Только лёгкие: max 2; паром+1; не две long-light (Selva+Carezza)
            usort($list, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $keep = [];
            $maxKeep = $this->slotHasFerry($list) ? 2 : self::MAX_LIGHTS_PER_DAY;
            foreach ($list as $p) {
                $need = $this->estimateVisitHours($p) + ($keep === [] ? 0.0 : self::HOP_HOURS);
                if (count($keep) >= $maxKeep) {
                    $pool[] = $p;
                    continue;
                }
                if ($this->slotHasFerry($keep) && count($keep) >= 2) {
                    $pool[] = $p;
                    continue;
                }
                if ($this->isFerryPlace($p) && $this->slotHasFerry($keep)) {
                    $pool[] = $p;
                    continue;
                }
                if ($this->isLongLight($p) && $this->slotHasLongLight($keep)) {
                    $pool[] = $p;
                    continue;
                }
                if ($this->slotHours($keep) + $need > self::DAY_HOURS_BUDGET) {
                    $pool[] = $p;
                    continue;
                }
                $keep[] = $p;
            }
            $slots[$d] = $keep;
        }

        // 2) Пул → пустые / тонкие дни; не выбрасываем POI (иначе strip даёт пустые 21–23)
        usort($pool, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        foreach ($pool as $p) {
            $target = $this->thinnestDayFor($slots, $dayList, $transferIdx, $p);
            if ($target === null) {
                $target = $this->forceDayFor($slots, $dayList, $transferIdx, $p);
            }
            if ($target !== null) {
                $slots[$target][] = $p;
            }
        }

        // 2b) После пула: тяжёлая + максимум 1 компаньон (лишнее — на другие дни)
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx && ! ($transferIdx === 0 && count($dayList) === 1)) {
                continue;
            }
            $list = $slots[$d] ?? [];
            if (! $this->slotHasHeavy($list) || count($list) < 3) {
                continue;
            }
            $heavy = null;
            $extras = [];
            foreach ($list as $p) {
                if ($this->isHeavyPlace($p) && $heavy === null) {
                    $heavy = $p;
                } else {
                    $extras[] = $p;
                }
            }
            if ($heavy === null) {
                continue;
            }
            $keep = [$heavy];
            $moved = [];
            foreach ($extras as $p) {
                if (count($keep) === 1 && ! $this->isFerryPlace($p) && $this->canPairWithHeavy($heavy, $p)) {
                    $keep[] = $p;
                } else {
                    $moved[] = $p;
                }
            }
            $slots[$d] = $keep;
            foreach ($moved as $p) {
                $target = $this->thinnestDayFor($slots, $dayList, $transferIdx, $p);
                if ($target === null || $target === $d) {
                    $target = null;
                    $bestScore = PHP_FLOAT_MAX;
                    foreach ($dayList as $j => $cand) {
                        if ($cand === $d) {
                            continue;
                        }
                        $cl = $slots[$cand] ?? [];
                        $score = $this->slotHours($cl) + count($cl);
                        if ($this->slotHasHeavy($cl) && count($cl) >= 2) {
                            $score += 40;
                        }
                        if ($this->slotHasFerry($cl) && count($cl) >= 2) {
                            $score += 40;
                        }
                        if ($j === $transferIdx && $cl !== []) {
                            $score += 10;
                        }
                        if ($score < $bestScore) {
                            $bestScore = $score;
                            $target = $cand;
                        }
                    }
                }
                if ($target !== null && $target !== $d) {
                    $slots[$target][] = $p;
                }
            }
        }

        // 2c) Дни только из лёгких — не больше MAX_LIGHTS (после 2b могли раздуться)
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx && ! ($transferIdx === 0 && count($dayList) === 1)) {
                $list = $slots[$d] ?? [];
                // На переезде: 1 любая или 2 короткие (≤1.5ч) — ок; иначе урезать
                if (count($list) <= 1) {
                    continue;
                }
                $allShort = true;
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p) || $this->estimateVisitHours($p) > 1.5) {
                        $allShort = false;
                        break;
                    }
                }
                if ($allShort && count($list) <= 2) {
                    continue;
                }
                $keep = [array_shift($list)];
                $slots[$d] = $keep;
                foreach ($list as $p) {
                    $target = null;
                    foreach ($dayList as $j => $cand) {
                        if ($j === $transferIdx || $cand === $d) {
                            continue;
                        }
                        $cl = $slots[$cand] ?? [];
                        if ($this->slotHasHeavy($cl)) {
                            if (count($cl) >= 2) {
                                continue;
                            }
                        } elseif (count($cl) >= self::MAX_LIGHTS_PER_DAY) {
                            continue;
                        }
                        $target = $cand;
                        break;
                    }
                    if ($target === null) {
                        $target = $this->forceDayFor($slots, $dayList, $transferIdx, $p);
                    }
                    if ($target !== null && $target !== $d) {
                        $slots[$target][] = $p;
                    }
                }
                continue;
            }
            $list = $slots[$d] ?? [];
            if ($this->slotHasHeavy($list) || count($list) <= self::MAX_LIGHTS_PER_DAY) {
                continue;
            }
            $keep = array_slice($list, 0, self::MAX_LIGHTS_PER_DAY);
            $extra = array_slice($list, self::MAX_LIGHTS_PER_DAY);
            $slots[$d] = $keep;
            foreach ($extra as $p) {
                $target = null;
                foreach ($dayList as $j => $cand) {
                    if ($cand === $d) {
                        continue;
                    }
                    $cl = $slots[$cand] ?? [];
                    if ($j === $transferIdx) {
                        if ($cl !== []) {
                            continue;
                        }
                        $target = $cand;
                        break;
                    }
                    if ($this->slotHasHeavy($cl) && count($cl) >= 2) {
                        continue;
                    }
                    if (! $this->slotHasHeavy($cl) && count($cl) >= self::MAX_LIGHTS_PER_DAY) {
                        continue;
                    }
                    $target = $cand;
                    break;
                }
                if ($target === null && $transferIdx >= 0) {
                    $td = $dayList[$transferIdx];
                    $tl = $slots[$td] ?? [];
                    // Две короткие на переезде лучше, чем 3 точки в tourist-дне
                    if ($td !== $d && count($tl) < 2
                        && $this->estimateVisitHours($p) <= 1.5
                        && ($tl === [] || $this->estimateVisitHours($tl[0]) <= 1.5)) {
                        $target = $td;
                    }
                }
                if ($target === null) {
                    $target = $this->forceDayFor($slots, $dayList, $transferIdx, $p);
                }
                if ($target !== null) {
                    $slots[$target][] = $p;
                }
            }
        }

        // 3) Короткие дни (1 лёгкая / мало часов) ← забрать с дней, где 2 лёгкие
        for ($pass = 0; $pass < count($dayList) * 2; $pass++) {
            $thin = null;
            $thinH = PHP_FLOAT_MAX;
            foreach ($dayList as $i => $d) {
                if ($i === $transferIdx) {
                    continue;
                }
                $list = $slots[$d] ?? [];
                if ($this->slotHasHeavy($list)) {
                    continue; // крупная активность одна — ок
                }
                $h = $this->slotHours($list);
                if ($list === [] || ($h < self::MIN_DAY_HOURS && count($list) < self::MAX_LIGHTS_PER_DAY)) {
                    if ($h < $thinH) {
                        $thinH = $h;
                        $thin = $d;
                    }
                }
            }
            if ($thin === null) {
                break;
            }
            $donor = null;
            $donorH = -1.0;
            foreach ($dayList as $i => $d) {
                if ($i === $transferIdx || $d === $thin) {
                    continue;
                }
                $list = $slots[$d] ?? [];
                if ($this->slotHasHeavy($list)) {
                    continue;
                }
                if (count($list) < 2) {
                    continue;
                }
                $h = $this->slotHours($list);
                if ($h > $donorH) {
                    $donorH = $h;
                    $donor = $d;
                }
            }
            if ($donor === null) {
                break;
            }
            $list = $slots[$donor];
            $move = null;
            foreach (array_reverse($list) as $p) {
                if (! $this->isHeavyPlace($p)) {
                    $move = $p;
                    break;
                }
            }
            if ($move === null) {
                break;
            }
            $need = $this->estimateVisitHours($move) + self::HOP_HOURS;
            if ($this->slotHours($slots[$thin] ?? []) + $need > self::DAY_HOURS_BUDGET) {
                break;
            }
            if (count($slots[$thin] ?? []) >= self::MAX_LIGHTS_PER_DAY) {
                break;
            }
            $mid = (int) ($move['id'] ?? 0);
            $slots[$donor] = array_values(array_filter(
                $list,
                fn (array $p) => (int) ($p['id'] ?? 0) !== $mid
            ));
            if ($slots[$donor] === []) {
                $slots[$donor] = $list;
                break;
            }
            $slots[$thin][] = $move;
        }

        // 4) Короткий tourist (Товель 1.5h) ← забрать лёгкую с дня переезда
        if ($transferIdx >= 0) {
            $td = $dayList[$transferIdx];
            $tList = $slots[$td] ?? [];
            if (count($tList) === 1 && ! $this->isHeavyPlace($tList[0])) {
                $fromTransfer = $tList[0];
                $need = $this->estimateVisitHours($fromTransfer) + self::HOP_HOURS;
                $bestThin = null;
                $bestH = PHP_FLOAT_MAX;
                foreach ($dayList as $i => $d) {
                    if ($i === $transferIdx) {
                        continue;
                    }
                    $list = $slots[$d] ?? [];
                    if ($this->slotHasHeavy($list)) {
                        continue;
                    }
                    $h = $this->slotHours($list);
                    if ($h >= self::MIN_DAY_HOURS) {
                        continue;
                    }
                    if (count($list) >= self::MAX_LIGHTS_PER_DAY) {
                        continue;
                    }
                    if ($h + $need > self::DAY_HOURS_BUDGET) {
                        continue;
                    }
                    if ($h < $bestH) {
                        $bestH = $h;
                        $bestThin = $d;
                    }
                }
                if ($bestThin !== null) {
                    $slots[$td] = [];
                    $slots[$bestThin][] = $fromTransfer;
                }
            }
        }

        return $slots;
    }

    /**
     * Есть ли tourist-день без тяжёлой и с нагрузкой < MIN_DAY_HOURS.
     *
     * @param  list<string>  $dayIdx
     * @param  array<string, list<array<string, mixed>>>  $slots
     */
    private function hasThinTouristDay(array $dayIdx, array $slots, int $transferIdx): bool
    {
        foreach ($dayIdx as $i => $d) {
            if ($i === $transferIdx) {
                continue;
            }
            $list = $slots[$d] ?? [];
            if ($this->slotHasHeavy($list)) {
                continue;
            }
            if ($this->slotHours($list) < self::MIN_DAY_HOURS) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  list<string>  $dayList
     * @param  array<string, mixed>  $place
     */
    private function thinnestDayFor(array $slots, array $dayList, int $transferIdx, array $place): ?string
    {
        if ($this->isHeavyPlace($place)) {
            // тяжёлую — только в пустой tourist-день
            foreach ($dayList as $i => $d) {
                if ($i === $transferIdx) {
                    continue;
                }
                if (($slots[$d] ?? []) === []) {
                    return $d;
                }
            }

            return null;
        }

        $need = $this->estimateVisitHours($place) + self::HOP_HOURS;
        $best = null;
        $bestH = PHP_FLOAT_MAX;
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx) {
                $list = $slots[$d] ?? [];
                if ($list === [] && $this->estimateVisitHours($place) <= self::TRANSFER_ACT_HOURS) {
                    // переезд — запасной вариант с низким приоритетом
                    if ($best === null) {
                        $best = $d;
                        $bestH = 100.0;
                    }
                }
                continue;
            }
            $list = $slots[$d] ?? [];
            if ($this->slotHasHeavy($list)) {
                $heavy = null;
                foreach ($list as $p) {
                    if ($this->isHeavyPlace($p)) {
                        $heavy = $p;
                        break;
                    }
                }
                if ($heavy === null || count($list) >= 2 || ! $this->canPairWithHeavy($heavy, $place)) {
                    continue;
                }
            } elseif (count($list) >= self::MAX_LIGHTS_PER_DAY) {
                continue;
            }
            if (($this->slotHasFerry($list) || $this->isFerryPlace($place)) && count($list) >= 1) {
                if ($this->slotHasFerry($list) && count($list) >= 2) {
                    continue;
                }
                if ($this->isFerryPlace($place) && ($this->slotHasFerry($list) || count($list) >= 2)) {
                    continue;
                }
            }
            $h = $this->slotHours($list);
            if ($h + $need > self::DAY_HOURS_BUDGET) {
                continue;
            }
            if ($h < $bestH) {
                $bestH = $h;
                $best = $d;
            }
        }

        return $best;
    }

    /**
     * Последний шанс не потерять точку: пустой день → переезд → самый свободный tourist.
     *
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  list<string>  $dayList
     * @param  array<string, mixed>  $place
     */
    private function forceDayFor(array $slots, array $dayList, int $transferIdx, array $place): ?string
    {
        $viable = function (string $d, int $i) use ($slots, $transferIdx, $dayList, $place): bool {
            $list = $slots[$d] ?? [];
            $isTransfer = $i === $transferIdx;
            $singleDayTransfer = $isTransfer && count($dayList) === 1;
            if ($this->isHeavyPlace($place) && $this->slotHasHeavy($list)) {
                return false;
            }
            if ($this->isLongLight($place) && $this->slotHasLongLight($list)) {
                return false;
            }
            if ($this->isFerryPlace($place) && ($this->slotHasHeavy($list) || $this->slotHasFerry($list))) {
                return false;
            }
            if ($this->slotHasFerry($list) && ($this->isHeavyPlace($place) || count($list) >= 2)) {
                return false;
            }
            if ($isTransfer) {
                // Переезд: только в пустой слот; single-day — та же жёсткость (не 2 POI)
                if ($list !== []) {
                    return false;
                }
                if ($singleDayTransfer && $this->estimateVisitHours($place) > self::TRANSFER_ACT_HOURS + 1.0) {
                    return false;
                }
                if (! $singleDayTransfer && $this->estimateVisitHours($place) > self::TRANSFER_ACT_HOURS) {
                    return false;
                }

                return true;
            }
            if ($this->slotHasHeavy($list)) {
                if ($this->isHeavyPlace($place) || count($list) >= 2) {
                    return false;
                }
                $heavy = null;
                foreach ($list as $hp) {
                    if ($this->isHeavyPlace($hp)) {
                        $heavy = $hp;
                        break;
                    }
                }

                return $heavy !== null && $this->canPairWithHeavy($heavy, $place);
            }

            return count($list) < self::MAX_LIGHTS_PER_DAY;
        };

        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx) {
                continue;
            }
            if (($slots[$d] ?? []) === [] && $viable($d, $i)) {
                return $d;
            }
        }
        if ($transferIdx >= 0) {
            $td = $dayList[$transferIdx];
            if (($slots[$td] ?? []) === [] && $viable($td, $transferIdx)) {
                return $td;
            }
        }
        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($dayList as $i => $d) {
            if (! $viable($d, $i)) {
                continue;
            }
            $list = $slots[$d] ?? [];
            $score = $this->slotHours($list) + count($list) * 0.5;
            if ($this->isFerryPlace($place) && $this->slotHasLimone($list) && count($list) === 1) {
                $score -= 20;
            }
            if ($this->isLimonePlace($place) && $this->slotHasFerry($list) && count($list) === 1) {
                $score -= 20;
            }
            if ($i === $transferIdx) {
                $score += 5;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }
        if ($best !== null) {
            return $best;
        }
        // Совсем некуда — пустой tourist-день, даже если «не viable» по soft-правилам
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx) {
                continue;
            }
            if (($slots[$d] ?? []) === []) {
                return $d;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isLimonePlace(array $row): bool
    {
        $n = mb_strtolower((string) ($row['name'] ?? ''));

        return (bool) preg_match('/limone|לימונה|лимоне/ui', $n);
    }

    /** Лёгкая, но почти на весь день (поход / крупное озеро) — одна на tourist-день. */
    private function isLongLight(array $row): bool
    {
        if ($this->isHeavyPlace($row) || $this->isFerryPlace($row)) {
            return false;
        }

        return $this->estimateVisitHours($row) >= 2.5;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function slotHasLongLight(array $list): bool
    {
        foreach ($list as $p) {
            if ($this->isLongLight($p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function slotHasLimone(array $list): bool
    {
        foreach ($list as $p) {
            if ($this->isLimonePlace($p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @param  list<string>  $dayList
     * @param  array<string, mixed>  $place
     */
    private function pushToBestTouristDay(array &$slots, array $dayList, int $transferIdx, array $place): void
    {
        $need = $this->estimateVisitHours($place) + self::HOP_HOURS;
        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($dayList as $i => $d) {
            if ($i === $transferIdx) {
                continue;
            }
            $list = $slots[$d] ?? [];
            $heavyInDay = null;
            foreach ($list as $p) {
                if ($this->isHeavyPlace($p)) {
                    $heavyInDay = $p;
                    break;
                }
            }
            if ($heavyInDay !== null) {
                if (count($list) >= 2 || ! $this->canPairWithHeavy($heavyInDay, $place)) {
                    continue;
                }
            } elseif (count($list) >= self::MAX_LIGHTS_PER_DAY) {
                continue;
            }
            $load = $this->slotHours($list);
            if ($load + $need > self::DAY_HOURS_BUDGET + 0.5 && $list !== []) {
                continue;
            }
            $score = $load + ($list === [] ? -0.2 : 0.0);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }
        if ($best !== null) {
            $slots[$best][] = $place;

            return;
        }
        // Не выбрасываем: иначе strip/balance теряют Ledro/Carezza и плодят пустые дни
        $forced = $this->forceDayFor($slots, $dayList, $transferIdx, $place);
        if ($forced !== null) {
            $slots[$forced][] = $place;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function slotHours(array $list): float
    {
        if ($list === []) {
            return 0.0;
        }
        $h = 0.0;
        foreach ($list as $i => $p) {
            $h += $this->estimateVisitHours($p);
            if ($i > 0) {
                $h += self::HOP_HOURS;
            }
        }

        return $h;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function slotHasHeavy(array $list): bool
    {
        foreach ($list as $p) {
            if ($this->isHeavyPlace($p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function countHeavy(array $list): int
    {
        $n = 0;
        foreach ($list as $p) {
            if ($this->isHeavyPlace($p)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isHeavyPlace(array $row): bool
    {
        $name = (string) ($row['name'] ?? '');
        if ($this->durations->isHeavyName($name)) {
            return true;
        }

        return $this->estimateVisitHours($row) >= self::HEAVY_HOURS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isFerryPlace(array $row): bool
    {
        $n = mb_strtolower((string) ($row['name'] ?? '').' '.(string) ($row['snippet'] ?? ''));

        return (bool) preg_match('/שייט|boat|катер|ferry|паром|traghetto|malcesine.*limon|מלצסינה/ui', $n);
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function slotHasFerry(array $list): bool
    {
        foreach ($list as $p) {
            if ($this->isFerryPlace($p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Длительность визита — LocationDurationResolver (факты → эвристика).
     *
     * @param  array<string, mixed>  $row
     */
    private function estimateVisitHours(array $row): float
    {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? '').' '.(string) ($row['snippet'] ?? ''));
        if ($id > 0) {
            return $this->durations->hoursForId($id, $name);
        }

        return $this->durations->hoursForName($name);
    }

    /**
     * @param  list<array<string, mixed>>  $places
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function nearestTo(array $places, array $base): ?array
    {
        $best = null;
        $bestD = PHP_FLOAT_MAX;
        foreach ($places as $p) {
            if ($p['lat'] === null || $p['lng'] === null) {
                continue;
            }
            $d = $this->distanceKm(
                (float) $base['lat'],
                (float) $base['lng'],
                (float) $p['lat'],
                (float) $p['lng'],
            );
            if ($d < $bestD) {
                $bestD = $d;
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     * @return array<string, mixed>|null
     */
    private function pickOvernight(array $cluster): ?array
    {
        foreach ($cluster as $row) {
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base' || ($row['trip_role'] ?? '') === 'base') {
                return $row;
            }
        }
        $baseId = null;
        foreach ($cluster as $row) {
            if (! empty($row['area_base_id'])) {
                $baseId = (int) $row['area_base_id'];
                break;
            }
        }
        if ($baseId) {
            foreach ($cluster as $row) {
                if ((int) ($row['id'] ?? 0) === $baseId) {
                    return $row;
                }
            }
        }
        [$clat, $clng] = $this->centroid($cluster);
        $best = null;
        $bestD = PHP_FLOAT_MAX;
        foreach ($cluster as $row) {
            $d = $this->distanceKm($clat, $clng, (float) $row['lat'], (float) $row['lng']);
            $name = mb_strtolower((string) ($row['name'] ?? ''));
            $bonus = preg_match('/טיול|מסלול|פארק|walk|hike|waterfall|מפל|водопад/u', $name) ? 8.0 : 0.0;
            $score = $d + $bonus;
            if ($score < $bestD) {
                $bestD = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     */
    private function clusterLabel(array $cluster, int $n): string
    {
        foreach ($cluster as $row) {
            $areaName = trim((string) ($row['area_name'] ?? ''));
            if ($areaName !== '') {
                if (mb_strlen($areaName) > 32) {
                    $areaName = mb_substr($areaName, 0, 32);
                }

                return 'Район '.$areaName;
            }
        }
        $base = $this->pickOvernight($cluster);
        $name = trim((string) ($base['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/[,–—\-]/u', $name) ?: [$name];
            $short = trim((string) ($parts[0] ?? $name));
            if (mb_strlen($short) > 28) {
                $short = mb_substr($short, 0, 28);
            }

            return 'Район '.$short;
        }

        return 'Район '.$n;
    }

    /**
     * @param  list<array<string, mixed>>  $cluster
     * @return array{0: float, 1: float}
     */
    private function centroid(array $cluster): array
    {
        $lat = 0.0;
        $lng = 0.0;
        $n = max(1, count($cluster));
        foreach ($cluster as $row) {
            $lat += (float) $row['lat'];
            $lng += (float) $row['lng'];
        }

        return [$lat / $n, $lng / $n];
    }

    /**
     * @return list<string>
     */
    private function parseTripDays(string $query): array
    {
        if (preg_match('/\b(\d{1,2})\s*[\.\/]\s*(\d{1,2})\b.+\b(\d{1,2})\s*[\.\/]\s*(\d{1,2})\b/u', $query, $m)) {
            return $this->rangeToDays((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]);
        }
        if (preg_match('/\b(\d{1,2})\s*[–—\-]\s*(\d{1,2})\s*(январ|феврал|март|апрел|ма[яй]|июн|июл|август|сентябр|октябр|ноябр|декабр)/ui', $query, $m)) {
            $mo = $this->monthFromRu((string) $m[3]);

            return $this->rangeToDays((int) $m[1], $mo, (int) $m[2], $mo);
        }
        if (preg_match('/\b(\d{1,2})\s*по\s*(\d{1,2})\b/ui', $query, $m)) {
            return $this->rangeToDays((int) $m[1], 8, (int) $m[2], 8);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function rangeToDays(int $d1, int $mo1, int $d2, int $mo2): array
    {
        if ($d1 < 1 || $d1 > 31 || $d2 < 1 || $d2 > 31 || $mo1 < 1 || $mo1 > 12 || $mo2 < 1 || $mo2 > 12) {
            return [];
        }
        $year = (int) date('Y');
        $start = strtotime(sprintf('%04d-%02d-%02d', $year, $mo1, $d1));
        $end = strtotime(sprintf('%04d-%02d-%02d', $year, $mo2, $d2));
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $out = [];
        for ($t = $start; $t <= $end; $t += 86400) {
            $out[] = date('d.m', $t);
            if (count($out) > 21) {
                break;
            }
        }

        return $out;
    }

    private function monthFromRu(string $token): int
    {
        $t = mb_strtolower($token);
        if (str_starts_with($t, 'январ')) {
            return 1;
        }
        if (str_starts_with($t, 'феврал')) {
            return 2;
        }
        if (str_starts_with($t, 'март')) {
            return 3;
        }
        if (str_starts_with($t, 'апрел')) {
            return 4;
        }
        if (str_starts_with($t, 'май') || str_starts_with($t, 'мая')) {
            return 5;
        }
        if (str_starts_with($t, 'июн')) {
            return 6;
        }
        if (str_starts_with($t, 'июл')) {
            return 7;
        }
        if (str_starts_with($t, 'август')) {
            return 8;
        }
        if (str_starts_with($t, 'сентябр')) {
            return 9;
        }
        if (str_starts_with($t, 'октябр')) {
            return 10;
        }
        if (str_starts_with($t, 'ноябр')) {
            return 11;
        }
        if (str_starts_with($t, 'декабр')) {
            return 12;
        }

        return 8;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
