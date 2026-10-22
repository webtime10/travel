<?php

namespace App\Services;

use App\Models\Location;
use App\Models\RouteSkeleton;

/**
 * После пакера: сначала подставить дневные конструкции директора (связки мест из его туров),
 * затем добить каталогом (плотность, переезд, без мусора).
 */
class RouteSkeletonRefiner
{
    private const ARRIVAL_MAX_KM = 12.0;

    private const FILL_MAX_KM = 28.0;

    /** Ниже — день «тонкий», надо добрать (stay). Цель путешествия ~6–8ч, не 2 точки. */
    private const THIN_ACT_HOURS = 4.5;

    /** Цель по активностям stay: добиваем, пока не набрали */
    private const RESIDUAL_STAY_HOURS = 5.5;

    /** Переезд: активности по дороге + у базы, не одна короткая остановка */
    private const RESIDUAL_TRANSFER_HOURS = 4.0;

    /** На переезде: остановки по оси + у новой базы */
    private const TRANSFER_MAX_PLACES = 3;

    private const STAY_MAX_PLACES = 3;

    /** Конструкция директора может быть длиннее (A → озеро → B) */
    private const CONSTRUCTION_STAY_MAX = 3;

    private const CONSTRUCTION_TRANSFER_MAX = 3;

    private const HARD_TREK = '/sella|sellajoch|passo\s*sella|סלה|סלבה|selva|tre\s*cime|три\s*чиме|3\s*פסגות|adolf|adolph|אדולף|marmolada|מרמולד|via\s*ferrata|ferrato|פראט|ferrata/ui';

    /** Тяжёлый трек-день (Sorapis / Cinque Torri / Tre Cime) — не ставить два подряд */
    private const HARD_DAY_TREK = '/sorapis|סורפיס|сорапис|cinque\s*torri|чинкве\s*торр|5\s*торр|tre\s*cime|drei\s*zinnen|три\s*чиме|3\s*פסג|cadini|кадини|lavaredo|лаваредо/ui';

    /** Озёра Валь-Пустерия — логичны на оси Брунико→Мизурина, не крюком из Мизурины */
    private const VAL_PUSTERIA_LAKE = '/braies|בראייס|прагс|прагсе|dobbiaco|דוביאק|доббиак|toblach|тобла/ui';

    /** Экстрим / hard adventure — в одном дне не больше одного */
    private const EXTREME_ACTIVITY = '/via\s*ferrata|ferrato|ferrata|פראט|canyon|каньон|קניון|bueno|raft|ראפט|рафтинг|skyscraper|x-?treme|extreme|אקסטרים|quad|квадро|טרקטורון|zip\s*line|omega/ui';

    /** Не для «программы у базы» — детские парки/бобслей у чужого курорта */
    private const WEAK_FILL = '/gimbor|ג.׳?מבור|bike\s*park|funbob|מגלש|playground|משחקי/ui';

    /**
     * Не слот туристической активности маршрута (рестораны, отели, инфо-страницы).
     * Michelin / מסעדות — рекомендация, не POI дня.
     */
    private const NOT_ROUTE_ACTIVITY = '/מסעד|restaurant|ristorante|michel|מישל|hotel|מלון|住宿|hostel|agl?ritur|agrisalus|ארוחת\s*בוקר|breakfast\s*farm|כשרות\s*ב|gluten|גלוטן|gelat|גלידר|icecream|список\s*ресторан|restaurants?:/ui';

    /** Семейный тур — не вариант «только для взрослых» */
    private const ADULTS_ONLY = '/למבוגרים|for\s*adults|adults?\s*only|18\+/ui';

    /** Длинная активность (≥4ч) — обычно не добиваем вторым POI */
    private const LONG_ACTIVITY_HOURS = 4.0;

    /** Полноценная программа 5–7ч — второй объект не добавлять никогда */
    private const FULL_DAY_ACTIVITY_HOURS = 5.0;

    /** Потолок суммы активностей (stay / transfer) */
    private const DAY_CAP_STAY_HOURS = 7.5;

    private const DAY_CAP_TRANSFER_HOURS = 6.5;

    /** Явная активность (не «город = ночёвка») */
    private const DISTINCT_ACTIVITY = '/מסלול|מפל|פארק|חבלים|שייט|ראפטינג|폭포|cascata|busatte|tempesta|cadini|ferrata|פראט|zip|omega|skyscraper|xtreme|x-treme|raft|varone|וארנ|tenno|טנו|limone|malcesine|cinque|5\s*פסג|dobbiaco|דוביאק|sorapis|סורפיס|bulla|col\s*de\s*flam|emozion|kronplatz|corones|מגדלנ|maddalen|chiusa|קיוז/ui';

    /** Прогулка по городу ночёвки — не второй POI */
    private const TOWN_WALK_ONLY = '/טיול\s*רגלי|прогулк|short\s*walk|walk\s*\(|\(קצר\)|קצר\s*\)/ui';

    /** Боковые озёра/крючки — не в день заезда к базе на оси */
    private const ARRIVAL_SIDE = '/ledro|לדרו|tovel|טובל|ampola|אמפולה|gardaland|outlet|аутлет/ui';

    /**
     * Хабы, которые нельзя смешивать с ночёвкой в другом хабе
     * (Мольвено при ночёвке Рива, Кронплац при Ортизеи).
     *
     * @var array<string, list<string>>
     */
    private const HUB_INCOMPAT = [
        'riva' => ['molveno', 'ortisei', 'brunico', 'braies', 'misurina', 'campiglio'],
        'garda' => ['molveno', 'ortisei', 'brunico', 'braies', 'misurina', 'campiglio'],
        'molveno' => ['ortisei', 'brunico', 'braies', 'misurina', 'riva', 'garda', 'campiglio'],
        'ortisei' => ['brunico', 'braies', 'misurina', 'molveno', 'riva', 'garda', 'campiglio'],
        'brunico' => ['ortisei', 'misurina', 'molveno', 'riva', 'garda', 'braies', 'campiglio'],
        // Braies ↔ Misurina/Cortina — один коридор Доломит; must-see Braies нельзя банить
        'braies' => ['ortisei', 'molveno', 'riva', 'garda'],
        'misurina' => ['ortisei', 'brunico', 'molveno', 'riva', 'garda'],
        'campiglio' => ['ortisei', 'brunico', 'braies', 'misurina', 'riva', 'garda', 'molveno'],
        'cortina' => ['ortisei', 'brunico', 'molveno', 'riva', 'garda'],
    ];

    public function __construct(
        private LocationDurationResolver $durations,
        private RouteSkeletonMatcher $matcher,
    ) {}

    /**
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $locations
     * @param  list<RouteSkeleton>  $skeletons
     * @return array{
     *   day_plan: list<string>,
     *   notes: list<string>,
     *   locations: list<array<string, mixed>>,
     *   provenance: array<string, mixed>
     * }
     */
    public function refine(array $dayPlan, array $locations, array $skeletons, string $query = ''): array
    {
        $notes = [];
        if ($dayPlan === []) {
            return ['day_plan' => [], 'notes' => [], 'locations' => $locations, 'provenance' => $this->emptyProvenance()];
        }

        $byId = [];
        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }
        $this->durations->forIds(array_keys($byId));

