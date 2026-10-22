<?php

namespace App\Services;

/**
 * Только проверка плана. Маршрут не переписывает.
 * Учитывает длительность из фактов каталога + переезды между точками.
 */
class LocationPlanValidator
{
    public function __construct(
        private readonly LocationDurationResolver $durationsResolver,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  list<string>  $dayPlan
     * @return array{
     *   ok: bool,
     *   reply: string,
     *   issues: list<string>,
     *   findings: list<array{
     *     severity: string,
     *     date: string,
     *     problem: string,
     *     objects: list<string>,
     *     why: string,
     *     data: string,
     *     ids: list<int>
     *   }>,
     *   report: string
     * }
     */
    public function validate(string $reply, array $locations, array $dayPlan): array
    {
        $cfg = config('travel_validator', []);
        $byId = $this->indexLocations($locations);
        $durations = $this->durationsResolver->forIds(array_keys($byId));
        $kinds = $this->classifyKinds($byId, $durations);

        $source = $dayPlan !== [] ? implode("\n", $dayPlan) : $reply;
        $days = $this->parseDays($source);
        foreach ($days as $i => $day) {
            $days[$i]['day_type'] = $this->classifyDayType($day, $kinds, $byId);
        }

        // TIME VALIDATOR — поминутный бюджет до findings
        $dayBudgets = $this->computeDayBudgets($days, $byId, $durations, $kinds, $cfg);

        $findings = [];
        $findings = array_merge($findings, $this->checkUnknownIds($reply, $byId));
        $findings = array_merge($findings, $this->checkMultiDayWords($source));
        $findings = array_merge($findings, $this->checkMultiDayFromDb($days, $durations));
        $findings = array_merge($findings, $this->checkDayLoad($days, $byId, $durations, $kinds, $cfg));
        $findings = array_merge($findings, $this->checkOvernightAsActivity($days, $byId, $kinds));
        $findings = array_merge($findings, $this->checkGeoSequence($days, $byId, $cfg));
        $findings = array_merge($findings, $this->checkRouteAxis($days, $byId, $cfg));
        $findings = array_merge($findings, $this->checkRepeats($days, $kinds));
        $findings = array_merge($findings, $this->checkFuzzyDuplicates($days, $byId));
        $findings = array_merge($findings, $this->checkEndPointNotes($days, $kinds));

        $blocking = array_filter(
            $findings,
            fn (array $f) => in_array($f['severity'], ['CRITICAL', 'MAJOR'], true)
        );
        $timeReport = $this->formatTimeBudgetReport($dayBudgets);
        $report = $timeReport."\n\n".$this->formatReport($findings);
        $issues = array_map(
            fn (array $f) => '['.$f['severity'].'] '.$f['date'].': '.$f['problem'],
            $findings
        );

        return [
            'ok' => $blocking === [],
            'reply' => $reply,
            'issues' => $issues,
            'findings' => $findings,
            'day_budgets' => $dayBudgets,
            'time_report' => $timeReport,
            'report' => $report,
        ];
    }

    /**
     * Поминутный бюджет дня: активности + переезды + запас vs доступное время.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<int, array{hours: float, source: string, multi_day?: bool}>  $durations
     * @param  array<int, string>  $kinds
     * @param  array<string, mixed>  $cfg
     * @return list<array<string, mixed>>
     */
    private function computeDayBudgets(array $days, array $byId, array $durations, array $kinds, array $cfg): array
    {
        $kmh = max(20.0, (float) ($cfg['drive_kmh'] ?? 50.0));
        $availableMin = (int) round(((float) ($cfg['available_day_hours'] ?? 10.0)) * 60);
        $bufferMin = (int) round(((float) ($cfg['day_buffer_hours'] ?? 1.0)) * 60);
        $spareAxisMin = (int) round(((float) ($cfg['spare_hours_axis_fill'] ?? 3.0)) * 60);
        $fullDayMin = (int) round(((float) ($cfg['full_day_activity_hours'] ?? 5.0)) * 60);
        $longOkMin = (int) round(((float) ($cfg['long_activity_ok_hours'] ?? 3.5)) * 60);
        $overloadMin = (int) round(((float) ($cfg['day_hours_overload'] ?? 7.5)) * 60);

        $out = [];
        foreach ($days as $day) {
            $touristIds = $this->realTouristIds($day, $kinds, $byId);
            $lines = [];
            $actMin = 0;
            $maxSingle = 0;
            foreach ($touristIds as $id) {
                $d = $durations[$id] ?? ['hours' => 1.5, 'source' => 'default'];
                $h = ! empty($d['multi_day']) ? 8.0 : (float) ($d['hours'] ?? 1.5);
                $m = (int) round($h * 60);
                $actMin += $m;
                $maxSingle = max($maxSingle, $m);
                $name = trim((string) ($byId[$id]['name'] ?? '')).' [id='.$id.']';
                $lines[] = ['kind' => 'activity', 'label' => $name, 'minutes' => $m, 'source' => (string) ($d['source'] ?? '')];
            }

            $transferMin = 0;
            for ($i = 0; $i < count($touristIds) - 1; $i++) {
                $a = $byId[$touristIds[$i]] ?? null;
                $b = $byId[$touristIds[$i + 1]] ?? null;
                $hopH = 0.75;
                if ($a && $b && $a['lat'] !== null && $b['lat'] !== null && $a['lng'] !== null && $b['lng'] !== null) {
                    $hopH = $this->distanceKm((float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng']) / $kmh;
                }
                $hopM = (int) round($hopH * 60);
                $transferMin += $hopM;
                $lines[] = [
                    'kind' => 'transfer',
                    'label' => 'переезд #'.$touristIds[$i].'→#'.$touristIds[$i + 1],
                    'minutes' => $hopM,
                    'source' => 'drive@'.$kmh.'km/h',
                ];
            }

            $dayType = (string) ($day['day_type'] ?? 'tourist');
            if (($dayType === 'transfer' || ! empty($day['transfer'])) && $day['night_id']) {
                $toNightH = 2.0;
                if ($touristIds !== []) {
                    $last = $byId[$touristIds[count($touristIds) - 1]] ?? null;
                    $night = $byId[$day['night_id']] ?? null;
                    if ($last && $night && $last['lat'] !== null && $night['lat'] !== null
                        && $last['lng'] !== null && $night['lng'] !== null) {
                        $toNightH = $this->distanceKm(
                            (float) $last['lat'], (float) $last['lng'],
                            (float) $night['lat'], (float) $night['lng']
                        ) / $kmh;
                    }
                } else {
                    $toNightH = 3.0;
                }
                $toNightM = (int) round($toNightH * 60);
                $transferMin += $toNightM;
                $lines[] = [
                    'kind' => 'transfer',
                    'label' => 'переезд к ночёвке',
                    'minutes' => $toNightM,
                    'source' => 'to_overnight',
                ];
            }

            $loadMin = $actMin + $transferMin;
            $totalWithBuffer = $loadMin + $bufferMin;
            $spareMin = $availableMin - $loadMin;

            $status = 'OK';
            $recommendation = null;
            if ($dayType === 'departure') {
                $status = 'OK';
                $recommendation = 'departure — туристическая программа не обязательна';
            } elseif ($maxSingle >= $fullDayMin && $loadMin <= $overloadMin) {
                $status = 'OK';
                $recommendation = 'полноценная активность ≥'.round($fullDayMin / 60, 1).'ч — второй POI не добавлять';
            } elseif ($loadMin > $overloadMin || $totalWithBuffer > $availableMin + 30) {
                $status = 'OVER';
                $recommendation = 'сумма активностей и переездов превышает комфортный день';
            } elseif ($spareMin >= $spareAxisMin && $maxSingle < $fullDayMin) {
                $status = 'SPARE';
                $recommendation = 'рекомендуется найти дополнительную активность по направлению следующей базы / ночёвки (не любую точку рядом)';
            } elseif ($maxSingle >= $longOkMin) {
                $status = 'OK';
                $recommendation = 'длинная активность — день допустим без добивки';
            }

            $out[] = [
                'date' => (string) ($day['date'] ?? ''),
                'day_type' => $dayType,
                'lines' => $lines,
                'activities_min' => $actMin,
                'transfers_min' => $transferMin,
                'buffer_min' => $bufferMin,
                'total_min' => $loadMin,
                'total_with_buffer_min' => $totalWithBuffer,
                'available_min' => $availableMin,
                'spare_min' => $spareMin,
                'max_single_min' => $maxSingle,
                'status' => $status,
                'recommendation' => $recommendation,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $budgets
     */
    private function formatTimeBudgetReport(array $budgets): string
    {
        if ($budgets === []) {
            return "=== TIME VALIDATOR ===\n(нет дней)";
        }
        $blocks = ['=== TIME VALIDATOR ===', 'act + переезды + запас ≤ доступное время дня', ''];
        foreach ($budgets as $b) {
            $blocks[] = (string) ($b['date'] ?? '');
            foreach ($b['lines'] as $line) {
                $blocks[] = ($line['label'] ?? '').': '.(int) ($line['minutes'] ?? 0).' мин';
            }
            if (($b['lines'] ?? []) === []) {
                $blocks[] = '(нет отдельных tourist-активностей)';
            }
            $blocks[] = 'итого нагрузка: '.(int) $b['total_min'].' мин';
            $blocks[] = 'запас (buffer): '.(int) $b['buffer_min'].' мин';
            $blocks[] = 'с запасом: '.(int) $b['total_with_buffer_min'].' мин';
            $blocks[] = 'доступное время: '.(int) $b['available_min'].' мин';
            $blocks[] = 'остаток: '.(int) $b['spare_min'].' мин';
            $blocks[] = 'STATUS: '.(string) $b['status'];
            if (! empty($b['recommendation'])) {
                $blocks[] = (string) $b['recommendation'];
            }
            $blocks[] = '';
        }

        return rtrim(implode("\n", $blocks));
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array<int, array<string, mixed>>
     */
    private function indexLocations(array $locations): array
    {
        $byId = [];
        foreach ($locations as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, string>  $kinds
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function classifyDayType(array $day, array $kinds, array $byId): string
    {
        if ($day['is_last']) {
            $tourist = $this->touristIds($day, $kinds);
            // Последний день: мало/нет отдельной программы → departure
            if ($tourist === [] || $this->dayIsDestinationOnly($day, $kinds)) {
                return 'departure';
            }
            // Только точка совпадающая с районом ночёвки (озеро Мизурина + ночь Мизурина)
            if (count($tourist) === 1 && $day['night_id']) {
                $pName = mb_strtolower((string) ($byId[$tourist[0]]['name'] ?? ''));
                $nName = mb_strtolower((string) ($byId[$day['night_id']]['name'] ?? ''));
                if ($pName !== '' && $nName !== '' && (str_contains($pName, mb_substr($nName, 0, 5)) || str_contains($nName, mb_substr($pName, 0, 5)))) {
                    return 'departure';
                }
            }
        }
        if ($day['transfer']) {
            return 'transfer';
        }

        return 'tourist';
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, string>  $kinds
     * @return list<int>
     */
    private function touristIds(array $day, array $kinds): array
    {
        $touristIds = [];
        foreach ($day['place_ids'] as $id) {
            $kind = $kinds[$id] ?? 'attraction';
            if (in_array($kind, ['overnight_location', 'transfer', 'destination'], true)) {
                continue;
            }
            if ($day['night_id'] !== null && $id === $day['night_id']) {
                continue;
            }
            $touristIds[] = $id;
        }

        return $touristIds;
    }

    /**
     * Туристические id с учётом «город/озеро ночёвки ≠ активность».
     *
     * @param  array<string, mixed>  $day
     * @param  array<int, string>  $kinds
     * @param  array<int, array<string, mixed>>  $byId
     * @return list<int>
     */
    private function realTouristIds(array $day, array $kinds, array $byId): array
    {
        $ids = $this->touristIds($day, $kinds);
        $nightId = $day['night_id'] ?? null;
        $night = $nightId ? ($byId[$nightId] ?? null) : null;
        if ($night === null) {
            return $ids;
        }
        $out = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row !== null && $this->isOvernightPseudoActivity($row, $night)) {
                continue;
            }
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<int, array{hours: float, source: string}>  $durations
     * @return array<int, string>
     */
    private function classifyKinds(array $byId, array $durations): array
    {
        $kinds = [];
        foreach ($byId as $id => $row) {
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                $kinds[$id] = 'overnight_location';
                continue;
            }
            if (! empty($row['is_end_point']) || ($row['role'] ?? '') === 'end_point') {
                $kinds[$id] = 'destination';
                continue;
            }
            $blob = mb_strtolower((string) ($row['name'] ?? '').' '.(string) ($row['snippet'] ?? ''));
            if (preg_match('/מסלול|hike|טיול רגלי|via ferrata|феррат|трек/ui', $blob)) {
                $kinds[$id] = 'route_hiking';
            } elseif (preg_match('/שייט|boat|катер|activity|פעילות/ui', $blob)) {
                $kinds[$id] = 'activity';
            } else {
                $kinds[$id] = 'attraction';
            }
        }

        return $kinds;
    }

    /**
     * @return list<array{
     *   date: string,
     *   place_ids: list<int>,
     *   night_id: ?int,
     *   transfer: bool,
     *   empty_body: bool,
     *   raw: string,
     *   is_last: bool
     * }>
     */
    private function parseDays(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $days = [];
        foreach ($lines as $line) {
            if (! preg_match('/^\s*(\d{1,2}\.\d{2})\s*[—\-–]\s*(.+)$/u', trim($line), $m)) {
                continue;
            }
            $rest = $m[2];
            $transfer = (bool) preg_match('/переезд/ui', $rest);
            $nightId = null;
            if (preg_match('/Ночёвка:[^\[]*\[id=(\d+)\]/ui', $rest, $nm)) {
                $nightId = (int) $nm[1];
            } elseif (preg_match('/Ночёвка:[^\(]*\(id\s*=\s*(\d+)\)/ui', $rest, $nm)) {
                $nightId = (int) $nm[1];
            }
            $allIds = [];
            if (preg_match_all('/\[id=(\d+)\]/u', $rest, $pm)) {
                foreach ($pm[1] as $id) {
                    $allIds[] = (int) $id;
                }
            }
            if (preg_match_all('/\(id\s*=\s*(\d+)\)/ui', $rest, $pm2)) {
                foreach ($pm2[1] as $id) {
                    $allIds[] = (int) $id;
                }
            }
            $allIds = array_values(array_unique($allIds));
            $placeIds = $nightId !== null
                ? array_values(array_filter($allIds, fn ($id) => $id !== $nightId))
                : $allIds;
            $body = preg_replace('/\.?\s*Переезд.*/ui', '', $rest) ?? $rest;
            $body = preg_replace('/\.?\s*Ночёвка.*/ui', '', $body) ?? $body;
            $body = trim($body);
            $emptyBody = $body === '' || preg_match('/^переезд$/ui', $body);

            $days[] = [
                'date' => $m[1],
                'place_ids' => $placeIds,
                'night_id' => $nightId,
                'transfer' => $transfer,
                'empty_body' => $emptyBody && $placeIds === [],
                'raw' => $line,
                'is_last' => false,
            ];
        }
        if ($days !== []) {
            $days[count($days) - 1]['is_last'] = true;
        }

        return $days;
    }

    /**
     * @param  array<int, array<string, mixed>>  $byId
     * @return list<array<string, mixed>>
     */
    private function checkUnknownIds(string $reply, array $byId): array
    {
        $out = [];
        if (! preg_match_all('/(?:id\s*=\s*|\[id=|\(id\s*=\s*)(\d+)/ui', $reply, $m)) {
            return $out;
        }
        foreach (array_unique($m[1]) as $id) {
            $id = (int) $id;
            if ($id > 0 && ! isset($byId[$id])) {
                $out[] = $this->finding('CRITICAL', '—', 'id нет в списке мест CONTEXT', [$id], $byId,
                    'В ответе есть id, которого нет среди переданных карточек.',
                    'id='.$id.' отсутствует в locations');
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checkMultiDayWords(string $text): array
    {
        if (preg_match('/יומיים|двухднев|two[- ]?day|2[- ]?day/ui', $text)) {
            return [$this->finding('CRITICAL', '—', 'активность на 2+ дня в однодневной программе', [], [],
                'В тексте есть признак маршрута на несколько дней.',
                'совпадение шаблона multi-day в тексте плана')];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<int, array{hours: float, source: string}>  $durations
     * @param  array<int, string>  $kinds
     * @param  array<string, mixed>  $cfg
     * @return list<array<string, mixed>>
     */
    private function checkDayLoad(array $days, array $byId, array $durations, array $kinds, array $cfg): array
    {
        $out = [];
        $critBelow = (float) ($cfg['day_hours_critical_below'] ?? 0.01);
        $majorBelow = (float) ($cfg['day_hours_major_below'] ?? 2.0);
        $minorBelow = (float) ($cfg['day_hours_minor_below'] ?? 4.0);
        $okMax = (float) ($cfg['day_hours_ok_max'] ?? 8.0);
        $overload = (float) ($cfg['day_hours_overload'] ?? 9.0);
        $transferMinor = (float) ($cfg['transfer_day_minor_below'] ?? 2.5);
        $kmh = max(20.0, (float) ($cfg['drive_kmh'] ?? 50.0));

        $longOk = (float) ($cfg['long_activity_ok_hours'] ?? 3.5);
        $fullDay = (float) ($cfg['full_day_activity_hours'] ?? 5.0);
        $pairActMajor = (float) ($cfg['transfer_pair_act_major'] ?? 4.0);
        $availableDay = (float) ($cfg['available_day_hours'] ?? 10.0);
        $dayBuffer = (float) ($cfg['day_buffer_hours'] ?? 1.0);
        $spareAxis = (float) ($cfg['spare_hours_axis_fill'] ?? 3.0);

        foreach ($days as $day) {
            $dayType = (string) ($day['day_type'] ?? 'tourist');
            $touristIds = $this->realTouristIds($day, $kinds, $byId);

            $lineDur = [];
            $actHours = 0.0;
            $maxSingle = 0.0;
            foreach ($touristIds as $id) {
                $d = $durations[$id] ?? ['hours' => 1.5, 'source' => 'default'];
                $h = (float) $d['hours'];
                $actHours += $h;
                $maxSingle = max($maxSingle, $h);
                $label = trim((string) ($byId[$id]['name'] ?? '')).' [id='.$id.']';
                $lineDur[] = $label.': '.round($h, 1).'h ('.$d['source'].')';
            }

            $travelH = 0.0;
            $hopDefault = 0.75; // если нет координат — минимальная оценка переезда между точками
            for ($i = 0; $i < count($touristIds) - 1; $i++) {
                $a = $byId[$touristIds[$i]] ?? null;
                $b = $byId[$touristIds[$i + 1]] ?? null;
                if (! $a || ! $b || $a['lat'] === null || $b['lat'] === null
                    || $a['lng'] === null || $b['lng'] === null) {
                    $travelH += $hopDefault;
                    $lineDur[] = 'hop #'.$touristIds[$i].'→#'.$touristIds[$i + 1].': '.$hopDefault.'h (нет координат)';
                    continue;
                }
                $km = $this->distanceKm((float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng']);
                $travelH += $km / $kmh;
            }

            $transferH = 0.0;
            if ($dayType === 'transfer' || $day['transfer']) {
                if ($day['night_id'] && $touristIds !== []) {
                    $last = $byId[$touristIds[count($touristIds) - 1]] ?? null;
                    $night = $byId[$day['night_id']] ?? null;
                    if ($last && $night && $last['lat'] !== null && $night['lat'] !== null
                        && $last['lng'] !== null && $night['lng'] !== null) {
                        $km = $this->distanceKm((float) $last['lat'], (float) $last['lng'], (float) $night['lat'], (float) $night['lng']);
                        $transferH = $km / $kmh;
                    } else {
                        // Типичный переезд между районами Доломит / Гарда→Ортизей
                        $transferH = 2.0;
                        $lineDur[] = 'Transfers to overnight: 2.0h (нет координат, оценка межрайонного переезда)';
                    }
                } elseif ($day['night_id'] && $touristIds === []) {
                    $transferH = 3.0;
                    $lineDur[] = 'Transfers (только переезд, оценка): 3.0h';
                }
            }

            $transfersTotal = $travelH + $transferH;
            $total = $actHours + $transfersTotal;
            $calcBlock = "day_type: {$dayType}\n"
                .($lineDur !== [] ? implode("\n", $lineDur)."\n" : "tourist activities: none\n")
                .'Transfers: '.round($transfersTotal, 1)."h\n"
                .'TOTAL: '.round($total, 1).'h';

            if ($dayType === 'departure') {
                if ($actHours >= (float) ($cfg['departure_extra_activity_major'] ?? 3.0)) {
                    $out[] = $this->finding('MINOR', $day['date'], 'departure-день с заметной туристической программой',
                        $touristIds, $byId,
                        'Тип дня = departure; активности не обязательны. Если это выезд — ок; если обычный tourist day — недогруз/ошибка типа дня.',
                        $calcBlock."\nSTATUS: MINOR\nREASON: departure day with tourist load ".round($actHours, 1).'h');
                } else {
                    $out[] = $this->finding('MINOR', $day['date'], 'departure-день (короткая/нулевая программа)',
                        $day['night_id'] ? array_values(array_unique(array_merge($touristIds, [$day['night_id']]))) : $touristIds,
                        $byId,
                        'Тип дня определён как departure. Ночёвка не считается туристической активностью. Пустая программа на выезд допустима.',
                        $calcBlock."\nSTATUS: MINOR (информационно)\nREASON: day_type=departure");
                }
                continue;
            }

            if ($dayType === 'transfer') {
                if ($transferH > 0 && ! str_contains(implode("\n", $lineDur), 'Transfers to overnight')) {
                    $lineDur[] = 'Transfers to overnight: '.round($transferH, 1).'h';
                    $calcBlock = "day_type: {$dayType}\n"
                        .($lineDur !== [] ? implode("\n", $lineDur)."\n" : "tourist activities: none\n")
                        .'Transfers: '.round($transfersTotal, 1)."h\n"
                        .'TOTAL: '.round($total, 1).'h';
                }
                $multiLim = (float) ($cfg['transfer_multi_activity_major'] ?? 5.0);
                $loadNoBufferT = $actHours + $transfersTotal;
                $spareT = $availableDay - $loadNoBufferT;
                if ($total > $overload) {
                    $out[] = $this->finding('MAJOR', $day['date'], 'transfer-день перегружен по времени',
                        $touristIds, $byId,
                        'Сумма duration активностей + переезд. Число POI само по себе не решает.',
                        $calcBlock."\nSTATUS: MAJOR\nREASON: TOTAL ".round($total, 1).'h > overload '.$overload.'h');
                } elseif (count($touristIds) >= 2 && ($total >= $multiLim || $actHours >= $pairActMajor)) {
                    $out[] = $this->finding('MAJOR', $day['date'], 'transfer: риск перегруза (2+ активности)',
                        $touristIds, $byId,
                        'Две активности + дорога к новой базе. Проверить совместимость по времени и нагрузке.',
                        $calcBlock."\nSTATUS: MAJOR\nREASON: transfer ≥2 act; TOTAL="
                        .round($total, 1).'h act='.round($actHours, 1).'h (пороги TOTAL≥'.$multiLim.' / act≥'.$pairActMajor.')');
                } elseif (count($touristIds) >= 2 && $maxSingle < $fullDay && $spareT >= $spareAxis) {
                    $out[] = $this->finding('MINOR', $day['date'], 'остаток времени — добавить по оси к следующей базе',
                        $touristIds, $byId,
                        'Две короткие активности + переезд; если spare ≥ '.$spareAxis.'h — точка по направлению движения, не любая рядом.',
                        $calcBlock."\nSTATUS: MINOR\nREASON: spare≈".round($spareT, 1).'h; max_single='
                        .round($maxSingle, 1).'h < full_day '.$fullDay.'h');
                } elseif (count($touristIds) >= 2) {
                    $out[] = $this->finding('MINOR', $day['date'], 'transfer: две активности — проверить совместимость',
                        $touristIds, $byId,
                        'Не ошибка само по себе; убедиться, что нагрузка и география совместимы.',
                        $calcBlock."\nSTATUS: MINOR\nREASON: transfer ≥2 act, TOTAL=".round($total, 1).'h в норме');
                } elseif ($touristIds === []) {
                    $out[] = $this->finding('MINOR', $day['date'], 'transfer-день без отдельной туристической активности',
                        $day['night_id'] ? [$day['night_id']] : [], $byId,
                        'Переезд учтён по времени; озеро/город ночёвки не считается tourist attraction.',
                        $calcBlock."\nSTATUS: MINOR\nREASON: day_type=transfer, tourist activities=none, Transfers=".round($transfersTotal, 1).'h');
                } elseif ($maxSingle >= $fullDay) {
                    // полноценная 5–7ч программа + переезд — второй POI не нужен
                } elseif ($maxSingle >= $longOk) {
                    // длинный трек + переезд — полноценный день
                } else {
                    $loadNoBuffer = $actHours + $transfersTotal;
                    $spare = $availableDay - $loadNoBuffer;
                    if ($spare >= $spareAxis) {
                        $out[] = $this->finding('MINOR', $day['date'], 'остаток времени — добавить по оси к следующей базе',
                            $touristIds, $byId,
                            'Не «чем заполнить день», а что разумно вставить в уже идущее движение к ночёвке/следующей базе.',
                            $calcBlock."\nSTATUS: MINOR\nREASON: spare≈".round($spare, 1)
                            .'h ≥ '.$spareAxis.'h (available='.$availableDay.'h, load='.round($loadNoBuffer, 1).'h); buffer учтён отдельно ≈'.$dayBuffer.'h');
                    } elseif ($total < $transferMinor) {
                        $out[] = $this->finding('MINOR', $day['date'], 'короткий transfer-день',
                            $touristIds, $byId,
                            'Мало суммарного времени; искать доп. активность по направлению к новой базе.',
                            $calcBlock."\nSTATUS: MINOR\nREASON: TOTAL ".round($total, 1).'h < transfer_minor '.$transferMinor.'h');
                    }
                }
                continue;
            }

            // tourist
            if ($touristIds === []) {
                $out[] = $this->finding('CRITICAL', $day['date'], 'полностью пустой туристический день',
                    $day['night_id'] ? [$day['night_id']] : [], $byId,
                    'day_type=tourist, но нет attraction/activity/route. Ночёвка не программа.',
                    $calcBlock."\nSTATUS: CRITICAL\nREASON: no tourist activities");
                continue;
            }

            // Полноценная 5–7ч или длинный hiking — не ругать и не требовать второй POI
            if ($maxSingle >= $fullDay && $total <= $overload) {
                continue;
            }
            if ($maxSingle >= $longOk && $total <= $overload) {
                continue;
            }

            $status = 'OK';
            $severity = null;
            $problem = '';
            $reason = '';
            $loadNoBuffer = $actHours + $transfersTotal;
            $spare = $availableDay - $loadNoBuffer;
            if ($total <= $critBelow) {
                $status = 'CRITICAL';
                $severity = 'CRITICAL';
                $problem = 'нулевая туристическая нагрузка';
                $reason = 'TOTAL ≈ 0h';
            } elseif ($total < $majorBelow) {
                $status = 'MAJOR';
                $severity = 'MAJOR';
                $problem = 'слишком короткий туристический день';
                $reason = 'TOTAL '.round($total, 1).'h < '.$majorBelow.'h';
            } elseif ($spare >= $spareAxis) {
                $status = 'MINOR';
                $severity = 'MINOR';
                $problem = 'остаток времени — добавить по оси к следующей базе';
                $reason = 'spare≈'.round($spare, 1).'h ≥ '.$spareAxis.'h (не любая точка рядом)';
            } elseif ($total < $minorBelow) {
                $status = 'MINOR';
                $severity = 'MINOR';
                $problem = 'короткий туристический день';
                $reason = 'TOTAL '.round($total, 1).'h < '.$minorBelow.'h (искать доп. по направлению движения)';
            } elseif ($total > $overload) {
                $status = 'MAJOR';
                $severity = 'MAJOR';
                $problem = 'перегруз дня по суммарной длительности';
                $reason = 'TOTAL '.round($total, 1).'h > overload '.$overload.'h (не «число POI»)';
            } elseif ($total > $okMax) {
                $status = 'MINOR';
                $severity = 'MINOR';
                $problem = 'плотный день (на верхней границе)';
                $reason = 'TOTAL '.round($total, 1).'h > ok_max '.$okMax.'h';
            }

            if ($severity !== null) {
                $out[] = $this->finding($severity, $day['date'], $problem, $touristIds, $byId,
                    'Оценка по сумме duration + переездов. Число объектов само по себе не решает; длинный hiking = полный день.',
                    $calcBlock."\nSTATUS: {$status}\nREASON: {$reason}");
            }
        }

        return $out;
    }

    /**
     * Город/озеро ночёвки, поданные как «активность».
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<int, string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function checkOvernightAsActivity(array $days, array $byId, array $kinds): array
    {
        $out = [];
        foreach ($days as $day) {
            $nightId = $day['night_id'] ?? null;
            $night = $nightId ? ($byId[$nightId] ?? null) : null;
            if ($night === null) {
                continue;
            }
            foreach ($day['place_ids'] as $id) {
                if ($id === $nightId) {
                    continue;
                }
                $kind = $kinds[$id] ?? 'attraction';
                if (in_array($kind, ['overnight_location', 'transfer', 'destination'], true)) {
                    continue;
                }
                $row = $byId[$id] ?? null;
                if ($row === null || ! $this->isOvernightPseudoActivity($row, $night)) {
                    continue;
                }
                $out[] = $this->finding('MAJOR', $day['date'], 'город/озеро ночёвки посчитаны как активность',
                    [$id, $nightId], $byId,
                    'Нельзя автоматически считать туристическим POI: дубль ночёвки, прогулка по городу базы или второе озеро той же базы.',
                    'place='.($row['name'] ?? '').' night='.($night['name'] ?? '')."\nSTATUS: MAJOR");
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $night
     */
    private function isOvernightPseudoActivity(array $place, array $night): bool
    {
        $pn = mb_strtolower(trim((string) ($place['name'] ?? '')));
        $nn = mb_strtolower(trim((string) ($night['name'] ?? '')));
        if ($pn === '' || $nn === '') {
            return false;
        }
        $pCore = $this->corePlaceName($pn);
        $nCore = $this->corePlaceName($nn);
        if ($pCore === '' || $nCore === '') {
            return false;
        }
        $same = $pCore === $nCore || str_contains($pCore, $nCore) || str_contains($nCore, $pCore);
        if (! $same) {
            return false;
        }
        // прогулка по городу базы
        if (preg_match('/טיול\s*רגלי|short\s*walk|прогулк|\(קצר\)/ui', $pn)) {
            return true;
        }
        // два озера одной базы
        if (preg_match('/אגם|lago|lake|озеро/ui', $pn) && preg_match('/אגם|lago|lake|озеро/ui', $nn)) {
            return true;
        }
        // имя почти = ночёвка без явной отдельной достопримечательности
        if (! preg_match('/מפל|cascata|פארק|חבלים|מסלול|raft|ferry|שייט|cadini|viewpoint/ui', $pn)) {
            return true;
        }

        return false;
    }

    private function corePlaceName(string $name): string
    {
        $s = preg_replace('/\(.*?\)/u', '', $name) ?? $name;
        $s = preg_replace('/למשפחות|למבוגרים|בלי\s*רכבל|for\s*families|for\s*adults/ui', '', $s) ?? $s;
        $s = preg_replace('/טיול\s*רגלי.*$/ui', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';

        return $s;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array{hours: float, source: string, multi_day?: bool}>  $durations
     * @return list<array<string, mixed>>
     */
    private function checkMultiDayFromDb(array $days, array $durations): array
    {
        $out = [];
        foreach ($days as $day) {
            foreach ($day['place_ids'] as $id) {
                if (! empty($durations[$id]['multi_day'])) {
                    $out[] = $this->finding('CRITICAL', $day['date'], 'multi-day активность в однодневной программе',
                        [$id], [],
                        'В каталоге/фактах маршрут на 2+ дня; нельзя ставить как обычную точку дня.',
                        'id='.$id.'; '.$durations[$id]['source']);
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @return list<array<string, mixed>>
     */
    private function checkFuzzyDuplicates(array $days, array $byId): array
    {
        $out = [];
        $keys = [];
        $coords = [];
        foreach ($days as $day) {
            foreach ($day['place_ids'] as $id) {
                if ($day['night_id'] !== null && $id === $day['night_id']) {
                    continue;
                }
                $row = $byId[$id] ?? [];
                $name = mb_strtolower((string) ($row['name'] ?? ''));
                $key = $this->fuzzyActivityKey($name);
                if ($key !== '') {
                    if (isset($keys[$key]) && $keys[$key]['id'] !== $id) {
                        $out[] = $this->finding('MAJOR', $day['date'], 'возможный дубль активности под другим названием',
                            [$keys[$key]['id'], $id], $byId,
                            'Похожие названия в разные дни/карточки — одна экскурсия в двух формулировках.',
                            'key='.$key.'; было: день '.$keys[$key]['date'].' id='.$keys[$key]['id'].'; сейчас: '.$day['date'].' id='.$id);
                    } else {
                        $keys[$key] = ['id' => $id, 'date' => $day['date']];
                    }
                }
                // Одинаковые координаты — тот же объект (215/216 верёвочный парк)
                if (isset($row['lat'], $row['lng']) && is_numeric($row['lat']) && is_numeric($row['lng'])) {
                    $lat = (float) $row['lat'];
                    $lng = (float) $row['lng'];
                    $ck = 'coord:'.round($lat, 4).','.round($lng, 4);
                    if (isset($coords[$ck]) && $coords[$ck]['id'] !== $id) {
                        $out[] = $this->finding('MAJOR', $day['date'], 'дубль по координатам (разные id, одно место)',
                            [$coords[$ck]['id'], $id], $byId,
                            'Две карточки с практически теми же lat/lng — обычно один объект в каталоге дважды.',
                            $ck.'; было: день '.$coords[$ck]['date'].' id='.$coords[$ck]['id'].'; сейчас: '.$day['date'].' id='.$id);
                    } else {
                        $coords[$ck] = ['id' => $id, 'date' => $day['date'], 'lat' => $lat, 'lng' => $lng];
                    }
                    // Близко по гео + тот же entity-key (Limone rent vs Limone town)
                    foreach ($coords as $prev) {
                        if (($prev['id'] ?? 0) === $id || ! isset($prev['lat'], $prev['lng'])) {
                            continue;
                        }
                        $km = $this->distanceKm($lat, $lng, (float) $prev['lat'], (float) $prev['lng']);
                        if ($km > 2.5) {
                            continue;
                        }
                        $prevRow = $byId[$prev['id']] ?? [];
                        $prevKey = $this->fuzzyActivityKey(mb_strtolower((string) ($prevRow['name'] ?? '')));
                        if ($key !== '' && $key === $prevKey && str_starts_with($key, 'entity:')) {
                            $out[] = $this->finding('MAJOR', $day['date'], 'семантический дубль одной сущности',
                                [$prev['id'], $id], $byId,
                                'Разные id, одна туристическая сущность (город/курорт) в разные дни.',
                                'entity='.$key.'; ≈'.round($km, 2).' км; было: день '.$prev['date']
                                .' id='.$prev['id'].'; сейчас: '.$day['date'].' id='.$id);
                            break;
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function fuzzyActivityKey(string $name): string
    {
        if (preg_match('/(malcesine|מלצ).{0,30}(limone|לימונה)|(limone|לימונה).{0,30}(malcesine|מלצ)/ui', $name)) {
            return 'boat:malcesine-limone';
        }
        // Город/курорт как сущность (Limone id=20 rent vs id=32 town)
        if (preg_match('/limone|לימונה|лимоне/ui', $name)) {
            return 'entity:limone';
        }
        if (preg_match('/malcesine|מלצס|мальчез/ui', $name)) {
            return 'entity:malcesine';
        }
        if (preg_match('/riva\s*del|ריבה|рива.?дель/ui', $name)) {
            return 'entity:riva';
        }
        // Только «город Ортизей» как сущность, не любой POI с Ortisei в названии
        if (preg_match('/^(ортизе|ortisei|אורטיז)/ui', trim($name))
            || preg_match('/טיול\s*רגלי\s*(ב)?אורטיז|прогулк.*ортиз|walk.*ortisei/ui', $name)) {
            return 'entity:ortisei-town';
        }
        if (preg_match('/molveno|מולבנ|мольвен/ui', $name)) {
            return 'entity:molveno';
        }
        if (preg_match('/nardis|נארדיס|нардис/ui', $name)) {
            return 'place:nardis';
        }
        if (preg_match('/braies|בראייס|брайес|браес/ui', $name)) {
            return 'place:braies';
        }
        if (preg_match('/tre cime|3 פסגות|5 פסגות|три пик|פסגות דולומיטים/ui', $name)) {
            return 'place:tre-cime';
        }
        if (preg_match('/5\s*אגמים|חמש\s*אגמים|cinq(ue)?\s*lagh|5 lakes|пять оз/ui', $name)) {
            return 'trek:five-lakes';
        }
        if (preg_match('/col\s*de\s*flam|emozion|פארק\s*חבלים|веревочн|rope\s*park|high\s*rope/ui', $name)) {
            return 'park:ropes';
        }
        if (preg_match('/skyscraper|plan\s*de\s*corones|kronplatz|קרונפ/ui', $name)) {
            return 'place:kronplatz-sky';
        }

        return '';
    }

    /**
     * Зигзаги относительно оси баз: detour_from_route, backtracking, distance_to_next_base.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $cfg
     * @return list<array<string, mixed>>
     */
    private function checkRouteAxis(array $days, array $byId, array $cfg): array
    {
        $out = [];
        $detourMajor = (float) ($cfg['detour_km_major'] ?? 45.0);
        $backtrackMajor = (float) ($cfg['backtrack_km_major'] ?? 55.0);
        $nextRatio = (float) ($cfg['distance_next_base_ratio'] ?? 1.35);
        $n = count($days);

        for ($i = 0; $i < $n; $i++) {
            $day = $days[$i];
            $night = ($day['night_id'] ?? null) ? ($byId[$day['night_id']] ?? null) : null;
            if ($night === null || $night['lat'] === null || $night['lng'] === null) {
                continue;
            }
            $prevNight = null;
            if ($i > 0 && ($days[$i - 1]['night_id'] ?? null)) {
                $prevNight = $byId[$days[$i - 1]['night_id']] ?? null;
            }
            $nextNight = null;
            if ($i + 1 < $n && ($days[$i + 1]['night_id'] ?? null)) {
                $nextNight = $byId[$days[$i + 1]['night_id']] ?? null;
            }

            foreach ($day['place_ids'] as $pid) {
                if ($day['night_id'] !== null && (int) $pid === (int) $day['night_id']) {
                    continue;
                }
                $p = $byId[$pid] ?? null;
                if ($p === null || $p['lat'] === null || $p['lng'] === null) {
                    continue;
                }
                $plat = (float) $p['lat'];
                $plng = (float) $p['lng'];
                $nlat = (float) $night['lat'];
                $nlng = (float) $night['lng'];

                // detour_from_route: на переезде / смене базы — лишний крюк A→P→B
                if ($prevNight !== null && $prevNight['lat'] !== null && $prevNight['lng'] !== null
                    && ((int) ($days[$i - 1]['night_id'] ?? 0) !== (int) $day['night_id'] || ! empty($day['transfer']))) {
                    $alat = (float) $prevNight['lat'];
                    $alng = (float) $prevNight['lng'];
                    $direct = $this->distanceKm($alat, $alng, $nlat, $nlng);
                    if ($direct >= 15.0) {
                        $via = $this->distanceKm($alat, $alng, $plat, $plng)
                            + $this->distanceKm($plat, $plng, $nlat, $nlng);
                        $detour = $via - $direct;
                        if ($detour >= $detourMajor) {
                            $out[] = $this->finding('MAJOR', $day['date'], 'detour_from_route: крюк вне оси переезда',
                                [(int) $pid, (int) $day['night_id']], $byId,
                                'Активность сильно уводит с пути предыдущая база → ночёвка дня (локально ок, глобально зигзаг).',
                                'detour≈'.round($detour, 1).' км; direct_bases≈'.round($direct, 1)
                                .' км; via_poi≈'.round($via, 1).' км; порог='.$detourMajor);
                        }
                    }
                }

                // distance_to_next_base: точка ближе к «будущей» базе, чем к текущей, и далеко от текущей
                if ($nextNight !== null && $nextNight['lat'] !== null && $nextNight['lng'] !== null
                    && (int) ($days[$i + 1]['night_id'] ?? 0) !== (int) $day['night_id']) {
                    $dCur = $this->distanceKm($plat, $plng, $nlat, $nlng);
                    $dNext = $this->distanceKm($plat, $plng, (float) $nextNight['lat'], (float) $nextNight['lng']);
                    if ($dCur >= 25.0 && $dNext > 0 && $dCur >= $dNext * $nextRatio) {
                        $out[] = $this->finding('MAJOR', $day['date'], 'distance_to_next_base: точка ближе к следующей базе',
                            [(int) $pid, (int) $day['night_id'], (int) $days[$i + 1]['night_id']], $byId,
                            'POI логичнее на дне переезда к следующей базе, чем в текущем stay.',
                            'km_to_current≈'.round($dCur, 1).'; km_to_next≈'.round($dNext, 1)
                            .'; ratio_порог='.$nextRatio);
                    }
                }

                // backtracking: относительно движения prev→current точка сильно «назад» к prev
                if ($prevNight !== null && $prevNight['lat'] !== null && $prevNight['lng'] !== null
                    && (int) ($days[$i - 1]['night_id'] ?? 0) !== (int) $day['night_id']) {
                    $dToPrev = $this->distanceKm($plat, $plng, (float) $prevNight['lat'], (float) $prevNight['lng']);
                    $dToCur = $this->distanceKm($plat, $plng, $nlat, $nlng);
                    $bases = $this->distanceKm(
                        (float) $prevNight['lat'], (float) $prevNight['lng'],
                        $nlat, $nlng
                    );
                    if ($bases >= 30.0 && $dToPrev + 15.0 < $dToCur && ($dToCur - $dToPrev) >= $backtrackMajor * 0.5
                        && $dToCur >= 40.0) {
                        $out[] = $this->finding('MAJOR', $day['date'], 'backtracking: возврат к предыдущей базе',
                            [(int) $pid], $byId,
                            'Точка ближе к уже покинутой базе, чем к текущей ночёвке — зигзаг назад.',
                            'km_to_prev_base≈'.round($dToPrev, 1).'; km_to_current≈'.round($dToCur, 1)
                            .'; bases≈'.round($bases, 1).' км');
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $cfg
     * @return list<array<string, mixed>>
     */
    private function checkGeoSequence(array $days, array $byId, array $cfg): array
    {
        $out = [];
        $jump = (float) ($cfg['jump_km_unplanned'] ?? 140.0);
        // Внутри дня: точки не должны быть в разных регионах (порог чуть ниже междневного)
        $intraJump = min($jump, 160.0);
        foreach ($days as $day) {
            $ids = [];
            foreach ($day['place_ids'] ?? [] as $rawId) {
                $id = (int) $rawId;
                if ($day['night_id'] !== null && $id === (int) $day['night_id']) {
                    continue;
                }
                $p = $byId[$id] ?? null;
                if (! $p || $p['lat'] === null || $p['lng'] === null) {
                    continue;
                }
                $ids[] = $id;
            }
            for ($j = 0; $j < count($ids) - 1; $j++) {
                $a = $byId[$ids[$j]];
                $b = $byId[$ids[$j + 1]];
                $km = $this->distanceKm(
                    (float) $a['lat'], (float) $a['lng'],
                    (float) $b['lat'], (float) $b['lng']
                );
                if ($km <= $intraJump) {
                    continue;
                }
                $out[] = $this->finding('CRITICAL', $day['date'], 'точки одного дня слишком далеко друг от друга',
                    [$ids[$j], $ids[$j + 1]], $byId,
                    'В одном дне смешаны локации из разных регионов (например Альпы + Сардиния).',
                    'расстояние≈'.round($km, 1).' км между #'.$ids[$j].' и #'.$ids[$j + 1].'; порог='.$intraJump.' км');
            }
        }
        $n = count($days);
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $this->dayEdge($days[$i], $byId, true);
            $b = $this->dayEdge($days[$i + 1], $byId, false);
            if ($a === null || $b === null) {
                continue;
            }
            $km = $this->distanceKm($a['lat'], $a['lng'], $b['lat'], $b['lng']);
            if ($km <= $jump) {
                continue;
            }
            // Явный переезд в конце дня N или в начале N+1 — запланированный переход между базами
            if ($days[$i]['transfer'] || $days[$i + 1]['transfer']) {
                continue;
            }
            $out[] = $this->finding('MAJOR', $days[$i]['date'].'→'.$days[$i + 1]['date'],
                'Резкий географический скачок',
                [$a['id'], $b['id']], $byId,
                'Между концом дня и началом следующего большое расстояние, и переезд в плане не обозначен.',
                'расстояние≈'.round($km, 1).' км; порог unplanned='.$jump.' км; '
                .'from=#'.$a['id'].' to=#'.$b['id'].'; нет слова «переезд» в этих днях');
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function checkRepeats(array $days, array $kinds): array
    {
        $out = [];
        $seen = [];
        foreach ($days as $day) {
            foreach ($day['place_ids'] as $id) {
                $kind = $kinds[$id] ?? 'attraction';
                if (in_array($kind, ['overnight_location', 'destination'], true)) {
                    continue;
                }
                if ($day['night_id'] !== null && $id === $day['night_id']) {
                    continue;
                }
                if (isset($seen[$id])) {
                    $out[] = $this->finding('MAJOR', $day['date'], 'повторное посещение локации',
                        [$id], [],
                        'Та же точка уже была туристической активностью в день '.$seen[$id].'. Ночёвка/транзит так не помечаются.',
                        'id='.$id.' kind='.$kind.'; ранее: '.$seen[$id].'; текущий день: '.$day['date']);
                } else {
                    $seen[$id] = $day['date'];
                }
            }
            // ночёвку запоминаем отдельно — не как attraction
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<int, string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function checkEndPointNotes(array $days, array $kinds): array
    {
        $out = [];
        foreach ($days as $i => $day) {
            if ($day['is_last']) {
                continue;
            }
            foreach ($day['place_ids'] as $id) {
                if (($kinds[$id] ?? '') === 'destination') {
                    $out[] = $this->finding('MAJOR', $day['date'], 'конечная точка стоит не в последний день',
                        [$id], [],
                        'Точка типа destination/end_point обычно завершает маршрут.',
                        'id='.$id.' kind=destination; день не последний');
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, string>  $kinds
     */
    private function dayIsDestinationOnly(array $day, array $kinds): bool
    {
        if ($day['place_ids'] === [] && $day['night_id'] && ($kinds[$day['night_id']] ?? '') === 'destination') {
            return true;
        }
        foreach ($day['place_ids'] as $id) {
            if (($kinds[$id] ?? '') !== 'destination') {
                return false;
            }
        }

        return $day['place_ids'] !== [];
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, array<string, mixed>>  $byId
     * @return array{id: int, lat: float, lng: float}|null
     */
    private function dayEdge(array $day, array $byId, bool $preferLast): ?array
    {
        $ids = $day['place_ids'];
        if ($ids === [] && $day['night_id']) {
            $ids = [$day['night_id']];
        }
        if ($ids === []) {
            return null;
        }
        $id = $preferLast ? $ids[count($ids) - 1] : $ids[0];
        // Для конца дня с переездом якорь — ночёвка (новая база), если есть
        if ($preferLast && $day['transfer'] && $day['night_id']) {
            $id = $day['night_id'];
        }
        $row = $byId[$id] ?? null;
        if (! $row || $row['lat'] === null || $row['lng'] === null) {
            return null;
        }

        return ['id' => $id, 'lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     * @return array{
     *   severity: string,
     *   date: string,
     *   problem: string,
     *   objects: list<string>,
     *   why: string,
     *   data: string,
     *   ids: list<int>
     * }
     */
    private function finding(
        string $severity,
        string $date,
        string $problem,
        array $ids,
        array $byId,
        string $why,
        string $data,
    ): array {
        $objects = [];
        foreach ($ids as $id) {
            $name = trim((string) ($byId[$id]['name'] ?? ''));
            $objects[] = $name !== '' ? $name.' [id='.$id.']' : 'id='.$id;
        }

        return [
            'severity' => $severity,
            'date' => $date,
            'problem' => $problem,
            'objects' => $objects,
            'why' => $why,
            'data' => $data,
            'ids' => $ids,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function formatReport(array $findings): string
    {
        if ($findings === []) {
            return 'validator: ok';
        }
        $order = ['CRITICAL' => 0, 'MAJOR' => 1, 'MINOR' => 2];
        usort($findings, fn ($a, $b) => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));
        $blocks = [];
        foreach ($findings as $f) {
            $blocks[] = '['.$f['severity']."]\n"
                .'дата: '.$f['date']."\n"
                .'проблема: '.$f['problem']."\n"
                .'объекты: '.($f['objects'] !== [] ? implode(', ', $f['objects']) : '—')."\n"
                .'почему: '.$f['why']."\n"
                .'данные, на основании которых принято решение: '.$f['data'];
        }

        return implode("\n\n", $blocks);
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
