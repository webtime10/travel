<?php

/**
 * Пороги логического validator'а плана дней.
 * Меняйте здесь / через .env — без правки кода проверок.
 */
return [
    // Суммарная туристическая нагрузка дня (часы активностей + переезды между точками дня)
    'day_hours_critical_below' => (float) env('TRAVEL_VALIDATOR_DAY_H_CRITICAL', 0.01),
    'day_hours_major_below' => (float) env('TRAVEL_VALIDATOR_DAY_H_MAJOR', 2.0),
    'day_hours_minor_below' => (float) env('TRAVEL_VALIDATOR_DAY_H_MINOR', 4.0),
    'day_hours_ok_max' => (float) env('TRAVEL_VALIDATOR_DAY_H_OK_MAX', 7.5),
    'day_hours_overload' => (float) env('TRAVEL_VALIDATOR_DAY_H_OVERLOAD', 7.5),
    // Transfer + ≥2 активности: TOTAL (act + hops + дорога к базе)
    'transfer_multi_activity_major' => (float) env('TRAVEL_VALIDATOR_TRANSFER_MULTI', 5.0),
    // Transfer + ≥2: MAJOR если активности ≥ этого и суммарная дорога ≥ порога
    'transfer_multi_act_hours' => (float) env('TRAVEL_VALIDATOR_TRANSFER_ACT_H', 2.5),
    'transfer_multi_road_hours' => (float) env('TRAVEL_VALIDATOR_TRANSFER_ROAD_H', 0.5),

    // Скачок км: между днями без пометки «переезд» — ошибка; с переездом — не ошибка
    'jump_km_unplanned' => (float) env('TRAVEL_VALIDATOR_JUMP_KM', 140.0),

    // Средняя скорость для оценки времени в пути (км/ч)
    'drive_kmh' => (float) env('TRAVEL_VALIDATOR_DRIVE_KMH', 50.0),

    // Длительность по умолчанию (часы), если в фактах нет visit duration
    'default_duration_hours' => (float) env('TRAVEL_VALIDATOR_DEFAULT_H', 1.5),

    // Transfer-день: ниже этого итога (activity+дорога) — MINOR «короткий transfer»
    'transfer_day_minor_below' => (float) env('TRAVEL_VALIDATOR_TRANSFER_MINOR', 2.5),

    // Departure: если есть tourist-активности дольше этого — MINOR (странный финиш)
    'departure_extra_activity_major' => (float) env('TRAVEL_VALIDATOR_DEPARTURE_EXTRA', 3.0),

    // Одна активность ≥ N часов = полноценный день (не «короткий» из‑за числа POI)
    'long_activity_ok_hours' => (float) env('TRAVEL_VALIDATOR_LONG_ACT', 3.5),

    // Полная программа 5–7ч — второй POI не нужен; Cinque Torri / рафтинг / каньон
    'full_day_activity_hours' => (float) env('TRAVEL_VALIDATOR_FULL_DAY', 5.0),

    // Доступное окно дня (act+дорога+запас). Свободно ≥ spare → MINOR «добавить по оси»
    'available_day_hours' => (float) env('TRAVEL_VALIDATOR_DAY_AVAILABLE', 10.0),
    'day_buffer_hours' => (float) env('TRAVEL_VALIDATOR_DAY_BUFFER', 1.0),
    'spare_hours_axis_fill' => (float) env('TRAVEL_VALIDATOR_SPARE_AXIS', 3.0),

    // Transfer + 2 активности: MAJOR только если TOTAL или act выше порогов (иначе MINOR «проверить»)
    'transfer_pair_act_major' => (float) env('TRAVEL_VALIDATOR_TRANSFER_PAIR_ACT', 4.0),

    // Зигзаг: лишние км относительно прямой между базами (A→P→B − A→B)
    'detour_km_major' => (float) env('TRAVEL_VALIDATOR_DETOUR_KM', 45.0),
    // Сильный возврат назад по оси маршрута баз
    'backtrack_km_major' => (float) env('TRAVEL_VALIDATOR_BACKTRACK_KM', 55.0),
    // POI дальше от следующей базы, чем предыдущая база (и далеко от оси)
    'distance_next_base_ratio' => (float) env('TRAVEL_VALIDATOR_NEXT_BASE_RATIO', 1.35),
];