        $days = [];
        foreach ($dayPlan as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed !== null) {
                $days[] = $parsed;
            }
        }
        if ($days === []) {
            return ['day_plan' => $dayPlan, 'notes' => [], 'locations' => $locations, 'provenance' => $this->emptyProvenance()];
        }

        $pool = $this->unusedPool($days, $byId);
        $preferNames = $this->skeletonPlaceNames($skeletons);

        // 0) Не-активности + дубль города/озера ночёвки + чужие хабы
        $days = $this->scrubNotRouteActivities($days, $byId, $pool, $notes);
        $days = $this->scrubOvernightDuplicates($days, $byId, $pool, $notes);
        $days = $this->scrubIncompatibleHubPlaces($days, $byId, $pool, $notes);

        // 1) Первый день: только точки близко к ночёвке (не Ледро «сбоку» после прилёта)
        $days = $this->softenArrivalDay($days, $byId, $pool, $notes);

        // 2) Тяжёлые треки (Селла и т.п.) — убрать из программы, вернуть в пул
        $days = $this->stripHardTreks($days, $byId, $pool, $notes);

        // 2a) ОСНОВА: готовые связки из туров директора (A → озеро → B), каталог потом
        $days = $this->applyDirectorConstructions($days, $byId, $pool, $skeletons, $notes);

        // 2b) Fallback: если день всё ещё пустой/тонкий — имена с хаба образца
        $days = $this->applySkeletonHubFills($days, $byId, $pool, $skeletons, $notes);

        // 3) Переезд: убрать чужие/далёкие; кап до 2 лёгких у новой базы (не «только дорога»)
        $days = $this->dropFarTransferPlaces($days, $byId, $pool, $notes);
        $days = $this->capTransferDays($days, $byId, $pool, $notes);

        // 4–6) Плотность + пустые + остаточная ёмкость дня (правило эксперта)
        $days = $this->fillThinDays($days, $byId, $pool, $preferNames, $notes);
        $days = $this->rescueEmptyStayDays($days, $byId, $pool, $preferNames, $notes);
        $days = $this->fillEmptyTransfers($days, $byId, $pool, $preferNames, $notes);
        $days = $this->dedupePlacesAcrossTrip($days, $byId, $pool, $notes);
        $days = $this->dedupeSemanticEntities($days, $byId, $pool, $notes);
        $days = $this->scrubRouteZigzags($days, $byId, $pool, $notes);
        $days = $this->scrubNotRouteActivities($days, $byId, $pool, $notes);
        $days = $this->scrubOvernightDuplicates($days, $byId, $pool, $notes);
        $days = $this->scrubIncompatibleHubPlaces($days, $byId, $pool, $notes);
        $days = $this->fillEmptyTransfers($days, $byId, $pool, $preferNames, $notes);
        $days = $this->enrichResidualCapacity($days, $byId, $pool, $preferNames, $notes);
        $days = $this->capTransferDays($days, $byId, $pool, $notes);
        $days = $this->scrubDayOverload($days, $byId, $pool, $notes);
        $days = $this->scrubNotRouteActivities($days, $byId, $pool, $notes);
        $days = $this->scrubOvernightDuplicates($days, $byId, $pool, $notes);
        $days = $this->scrubIncompatibleHubPlaces($days, $byId, $pool, $notes);
        $days = $this->rescueEmptyStayDays($days, $byId, $pool, $preferNames, $notes);
        $days = $this->fillThinDays($days, $byId, $pool, $preferNames, $notes);
        $days = $this->enrichResidualCapacity($days, $byId, $pool, $preferNames, $notes);
        $days = $this->scrubDayOverload($days, $byId, $pool, $notes);
        $days = $this->scrubOvernightDuplicates($days, $byId, $pool, $notes);
        $days = $this->rescueEmptyStayDays($days, $byId, $pool, $preferNames, $notes);
        // Финал: порядок по оси, гео-ось и семантические дубли после всех добивок
        $days = $this->orderPlacesAlongAxis($days, $byId);
        $days = $this->dedupeSemanticEntities($days, $byId, $pool, $notes);
        $days = $this->scrubRouteZigzags($days, $byId, $pool, $notes);
        $days = $this->dedupePlacesAcrossTrip($days, $byId, $pool, $notes);
        // Финал смысла: логистика эксперта + база + часы
        $days = $this->scrubDualExtremes($days, $byId, $pool, $notes);
        $days = $this->scrubConsecutiveHardTreks($days, $byId, $pool, $notes);
        $days = $this->relocateValPusteriaLakes($days, $byId, $pool, $notes);
        $days = $this->ensureBaseLived($days, $byId, $pool, $notes);
        $days = $this->fillThinDays($days, $byId, $pool, $preferNames, $notes);
        $days = $this->enrichResidualCapacity($days, $byId, $pool, $preferNames, $notes);
        $days = $this->scrubDualExtremes($days, $byId, $pool, $notes);
        $days = $this->scrubConsecutiveHardTreks($days, $byId, $pool, $notes);
        $days = $this->relocateValPusteriaLakes($days, $byId, $pool, $notes);
        // Ещё раз: добивка не должна «съесть» базу; текст базы обязателен
        $days = $this->ensureBaseLived($days, $byId, $pool, $notes);

        $out = [];
        foreach ($days as $di => $d) {
            $out[] = $this->formatLine($d, $byId, $di === 0);
        }

        return [
            'day_plan' => $out,
            'notes' => array_values(array_unique($notes)),
            'locations' => $this->mergeLocations($locations, $byId, $days),
            'provenance' => $this->buildProvenance($days, $byId, $skeletons),
        ];
    }

    /**
     * @return array{days: list<array<string, mixed>>, skeleton_titles: list<string>}
     */
    private function emptyProvenance(): array
    {
        return ['days' => [], 'skeleton_titles' => []];
    }

    /**
     * Резюме для директора: что из его конструкции, что из общей базы.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<\App\Models\RouteSkeleton>  $skeletons
     * @return array{days: list<array<string, mixed>>, skeleton_titles: list<string>}
     */
    private function buildProvenance(array $days, array $byId, array $skeletons): array
    {
        $titleBySid = [];
        $skeletonTitles = [];
        foreach ($skeletons as $s) {
            $sid = (int) ($s->source_route_id ?? $s->id ?? 0);
            $title = trim((string) ($s->displayTitle(true) ?: $s->title ?? ''));
            if ($sid > 0 && $title !== '') {
                $titleBySid[$sid] = $title;
            }
            if ($title !== '' && ! in_array($title, $skeletonTitles, true)) {
                $skeletonTitles[] = $title;
            }
        }

        $outDays = [];
        foreach ($days as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $cIds = array_values(array_unique(array_map('intval', $day['construction_ids'] ?? [])));
            $allIds = array_values(array_unique(array_map('intval', $day['place_ids'] ?? [])));
            $extraCatalog = array_values(array_unique(array_map('intval', $day['catalog_ids'] ?? [])));
            $catalogIds = array_values(array_unique(array_merge(
                array_values(array_diff($allIds, $cIds)),
                $extraCatalog
            )));
            // не дублировать construction в catalog
            $catalogIds = array_values(array_diff($catalogIds, $cIds));

            $construction = [];
            foreach ($cIds as $id) {
                if ($id <= 0 || ! isset($byId[$id])) {
                    continue;
                }
                $construction[] = [
                    'id' => $id,
                    'name' => $this->name($byId[$id]),
                ];
            }
            $catalog = [];
            foreach ($catalogIds as $id) {
                if ($id <= 0 || ! isset($byId[$id])) {
                    continue;
                }
                $catalog[] = [
                    'id' => $id,
                    'name' => $this->name($byId[$id]),
                ];
            }

            $sid = (int) ($day['construction_source_route_id'] ?? 0);
            $sourceTitle = $sid > 0 ? (string) ($titleBySid[$sid] ?? '') : '';

            if ($construction === [] && $catalog === []) {
                continue;
            }

            // «Из конструкции» только при связке ≥2 мест
            $isConstruction = ! empty($day['from_construction']) && count($construction) >= 2;
            if (! $isConstruction && $construction !== []) {
                // обломок → в каталог для честного резюме
                $catalog = array_merge($construction, $catalog);
                $construction = [];
            }

            $outDays[] = [
                'date' => $date,
                'from_construction' => $isConstruction,
                'source_title' => $isConstruction ? $sourceTitle : '',
                'construction' => $construction,
                'catalog' => $catalog,
            ];
        }

        return [
            'days' => $outDays,
            'skeleton_titles' => array_slice($skeletonTitles, 0, 5),
        ];
    }

    /**
     * Не больше одного экстрима в дне (каньон+феррата и т.п.).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubDualExtremes(array $days, array &$byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            $extremeIdx = [];
            foreach ($day['place_ids'] ?? [] as $i => $pid) {
                $row = $byId[(int) $pid] ?? null;
                if ($row && preg_match(self::EXTREME_ACTIVITY, $this->name($row))) {
                    $extremeIdx[] = (int) $i;
                }
            }
            if (count($extremeIdx) < 2) {
                continue;
            }
            // Оставляем первый экстрим, остальные — в пул (заменим на природу при ensure/fill)
            $keep = $extremeIdx[0];
            $newIds = [];
            foreach ($day['place_ids'] as $i => $pid) {
                $pid = (int) $pid;
                if (in_array($i, $extremeIdx, true) && $i !== $keep) {
                    if (isset($byId[$pid])) {
                        $pool[] = $byId[$pid];
                    }
                    $notes[] = 'день '.$day['date'].': убран второй экстрим #'.$pid
                        .' ('.$this->name($byId[$pid] ?? []).') — один развлекательный слот на день';
                    // снять из construction_ids
                    if (! empty($day['construction_ids'])) {
                        $day['construction_ids'] = array_values(array_filter(
                            array_map('intval', $day['construction_ids']),
                            fn ($id) => $id !== $pid
                        ));
                    }
                    continue;
                }
                $newIds[] = $pid;
            }
            $day['place_ids'] = $newIds;
        }
        unset($day);

        return $days;
    }

    /**
     * Два тяжёлых трека подряд (Sorapis → Cinque Torri) — выматывает; второй день облегчаем.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubConsecutiveHardTreks(array $days, array &$byId, array &$pool, array &$notes): array
    {
        $prevHard = false;
        foreach ($days as &$day) {
            $hardIds = [];
            foreach ($day['place_ids'] ?? [] as $pid) {
                $row = $byId[(int) $pid] ?? null;
                if ($row && preg_match(self::HARD_DAY_TREK, $this->name($row))) {
                    $hardIds[] = (int) $pid;
                }
            }
            $isHard = $hardIds !== [];
            if ($prevHard && $isHard) {
                foreach ($hardIds as $pid) {
                    $idx = array_search($pid, $day['place_ids'], true);
                    if ($idx === false) {
                        continue;
                    }
                    array_splice($day['place_ids'], (int) $idx, 1);
                    if (isset($byId[$pid])) {
                        $pool[] = $byId[$pid];
                    }
                    $notes[] = 'день '.$day['date'].': убран тяжёлый трек #'.$pid
                        .' ('.$this->name($byId[$pid] ?? []).') — вчера уже был тяжёлый день, нужен отдых/спокойная программа';
                }
                // Добавим мягкую природу у ночёвки из пула
                $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
                if ($night !== null && $day['place_ids'] === []) {
                    foreach ($pool as $pi => $cand) {
                        if (! is_array($cand)) {
                            continue;
                        }
                        $cn = $this->name($cand);
                        if ($cn === '' || preg_match(self::HARD_DAY_TREK, $cn) || preg_match(self::EXTREME_ACTIVITY, $cn)) {
                            continue;
                        }
                        if (! $this->placeFitsOvernight($cand, $night)) {
                            continue;
                        }
                        $cid = (int) ($cand['id'] ?? 0);
                        if ($cid <= 0) {
                            continue;
                        }
                        $day['place_ids'][] = $cid;
                        $byId[$cid] = $cand;
                        unset($pool[$pi]);
                        $pool = array_values($pool);
                        $notes[] = 'день '.$day['date'].': вместо тяжёлого трека — спокойная #'.$cid.' ('.$cn.')';
                        break;
                    }
                }
            }
            $prevHard = false;
            foreach ($day['place_ids'] ?? [] as $pid) {
                $row = $byId[(int) $pid] ?? null;
                if ($row && preg_match(self::HARD_DAY_TREK, $this->name($row))) {
                    $prevHard = true;
                    break;
                }
            }
        }
        unset($day);

        return $days;
    }

    /**
     * Браес/Доббиако: снять с дней у Мизурины и поставить на переезд в Мизурину (ось из Брунико).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function relocateValPusteriaLakes(array $days, array &$byId, array &$pool, array &$notes): array
    {
        $moved = []; // id => row
        foreach ($days as $i => &$day) {
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $hubs = $this->matcher->detectHubs($this->name($night).' '.(string) ($day['night_label'] ?? ''));
            // Крюк: ночёвка Мизурина/Кортина + Браес/Доббиако в программе
            if (array_intersect($hubs, ['misurina', 'cortina']) === []) {
                continue;
            }
            if (! empty($day['is_transfer'])) {
                continue; // переезд В Мизурину — как раз правильное место, не трогаем
            }
            $keep = [];
            foreach ($day['place_ids'] ?? [] as $pid) {
                $pid = (int) $pid;
                $row = $byId[$pid] ?? null;
                if ($row && preg_match(self::VAL_PUSTERIA_LAKE, $this->name($row))) {
                    $moved[$pid] = $row;
                    $notes[] = 'день '.$day['date'].': Браес/Доббиако убран из Мизурины (крюк) — перенос на переезд по долине';
                    continue;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        if ($moved === []) {
            return $days;
        }

        // Цель: день переезда с ночёвкой Misurina, иначе день с ночёвкой Brunico перед Мизуриной
        $targetIdx = null;
        foreach ($days as $i => $d) {
            $night = $byId[(int) ($d['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $hubs = $this->matcher->detectHubs($this->name($night));
            if (! empty($d['is_transfer']) && array_intersect($hubs, ['misurina', 'cortina']) !== []) {
                $targetIdx = $i;
                break;
            }
        }
        if ($targetIdx === null) {
            foreach ($days as $i => $d) {
                $night = $byId[(int) ($d['night_id'] ?? 0)] ?? null;
                if ($night === null) {
                    continue;
                }
                $hubs = $this->matcher->detectHubs($this->name($night));
                if (array_intersect($hubs, ['brunico', 'braies']) !== []) {
                    $targetIdx = $i;
                }
            }
        }

        if ($targetIdx === null) {
            foreach ($moved as $row) {
                $pool[] = $row;
            }

            return $days;
        }

        $cap = ! empty($days[$targetIdx]['from_construction'])
            ? self::CONSTRUCTION_TRANSFER_MAX
            : self::TRANSFER_MAX_PLACES;
        foreach ($moved as $pid => $row) {
            if (in_array($pid, $days[$targetIdx]['place_ids'] ?? [], true)) {
                continue;
            }
            if (count($days[$targetIdx]['place_ids'] ?? []) >= $cap) {
                $pool[] = $row;
                continue;
            }
            $days[$targetIdx]['place_ids'][] = $pid;
            $byId[$pid] = $row;
            $notes[] = 'день '.$days[$targetIdx]['date'].': на ось Брунико→Мизурина добавлен #'.$pid
                .' ('.$this->name($row).')';
        }

        return $days;
    }

    /**
     * База ночёвки = место путешествия. В программе обязательно «время на озере/в городе»,
     * не только аттракцион рядом (правка директора, повторно).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function ensureBaseLived(array $days, array &$byId, array &$pool, array &$notes): array
    {
        $patterns = [
            'molveno' => [
                'hubs' => ['molveno'],
                'want' => '/molveno|מולבנ|мольвен|lago\s*di\s*molveno|אגם\s*מולבנ/ui',
                'label' => 'Время на озере Мольвено (прогулка у воды)',
                'search' => ['Molveno', 'Lago di Molveno', 'אגם מולבנו', 'Мольвено'],
            ],
            'riva' => [
                'hubs' => ['riva', 'garda'],
                'want' => '/riva\s*del\s*garda|riva(?!\s*canyon)|ריבה|рива-?дель|рива\s*дель|lungolago|набереж|limone|לימונ|malcesine/ui',
                'label' => 'Время в Рива-дель-Гарда (прогулка по набережной)',
                'search' => ['Riva del Garda', 'Riva', 'ריבה', 'Рива-дель-Гарда', 'Limone'],
            ],
            'ortisei' => [
                'hubs' => ['ortisei'],
                'want' => '/ortisei|אורטיז|ортизе|bulla|בולה|gardena|валь\s*гарден/ui',
                'label' => 'Время в Ортизее (прогулка по посёлку)',
                'search' => ['Ortisei', 'אורטיזי', 'Ортизей', 'Bulla'],
            ],
            'misurina' => [
                'hubs' => ['misurina'],
                'want' => '/misurina|מיזורינ|мизурин|lago\s*di\s*misurina/ui',
                'label' => 'Время на озере Мизурина (прогулка у воды)',
                'search' => ['Misurina', 'Lago di Misurina', 'מיזורינה', 'Мизурина'],
            ],
        ];

        foreach ($days as $di => &$day) {
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $hubs = $this->matcher->detectHubs(
                $this->name($night).' '.(string) ($day['night_label'] ?? '')
            );
            if ($hubs === []) {
                continue;
            }

            $key = null;
            $rule = null;
            foreach ($patterns as $k => $p) {
                if (array_intersect($hubs, $p['hubs']) !== []) {
                    $key = $k;
                    $rule = $p;
                    break;
                }
            }
            if ($rule === null) {
                continue;
            }

            // Всегда фиксируем человеческую формулировку — база как место путешествия
            $day['base_lived_label'] = $rule['label'];

            $progBlob = '';
            foreach ($day['place_ids'] ?? [] as $pid) {
                $progBlob .= ' '.$this->name($byId[(int) $pid] ?? []);
            }
            if (preg_match($rule['want'], $progBlob)) {
                $notes[] = 'день '.$day['date'].': база уже в программе ('.$key.')';
                continue;
            }

            $maxPlaces = ! empty($day['is_transfer'])
                ? (! empty($day['from_construction']) ? self::CONSTRUCTION_TRANSFER_MAX : self::TRANSFER_MAX_PLACES)
                : (! empty($day['from_construction']) ? self::CONSTRUCTION_STAY_MAX : self::STAY_MAX_PLACES);

            $pick = $this->pickBaseLivedPoi($rule, $night, $pool, $byId, $days, $day);
            if ($pick === null) {
                // Синтетическая карточка: текст точно попадёт в день, даже без POI в каталоге
                $pick = $this->makeSyntheticBaseLived($rule['label'], $night, $di);
            }

            $pid = (int) $pick['id'];
            if (count($day['place_ids']) >= $maxPlaces) {
                $dropIdx = $this->indexToSwapForBase($day, $byId);
                if ($dropIdx === null) {
                    // Вставляем в начало принудительно, вытеснив последний
                    $dropIdx = count($day['place_ids']) - 1;
                }
                $drop = (int) $day['place_ids'][$dropIdx];
                if (isset($byId[$drop]) && empty($byId[$drop]['force_base_lived'])) {
                    $pool[] = $byId[$drop];
                }
                array_splice($day['place_ids'], $dropIdx, 1);
                $notes[] = 'день '.$day['date'].': слот под базу — убран #'.$drop;
            }

            // База первой в программе — видно как место путешествия
            array_unshift($day['place_ids'], $pid);
            $day['place_ids'] = array_values(array_unique($day['place_ids']));
            $byId[$pid] = $pick;
            $day['base_lived_ids'] = array_values(array_unique(array_merge(
                array_map('intval', $day['base_lived_ids'] ?? []),
                [$pid]
            )));
            $day['catalog_ids'] = array_values(array_unique(array_merge(
                array_map('intval', $day['catalog_ids'] ?? []),
                [$pid]
            )));
            $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
            unset($day['base_not_lived']);
            $notes[] = 'день '.$day['date'].': база как место путешествия → '.$rule['label'].' #'.$pid;
        }
        unset($day);

        return $days;
    }

    /**
     * @param  array{want: string, label: string, search: list<string>}  $rule
     * @param  array<string, mixed>  $night
     * @return array<string, mixed>
     */
    private function makeSyntheticBaseLived(string $label, array $night, int $dayIndex): array
    {
        $nid = (int) ($night['id'] ?? 0);
        // Синтетический id вне каталога, но стабильный
        $sid = 900000 + ($nid % 10000) * 10 + ($dayIndex % 10);

        return [
            'id' => $sid,
            'name' => $label,
            'lat' => $night['lat'] ?? null,
            'lng' => $night['lng'] ?? null,
            'force_base_lived' => true,
            'is_base' => false,
            'role' => 'activity',
            'snippet' => 'base_lived',
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function dayHasExtreme(array $ids, array $byId): bool
    {
        foreach ($ids as $pid) {
            $row = $byId[(int) $pid] ?? null;
            if ($row && preg_match(self::EXTREME_ACTIVITY, $this->name($row))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function indexToSwapForBase(array $day, array $byId): ?int
    {
        $protect = [];
        foreach ($day['construction_ids'] ?? [] as $cid) {
            $protect[(int) $cid] = true;
        }
        foreach ($day['base_lived_ids'] ?? [] as $bid) {
            $protect[(int) $bid] = true;
        }
        $ids = $day['place_ids'] ?? [];
        for ($i = count($ids) - 1; $i >= 0; $i--) {
            $pid = (int) $ids[$i];
            if (isset($protect[$pid])) {
                continue;
            }
            $row = $byId[$pid] ?? null;
            if ($row && ! empty($row['force_base_lived'])) {
                continue;
            }
            if ($row && preg_match(self::EXTREME_ACTIVITY, $this->name($row))) {
                continue; // не выкидывать единственный экстрим
            }

            return $i;
        }

        return null;
    }

    /**
     * @param  array{want: string, label: string, search: list<string>}  $rule
     * @param  list<array<string, mixed>>  $pool
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>|null
     */
    private function pickBaseLivedPoi(
        array $rule,
        array $night,
        array &$pool,
        array &$byId,
        array $days,
        array $day,
    ): ?array {
        $wantRe = $rule['want'];
        $used = $this->usedPlaceIds($days);
        foreach ($day['place_ids'] ?? [] as $pid) {
            $used[(int) $pid] = true;
        }
        $nightId = (int) ($night['id'] ?? 0);

        $best = null;
        $bestScore = -1.0;
        $consider = function (array $row) use (&$best, &$bestScore, $wantRe, $night, $nightId, $used): void {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($used[$id])) {
                return;
            }
            // Карточка самой ночёвки ок, если это озеро/город — пометим как lived
            $n = $this->name($row);
            if ($n === '' || ! preg_match($wantRe, $n)) {
                return;
            }
            if (preg_match(self::EXTREME_ACTIVITY, $n) || preg_match(self::NOT_ROUTE_ACTIVITY, $n)) {
                return;
            }
            $score = 10.0;
            if (preg_match('/lago|אגם|озеро|lake/ui', $n)) {
                $score += 12.0;
            }
            if ($id === $nightId) {
                $score += 3.0; // сама база — нормальный lived
            }
            $km = $this->kmBetween($row, $night);
            if ($km !== null) {
                if ($km > 20) {
                    return;
                }
                $score += max(0, 20 - $km);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        };

        foreach (array_merge($pool, array_values($byId)) as $row) {
            if (is_array($row)) {
                $consider($row);
            }
        }

        // Каталог по именам (не только текущий pool)
        try {
            $ll = $this->latLng($night);
            $q = Location::query()->limit(40);
            $q->where(function ($w) use ($rule) {
                foreach ($rule['search'] as $term) {
                    $t = trim($term);
                    if ($t === '') {
                        continue;
                    }
                    $w->orWhere('meta->title', 'like', '%'.$t.'%')
                        ->orWhere('meta_ru->title', 'like', '%'.$t.'%');
                }
            });
            if ($ll !== null) {
                $q->whereNotNull('lat')->whereNotNull('lng')
                    ->whereBetween('lat', [$ll[0] - 0.35, $ll[0] + 0.35])
                    ->whereBetween('lng', [$ll[1] - 0.45, $ll[1] + 0.45]);
            }
            foreach ($q->get(['id', 'lat', 'lng', 'trip_role', 'meta', 'meta_ru']) as $loc) {
                $row = [
                    'id' => (int) $loc->id,
                    'name' => trim((string) (data_get($loc->meta_ru, 'title')
                        ?: data_get($loc->meta, 'title')
                        ?: '')),
                    'lat' => $loc->lat,
                    'lng' => $loc->lng,
                    'role' => $loc->trip_role,
                    'is_base' => false,
                    'force_base_lived' => true,
                ];
                if ($row['name'] === '') {
                    continue;
                }
                $consider($row);
            }
        } catch (\Throwable) {
            // каталог недоступен — synthetic снаружи
        }

        if ($best !== null) {
            $best['force_base_lived'] = true;
            // Не называть жильём — это программа у базы
            if ((int) ($best['id'] ?? 0) === $nightId || ! empty($best['is_base']) || ($best['role'] ?? '') === 'base') {
                $best['name'] = $rule['label'];
                $best['is_base'] = false;
                $best['role'] = 'activity';
            }
        }

        return $best;
    }

    /**
     * Карточки, добавленные из каталога (hub fill / rescue), должны попасть в CONTEXT.
     *
     * @param  list<array<string, mixed>>  $locations
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function mergeLocations(array $locations, array $byId, array $days): array
    {
        $have = [];
        foreach ($locations as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $have[(int) $row['id']] = true;
            }
        }
        $need = [];
        foreach ($days as $d) {
            foreach ($d['place_ids'] as $pid) {
                $need[(int) $pid] = true;
            }
            if ((int) ($d['night_id'] ?? 0) > 0) {
                $need[(int) $d['night_id']] = true;
            }
        }
        foreach ($need as $id => $_) {
            if (isset($have[$id]) || ! isset($byId[$id])) {
                continue;
            }
            $locations[] = $byId[$id];
            $have[$id] = true;
        }

        return $locations;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function softenArrivalDay(array $days, array $byId, array &$pool, array &$notes): array
    {
        if ($days === []) {
            return $days;
        }
        $first = &$days[0];
        $nightId = (int) ($first['night_id'] ?? 0);
        $night = $byId[$nightId] ?? null;
        if ($night === null || $this->latLng($night) === null) {
            return $days;
        }
        $keep = [];
        foreach ($first['place_ids'] as $pid) {
            $row = $byId[$pid] ?? null;
            if ($row === null) {
                continue;
            }
            $blob = $this->name($row).' '.($row['snippet'] ?? '');
            $km = $this->kmBetween($night, $row);
            $tooFar = $km !== null && $km > self::ARRIVAL_MAX_KM;
            $side = (bool) preg_match(self::ARRIVAL_SIDE, $blob);
            if ($tooFar || $side) {
                $pool[] = $row;
                $why = $side ? 'боковая точка' : ('~'.round((float) $km).' км от ночёвки');
                $notes[] = 'день '.$first['date'].': убрана '.$why.' #'.$pid.' ('.$this->name($row).') — не для дня заезда';
                continue;
            }
            $keep[] = $pid;
        }
        $first['place_ids'] = $keep;
        // Если день заезда опустел — лёгкая рядом с базой (из пула или с соседнего дня той же ночёвки)
        if ($first['place_ids'] === []) {
            $pick = $this->pickArrivalLight($pool, $night, $byId);
            $fromPool = $pick !== null;
            if ($pick === null) {
                $pick = $this->stealArrivalFromLaterDays($days, $night, $byId, $notes);
            }
            if ($pick !== null) {
                $pid = (int) $pick['id'];
                $first['place_ids'] = [$pid];
                if ($fromPool) {
                    $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
                }
                $notes[] = 'день '.$first['date'].': на заезд поставлена лёгкая #'.$pid.' ('.$this->name($pick).')';
            }
        }
        // День заезда: добрать 2-ю лёгкую рядом (Тенно+Вароне), не Ледро
        $days = $this->enrichArrivalDay($days, $byId, $pool, $notes);

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function enrichArrivalDay(array $days, array &$byId, array &$pool, array &$notes): array
    {
        if ($days === [] || ($days[0]['is_transfer'] ?? false)) {
            return $days;
        }
        $first = &$days[0];
        if (count($first['place_ids']) >= 2) {
            return $days;
        }
        $night = $byId[(int) ($first['night_id'] ?? 0)] ?? null;
        if ($night === null) {
            return $days;
        }
        $used = $first['place_ids'];
        $pick = null;
        $nightId = (int) ($night['id'] ?? 0);
        // С соседнего дня той же базы, если там ≥2 точек (Тенно←Вароне с 15.08)
        for ($i = 1; $i < count($days) && $pick === null; $i++) {
            if ((int) ($days[$i]['night_id'] ?? 0) !== $nightId) {
                break;
            }
            if (count($days[$i]['place_ids']) < 2) {
                continue;
            }
            // Предпочитаем короткие (Вароне/Тенно), не длинный трек Busatte
            $candidates = [];
            foreach ($days[$i]['place_ids'] as $idx => $candId) {
                $cand = $byId[$candId] ?? null;
                if ($cand === null || in_array($candId, $used, true)) {
                    continue;
                }
                $blob = $this->name($cand);
                $km = $this->kmBetween($night, $cand);
                if ($km === null || $km > self::ARRIVAL_MAX_KM
                    || preg_match(self::ARRIVAL_SIDE, $blob)
                    || preg_match(self::HARD_TREK, $blob)) {
                    continue;
                }
                $candidates[] = ['idx' => $idx, 'id' => $candId, 'row' => $cand, 'h' => $this->hoursFor($cand), 'km' => $km];
            }
            usort($candidates, fn ($a, $b) => ($a['h'] <=> $b['h']) ?: ($a['km'] <=> $b['km']));
            if ($candidates !== []) {
                $best = $candidates[0];
                array_splice($days[$i]['place_ids'], $best['idx'], 1);
                $pick = $best['row'];
                $notes[] = 'день '.$first['date'].': с '.$days[$i]['date'].' на заезд взята #'.$best['id'].' ('.$this->name($pick).')';
            }
        }
        if ($pick === null) {
            $pick = $this->pickFromPool($pool, $used, $night, [], $byId, self::ARRIVAL_MAX_KM);
            if ($pick !== null) {
                $blob = $this->name($pick);
                if (preg_match(self::ARRIVAL_SIDE, $blob) || preg_match(self::HARD_TREK, $blob)) {
                    $pick = null;
                }
            }
        }
        if ($pick === null) {
            $pick = $this->pickFromCatalogNear($night, $byId, $days);
            if ($pick !== null) {
                $km = $this->kmBetween($night, $pick);
                $blob = $this->name($pick);
                if ($km === null || $km > self::ARRIVAL_MAX_KM
                    || preg_match(self::ARRIVAL_SIDE, $blob)
                    || preg_match(self::HARD_TREK, $blob)
                    || ! $this->placeFitsOvernight($pick, $night)) {
                    $pick = null;
                }
            }
        }
        if ($pick === null) {
            return $days;
        }
        $pid = (int) $pick['id'];
        if (in_array($pid, $first['place_ids'], true)) {
            return $days;
        }
        $first['place_ids'][] = $pid;
        $byId[$pid] = $pick;
        $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
        $notes[] = 'день '.$first['date'].': на заезд добавлена 2-я лёгкая #'.$pid.' ('.$this->name($pick).')';

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, mixed>  $night
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<string>  $notes
     * @return array<string, mixed>|null
     */
    private function stealArrivalFromLaterDays(array &$days, array $night, array $byId, array &$notes): ?array
    {
        $nightId = (int) ($night['id'] ?? 0);
        for ($i = 1; $i < count($days); $i++) {
            if ((int) ($days[$i]['night_id'] ?? 0) !== $nightId) {
                continue;
            }
            if (count($days[$i]['place_ids']) < 2) {
                continue;
            }
            foreach ($days[$i]['place_ids'] as $idx => $pid) {
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                $blob = $this->name($row).' '.($row['snippet'] ?? '');
                if (preg_match(self::HARD_TREK, $blob) || preg_match(self::ARRIVAL_SIDE, $blob)) {
                    continue;
                }
                $km = $this->kmBetween($night, $row);
                if ($km === null || $km > self::ARRIVAL_MAX_KM + 8) {
                    continue;
                }
                unset($days[$i]['place_ids'][$idx]);
                $days[$i]['place_ids'] = array_values($days[$i]['place_ids']);
                $notes[] = 'день '.$days[$i]['date'].': #'.$pid.' сдвинут на день заезда';

                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     * @param  array<string, mixed>  $night
     * @param  array<int, array<string, mixed>>  $byId
     * @return array<string, mixed>|null
     */
    private function pickArrivalLight(array $pool, array $night, array $byId): ?array
    {
        $best = null;
        $bestKm = 999.0;
        foreach ($pool as $row) {
            $blob = $this->name($row).' '.($row['snippet'] ?? '');
            if (preg_match(self::HARD_TREK, $blob) || preg_match(self::ARRIVAL_SIDE, $blob)) {
                continue;
            }
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                continue;
            }
            $km = $this->kmBetween($night, $row);
            if ($km === null || $km > self::ARRIVAL_MAX_KM) {
                continue;
            }
            if ($km < $bestKm) {
                $bestKm = $km;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function stripHardTreks(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                $row = $byId[$pid] ?? null;
                $blob = $row ? $this->name($row).' '.($row['snippet'] ?? '') : '';
                if ($row && preg_match(self::HARD_TREK, $blob)) {
                    $pool[] = $row;
                    $notes[] = 'день '.$day['date'].': убран тяжёлый трек #'.$pid.' ('.$this->name($row).')';
                    continue;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubIncompatibleHubPlaces(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                if (! $this->placeFitsOvernight($row, $night)) {
                    $pool[] = $row;
                    $notes[] = 'день '.$day['date'].': убран чужой хаб #'.$pid.' ('.$this->name($row).')';
                    continue;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * Убрать рестораны/Michelin/отели из слотов активностей.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubNotRouteActivities(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                if (! $this->isAllowedRouteActivity($row)) {
                    $pool[] = $row;
                    $notes[] = 'день '.$day['date'].': убран не-маршрут #'.$pid.' ('.$this->name($row).')';
                    continue;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isAllowedRouteActivity(array $row): bool
    {
        if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
            return false;
        }
        $blob = $this->name($row).' '.($row['snippet'] ?? '');
        if ($blob === '' || preg_match(self::NOT_ROUTE_ACTIVITY, $blob)) {
            return false;
        }
        if (preg_match(self::WEAK_FILL, $blob) || preg_match(self::ADULTS_ONLY, $blob)) {
            return false;
        }

        return true;
    }

    /**
     * Убрать только явный дубль ночёвки (тот же id / отель).
     * Озеро и город базы — НАОБОРОТ нужны как место путешествия (правка директора ×2).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubOvernightDuplicates(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $protect = [];
            foreach ($day['base_lived_ids'] ?? [] as $bid) {
                $protect[(int) $bid] = true;
            }
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                $pid = (int) $pid;
                // защищённый слот «время на озере/в городе базы»
                if (isset($protect[$pid])) {
                    $keep[] = $pid;
                    continue;
                }
                if ($pid === (int) ($day['night_id'] ?? 0)) {
                    continue;
                }
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                if ($this->isOvernightDuplicate($row, $night)) {
                    $pool[] = $row;
                    $notes[] = 'день '.$day['date'].': убран дубль ночёвки #'.$pid.' ('.$this->name($row).')';
                    continue;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * Дубль ночёвки = отель/та же карточка жилья, НЕ «время на озере у базы».
     *
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $night
     */
    private function isOvernightDuplicate(array $place, array $night): bool
    {
        if (! empty($place['force_base_lived'])) {
            return false;
        }
        $pn = $this->name($place);
        $nn = $this->name($night);
        if ($pn === '' || $nn === '') {
            return false;
        }
        // Отели / жильё с тем же ядром имени — да, дубль
        if (preg_match('/hotel|מלון|resort|apartment|апарт|ночев|overnight/ui', $pn)) {
            $pCore = $this->placeCoreName($pn);
            $nCore = $this->placeCoreName($nn);
            if ($pCore !== '' && $nCore !== '' && ($pCore === $nCore || str_contains($pCore, $nCore) || str_contains($nCore, $pCore))) {
                return true;
            }
        }
        // Озеро / город / прогулка у базы — это программа, не дубль кровати
        if (preg_match('/lago|אגם|озеро|lake|прогулк|время\s+на|lungolago|набереж|town|город/ui', $pn)) {
            return false;
        }
        if (preg_match(self::TOWN_WALK_ONLY, $pn)) {
            return false;
        }

        return false;
    }

    private function placeCoreName(string $name): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/\(.*?\)/u', '', $s) ?? $s;
        $s = preg_replace('/למשפחות|למבוגרים|בלי\s*רכבל|for\s*families|for\s*adults|without\s*cable/ui', '', $s) ?? $s;
        $s = preg_replace('/טיול\s*רגלי.*$/ui', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;

        return $s;
    }

    /**
     * Снять лишнюю точку, если сумма часов > потолка (Skyscraper+X-Treme и т.п.).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubDayOverload(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            $cap = ($day['is_transfer'] ?? false) ? self::DAY_CAP_TRANSFER_HOURS : self::DAY_CAP_STAY_HOURS;
            while (count($day['place_ids']) > 1 && $this->actHours($day['place_ids'], $byId) > $cap) {
                // Сначала снимаем добивки не из конструкции / не must-see района
                $protect = [];
                foreach ($day['construction_ids'] ?? [] as $cid) {
                    $protect[(int) $cid] = true;
                }
                foreach ($day['place_ids'] as $pid) {
                    $nm = mb_strtolower($this->name($byId[$pid] ?? []));
                    if (preg_match('/braies|בראייס|прагс|monte\s*baldo|מונטה\s*באלד|бальдо/ui', $nm)) {
                        $protect[(int) $pid] = true;
                    }
                }
                $dropIdx = null;
                $lightH = 999.0;
                foreach ($day['place_ids'] as $i => $pid) {
                    if ($protect !== [] && isset($protect[(int) $pid]) && count($day['place_ids']) > count($protect)) {
                        continue; // есть что снять кроме конструкции/must-see
                    }
                    $row = $byId[$pid] ?? null;
                    $h = $row ? $this->hoursFor($row) : 1.5;
                    // Не из конструкции — приоритет на снятие
                    if ($protect !== [] && ! isset($protect[(int) $pid])) {
                        $h -= 100;
                    }
                    if ($h < $lightH) {
                        $lightH = $h;
                        $dropIdx = $i;
                    }
                }
                if ($dropIdx === null) {
                    $dropIdx = count($day['place_ids']) - 1;
                }
                $drop = $day['place_ids'][$dropIdx];
                array_splice($day['place_ids'], $dropIdx, 1);
                if (isset($byId[$drop])) {
                    $pool[] = $byId[$drop];
                    $notes[] = 'день '.$day['date'].': снят перегруз #'.$drop.' ('.$this->name($byId[$drop]).')';
                }
            }
        }
        unset($day);

        return $days;
    }

    private function dayCapHours(array $day): float
    {
        return ($day['is_transfer'] ?? false) ? self::DAY_CAP_TRANSFER_HOURS : self::DAY_CAP_STAY_HOURS;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function hasLongEnoughActivity(array $ids, array $byId): bool
    {
        foreach ($ids as $pid) {
            $row = $byId[$pid] ?? null;
            if ($row !== null && $this->hoursFor($row) >= self::LONG_ACTIVITY_HOURS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Полноценный день (рафтинг/каньонинг/длинный трек 5ч+) — не набивать вторым POI.
     *
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function hasFullDayActivity(array $ids, array $byId): bool
    {
        foreach ($ids as $pid) {
            $row = $byId[$pid] ?? null;
            if ($row !== null && $this->hoursFor($row) >= self::FULL_DAY_ACTIVITY_HOURS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Не добивать день вторым объектом, если уже есть длинная/полная программа.
     *
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function shouldNotAddSecondActivity(array $ids, array $byId): bool
    {
        // Только full-day (рафтинг/каньон ≥5ч) закрывает день.
        // Трек 4ч (Cinque Torri / Sorapis) — день ещё можно добить природой/озером.
        return $this->hasFullDayActivity($ids, $byId);
    }

    /**
     * Одна точка — один раз за поездку (кроме ночёвок).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function dedupePlacesAcrossTrip(array $days, array $byId, array &$pool, array &$notes): array
    {
        $seen = [];
        foreach ($days as &$day) {
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                if (isset($seen[$pid])) {
                    if (isset($byId[$pid])) {
                        $pool[] = $byId[$pid];
                    }
                    $notes[] = 'день '.$day['date'].': убран повтор #'.$pid;
                    continue;
                }
                $seen[$pid] = true;
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * Семантический дубль: Limone #20 и Limone #32 — одна сущность.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function dedupeSemanticEntities(array $days, array $byId, array &$pool, array &$notes): array
    {
        $seenEntity = [];
        foreach ($days as &$day) {
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                $key = $this->semanticEntityKey($row);
                if ($key !== '' && isset($seenEntity[$key])) {
                    $pool[] = $row;
                    $notes[] = 'день '.$day['date'].': убран семантический дубль #'.$pid
                        .' ('.$this->name($row).', entity='.$key.', уже #'.$seenEntity[$key].')';
                    continue;
                }
                if ($key !== '') {
                    $seenEntity[$key] = $pid;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function semanticEntityKey(array $row): string
    {
        $name = mb_strtolower($this->name($row));
        if (preg_match('/(malcesine|מלצ).{0,40}(limone|לימונה)|(limone|לימונה).{0,40}(malcesine|מלצ)/ui', $name)) {
            return 'boat:malcesine-limone';
        }
        if (preg_match('/limone|לימונה|лимоне/ui', $name)) {
            return 'entity:limone';
        }
        if (preg_match('/malcesine|מלצס|мальчез/ui', $name) && ! preg_match('/baldo|באלד/ui', $name)) {
            return 'entity:malcesine';
        }
        // Не клеить верёвочный парк и Bulla в один entity из‑за слова Ortisei
        if (preg_match('/braies|בראייס/ui', $name)) {
            return 'entity:braies';
        }
        if (preg_match('/skyscraper|plan\s*de\s*corones/ui', $name)) {
            return 'entity:kronplatz-sky';
        }
        if (preg_match('/פארק\s*חבלים|col\s*de\s*flam|emozion|rope\s*park|adventure\s*park|веревочн/ui', $name)) {
            return 'entity:rope-park';
        }

        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @return array<string, true>
     */
    private function usedSemanticKeys(array $days, array $byId = []): array
    {
        $keys = [];
        foreach ($days as $day) {
            foreach ($day['place_ids'] ?? [] as $pid) {
                $row = $byId[(int) $pid] ?? null;
                if ($row === null) {
                    continue;
                }
                $ek = $this->semanticEntityKey($row);
                if ($ek !== '') {
                    $keys[$ek] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Убрать POI с большим крюком относительно оси предыдущая база → ночёвка дня.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function scrubRouteZigzags(array $days, array $byId, array &$pool, array &$notes): array
    {
        $detourMax = (float) config('travel_validator.detour_km_major', 45.0);
        $n = count($days);
        for ($i = 0; $i < $n; $i++) {
            $night = $byId[(int) ($days[$i]['night_id'] ?? 0)] ?? null;
            if ($night === null || $i === 0) {
                continue;
            }
            $prevNight = $byId[(int) ($days[$i - 1]['night_id'] ?? 0)] ?? null;
            if ($prevNight === null) {
                continue;
            }
            $sameBase = (int) ($days[$i - 1]['night_id'] ?? 0) === (int) ($days[$i]['night_id'] ?? 0);
            // Stay на той же базе — зигзаг межбазовый не считаем; только transfer / смена базы
            if ($sameBase && empty($days[$i]['is_transfer'])) {
                continue;
            }
            $direct = $this->kmBetween($prevNight, $night);
            if ($direct === null || $direct < 20.0) {
                continue;
            }
            $protect = [];
            foreach ($days[$i]['construction_ids'] ?? [] as $cid) {
                $protect[(int) $cid] = true;
            }
            $keep = [];
            foreach ($days[$i]['place_ids'] as $pid) {
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                $via1 = $this->kmBetween($prevNight, $row);
                $via2 = $this->kmBetween($row, $night);
                if ($via1 === null || $via2 === null) {
                    $keep[] = $pid;
                    continue;
                }
                $detour = ($via1 + $via2) - $direct;
                $allow = $detourMax;
                if (isset($protect[$pid])) {
                    $allow = $detourMax * 1.25;
                }
                $nm = mb_strtolower($this->name($row));
                $nightHubs = $this->matcher->detectHubs($this->name($night));
                // Браес/Доббиако из Мизурины — крюк; на оси к Мизурине — ок
                if (preg_match(self::VAL_PUSTERIA_LAKE, $nm)) {
                    if (array_intersect($nightHubs, ['misurina', 'cortina']) !== []) {
                        $allow = min($allow, 18.0); // почти наверняка detour → убрать
                    } elseif (array_intersect($nightHubs, ['brunico', 'braies']) !== []) {
                        $allow = max($allow, 55.0);
                    }
                }
                // Кортина на оси Ортизей→Брунико — классический зигзаг к Мизурине
                if (preg_match('/cortina|קורטינ|кортин/ui', $nm)
                    && array_intersect($nightHubs, ['brunico', 'ortisei']) !== []) {
                    $allow = min($allow, 25.0);
                }
                if ($detour > $allow) {
                    $pool[] = $row;
                    $notes[] = 'день '.$days[$i]['date'].': убран зигзаг #'.$pid.' ('.$this->name($row)
                        .', detour≈'.round($detour, 1).' км от оси баз)';
                    continue;
                }
                $keep[] = $pid;
            }
            $days[$i]['place_ids'] = $keep;
        }

        return $days;
    }

    /**
     * На переезде не держать POI дальше 70 км от новой ночёвки (Нардис→Ортизеи).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function dropFarTransferPlaces(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            if (! $day['is_transfer']) {
                continue;
            }
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $keep = [];
            foreach ($day['place_ids'] as $pid) {
                $row = $byId[$pid] ?? null;
                if ($row === null) {
                    continue;
                }
                $km = $this->kmBetween($night, $row);
                $far = $km !== null && $km > 45;
                $badHub = ! $this->placeFitsOvernight($row, $night);
                if ($far || $badHub) {
                    $pool[] = $row;
                    $why = $badHub ? 'чужой хаб' : ('~'.round((float) $km).' км');
                    $notes[] = 'день '.$day['date'].': с переезда убрана #'.$pid.' ('.$why.')';
                    continue;
                }
                $keep[] = $pid;
            }
            $day['place_ids'] = $keep;
        }
        unset($day);

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function capTransferDays(array $days, array $byId, array &$pool, array &$notes): array
    {
        foreach ($days as &$day) {
            if (! $day['is_transfer']) {
                continue;
            }
            $ids = $day['place_ids'];
            $cap = ! empty($day['from_construction'])
                ? self::CONSTRUCTION_TRANSFER_MAX
                : self::TRANSFER_MAX_PLACES;
            if (count($ids) <= $cap) {
                // Не вычищать остановки «по дороге» — они как раз нужны на переезде
                continue;
            }
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            usort($ids, function (int $a, int $b) use ($byId, $night) {
                $ra = $byId[$a] ?? null;
                $rb = $byId[$b] ?? null;
                $ka = ($night && $ra) ? ($this->kmBetween($night, $ra) ?? 999) : 999;
                $kb = ($night && $rb) ? ($this->kmBetween($night, $rb) ?? 999) : 999;

                return $ka <=> $kb;
            });
            $keep = array_slice($ids, 0, $cap);
            $drop = array_slice($ids, $cap);
            $day['place_ids'] = $keep;
            foreach ($drop as $pid) {
                if (isset($byId[$pid])) {
                    $pool[] = $byId[$pid];
                    $notes[] = 'день '.$day['date'].': с переезда снята лишняя точка #'.$pid;
                }
            }
        }
        unset($day);

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $preferNames
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function fillThinDays(array $days, array &$byId, array &$pool, array $preferNames, array &$notes): array
    {
        foreach ($days as $di => &$day) {
            // День заезда — только через enrichArrivalDay
            if ($di === 0 && ! ($day['is_transfer'] ?? false)) {
                continue;
            }
            $maxPlaces = $day['is_transfer']
                ? (! empty($day['from_construction']) ? self::CONSTRUCTION_TRANSFER_MAX : self::TRANSFER_MAX_PLACES)
                : (! empty($day['from_construction']) ? self::CONSTRUCTION_STAY_MAX : self::STAY_MAX_PLACES);
            while (count($day['place_ids']) > $maxPlaces) {
                // Не выкидывать хвост конструкции — сначала лишние добивки
                $protect = [];
                foreach ($day['construction_ids'] ?? [] as $cid) {
                    $protect[(int) $cid] = true;
                }
                $dropIdx = count($day['place_ids']) - 1;
                if ($protect !== []) {
                    for ($i = count($day['place_ids']) - 1; $i >= 0; $i--) {
                        if (! isset($protect[(int) $day['place_ids'][$i]])) {
                            $dropIdx = $i;
                            break;
                        }
                    }
                }
                $drop = $day['place_ids'][$dropIdx];
                array_splice($day['place_ids'], $dropIdx, 1);
                if (isset($byId[$drop])) {
                    $pool[] = $byId[$drop];
                    $notes[] = 'день '.$day['date'].': убрана лишняя #'.$drop.' (макс. '.$maxPlaces.')';
                }
            }
            $targetH = $day['is_transfer'] ? self::RESIDUAL_TRANSFER_HOURS : self::THIN_ACT_HOURS;
            $hours = $this->actHours($day['place_ids'], $byId);
            if (count($day['place_ids']) >= $maxPlaces
                || $hours >= $targetH
                || $this->shouldNotAddSecondActivity($day['place_ids'], $byId)) {
                continue;
            }
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            while (count($day['place_ids']) < $maxPlaces
                && $this->actHours($day['place_ids'], $byId) < $targetH
                && ! $this->shouldNotAddSecondActivity($day['place_ids'], $byId)) {
                $pick = $this->pickAlongRoute($pool, $day, $night, $preferNames, $byId, $days);
                if ($pick === null) {
                    break;
                }
                if (! $this->fitsDayLoad($day, $pick, $byId)) {
                    break;
                }
                $pid = (int) $pick['id'];
                $day['place_ids'][] = $pid;
                $byId[$pid] = $pick;
                $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
                $notes[] = 'день '.$day['date'].': добрана точка #'.$pid.' ('.$this->name($pick).')';
            }
        }
        unset($day);

        return $days;
    }

    /**
     * Остаточная ёмкость: после основной активности (+переезда) ещё есть время —
     * ищем совместимую точку у ночёвки / по направлению, не «случайную».
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $preferNames
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function enrichResidualCapacity(array $days, array &$byId, array &$pool, array $preferNames, array &$notes): array
    {
        foreach ($days as $di => &$day) {
            if ($di === 0 && ! ($day['is_transfer'] ?? false)) {
                // заезд тоже добиваем, если только Тенно/короткие точки
            }
            $maxPlaces = $day['is_transfer']
                ? (! empty($day['from_construction']) ? self::CONSTRUCTION_TRANSFER_MAX : self::TRANSFER_MAX_PLACES)
                : (! empty($day['from_construction']) ? self::CONSTRUCTION_STAY_MAX : self::STAY_MAX_PLACES);
            $targetH = $day['is_transfer'] ? self::RESIDUAL_TRANSFER_HOURS : self::RESIDUAL_STAY_HOURS;
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            while (count($day['place_ids']) < $maxPlaces
                && $this->actHours($day['place_ids'], $byId) < $targetH
                && ! $this->shouldNotAddSecondActivity($day['place_ids'], $byId)) {
                $pick = $this->pickAlongRoute($pool, $day, $night, $preferNames, $byId, $days);
                if ($pick === null || ! $this->fitsDayLoad($day, $pick, $byId)) {
                    break;
                }
                // на переезде предпочитаем точки «по дороге» / у новой базы, не второй экстрим
                if (($day['is_transfer'] ?? false) && preg_match(self::EXTREME_ACTIVITY, $this->name($pick))) {
                    break;
                }
                $pid = (int) $pick['id'];
                $day['place_ids'][] = $pid;
                $byId[$pid] = $pick;
                $day['catalog_ids'] = array_values(array_unique(array_merge(
                    array_map('intval', $day['catalog_ids'] ?? []),
                    [$pid]
                )));
                $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
                $notes[] = 'день '.$day['date'].': добивка по оси/времени → #'.$pid.' ('.$this->name($pick).')';
            }
        }
        unset($day);

        return $days;
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $pick
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function fitsDayLoad(array $day, array $pick, array $byId): bool
    {
        $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
        if ($night !== null && $this->isOvernightDuplicate($pick, $night)) {
            return false;
        }
        $cap = $this->dayCapHours($day);
        $next = $this->actHours($day['place_ids'], $byId) + $this->hoursFor($pick);

        return $next <= $cap + 0.05;
    }

    /**
     * Точка у новой/текущей ночёвки (хаб), лёгкая, не использована в поездке.
     *
     * @param  list<array<string, mixed>>  $pool
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $night
     * @param  list<string>  $preferNames
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>|null
     */
    private function pickAlongRoute(array $pool, array $day, array $night, array $preferNames, array $byId, array $days): ?array
    {
        $used = array_keys($this->usedPlaceIds($days));
        foreach ($day['place_ids'] as $pid) {
            $used[] = (int) $pid;
        }
        $used = array_values(array_unique($used));
        $nightHubs = $this->matcher->detectHubs($this->name($night));
        $maxKm = $day['is_transfer'] ? 30.0 : self::FILL_MAX_KM;

        $isTransfer = (bool) ($day['is_transfer'] ?? false);
        $candidates = [];
        $fromPool = $this->pickFromPool($pool, $used, $night, $preferNames, $byId, $maxKm);
        if ($fromPool !== null) {
            $candidates[] = $fromPool;
        }
        foreach ($this->catalogNearRanked($night, $days, 8) as $fromCat) {
            $candidates[] = $fromCat;
        }
        $usedEntities = $this->usedSemanticKeys($days, $byId);
        $prevNight = null;
        $nextNight = null;
        foreach ($days as $idx => $d) {
            if (($d['date'] ?? '') === ($day['date'] ?? '')) {
                if ($idx > 0) {
                    $prevNight = $byId[(int) ($days[$idx - 1]['night_id'] ?? 0)] ?? null;
                }
                if ($idx + 1 < count($days)) {
                    $nextNight = $byId[(int) ($days[$idx + 1]['night_id'] ?? 0)] ?? null;
                    if ($nextNight !== null && (int) ($nextNight['id'] ?? 0) === (int) ($night['id'] ?? 0)) {
                        $nextNight = null; // та же база — нет «следующей»
                    }
                }
                break;
            }
        }
        $detourMax = (float) config('travel_validator.detour_km_major', 45.0);
        // Ось добивки: к следующей базе (или на transfer — от предыдущей к текущей ночёвке)
        $axisFrom = $prevNight;
        $axisTo = $night;
        if (! $isTransfer && $nextNight !== null) {
            $axisFrom = $night;
            $axisTo = $nextNight;
        }
        $ok = [];
        $dayAlreadyExtreme = $this->dayHasExtreme($day['place_ids'] ?? [], $byId);
        $prevDayHardTrek = false;
        foreach ($days as $idx => $d) {
            if (($d['date'] ?? '') === ($day['date'] ?? '')) {
                if ($idx > 0) {
                    foreach ($days[$idx - 1]['place_ids'] ?? [] as $pp) {
                        $pr = $byId[(int) $pp] ?? null;
                        if ($pr && preg_match(self::HARD_DAY_TREK, $this->name($pr))) {
                            $prevDayHardTrek = true;
                            break;
                        }
                    }
                }
                break;
            }
        }
        foreach ($candidates as $pick) {
            $blob = $this->name($pick);
            if (! $this->isAllowedRouteActivity($pick) || preg_match(self::HARD_TREK, $blob) || preg_match(self::ARRIVAL_SIDE, $blob)) {
                continue;
            }
            // Второй экстрим в день не добавляем
            if ($dayAlreadyExtreme && preg_match(self::EXTREME_ACTIVITY, $blob)) {
                continue;
            }
            // После тяжёлого трека вчера — не ставить Sorapis/Cinque Torri сегодня
            if ($prevDayHardTrek && preg_match(self::HARD_DAY_TREK, $blob)) {
                continue;
            }
            // Браес/Доббиако не добивать в stay у Мизурины
            $nh = $this->matcher->detectHubs($this->name($night));
            if (preg_match(self::VAL_PUSTERIA_LAKE, $blob)
                && array_intersect($nh, ['misurina', 'cortina']) !== []
                && empty($day['is_transfer'])) {
                continue;
            }
            if ($this->isOvernightDuplicate($pick, $night)) {
                continue;
            }
            $ek = $this->semanticEntityKey($pick);
            if ($ek !== '' && isset($usedEntities[$ek])) {
                continue;
            }
            $detour = null;
            if ($axisFrom !== null && $axisTo !== null) {
                $direct = $this->kmBetween($axisFrom, $axisTo);
                $v1 = $this->kmBetween($axisFrom, $pick);
                $v2 = $this->kmBetween($pick, $axisTo);
                if ($direct !== null && $direct >= 15 && $v1 !== null && $v2 !== null) {
                    $detour = ($v1 + $v2) - $direct;
                    if ($detour > $detourMax) {
                        continue; // только по направлению следующей базы, не «вообще рядом»
                    }
                }
            }
            $ph = $this->matcher->detectHubs($blob);
            $sameHub = $nightHubs !== [] && $ph !== [] && array_intersect($nightHubs, $ph) !== [];
            $kmPick = $this->kmBetween($night, $pick);
            $pickH = $this->hoursFor($pick);
            $emptyDay = ($day['place_ids'] ?? []) === [];
            // Пустой день: можно одну полноценную программу (рафтинг 5ч). Иначе — только лёгкая добивка.
            if ($emptyDay) {
                $maxH = self::FULL_DAY_ACTIVITY_HOURS + 0.5;
            } else {
                $maxH = ($sameHub || ($kmPick !== null && $kmPick <= 12.0)) ? 3.0 : 2.5;
                if ($pickH >= self::FULL_DAY_ACTIVITY_HOURS) {
                    continue; // не второй full-day объект
                }
            }
            if ($pickH > $maxH) {
                continue;
            }
            if ($isTransfer) {
                if (! $this->transferPickOk($pick, $night, $nightHubs)) {
                    continue;
                }
            } elseif (! $this->placeFitsOvernight($pick, $night) && $nextNight === null) {
                continue;
            } elseif (! $this->placeFitsOvernight($pick, $night) && $nextNight !== null) {
                // stay: допускаем точку на оси к следующей базе
                if ($detour === null || $detour > $detourMax * 0.85) {
                    continue;
                }
            }
            $ok[] = ['row' => $pick, 'detour' => $detour ?? 999.0];
        }
        if ($ok === []) {
            return null;
        }
        // Лучший = минимальный крюк по оси к следующей / новой базе
        usort($ok, function (array $a, array $b) {
            $da = (float) $a['detour'];
            $db = (float) $b['detour'];
            if (abs($da - $db) > 0.5) {
                return $da <=> $db;
            }

            return $this->hoursFor($a['row']) <=> $this->hoursFor($b['row']);
        });

        return $ok[0]['row'];
    }

    /**
     * На переезде упорядочить точки вдоль оси prev→новая база (достопримечательность → промежуточная → база).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @return list<array<string, mixed>>
     */
    private function orderPlacesAlongAxis(array $days, array $byId): array
    {
        $n = count($days);
        for ($i = 1; $i < $n; $i++) {
            if (count($days[$i]['place_ids'] ?? []) < 2) {
                continue;
            }
            $night = $byId[(int) ($days[$i]['night_id'] ?? 0)] ?? null;
            $prev = $byId[(int) ($days[$i - 1]['night_id'] ?? 0)] ?? null;
            if ($night === null || $prev === null) {
                continue;
            }
            if ((int) ($days[$i - 1]['night_id'] ?? 0) === (int) ($days[$i]['night_id'] ?? 0)
                && empty($days[$i]['is_transfer'])) {
                continue;
            }
            $ids = $days[$i]['place_ids'];
            usort($ids, function (int $a, int $b) use ($byId, $prev) {
                $ra = $byId[$a] ?? null;
                $rb = $byId[$b] ?? null;
                $ka = ($ra && $prev) ? ($this->kmBetween($prev, $ra) ?? 999) : 999;
                $kb = ($rb && $prev) ? ($this->kmBetween($prev, $rb) ?? 999) : 999;

                return $ka <=> $kb;
            });
            $days[$i]['place_ids'] = $ids;
        }

        return $days;
    }

    /**
     * Пустой tourist-день у базы (после снятия Селлы) — забрать точку с соседнего переезда
     * или из пула с большим радиусом / каталога.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $preferNames
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function rescueEmptyStayDays(array $days, array &$byId, array &$pool, array $preferNames, array &$notes): array
    {
        foreach ($days as $i => &$day) {
            if ($day['is_transfer'] || $day['place_ids'] !== []) {
                continue;
            }
            $nightId = (int) ($day['night_id'] ?? 0);
            $night = $byId[$nightId] ?? null;
            if ($night === null) {
                continue;
            }

            // 1) С соседнего дня-переезда с той же базой отправления — лёгкую точку «домой»
            foreach ([1, -1, 2] as $delta) {
                $j = $i + $delta;
                if (! isset($days[$j]) || ! $days[$j]['is_transfer']) {
                    continue;
                }
                if ($days[$j]['place_ids'] === []) {
                    continue;
                }
                // переезд «из» этой базы: ночёвка уже другая, а активности ещё у старой
                $candId = (int) $days[$j]['place_ids'][0];
                $cand = $byId[$candId] ?? null;
                if ($cand === null) {
                    continue;
                }
                $km = $this->kmBetween($night, $cand);
                if ($km === null || $km > 40) {
                    continue;
                }
                $blob = $this->name($cand).' '.($cand['snippet'] ?? '');
                if (preg_match(self::HARD_TREK, $blob)) {
                    continue;
                }
                array_shift($days[$j]['place_ids']);
                $day['place_ids'][] = $candId;
                $notes[] = 'день '.$day['date'].': с дня '.$days[$j]['date'].' перенесена #'.$candId.' ('.$this->name($cand).') — пустой день у базы';
                break;
            }
            if ($day['place_ids'] !== []) {
                continue;
            }

            // 2) Сначала каталог у ночёвки (Cadini@Misurina), иначе packer подсовывает Cortina/ferrata
            $usedTrip = array_keys($this->usedPlaceIds($days));
            $pick = $this->pickFromCatalogNear($night, $byId, $days);
            if ($pick === null) {
                $pick = $this->pickFromPool($pool, $usedTrip, $night, $preferNames, $byId, 55.0);
            }
            if ($pick === null) {
                $pick = $this->pickFromPool(array_values($byId), $usedTrip, $night, $preferNames, $byId, 40.0);
            }
            if ($pick !== null) {
                $pid = (int) $pick['id'];
                $day['place_ids'][] = $pid;
                $byId[$pid] = $pick;
                $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
                $notes[] = 'день '.$day['date'].': спасён пустой день точкой #'.$pid.' ('.$this->name($pick).')';
            }
        }
        unset($day);

        return $days;
    }

    /**
     * @param  array<string, mixed>  $night
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>|null
     */
    private function pickFromCatalogNear(array $night, array $byId, array $days): ?array
    {
        $list = $this->catalogNearRanked($night, $days, 1);

        return $list[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function catalogNearRanked(array $night, array $days, int $limit = 8): array
    {
        $ll = $this->latLng($night);
        if ($ll === null) {
            return [];
        }
        $used = $this->usedPlaceIds($days);
        foreach ($days as $d) {
            if (($d['night_id'] ?? 0) > 0) {
                $used[(int) $d['night_id']] = true;
            }
        }

        $nLat = $ll[0];
        $nLng = $ll[1];
        $dLat = 0.42;
        $dLng = 0.55;
        $candidates = \App\Models\Location::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [$nLat - $dLat, $nLat + $dLat])
            ->whereBetween('lng', [$nLng - $dLng, $nLng + $dLng])
            ->where(function ($q) {
                $q->whereNull('trip_role')->orWhere('trip_role', '!=', 'base');
            })
            ->limit(200)
            ->get(['id', 'lat', 'lng', 'trip_role', 'meta']);

        $nightHubs = $this->matcher->detectHubs($this->name($night));
        $scored = [];
        foreach ($candidates as $loc) {
            $id = (int) $loc->id;
            if (isset($used[$id])) {
                continue;
            }
            $meta = is_array($loc->meta) ? $loc->meta : [];
            $name = (string) ($meta['title'] ?? '');
            if ($name === '' || preg_match(self::HARD_TREK, $name) || preg_match(self::ARRIVAL_SIDE, $name)) {
                continue;
            }
            $row = [
                'id' => $id,
                'name' => $name,
                'lat' => (float) $loc->lat,
                'lng' => (float) $loc->lng,
                'role' => 'place',
                'is_base' => false,
                'snippet' => '',
            ];
            if (! $this->isAllowedRouteActivity($row) || ! $this->placeFitsOvernight($row, $night)) {
                continue;
            }
            $h = $this->hoursFor($row);
            $ph = $this->matcher->detectHubs($name);
            // Допускаем full-day (рафтинг 5ч) в кандидатах; второй слот отфильтрует pickAlongRoute
            $maxH = self::FULL_DAY_ACTIVITY_HOURS + 0.5;
            if ($h > $maxH) {
                continue;
            }
            $km = $this->kmBetween($night, $row);
            if ($km === null || $km > 35) {
                continue;
            }
            $ph = $this->matcher->detectHubs($name);
            $score = 50.0 - $km;
            if ($nightHubs !== [] && array_intersect($nightHubs, $ph) !== []) {
                $score += 25.0;
            } elseif ($nightHubs !== [] && $ph === []) {
                $score -= 5.0;
            } elseif ($nightHubs !== [] && $ph !== [] && array_intersect($nightHubs, $ph) === []) {
                $score -= 15.0;
            }
            $scored[] = ['score' => $score, 'row' => $row];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($x) => $x['row'], array_slice($scored, 0, max(1, $limit)));
    }

    /**
     * ОСНОВА плана: готовая дневная связка из туров директора (порядок мест сохраняем).
     * Каталог — только если связку не смапили.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<RouteSkeleton>  $skeletons
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function applyDirectorConstructions(array $days, array &$byId, array &$pool, array $skeletons, array &$notes): array
    {
        if ($skeletons === []) {
            return $days;
        }

        foreach ($days as $di => &$day) {
            if ($di === 0) {
                continue;
            }
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $nightHubs = $this->matcher->detectHubs(
                $this->name($night).' '.(string) ($day['night_label'] ?? '')
            );
            if ($nightHubs === []) {
                continue;
            }

            $hubsForConstruct = $nightHubs;
            // Коридор Мизурина/Кортина — подтягиваем конструкции Braies (must-see района)
            if (array_intersect($nightHubs, ['misurina', 'cortina']) !== []) {
                $hubsForConstruct[] = 'braies';
                $hubsForConstruct = array_values(array_unique($hubsForConstruct));
            }
            $candidates = $this->matcher->constructionsForHubs($skeletons, $hubsForConstruct);
            if ($candidates === []) {
                continue;
            }

            $maxPlaces = ! empty($day['is_transfer'])
                ? self::CONSTRUCTION_TRANSFER_MAX
                : self::CONSTRUCTION_STAY_MAX;

            $bestIds = [];
            $bestMeta = null;
            $bestScore = 0.0;
            $usedTripBase = $this->usedPlaceIdsExceptDay($days, $di);

            foreach ($candidates as $c) {
                $resolved = [];
                $usedLocal = $usedTripBase;
                foreach ($c['places'] as $wantName) {
                    if (count($resolved) >= $maxPlaces) {
                        break;
                    }
                    if (preg_match(self::HARD_TREK, $wantName) || preg_match(self::NOT_ROUTE_ACTIVITY, $wantName)) {
                        continue;
                    }
                    if (preg_match(self::WEAK_FILL, $wantName)) {
                        continue;
                    }
                    // Боковые озёра при ночёвке Рива — не основа stay-дня
                    if (empty($day['is_transfer']) && array_intersect($nightHubs, ['riva', 'garda']) !== []
                        && preg_match(self::ARRIVAL_SIDE, $wantName)) {
                        continue;
                    }
                    $row = $this->resolveNameToLocation($wantName, $byId, $pool, $night, $usedLocal);
                    if ($row === null || ! $this->isAllowedRouteActivity($row)) {
                        continue;
                    }
                    if (! $this->placeFitsOvernight($row, $night)) {
                        continue;
                    }
                    $pid = (int) $row['id'];
                    if ($pid === (int) ($day['night_id'] ?? 0) || isset($usedLocal[$pid])) {
                        continue;
                    }
                    if ($this->isOvernightDuplicate($row, $night)) {
                        continue;
                    }
                    $resolved[] = $pid;
                    $usedLocal[$pid] = true;
                    $byId[$pid] = $row;
                }
                if ($resolved === []) {
                    continue;
                }
                // Конструкция = связка. Один обломок из длинного дня — не конструкция.
                $srcCount = count($c['places']);
                if ($srcCount >= 2 && count($resolved) < 2) {
                    continue;
                }
                $ratio = count($resolved) / max(1, min($srcCount, $maxPlaces));
                $score = count($resolved) * 10.0 + $ratio * 6.0;
                if (count($resolved) >= 2) {
                    $score += 12.0;
                }
                foreach ($resolved as $pid) {
                    $nm = mb_strtolower($this->name($byId[$pid] ?? []));
                    if (preg_match('/braies|בראייס|прагс|monte\s*baldo|מונטה\s*באלד|бальдо|cascata|מפל|molveno|מולבנ|dobbiaco|דוביאק|varone|וארנ|tenno|טנו/ui', $nm)) {
                        $score += 5.0;
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIds = $resolved;
                    $bestMeta = $c;
                }
            }

            if ($bestIds === [] || $bestMeta === null) {
                continue;
            }
            // Финальный порог: связка ≥2 (или исходник был из 1 места)
            $srcPlaces = count($bestMeta['places'] ?? []);
            if ($srcPlaces >= 2 && count($bestIds) < 2) {
                continue;
            }
            if ($day['place_ids'] === $bestIds) {
                $day['from_construction'] = count($bestIds) >= 2 || $srcPlaces <= 1;
                $day['construction_ids'] = $bestIds;
                $day['construction_source_route_id'] = (int) ($bestMeta['source_route_id'] ?? 0);
                continue;
            }

            foreach ($day['place_ids'] as $old) {
                $old = (int) $old;
                if ($old > 0 && isset($byId[$old]) && ! in_array($old, $bestIds, true)) {
                    $pool[] = $byId[$old];
                }
            }
            foreach ($bestIds as $pid) {
                $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
            }

            $day['place_ids'] = $bestIds;
            $day['from_construction'] = count($bestIds) >= 2 || $srcPlaces <= 1;
            $day['construction_ids'] = $bestIds;
            $day['construction_source_route_id'] = (int) ($bestMeta['source_route_id'] ?? 0);
            $day['catalog_ids'] = [];
            $notes[] = 'день '.$day['date'].': конструкция директора (route #'
                .$bestMeta['source_route_id'].' d'.$bestMeta['day_index']
                .', хаб '.implode('/', $nightHubs).') → #'.implode(',#', $bestIds);
        }
        unset($day);

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<int, true>
     */
    private function usedPlaceIdsExceptDay(array $days, int $exceptDi): array
    {
        $used = [];
        foreach ($days as $di => $day) {
            if ($di === $exceptDi) {
                continue;
            }
            foreach ($day['place_ids'] ?? [] as $id) {
                $used[(int) $id] = true;
            }
        }

        return $used;
    }

    /**
     * Fallback: если день тонкий — 1–2 места с хаба образца (не полная конструкция).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<RouteSkeleton>  $skeletons
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function applySkeletonHubFills(array $days, array &$byId, array &$pool, array $skeletons, array &$notes): array
    {
        if ($skeletons === []) {
            return $days;
        }
        foreach ($days as $di => &$day) {
            if ($day['is_transfer'] || $di === 0) {
                continue;
            }
            // День уже из конструкции директора — не перетирать, только дописать если совсем тонкий
            $fromConstruction = ! empty($day['from_construction']);
            $night = $byId[(int) ($day['night_id'] ?? 0)] ?? null;
            if ($night === null) {
                continue;
            }
            $hubs = $this->matcher->detectHubs($this->name($night).' '.(string) ($day['night_label'] ?? ''));
            if ($hubs === []) {
                continue;
            }
            $weak = count($day['place_ids']) < 2
                || $this->actHours($day['place_ids'], $byId) < self::THIN_ACT_HOURS
                || $this->dayLooksWeak($day['place_ids'], $byId);

            if (! $weak && count($day['place_ids']) >= 2) {
                continue;
            }
            if ($fromConstruction && count($day['place_ids']) >= 2) {
                continue;
            }

            $wanted = [];
            foreach ($hubs as $hub) {
                foreach ($this->matcher->placeNamesForHub($skeletons, $hub) as $n) {
                    $wanted[] = $n;
                }
            }
            $wanted = array_values(array_unique($wanted));
            if ($wanted === []) {
                continue;
            }

            $picked = [];
            foreach ($wanted as $wantName) {
                if (count($picked) >= 2) {
                    break;
                }
                if (preg_match(self::HARD_TREK, $wantName) || preg_match(self::NOT_ROUTE_ACTIVITY, $wantName)) {
                    continue;
                }
                $usedTrip = $this->usedPlaceIds($days);
                $row = $this->resolveNameToLocation($wantName, $byId, $pool, $night, $usedTrip);
                if ($row === null || ! $this->isAllowedRouteActivity($row) || ! $this->placeFitsOvernight($row, $night)) {
                    continue;
                }
                $pid = (int) $row['id'];
                if (in_array($pid, $day['place_ids'], true) || in_array($pid, $picked, true) || isset($usedTrip[$pid])) {
                    continue;
                }
                if ((int) ($day['night_id'] ?? 0) === $pid) {
                    continue;
                }
                if ($this->isOvernightDuplicate($row, $night)) {
                    continue;
                }
                $picked[] = $pid;
                $byId[$pid] = $row;
                $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
            }

            if ($picked === []) {
                continue;
            }

            // Конструкцию не заменяем — только дописываем
            if ($fromConstruction) {
                foreach ($picked as $pid) {
                    $cap = ! empty($day['is_transfer']) ? self::CONSTRUCTION_TRANSFER_MAX : self::CONSTRUCTION_STAY_MAX;
                    if (count($day['place_ids']) >= $cap) {
                        break;
                    }
                    $row = $byId[$pid] ?? null;
                    if ($row && preg_match(self::ARRIVAL_SIDE, $this->name($row))) {
                        continue;
                    }
                    if (! in_array($pid, $day['place_ids'], true)) {
                        $day['place_ids'][] = $pid;
                        $day['catalog_ids'] = array_values(array_unique(array_merge(
                            array_map('intval', $day['catalog_ids'] ?? []),
                            [$pid]
                        )));
                        $notes[] = 'день '.$day['date'].': к конструкции дописан #'.$pid;
                    }
                }
                continue;
            }

            // Если день совсем слабый — заменить; иначе дописать до 2
            if ($weak && count($day['place_ids']) <= 1) {
                foreach ($day['place_ids'] as $old) {
                    if (isset($byId[$old]) && ! in_array($old, $picked, true)) {
                        $pool[] = $byId[$old];
                    }
                }
                $day['place_ids'] = array_slice($picked, 0, 2);
                $notes[] = 'день '.$day['date'].': программа по образцу хаба ('.implode(',', $hubs).') → #'
                    .implode(',#', $day['place_ids']);
            } else {
                foreach ($picked as $pid) {
                    if (count($day['place_ids']) >= 2) {
                        break;
                    }
                    if (! in_array($pid, $day['place_ids'], true)) {
                        $day['place_ids'][] = $pid;
                        $notes[] = 'день '.$day['date'].': из образца добавлен #'.$pid;
                    }
                }
            }
        }
        unset($day);

        return $days;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function dayLooksWeak(array $ids, array $byId): bool
    {
        if ($ids === []) {
            return true;
        }
        foreach ($ids as $pid) {
            $row = $byId[$pid] ?? null;
            if ($row === null) {
                continue;
            }
            $blob = $this->name($row);
            if (preg_match('/maddalen|מגדלנ|ampola|אמפול|varone|וארנ/ui', $blob) && count($ids) < 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  array<string, mixed>  $night
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<int, true>  $usedTrip
     */
    private function resolveNameToLocation(string $wantName, array $byId, array $pool, array $night, array $usedTrip = []): ?array
    {
        $want = mb_strtolower($wantName);
        $wantNorm = $this->normName($want);
        $best = null;
        $bestScore = 0.0;

        $candidates = array_merge(array_values($byId), $pool);
        foreach ($candidates as $row) {
            if (! is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
                continue;
            }
            if (isset($usedTrip[(int) $row['id']])) {
                continue;
            }
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                continue;
            }
            $name = mb_strtolower($this->name($row));
            $norm = $this->normName($name);
            $score = 0.0;
            if ($norm !== '' && ($norm === $wantNorm || str_contains($norm, $wantNorm) || str_contains($wantNorm, $norm))) {
                $score = 10.0;
            } elseif ($want !== '' && (str_contains($name, $want) || str_contains($want, $name))) {
                $score = 6.0;
            }
            if ($score <= 0) {
                continue;
            }
            $km = $this->kmBetween($night, $row);
            if ($km !== null && $km > 55) {
                continue;
            }
            if ($km !== null) {
                $score += max(0, 20 - $km) * 0.1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best !== null) {
            return $best;
        }

        // Каталог: грубый like по куску имени
        $needle = mb_substr(trim($wantName), 0, 24);
        if (mb_strlen($needle) < 4) {
            return null;
        }
        $loc = Location::query()
            ->whereNotNull('lat')
            ->where(function ($q) use ($needle) {
                $q->where('meta->title', 'like', '%'.$needle.'%');
            })
            ->limit(5)
            ->get(['id', 'lat', 'lng', 'trip_role', 'meta']);

        foreach ($loc as $l) {
            if ($l->trip_role === 'base') {
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
                'snippet' => '',
            ];
            $km = $this->kmBetween($night, $row);
            if ($km !== null && $km <= 55) {
                return $row;
            }
        }

        return null;
    }

    private function normName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\(.*?\)/u', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;

        return $s;
    }

    /**
     * Точка должна быть того же «мира», что и ночёвка (не Мольвено при Риве, не Кронплац при Ортизеи).
     *
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $night
     */
    private function placeFitsOvernight(array $place, array $night): bool
    {
        // Только имена — snippet пакера часто содержит весь маршрут
        // (Гарда+Ортизеи+Мизурина) и ломает и ban, и «общий хаб».
        $nightHubs = $this->matcher->detectHubs($this->name($night));
        $placeHubs = $this->matcher->detectHubs($this->name($place));
        if ($nightHubs === []) {
            return true;
        }
        if ($placeHubs === []) {
            // Нет тега хаба у POI — только у самой базы (иначе Нардис 17км → Мольвено)
            $km = $this->kmBetween($night, $place);

            return $km !== null && $km <= 12.0;
        }
        // Общий хаб с ночёвкой — всегда ок (misurina∩cadini), даже если в имени ещё dolomiti
        if (array_intersect($nightHubs, $placeHubs) !== []) {
            return true;
        }
        $broad = ['dolomiti', 'milan', 'verona'];
        $nightConcrete = array_values(array_filter($nightHubs, fn (string $h) => ! in_array($h, $broad, true)));
        $placeConcrete = array_values(array_filter($placeHubs, fn (string $h) => ! in_array($h, $broad, true)));
        // «אקסטרים בדולומיטים» при ночёвке Misurina — не подходит
        if ($nightConcrete !== [] && $placeConcrete === [] && array_intersect($placeHubs, $broad) !== []) {
            $km = $this->kmBetween($night, $place);

            return $km !== null && $km <= 15.0;
        }
        foreach ($nightHubs as $nh) {
            $ban = self::HUB_INCOMPAT[$nh] ?? [];
            foreach ($placeHubs as $ph) {
                if (in_array($ph, $ban, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Пустой день переезда — одна лёгкая у новой ночёвки (не «без программы»).
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $pool
     * @param  list<string>  $preferNames
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function fillEmptyTransfers(array $days, array &$byId, array &$pool, array $preferNames, array &$notes): array
    {
        $usedTrip = $this->usedPlaceIds($days);
        foreach ($days as $i => &$day) {
            if (! ($day['is_transfer'] ?? false)) {
                continue;
            }
            $nightId = (int) ($day['night_id'] ?? 0);
            $night = $byId[$nightId] ?? null;
            if ($night === null) {
                continue;
            }
            $maxPlaces = ! empty($day['from_construction'])
                ? self::CONSTRUCTION_TRANSFER_MAX
                : self::TRANSFER_MAX_PLACES;
            $targetH = self::RESIDUAL_TRANSFER_HOURS;
            // Пустой или тонкий переезд — добираем остановки по дороге / у новой базы
            $guard = 0;
            while ($guard++ < 4
                && count($day['place_ids']) < $maxPlaces
                && $this->actHours($day['place_ids'], $byId) < $targetH
                && ! $this->shouldNotAddSecondActivity($day['place_ids'], $byId)) {
                $nightHubs = $this->matcher->detectHubs($this->name($night));
                $pick = $this->pickAlongRoute($pool, $day, $night, $preferNames, $byId, $days);
                if ($pick === null) {
                    $pick = $this->pickFromPool($pool, array_keys($usedTrip), $night, $preferNames, $byId, 55.0);
                    if ($pick !== null && ! $this->transferPickOk($pick, $night, $nightHubs)) {
                        $pick = null;
                    }
                }
                if ($pick === null) {
                    $pick = $this->pickFromCatalogNear($night, $byId, $days);
                    if ($pick !== null && ! $this->transferPickOk($pick, $night, $nightHubs)) {
                        $pick = null;
                    }
                }
                $borrowedFrom = null;
                if ($pick === null && $day['place_ids'] === []) {
                    for ($j = $i + 1; $j < count($days); $j++) {
                        $other = $days[$j];
                        if ($other['is_transfer'] || (int) ($other['night_id'] ?? 0) !== $nightId) {
                            continue;
                        }
                        if (count($other['place_ids']) < 2) {
                            continue;
                        }
                        foreach ($other['place_ids'] as $idx => $candId) {
                            $cand = $byId[$candId] ?? null;
                            if ($cand === null || ! $this->transferPickOk($cand, $night, $nightHubs)) {
                                continue;
                            }
                            $pick = $cand;
                            $borrowedFrom = [$j, $idx];
                            break 2;
                        }
                    }
                }
                if ($pick === null) {
                    if ($day['place_ids'] === []) {
                        $notes[] = 'день '.$day['date'].': переезд без точки — нет подходящего POI по оси/у базы';
                    }
                    break;
                }
                if (preg_match(self::EXTREME_ACTIVITY, $this->name($pick)) && $day['place_ids'] !== []) {
                    break;
                }
                $pid = (int) $pick['id'];
                if ($borrowedFrom !== null) {
                    [$j, $idx] = $borrowedFrom;
                    array_splice($days[$j]['place_ids'], $idx, 1);
                    $notes[] = 'день '.$day['date'].': с '.$days[$j]['date'].' на переезд взята #'.$pid.' ('.$this->name($pick).')';
                } else {
                    if (isset($usedTrip[$pid])) {
                        break;
                    }
                    $notes[] = 'день '.$day['date'].': по дороге/у базы на переезд → #'.$pid.' ('.$this->name($pick).')';
                    $pool = array_values(array_filter($pool, fn ($r) => (int) ($r['id'] ?? 0) !== $pid));
                }
                $day['place_ids'][] = $pid;
                $day['catalog_ids'] = array_values(array_unique(array_merge(
                    array_map('intval', $day['catalog_ids'] ?? []),
                    [$pid]
                )));
                $usedTrip[$pid] = true;
                $byId[$pid] = $pick;
            }
        }
        unset($day);

        return $days;
    }

    /**
     * @param  array<string, mixed>  $pick
     * @param  array<string, mixed>  $night
     * @param  list<string>  $nightHubs
     */
    private function transferPickOk(array $pick, array $night, array $nightHubs): bool
    {
        if (! $this->placeFitsOvernight($pick, $night)) {
            return false;
        }
        $blob = $this->name($pick);
        if (! $this->isAllowedRouteActivity($pick) || preg_match(self::HARD_TREK, $blob)) {
            return false;
        }
        if ($this->isOvernightDuplicate($pick, $night)) {
            return false;
        }
        $ph = $this->matcher->detectHubs($blob);
        $sameHub = $nightHubs !== [] && $ph !== [] && array_intersect($nightHubs, $ph) !== [];
        $km = $this->kmBetween($night, $pick);
        $nearBase = $km !== null && $km <= 12.0;
        $alongCorridor = $km !== null && $km <= 55.0;
        // Одна программа на пустой transfer может быть full-day (рафтинг)
        $maxH = ($sameHub || $nearBase || $alongCorridor)
            ? self::FULL_DAY_ACTIVITY_HOURS + 0.5
            : 3.0;
        if ($this->hoursFor($pick) > $maxH) {
            return false;
        }
        if ($nightHubs === []) {
            return true;
        }
        if ($sameHub || $nearBase) {
            return true;
        }
        // Рафтинг/каньон по дороге к новой базе — хаб может быть garda при ночи molveno
        if ($alongCorridor && preg_match('/raft|ראפט|canyon|קניון|рафтинг/ui', $blob)) {
            return true;
        }
        if ($ph === []) {
            return $nearBase || $alongCorridor;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<int, true>
     */
    private function usedPlaceIds(array $days): array
    {
        $used = [];
        foreach ($days as $d) {
            foreach ($d['place_ids'] as $pid) {
                $used[(int) $pid] = true;
            }
        }

        return $used;
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     * @param  list<int>  $usedInDay
     * @param  array<string, mixed>  $night
     * @param  list<string>  $preferNames
     * @param  array<int, array<string, mixed>>  $byId
     * @return array<string, mixed>|null
     */
    private function pickFromPool(array $pool, array $usedInDay, array $night, array $preferNames, array $byId, float $maxKm = self::FILL_MAX_KM): ?array
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($pool as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || in_array($id, $usedInDay, true)) {
                continue;
            }
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                continue;
            }
            $blob = $this->name($row).' '.($row['snippet'] ?? '');
            if (! $this->isAllowedRouteActivity($row) || preg_match(self::HARD_TREK, $blob)) {
                continue;
            }
            $km = $this->kmBetween($night, $row);
            if ($km === null || $km > $maxKm) {
                continue;
            }
            if (! $this->placeFitsOvernight($row, $night)) {
                continue;
            }
            $h = $this->hoursFor($row);
            if ($h > 3.5) {
                continue;
            }
            $score = 40.0 - $km;
            $score += min(2.0, $h) * 2;
            $nightHubs = $this->matcher->detectHubs($this->name($night).' '.($night['snippet'] ?? ''));
            $placeHubs = $this->matcher->detectHubs($blob);
            $concrete = array_values(array_filter(
                $nightHubs,
                fn (string $h) => ! in_array($h, ['dolomiti', 'milan', 'verona'], true)
            ));
            if ($concrete !== []) {
                if (array_intersect($concrete, $placeHubs) !== []) {
                    $score += 25.0;
                } elseif ($placeHubs !== [] && array_intersect($concrete, $placeHubs) === []) {
                    // Cortina(dolomiti) при ночёвке Misurina — хуже Cadini
                    $score -= 20.0;
                }
            }
            $nl = mb_strtolower($this->name($row));
            foreach ($preferNames as $pn) {
                if ($pn !== '' && (str_contains($nl, $pn) || str_contains($pn, $nl))) {
                    $score += 15;
                    break;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @return list<array<string, mixed>>
     */
    private function unusedPool(array $days, array $byId): array
    {
        $used = [];
        foreach ($days as $d) {
            foreach ($d['place_ids'] as $pid) {
                $used[$pid] = true;
            }
            if (($d['night_id'] ?? 0) > 0) {
                $used[(int) $d['night_id']] = true;
            }
        }
        $pool = [];
        foreach ($byId as $id => $row) {
            if (isset($used[$id])) {
                continue;
            }
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                continue;
            }
            $pool[] = $row;
        }

        return $pool;
    }

    /**
     * @param  list<RouteSkeleton>  $skeletons
     * @return list<string>
     */
    private function skeletonPlaceNames(array $skeletons): array
    {
        $names = [];
        foreach ($skeletons as $s) {
            foreach ($s->days as $day) {
                $places = [];
                if (is_array($day->places_ru) && $day->places_ru !== []) {
                    $places = $day->places_ru;
                } elseif (is_array($day->places)) {
                    $places = $day->places;
                }
                foreach ($places as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $n = mb_strtolower(trim((string) ($p['name'] ?? '')));
                    if (mb_strlen($n) >= 4) {
                        $names[] = $n;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{date: string, place_ids: list<int>, night_id: int, night_label: string, transfer: string, is_transfer: bool}|null
     */
    private function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (! preg_match('/^(\d{1,2}\.\d{1,2})\s*[—\-–]\s*(.+)$/u', $line, $m)) {
            return null;
        }
        $date = $m[1];
        $rest = $m[2];
        $transfer = '';
        $isTransfer = false;
        // «18.08 — Переезд в …» (без точек до) и «…. Переезд в …»
        if (preg_match('/(?:^|\.\s*)Переезд\s+в\s+(.+?)(?:\.\s*Ночёвка:|$)/ui', $rest, $tm)) {
            $transfer = trim($tm[1]);
            $isTransfer = true;
        }
        $nightId = 0;
        $nightLabel = '';
        if (preg_match('/Ночёвка:\s*(.+)$/ui', $rest, $nm)) {
            $nightLabel = trim($nm[1]);
            if (preg_match('/\[id=(\d+)\]/u', $nightLabel, $idm)) {
                $nightId = (int) $idm[1];
            }
        }
        $placePart = $rest;
        $placePart = preg_replace('/(?:^|\.\s*)Переезд\s+в\s+.+$/ui', '', $placePart) ?? $placePart;
        $placePart = preg_replace('/\.\s*Ночёвка:\s*.+$/ui', '', $placePart) ?? $placePart;
        $placePart = trim($placePart);
        // «день у базы» / «лёгкий день…» — не POI
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
            'night_label' => $nightLabel,
            'transfer' => $transfer,
            'is_transfer' => $isTransfer,
        ];
    }

    /**
     * @param  array{date: string, place_ids: list<int>, night_id: int, night_label: string, transfer: string, is_transfer: bool}  $day
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function formatLine(array $day, array $byId, bool $isArrival = false): string
    {
        $parts = [];
        $seenLived = false;
        foreach ($day['place_ids'] as $pid) {
            $row = $byId[$pid] ?? null;
            $name = $row ? $this->name($row) : ('#'.$pid);
            $label = $this->humanActivityLabel($name, $pid);
            if (! empty($row['force_base_lived']) || preg_match('/время\s+на\s+озер|время\s+в\s+рив|время\s+в\s+ортиз/ui', $label)) {
                $seenLived = true;
            }
            $parts[] = $label.' [id='.$pid.']';
        }
        // Если слот базы не в ids — всё равно пишем текст (место путешествия видно)
        $livedLabel = trim((string) ($day['base_lived_label'] ?? ''));
        if ($livedLabel !== '' && ! $seenLived) {
            array_unshift($parts, $livedLabel);
        }
        $line = $day['date'].' — ';
        $nightText = $this->formatOvernightLabel($day, $byId);
        $nightShort = preg_replace('/\s*\[id=\d+\]\s*/u', '', $nightText) ?? $nightText;
        if ($isArrival && $nightShort !== '') {
            $line .= 'Заезд в '.$nightShort.'. ';
        }
        if ($parts !== []) {
            $line .= implode(', ', $parts);
            if ($day['is_transfer'] && $day['transfer'] !== '') {
                $line .= '. Переезд в '.$day['transfer'];
            }
        } elseif ($day['is_transfer'] && $day['transfer'] !== '') {
            $line .= 'Переезд в '.$day['transfer'];
        } else {
            $line .= $isArrival ? 'размещение и лёгкая программа у базы' : 'день у базы';
        }
        if ($nightText !== '') {
            $line .= '. Ночёвка: '.$nightText;
        }

        return $line;
    }

    /**
     * Для человека: не голое «Molveno», а «Прогулка у озера Мольвено (~1.5 ч)».
     */
    private function humanActivityLabel(string $name, int $id = 0): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }
        // Уже обогащено
        if (preg_match('/прогулк|посещени|~\s*\d/ui', $name)) {
            return $name;
        }

        $hours = $id > 0
            ? $this->durations->hoursForId($id, $name)
            : $this->durations->hoursForName($name);
        $hLabel = $hours > 0 ? ' (~'.(fmod($hours, 1.0) < 0.05 ? (string) (int) $hours : rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.')).' ч)' : '';

        $n = mb_strtolower($name);
        // Озеро — явно заложить время на озере, не «местность»
        if (preg_match('/lago|אגם|озеро|tenno|טנו|тенн|molveno|מולבנ|мольвен|braies|בראייס|прагс|tovel|טובל|товел|ledro|לדרו|ледро|carezza|карец|misurina|мизурин|dobbiaco|דוביאק|доббиак/ui', $n)
            && ! preg_match('/cascata|водопад|מפל|raft|рафт|canyon|каньон/ui', $n)) {
            if (preg_match('/озер|lago|lake|אגם/ui', $name)) {
                return 'Время на '.$name.' (прогулка у воды)'.$hLabel;
            }

            return 'Время на озере '.$name.' (прогулка у воды)'.$hLabel;
        }
        if (preg_match('/cascata|водопад|מפל|varone|варон|nardis|нардис/ui', $n)) {
            if (! preg_match('/водопад|cascata|посещени/ui', $name)) {
                return 'Водопад '.$name.$hLabel;
            }

            return $name.$hLabel;
        }
        if (preg_match('/riva\s*del|рива-?дель|lungolago|набереж/ui', $n)) {
            return 'Прогулка по '.$name.$hLabel;
        }

        return $name.$hLabel;
    }

    /**
     * Полное имя базы для человека (Рива-дель-Гарда / Мольвено), не голое «ночёвка».
     *
     * @param  array<string, mixed>  $day
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function formatOvernightLabel(array $day, array $byId): string
    {
        $nid = (int) ($day['night_id'] ?? 0);
        $row = $nid > 0 ? ($byId[$nid] ?? null) : null;
        $fromRow = $row ? trim($this->name($row)) : '';
        $label = trim((string) ($day['night_label'] ?? ''));
        // предпочитаем имя из карточки локации
        $base = $fromRow !== '' ? $fromRow : $label;
        if ($base === '') {
            return '';
        }
        // Расширить короткие ярлыки
        if (preg_match('/^riva\b/ui', $base) && ! preg_match('/garda|гард/ui', $base)) {
            $base = 'Рива-дель-Гарда';
        } elseif (preg_match('/^molveno\b/ui', $base) && ! preg_match('/мольвен/ui', $base)) {
            $base = 'Мольвено';
        } elseif (preg_match('/^ortisei\b/ui', $base) && ! preg_match('/ортиз/ui', $base)) {
            $base = 'Ортизей';
        }
        if ($nid > 0 && ! str_contains($base, '[id=')) {
            $base .= ' [id='.$nid.']';
        }

        return $base;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function actHours(array $ids, array $byId): float
    {
        $h = 0.0;
        foreach ($ids as $i => $pid) {
            $row = $byId[$pid] ?? null;
            if ($row === null) {
                continue;
            }
            $h += $this->hoursFor($row);
            if ($i > 0) {
                $h += 0.4;
            }
        }

        return $h;
    }

    /** @param  array<string, mixed>  $row */
    private function hoursFor(array $row): float
    {
        if (! empty($row['force_base_lived']) || preg_match('/время\s+на\s+озер|время\s+в\s+/ui', $this->name($row))) {
            return 2.0;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = $this->name($row).' '.($row['snippet'] ?? '');
        // Синтетические id 900000+ — только по имени
        if ($id >= 900000) {
            return $this->durations->hoursForName($name);
        }

        return $id > 0 ? $this->durations->hoursForId($id, $name) : $this->durations->hoursForName($name);
    }

    /** @param  array<string, mixed>  $row */
    private function name(array $row): string
    {
        return trim((string) ($row['name'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: float, 1: float}|null
     */
    private function latLng(array $row): ?array
    {
        $lat = $row['lat'] ?? null;
        $lng = $row['lng'] ?? null;
        if ($lat === null || $lng === null || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [(float) $lat, (float) $lng];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function kmBetween(array $a, array $b): ?float
    {
        $pa = $this->latLng($a);
        $pb = $this->latLng($b);
        if ($pa === null || $pb === null) {
            return null;
        }
        $earth = 6371.0;
        $dLat = deg2rad($pb[0] - $pa[0]);
        $dLng = deg2rad($pb[1] - $pa[1]);
        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($pa[0])) * cos(deg2rad($pb[0])) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($h)));
    }
}
