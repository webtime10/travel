<?php

/**
 * Географический fit POI на transfer day (Test 2 / Gemini scenario).
 * Haversine + поперечное отклонение от оси баз; позже — road routing.
 */
return [
    // Короткие горные плечи (Ortisei→Brunico ~32 km) тоже дают TRANSFER CANDIDATES
    'min_transfer_direct_km' => (float) env('TRAVEL_ROUTE_FIT_MIN_DIRECT', 28.0),

    // Чуть шире: Braies+Misurina у Брунико должны попадать в STAY near
    'stay_radius_km' => (float) env('TRAVEL_ROUTE_FIT_STAY_RADIUS', 70.0),

    // Многочасовые / тяжёлые STAY: Haversine-радиус жёстче (Мармолада≠STAY из Брунико)
    'stay_long_poi_hours' => (float) env('TRAVEL_ROUTE_FIT_STAY_LONG_H', 3.0),
    'stay_long_poi_radius_km' => (float) env('TRAVEL_ROUTE_FIT_STAY_LONG_R', 35.0),

    // Связность POI внутри одного stay-кластера (28: Carezza ↔ Sella/Pordoi-класс)
    'cluster_link_km' => (float) env('TRAVEL_ROUTE_FIT_CLUSTER_LINK', 28.0),

    'excellent_detour_km' => (float) env('TRAVEL_ROUTE_FIT_EXCELLENT_KM', 10.0),
    'excellent_ratio' => (float) env('TRAVEL_ROUTE_FIT_EXCELLENT_RATIO', 1.18),

    'good_detour_km' => (float) env('TRAVEL_ROUTE_FIT_GOOD_KM', 20.0),
    'good_ratio' => (float) env('TRAVEL_ROUTE_FIT_GOOD_RATIO', 1.35),

    // На коротком direct ratio завышен — смотри абсолютный detour; ~36 держит Fedaia/Marmolada-класс
    'acceptable_detour_km' => (float) env('TRAVEL_ROUTE_FIT_ACCEPTABLE_KM', 36.0),
    'acceptable_ratio' => (float) env('TRAVEL_ROUTE_FIT_ACCEPTABLE_RATIO', 2.15),

    // Макс. расстояние от хорды A→B (км). ~26: Tovel-класс режется, Marmolada/Fedaia на коротком NE-плече — нет.
    'max_cross_track_km' => (float) env('TRAVEL_ROUTE_FIT_MAX_CROSS', 26.0),
    // POI должен лежать примерно между A и B по проекции на ось (доля 0..1)
    'min_axis_progress' => (float) env('TRAVEL_ROUTE_FIT_MIN_PROGRESS', -0.05),
    'max_axis_progress' => (float) env('TRAVEL_ROUTE_FIT_MAX_PROGRESS', 1.08),

    'llm_allowed_fits' => ['excellent', 'good', 'acceptable'],

    // Multi-stop transfer: OLD→S1→S2→NEW vs OLD→NEW
    'max_transfer_path_detour_km' => (float) env('TRAVEL_ROUTE_FIT_PATH_DETOUR', 32.0),
    'max_transfer_path_ratio' => (float) env('TRAVEL_ROUTE_FIT_PATH_RATIO', 1.55),
    // Компактная связка (Marmolada↔Fedaia): абсолютный detour важнее ratio на коротком плече
    'max_transfer_compact_path_detour_km' => (float) env('TRAVEL_ROUTE_FIT_COMPACT_PATH_DETOUR', 40.0),
    'transfer_compact_link_km' => (float) env('TRAVEL_ROUTE_FIT_COMPACT_LINK', 18.0),

    // Stop у OLD base (Monte Pana/Alpacas у Ortisei) ≠ движение к NEW, даже при малом detour
    'transfer_origin_zone_km' => (float) env('TRAVEL_ROUTE_FIT_ORIGIN_ZONE', 12.0),
    'transfer_min_corridor_progress' => (float) env('TRAVEL_ROUTE_FIT_MIN_CORR_PROG', 0.12),

    // Departure / выезд: не stay-density; ранний выезд режет программу
    'departure_max_hours_default' => (float) env('TRAVEL_ROUTE_FIT_DEP_MAX_H', 4.0),
    'departure_max_hours_early' => (float) env('TRAVEL_ROUTE_FIT_DEP_EARLY_H', 2.0),
    // час выезда/вылета ≤ этого → early (локальное время из query)
    'departure_early_before_hour' => (int) env('TRAVEL_ROUTE_FIT_DEP_EARLY_BEFORE', 12),

    // Stay day: BASE→POIs→BASE — не склеивать противоположные направления
    'stay_max_bearing_spread_deg' => (float) env('TRAVEL_ROUTE_FIT_STAY_BEARING', 110.0),
    'stay_prev_zone_km' => (float) env('TRAVEL_ROUTE_FIT_STAY_PREV_ZONE', 25.0),
    // Петля BASE→POIs→BASE vs лучший одиночный out-back кластера
    'stay_max_loop_ratio' => (float) env('TRAVEL_ROUTE_FIT_STAY_LOOP_RATIO', 2.0),

    // Длинный переезд: prefer short corridor stop, не полноценный stay 3–4 ч
    'long_transfer_direct_km' => (float) env('TRAVEL_ROUTE_FIT_LONG_DIRECT', 120.0),
    'short_stop_max_hours' => (float) env('TRAVEL_ROUTE_FIT_SHORT_STOP_H', 2.0),

    // Overnight: вторичные хабы (Molveno) не ночёвка, если дальше есть primary (Garda)
    'overnight_secondary_hubs' => ['molveno'],
    'overnight_primary_hubs' => ['ortisei', 'brunico', 'cortina', 'garda', 'milan', 'verona'],

    // Stay linked pairs: якорь в дне + пара в радиусе базы → добить (Braies↔Misurina)
    'stay_linked_pairs' => [
        116 => 117,
        117 => 116,
    ],
    // Эти якоря с transfer-дня прибытия → следующий stay той же базы (не «Braies на переезде + Misurina одна»)
    'stay_pair_move_from_transfer' => [116],

    // Valley-short у базы нельзя клеить к high-pass / ледник дню (Tervela + Sella/Pordoi)
    'valley_short_max_hours' => 1.0,
    'valley_short_max_km_from_base' => 10.0,
    'high_pass_poi_ids' => [200, 218, 651, 157, 456, 187, 652, 129],

    // Тонкий transfer (~только base 1.5ч) — добить corridor из CANDIDATES
    'long_transfer_enrich_min_stops' => 2,
    'short_transfer_enrich_min_stops' => 1,
    'thin_transfer_min_activity_hours' => 2.0,

    // Выезд: не тяжёлый трек (Busatte); предпочесть Limone/ferry
    'departure_heavy_min_hours' => 2.8,
    'departure_prefer_poi_ids' => [32, 395, 333],

    // Короткий transfer: Marmolada/Fedaia-пакет → не corridor, а stay у OLD base
    'short_transfer_heavy_direct_km' => (float) env('TRAVEL_ROUTE_FIT_SHORT_HEAVY_DIRECT', 55.0),
    'short_transfer_heavy_poi_ids' => [187, 456, 129, 652],
    // Если пакет так и не попал в stay OLD — добить якорь+компаньона у stay с запасом часов
    'short_transfer_package_anchor_ids' => [456, 187],
    'short_transfer_package_companion_ids' => [652],
    // Не клеить пакет на день, где activity+пакет > max (иначе ~10ч). Нет места → новый stay-день.
    'package_stay_max_day_hours' => (float) env('TRAVEL_ROUTE_FIT_PKG_STAY_MAX_H', 7.0),

    // Проверки маршрута (общие, не prompt):
    // 1) повтор того же гео-объекта в РАЗНЫХ днях (Sass Pordoi #200 и Funivia #218).
    // Имена RU/HE часто не матчятся → явные пары id + сходство имён/латиницы.
    'geo_duplicate_km' => (float) env('TRAVEL_ROUTE_FIT_GEO_DUP_KM', 2.0),
    'geo_same_site_ids' => [
        200 => [218],
        218 => [200],
    ],
    // thin-enrich: не возвращать POI ближе этого км к уже использованному (даже без имени)
    'thin_enrich_block_km' => (float) env('TRAVEL_ROUTE_FIT_THIN_BLOCK_KM', 2.0),
    // long enrich: не добирать stop с cross_track выше (Nardis-крюк)
    'thin_enrich_max_cross_km' => (float) env('TRAVEL_ROUTE_FIT_THIN_MAX_CROSS', 22.0),
    // 2) порядок transfer_stop по axis progress OLD→NEW
    // 3) лишняя переправа: не два water-crossing подряд; near-base walk до crossing в том же дне
    'lake_crossing_near_base_km' => (float) env('TRAVEL_ROUTE_FIT_CROSS_NEAR_KM', 18.0),

    'distance_driver' => env('TRAVEL_ROUTE_FIT_DRIVER', 'haversine'),
];
