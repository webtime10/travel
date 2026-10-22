<?php

namespace App\Services;

use App\Models\RouteSkeleton;

/**
 * Critic → Evidence → Refiner → re-validate.
 * audit_trace[] — мышление по каждому дню (debug), без усиления автоправок.
 */
class RouteAuditPipeline
{
    public function __construct(
        private RouteAuditorService $auditor,
        private RouteAuditEvidenceService $evidence,
        private RouteAuditRefiner $refiner,
        private LocationPlanValidator $validator,
    ) {}

    /**
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $locations
     * @param  list<RouteSkeleton>  $skeletons
     * @param  list<array<string, mixed>>  $dayBudgets
     * @return array<string, mixed>
     */
    public function run(
        array $dayPlan,
        array $locations,
        array $skeletons,
        array $dayBudgets = [],
    ): array {
        $dayPlan = array_values(array_filter(
            array_map('strval', $dayPlan),
            fn ($l) => trim($l) !== ''
        ));
        $planBefore = $dayPlan;

        $timeSummary = $this->summarizeBudgets($dayBudgets);
        $audit = $this->auditor->audit($dayPlan, $dayBudgets, $skeletons, $locations);
        $issues = is_array($audit['issues'] ?? null) ? $audit['issues'] : [];

        $byDay = [];
        $evidenceTrace = [];
        $refined = [
            'day_plan' => $dayPlan,
            'locations' => $locations,
            'notes' => [],
            'changed_days' => [],
            'skipped' => [],
            'decisions' => [],
        ];
        $validationAfter = null;
        $notes = [];
        $skipped = [];
        $changed = [];
        $newPlan = $dayPlan;
        $newLocs = $locations;
        $reply = implode("\n", $dayPlan);

        if ($issues !== []) {
            $ev = $this->evidence->candidatesForIssues($issues, $dayPlan, $locations, $skeletons);
            $byDay = $ev['by_day'] ?? [];
            $evidenceTrace = $ev['trace'] ?? [];
            $refined = $this->refiner->apply($dayPlan, $locations, $issues, $byDay);

            $newPlan = $refined['day_plan'];
            $newLocs = $refined['locations'];
            $notes = $refined['notes'];
            $skipped = $refined['skipped'] ?? [];
            $changed = $refined['changed_days'];
            $reply = implode("\n", $newPlan);

            if ($changed !== []) {
                $validationAfter = $this->validator->validate($reply, $newLocs, $newPlan);
                $rolled = $this->rollbackMajorDays(
                    $dayPlan,
                    $newPlan,
                    $changed,
                    $validationAfter['findings'] ?? []
                );
                if ($rolled['reverted'] !== []) {
                    $newPlan = $rolled['day_plan'];
                    $changed = $rolled['changed_days'];
                    $reply = implode("\n", $newPlan);
                    foreach ($rolled['reverted'] as $d) {
                        $msg = $d.': rollback — re-validate CRITICAL/MAJOR';
                        $notes[] = $msg;
                        $skipped[] = $msg;
                        $refined['decisions'][$d] = [
                            'action' => 'ROLLBACK',
                            'reason' => 'revalidate_major',
                            'detail' => 'CRITIAL/MAJOR после правки — день возвращён',
                        ];
                    }
                    $validationAfter = $changed !== []
                        ? $this->validator->validate($reply, $newLocs, $newPlan)
                        : null;
                }
            }
        }

        $evidenceCompact = [];
        foreach ($byDay as $day => $cands) {
            $evidenceCompact[$day] = array_map(
                fn ($c) => [
                    'id' => $c['id'] ?? null,
                    'name' => $c['name'] ?? null,
                    'hours' => $c['hours'] ?? null,
                    'historical_score' => $c['historical_score'] ?? null,
                    'detour_km' => $c['detour_km'] ?? null,
                    'score' => $c['score'] ?? null,
                    'fits_max_hours' => $c['fits_max_hours'] ?? null,
                ],
                $cands
            );
        }

        $suggestions = $this->buildSuggestions($issues, $evidenceCompact, $changed, $skipped);
        $auditTrace = $this->buildAuditTrace(
            $dayPlan,
            $timeSummary,
            $issues,
            $evidenceTrace,
            $refined['decisions'] ?? [],
            $changed,
            $skipped
        );
        $auditResult = $this->formatAuditResult($auditTrace);
        $visualReport = $this->buildVisualReport(
            $planBefore,
            $newPlan,
            $auditTrace,
            $suggestions,
            $evidenceTrace,
            $refined['decisions'] ?? []
        );
        $humanReport = $this->buildHumanDirectorReport($visualReport, $planBefore, $newPlan);

        $log = [
            'time' => $timeSummary,
            'plan_before_audit' => $planBefore,
            'plan_after_audit' => $newPlan,
            'audit' => $issues,
            'audit_status' => $audit['status'] ?? null,
            'audit_source' => $audit['source'] ?? null,
            'evidence' => $evidenceCompact,
            'suggestions' => $suggestions,
            'audit_trace' => $auditTrace,
            'audit_result' => $auditResult,
            'audit_result_text' => "AUDIT RESULT\n".implode("\n", $auditResult),
            'visual_report' => $visualReport,
            'human_report' => $humanReport,
            'refinement' => [
                'notes' => $notes,
                'skipped' => $skipped,
                'changed_days' => $changed,
                'decisions' => $refined['decisions'] ?? [],
            ],
            'validation_after_ok' => is_array($validationAfter) ? ($validationAfter['ok'] ?? null) : null,
        ];

        return [
            'day_plan' => $newPlan,
            'locations' => $newLocs,
            'reply' => $reply,
            'plan_before_audit' => $planBefore,
            'plan_after_audit' => $newPlan,
            'audit' => $audit,
            'evidence_by_day' => $byDay,
            'refine_notes' => $notes,
            'changed_days' => $changed,
            'skipped' => $skipped,
            'suggestions' => $suggestions,
            'audit_trace' => $auditTrace,
            'audit_result' => $auditResult,
            'audit_result_text' => $log['audit_result_text'],
            'visual_report' => $visualReport,
            'human_report' => $humanReport,
            'validation_after' => $validationAfter,
            'pipeline_log' => $log,
        ];
    }

    /**
     * HUMAN / DIRECTOR отчёт — без внутренних кодов, для человека.
     * Можно пересобрать после русификации visual_report.
     *
     * @param  list<array<string, mixed>>  $visualReport
     * @param  list<string>  $planBefore
     * @param  list<string>  $planAfter
     * @return array{
     *   title: string,
     *   period: string,
     *   summary: array<string, int|string>,
     *   days: list<array<string, mixed>>,
     *   text: string
     * }
     */
    /**
     * @param  list<array<string, mixed>>  $visualReport
     * @param  list<string>  $planBefore
     * @param  list<string>  $planAfter
     * @param  array<string, mixed>  $provenance  из RouteSkeletonRefiner
     */
    public function buildHumanDirectorReport(
        array $visualReport,
        array $planBefore = [],
        array $planAfter = [],
        array $provenance = [],
    ): array {
        $dates = [];
        foreach ($visualReport as $d) {
            if (! empty($d['date'])) {
                $dates[] = (string) $d['date'];
            }
        }
        sort($dates);
        $period = $dates === [] ? '—' : ($dates[0].' – '.$dates[array_key_last($dates)]);

        $changed = 0;
        $recommendations = 0;
        $okCount = 0;
        $dayBlocks = [];

        foreach ($visualReport as $d) {
            $st = (string) ($d['status'] ?? 'PASS');
            $flag = is_array($d['flag'] ?? null) ? $d['flag'] : null;
            $fix = is_array($d['fix'] ?? null) ? $d['fix'] : null;
            $ref = is_array($d['refiner'] ?? null) ? $d['refiner'] : [];
            $cands = is_array($d['candidates'] ?? null) ? $d['candidates'] : [];
            $ideas = is_array($d['gemini_ideas'] ?? null) ? $d['gemini_ideas'] : [];
            $code = (string) ($flag['problem'] ?? '');

            $alts = [];
            foreach ($cands as $c) {
                $n = $this->directorPlaceName((string) ($c['name'] ?? ''));
                if ($n !== '') {
                    $alts[] = $n;
                }
            }
            foreach ($ideas as $g) {
                $n = $this->directorPlaceName((string) ($g['title'] ?? $g['name'] ?? ''));
                if ($n !== '' && ! in_array($n, $alts, true)) {
                    $alts[] = $n;
                }
            }

            // 3) Проверено — коротко
            if ($st === 'PASS' && $flag === null) {
                $okCount++;
                $dayBlocks[] = [
                    'date' => (string) ($d['date'] ?? ''),
                    'kind' => 'ok',
                    'status_label' => 'Проверено, изменений не требуется',
                    'checks' => [
                        'День соответствует временным ограничениям.',
                        'Маршрут логичен по расположению объектов.',
                    ],
                    'decision' => 'Изменений не требуется.',
                ];
                continue;
            }

            // 1) Изменено — система реально поменяла маршрут
            if ($st === 'FIXED') {
                $changed++;
                $added = $this->directorPlaceName((string) ($fix['name'] ?? ''));
                $isReplace = str_contains(strtoupper((string) ($fix['action'] ?? '')), 'REPLACE');
                $dayBlocks[] = [
                    'date' => (string) ($d['date'] ?? ''),
                    'kind' => 'changed',
                    'status_label' => 'Изменено',
                    'detected' => $this->humanDetectedForChange($code, $flag['reason'] ?? null),
                    'proposed' => $this->humanProposed($code, $ref['action'] ?? $fix['action'] ?? null),
                    'decision' => $added !== ''
                        ? ($isReplace ? 'Заменена активность на '.$added.'.' : 'Добавлена '.$added.'.')
                        : 'День обновлён.',
                    'why' => 'Подходит по времени, расстоянию и логике маршрута.',
                    'was' => $this->activitySummary((string) ($d['line_before'] ?? '')),
                    'line' => $this->activitySummary((string) ($d['line_after'] ?? '')),
                ];
                continue;
            }

            // 2) Рекомендация — маршрут нормальный / правка небезопасна, идея на рассмотрение
            $recommendations++;
            $dayBlocks[] = [
                'date' => (string) ($d['date'] ?? ''),
                'kind' => 'recommendation',
                'status_label' => 'Рекомендация',
                'route_status' => 'Маршрут: оставлен без изменений.',
                'recommendation' => $this->humanSoftRecommendation($code, $flag['reason'] ?? null),
                'alternatives' => $alts,
                'why_not_auto' => $this->humanWhyNotAuto(
                    (string) ($ref['reason'] ?? ''),
                    $flag['reason'] ?? null,
                    $st === 'ROLLBACK',
                    $code
                ),
                'line' => $this->activitySummary((string) ($d['line_after'] ?? '')),
            ];
        }

        $summaryLine = $this->directorSummaryLine($changed, $recommendations, $okCount);
        $summary = [
            'period' => $period,
            'days_checked' => count($visualReport),
            'changed_auto' => $changed,
            'recommendations' => $recommendations,
            'ok' => $okCount,
            'summary_line' => $summaryLine,
            // совместимость со старым UI
            'unchanged' => $okCount + $recommendations,
            'critical' => 0,
        ];

        $workResume = $this->buildWorkResume($provenance, $dayBlocks);
        $text = $this->formatHumanReportText($summary, $dayBlocks, $workResume);

        return [
            'title' => 'Аудит маршрута',
            'period' => $period,
            'summary' => $summary,
            'work_resume' => $workResume,
            'days' => $dayBlocks,
            'text' => $text,
            'logic_note' => 'Система меняет маршрут только если замечание подтверждено и правку можно сделать безопасно. Иначе день оставляют и показывают рекомендацию.',
        ];
    }

    /**
     * Нумерационное резюме проделанной работы для директора (происхождение + решения).
     *
     * @param  array<string, mixed>  $provenance
     * @param  list<array<string, mixed>>  $dayBlocks
     * @return array{title: string, items: list<array{n: int, kind: string, label: string, text: string, details: list<string>}>}
     */
    private function buildWorkResume(array $provenance, array $dayBlocks): array
    {
        $items = [];
        $n = 1;

        $provDays = is_array($provenance['days'] ?? null) ? $provenance['days'] : [];
        $skeletonTitles = is_array($provenance['skeleton_titles'] ?? null) ? $provenance['skeleton_titles'] : [];

        $constructionLines = [];
        $catalogLines = [];
        foreach ($provDays as $pd) {
            $date = (string) ($pd['date'] ?? '');
            $cNames = [];
            foreach ($pd['construction'] ?? [] as $p) {
                $nm = $this->directorPlaceName((string) ($p['name'] ?? ''));
                if ($nm !== '') {
                    $cNames[] = $nm;
                }
            }
            $kNames = [];
            foreach ($pd['catalog'] ?? [] as $p) {
                $nm = $this->directorPlaceName((string) ($p['name'] ?? ''));
                if ($nm !== '') {
                    $kNames[] = $nm;
                }
            }
            $src = trim((string) ($pd['source_title'] ?? ''));
            if ($cNames !== []) {
                $line = $date.': '.implode(', ', $cNames);
                if ($src !== '') {
                    $line .= ' — из маршрута «'.$src.'»';
                }
                $constructionLines[] = $line;
            }
            if ($kNames !== []) {
                $catalogLines[] = $date.': '.implode(', ', $kNames);
            }
        }

        if ($constructionLines !== []) {
            $intro = 'Взято из вашей конструкции';
            if ($skeletonTitles !== []) {
                $intro .= ' (образцы: '.implode('; ', array_slice($skeletonTitles, 0, 3)).')';
            }
            $items[] = [
                'n' => $n++,
                'kind' => 'construction',
                'label' => 'Из вашей конструкции',
                'text' => $intro.'.',
                'details' => $constructionLines,
            ];
        } elseif ($skeletonTitles !== []) {
            $items[] = [
                'n' => $n++,
                'kind' => 'construction',
                'label' => 'Опора на вашу конструкцию',
                'text' => 'Маршрут собран с опорой на ваши образцы: '.implode('; ', array_slice($skeletonTitles, 0, 3)).'.',
                'details' => [],
            ];
        }

        if ($catalogLines !== []) {
            $items[] = [
                'n' => $n++,
                'kind' => 'catalog',
                'label' => 'Из общей базы',
                'text' => 'Добавлено из общей базы мест (не из вашей дневной связки):',
                'details' => $catalogLines,
            ];
        }

        $auditAdd = [];
        $auditRec = [];
        $auditOk = [];
        foreach ($dayBlocks as $d) {
            $kind = (string) ($d['kind'] ?? '');
            $date = (string) ($d['date'] ?? '');
            if ($kind === 'changed') {
                $auditAdd[] = $date.': '.trim((string) ($d['decision'] ?? 'день обновлён'), '.');
            } elseif ($kind === 'recommendation') {
                $auditRec[] = $date.': '.trim((string) ($d['recommendation'] ?? 'есть идея улучшения'), '.');
            } elseif ($kind === 'ok') {
                $auditOk[] = $date;
            }
        }

        if ($auditAdd !== []) {
            $items[] = [
                'n' => $n++,
                'kind' => 'audit_add',
                'label' => 'Добавлено при проверке',
                'text' => 'После проверки система внесла правки в маршрут:',
                'details' => $auditAdd,
            ];
        }

        if ($auditRec !== []) {
            $items[] = [
                'n' => $n++,
                'kind' => 'recommend',
                'label' => 'Рекомендации без внесения',
                'text' => 'Есть идеи, но в маршрут они не внесены — решение за директором:',
                'details' => $auditRec,
            ];
        }

        if ($auditOk !== []) {
            $items[] = [
                'n' => $n++,
                'kind' => 'kept',
                'label' => 'Оставлено как есть',
                'text' => 'Дни без правок: '.implode(', ', $auditOk).'.',
                'details' => [],
            ];
        }

        return [
            'title' => 'Резюме: откуда собран маршрут',
            'items' => $items,
        ];
    }

    private function directorSummaryLine(int $changed, int $recs, int $ok): string
    {
        return $this->ruDays($changed, 'изменён', 'изменены', 'изменено')
            .' · '
            .$this->ruDays($recs, 'требует внимания', 'требуют внимания', 'требуют внимания')
            .' · '
            .$this->ruDays($ok, 'без изменений', 'без изменений', 'без изменений');
    }

    /** 1 день / 2 дня / 5 дней + хвост */
    private function ruDays(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            $word = 'день';
            $tail = $one;
        } elseif ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            $word = 'дня';
            $tail = $few;
        } else {
            $word = 'дней';
            $tail = $many;
        }

        return $n.' '.$word.' '.$tail;
    }

    /** Для блока «Изменено»: что обнаружили до правки. */
    private function humanDetectedForChange(?string $code, ?string $reason): string
    {
        return match ($code) {
            'underfilled_day' => 'День был недостаточно заполнен.',
            'destination_not_explored' => 'После переезда район новой базы почти не использовался.',
            'day_is_one_dimensional' => 'День был однообразен по характеру активностей.',
            'repeated_activity_type' => 'Повторялся похожий тип активности.',
            'along_transfer_gap' => 'По оси переезда оставалось свободное время без остановки.',
            default => 'Найдено замечание, которое можно безопасно исправить.',
        };
    }

    /** Мягкая рекомендация — без слова «проблема». */
    private function humanSoftRecommendation(?string $code, ?string $reason): string
    {
        $r = (string) $reason;
        if ($code === 'destination_not_explored') {
            if (preg_match('/Мольвено|Molveno|озер/ui', $r)) {
                return 'В программе нет самого озера Мольвено — при желании стоит добавить посещение озера, а не только аттракцион рядом.';
            }
            if (preg_match('/Рива|Riva/ui', $r)) {
                return 'Ночёвка в Рива-дель-Гарда, но сама Рива в программе не посещена — можно добавить прогулку по городу/набережной.';
            }
            if (preg_match('/Ортизей|Ortisei/ui', $r)) {
                return 'При необходимости можно дополнить программу у самой базы Ортизей.';
            }
            if (preg_match('/Мизурин|Misurina/ui', $r)) {
                return 'При необходимости можно дополнить программу в районе Мизурина.';
            }

            return 'Район заявлен, но само место базы в программе не посещено — стоит добавить.';
        }

        return match ($code) {
            'underfilled_day' => 'При желании день можно сделать более насыщенным.',
            'day_is_one_dimensional' => 'При желании можно заменить одно из развлечений на природный объект: в дне лучше одно яркое занятие + природа, а не два экстрима.',
            'repeated_activity_type' => 'При желании можно заменить одну из похожих активностей.',
            'along_transfer_gap' => 'При желании по пути переезда можно добавить короткую остановку.',
            'missing_historical_pattern' => 'Есть идея усилить день типичной связкой из похожих маршрутов.',
            default => 'Есть идея возможного улучшения — на усмотрение директора.',
        };
    }

    private function humanProposed(?string $problem, ?string $action): string
    {
        $a = strtoupper((string) $action);
        if (str_contains($a, 'REPLACE')) {
            return 'Заменить одну из активностей на более подходящую.';
        }
        if (str_contains($a, 'ADD_LOCAL') || $problem === 'destination_not_explored') {
            return 'Добавить короткую локальную активность у новой базы.';
        }
        if (str_contains($a, 'ADD') || $problem === 'underfilled_day') {
            return 'Добавить вторую активность.';
        }

        return 'Усилить программу дня при возможности.';
    }

    private function humanWhyNotAuto(string $reason, ?string $flagReason, bool $wasRollback, string $code = ''): string
    {
        if ($wasRollback || str_contains(strtolower($reason), 'rollback') || str_contains(strtolower($reason), 'revalidate')) {
            return 'Проверены варианты замены, но они ухудшали общую логику дня. Поэтому исходный маршрут сохранён.';
        }
        // REPLACE / однообразие — решение оставить, а не «не смогли добавить»
        if ($code === 'day_is_one_dimensional' || $code === 'repeated_activity_type') {
            return 'Проверены варианты замены, но они ухудшали общую логику дня. Поэтому исходный маршрут сохранён.';
        }
        $r = strtolower($reason);
        $flag = (string) $flagReason;
        if (str_contains($r, 'full_day') || str_contains($flag, 'full-day')) {
            return 'День уже достаточно заполнен.';
        }
        if (str_contains($r, 'suggest_only') || str_contains($flag, 'только на рассмотрение')) {
            return 'День уже достаточно заполнен.';
        }
        if (str_contains($r, 'no_valid_candidate') || str_contains($r, 'no candidate')) {
            return 'Подходящего безопасного варианта не нашлось, исходный маршрут сохранён.';
        }
        if (str_contains($r, 'transfer_max')) {
            return 'В день переезда уже достаточно точек, исходный маршрут сохранён.';
        }

        return 'Проверены варианты, но исходный маршрут оказался лучше — его оставили.';
    }

    /** Имя места для директора: без #id, id=, кодов. */
    private function directorPlaceName(string $name): string
    {
        $name = preg_replace('/\s*\[id=\d+\]/ui', '', $name) ?? $name;
        $name = preg_replace('/\s*#\d+\b/u', '', $name) ?? $name;
        $name = preg_replace('/\bid\s*=\s*\d+\b/ui', '', $name) ?? $name;
        $name = preg_replace('/\b(max_hours|not_found_in_db|underfilled_day|auto_apply)\b[=:]?\s*\S*/ui', '', $name) ?? $name;
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name) ?? $name, " \t.,;—-");

        return $name;
    }

    private function activitySummary(string $line): string
    {
        $line = preg_replace('/^\d{2}\.\d{2}\s*[—\-–]\s*/u', '', $line) ?? $line;
        $line = preg_replace('/\.\s*Переезд.*/u', '', $line) ?? $line;
        $line = preg_replace('/\.\s*Ночёвка:.*/u', '', $line) ?? $line;
        $line = $this->directorPlaceName($line);

        return $line !== '' ? $line : '—';
    }

    /**
     * @param  array<string, int|string>  $summary
     * @param  list<array<string, mixed>>  $days
     * @param  array{title?: string, items?: list<array<string, mixed>>}  $workResume
     */
    private function formatHumanReportText(array $summary, array $days, array $workResume = []): string
    {
        $lines = [];
        $lines[] = 'Аудит маршрута';
        $lines[] = 'Период: '.$summary['period'];
        $lines[] = 'Дней проверено: '.$summary['days_checked'];
        $lines[] = '';
        $lines[] = (string) ($summary['summary_line'] ?? '');
        $lines[] = '';
        $lines[] = 'Система меняет маршрут только если замечание подтверждено и правку можно сделать безопасно. Иначе день оставляют и показывают рекомендацию.';
        $lines[] = '';

        $resumeItems = is_array($workResume['items'] ?? null) ? $workResume['items'] : [];
        if ($resumeItems !== []) {
            $lines[] = (string) ($workResume['title'] ?? 'Резюме: откуда собран маршрут');
            foreach ($resumeItems as $it) {
                $lines[] = ($it['n'] ?? '').'. '.($it['label'] ?? '').'. '.($it['text'] ?? '');
                foreach ($it['details'] ?? [] as $det) {
                    $lines[] = '   — '.$det;
                }
            }
            $lines[] = '';
        }

        $changed = array_values(array_filter($days, fn ($d) => ($d['kind'] ?? '') === 'changed'));
        $recs = array_values(array_filter($days, fn ($d) => ($d['kind'] ?? '') === 'recommendation'));
        $oks = array_values(array_filter($days, fn ($d) => ($d['kind'] ?? '') === 'ok'));

        if ($changed !== []) {
            $lines[] = '— Изменено —';
            foreach ($changed as $d) {
                $lines[] = $d['date'];
                $lines[] = 'Обнаружено: '.($d['detected'] ?? '');
                $lines[] = 'Предложено: '.($d['proposed'] ?? '');
                $lines[] = 'Решение: '.($d['decision'] ?? '');
                $lines[] = 'Причина: '.($d['why'] ?? '');
                $lines[] = '';
            }
        }

        if ($recs !== []) {
            $lines[] = '— Рекомендации —';
            foreach ($recs as $d) {
                $lines[] = $d['date'];
                $lines[] = $d['route_status'] ?? 'Маршрут: оставлен без изменений.';
                $lines[] = 'Рекомендация: '.($d['recommendation'] ?? '');
                if (! empty($d['alternatives'])) {
                    $lines[] = 'Варианты: '.implode(', ', $d['alternatives']).'.';
                }
                $lines[] = 'Почему не изменено: '.($d['why_not_auto'] ?? '');
                $lines[] = '';
            }
        }

        if ($oks !== []) {
            $lines[] = '— Без изменений —';
            if (count($oks) <= 2) {
                foreach ($oks as $d) {
                    $lines[] = $d['date'];
                    foreach ($d['checks'] ?? [] as $c) {
                        $lines[] = '✓ '.$c;
                    }
                    $lines[] = $d['decision'] ?? 'Изменений не требуется.';
                    $lines[] = '';
                }
            } else {
                $lines[] = implode(', ', array_map(fn ($d) => (string) ($d['date'] ?? ''), $oks));
                $checks = $oks[0]['checks'] ?? [
                    'День соответствует временным ограничениям.',
                    'Маршрут логичен по расположению объектов.',
                ];
                foreach ($checks as $c) {
                    $lines[] = '✓ '.$c;
                }
                $lines[] = 'Изменений не требуется.';
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Структурированный отчёт для цветного UI (день за днём).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  list<array<string, mixed>>  $auditTrace
     * @param  array<string, list<array<string, mixed>>>  $suggestions
     * @param  array<string, array<string, mixed>>  $evidenceTrace
     * @param  array<string, array<string, mixed>>  $decisions
     * @return list<array<string, mixed>>
     */
    private function buildVisualReport(
        array $before,
        array $after,
        array $auditTrace,
        array $suggestions,
        array $evidenceTrace,
        array $decisions,
    ): array {
        $beforeBy = [];
        foreach ($before as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $beforeBy[$m[1]] = $line;
            }
        }
        $afterBy = [];
        foreach ($after as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $afterBy[$m[1]] = $line;
            }
        }

        $out = [];
        foreach ($auditTrace as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $c = $row['critic'] ?? [];
            $e = $row['evidence'] ?? [];
            $r = $row['refiner'] ?? [];
            $time = $row['time'] ?? [];
            $flagged = ! empty($c['flagged']);
            $action = strtoupper((string) ($r['action'] ?? 'NOOP'));
            $lineBefore = $beforeBy[$date] ?? '';
            $lineAfter = $afterBy[$date] ?? $lineBefore;
            $changed = $lineBefore !== '' && $lineAfter !== '' && $lineBefore !== $lineAfter;

            $status = 'PASS';
            if ($flagged && in_array($action, ['ADD', 'REPLACE', 'APPLIED'], true)) {
                $status = 'FIXED';
            } elseif ($flagged && $action === 'ROLLBACK') {
                $status = 'ROLLBACK';
            } elseif ($flagged) {
                $status = 'FLAG';
            }

            $fixNote = null;
            if ($status === 'FIXED') {
                $dec = $decisions[$date] ?? [];
                $fixNote = (string) ($dec['picked_name'] ?? $dec['to_name'] ?? $r['picked_name'] ?? $r['to_name'] ?? '');
            }

            $cands = [];
            foreach ($suggestions[$date] ?? [] as $s) {
                if (($s['action'] ?? '') === 'NO_ACTION') {
                    continue;
                }
                $cands[] = [
                    'id' => $s['id'] ?? null,
                    'name' => $s['name'] ?? null,
                    'hours' => $s['hours'] ?? null,
                    'detour_km' => $s['detour_km'] ?? null,
                    'note' => $s['note'] ?? null,
                    'fits' => ($s['fits_auto'] ?? true) !== false,
                ];
            }
            if ($cands === [] && ! empty($e['accepted'])) {
                foreach ($e['accepted'] as $a) {
                    $cands[] = [
                        'id' => $a['id'] ?? null,
                        'name' => $a['name'] ?? null,
                        'hours' => $a['hours'] ?? null,
                        'detour_km' => $a['detour_km'] ?? null,
                        'note' => null,
                        'fits' => ($a['fits_max_hours'] ?? true) !== false,
                    ];
                }
            }

            $rejected = [];
            foreach (array_slice($e['rejected'] ?? [], 0, 6) as $rej) {
                $rejected[] = [
                    'id' => $rej['id'] ?? null,
                    'name' => $rej['name'] ?? null,
                    'reason' => $rej['reason'] ?? null,
                ];
            }

            $out[] = [
                'date' => $date,
                'status' => $status,
                'line_before' => $lineBefore,
                'line_after' => $lineAfter,
                'changed' => $changed,
                'time_status' => $time['status'] ?? null,
                'time_note' => $this->timeNote($time),
                'flag' => $flagged ? [
                    'problem' => $c['problem'] ?? null,
                    'reason' => $c['reason'] ?? null,
                    'severity' => $c['severity'] ?? null,
                    'auto_apply' => ($c['auto_apply'] ?? true) !== false,
                ] : null,
                'fix' => $status === 'FIXED' ? [
                    'action' => $action,
                    'name' => $fixNote,
                    'id' => $decisions[$date]['picked_id'] ?? $decisions[$date]['to_id'] ?? null,
                    'detail' => $r['reason'] ?? null,
                ] : null,
                'refiner' => [
                    'action' => $action,
                    'reason' => $r['reason'] ?? null,
                ],
                'candidates' => $cands,
                'rejected' => $rejected,
                'gemini_ideas' => [], // место под аналитический слой (не в маршрут)
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $time
     */
    private function timeNote(?array $time): string
    {
        if (! is_array($time) || $time === []) {
            return '';
        }
        $status = (string) ($time['status'] ?? '');
        $max = (int) ($time['max_single_min'] ?? 0);
        if ($status === 'OK' && $max >= 300) {
            return 'full-day';
        }
        if ($status === 'OK') {
            return 'TIME OK';
        }
        if ($status === 'SPARE') {
            return 'TIME SPARE';
        }

        return $status;
    }

    /**
     * Однострочный итог на день: PASS / FLAG → … → FIXED|SKIP.
     *
     * @param  list<array<string, mixed>>  $auditTrace
     * @return list<string>
     */
    private function formatAuditResult(array $auditTrace): array
    {
        $lines = [];
        foreach ($auditTrace as $row) {
            $date = (string) ($row['date'] ?? '?');
            $c = $row['critic'] ?? [];
            $e = $row['evidence'] ?? [];
            $r = $row['refiner'] ?? [];
            $time = $row['time'] ?? [];

            if (empty($c['flagged'])) {
                $extra = '';
                $status = (string) ($time['status'] ?? '');
                $max = (int) ($time['max_single_min'] ?? 0);
                if ($status === 'OK' && $max >= 300) {
                    $extra = ' → full-day';
                } elseif ($status === 'OK') {
                    $extra = ' → TIME OK';
                } elseif ($status === 'SPARE') {
                    $extra = ' → TIME SPARE (no semantic flag)';
                }
                $lines[] = $date.': PASS'.$extra;
                continue;
            }

            $problem = (string) ($c['problem'] ?? 'issue');
            $action = strtoupper((string) ($r['action'] ?? 'SKIP'));
            $reason = (string) ($r['reason'] ?? '');

            if ($action === 'ADD' || $action === 'REPLACE' || $action === 'APPLIED') {
                $name = (string) ($r['picked_name'] ?? $r['to_name'] ?? '');
                if ($name === '' && ! empty($e['accepted'][0]['name']) && $action === 'ADD') {
                    $name = (string) $e['accepted'][0]['name'];
                }
                $fix = $action === 'REPLACE' ? 'FIXED (REPLACE)' : 'FIXED';
                $lines[] = $date.': FLAG → '.$problem.' → '.$fix
                    .($name !== '' ? ' → '.$name : '');
                continue;
            }

            if ($action === 'ROLLBACK') {
                $lines[] = $date.': FLAG → '.$problem.' → ROLLBACK (TIME MAJOR)';
                continue;
            }

            // SKIP / suggest
            $tail = 'SKIP';
            if ($reason === 'suggest_only') {
                $found = (int) ($e['candidates_found'] ?? 0);
                $tail = $found > 0
                    ? 'SKIP/suggest ('.$found.' candidates, no auto)'
                    : 'SKIP/suggest (no candidate)';
            } elseif ($reason === 'no_valid_candidate') {
                $tail = 'SKIP/no candidate';
            } elseif ($reason === 'full_day') {
                $tail = 'SKIP/full-day';
            } elseif ($reason !== '') {
                $tail = 'SKIP/'.$reason;
            }
            $lines[] = $date.': FLAG → '.$problem.' → '.$tail;
        }

        return $lines;
    }

    /**
     * Мышление по каждому дню: critic / evidence / refiner.
     *
     * @param  list<string>  $dayPlan
     * @param  array<string, array<string, mixed>>  $timeSummary
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, array<string, mixed>>  $evidenceTrace
     * @param  array<string, array<string, mixed>>  $decisions
     * @param  list<string>  $changed
     * @param  list<string>  $skipped
     * @return list<array<string, mixed>>
     */
    private function buildAuditTrace(
        array $dayPlan,
        array $timeSummary,
        array $issues,
        array $evidenceTrace,
        array $decisions,
        array $changed,
        array $skipped,
    ): array {
        $issueByDay = [];
        foreach ($issues as $iss) {
            $d = (string) ($iss['day'] ?? '');
            if ($d !== '') {
                $issueByDay[$d][] = $iss;
            }
        }

        $dates = [];
        foreach ($dayPlan as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $dates[$m[1]] = true;
            }
        }
        foreach (array_keys($timeSummary) as $d) {
            $dates[$d] = true;
        }
        ksort($dates);

        $out = [];
        foreach (array_keys($dates) as $date) {
            $issList = $issueByDay[$date] ?? [];
            $primary = $issList[0] ?? null;
            $time = $timeSummary[$date] ?? null;

            if ($primary) {
                $critic = [
                    'flagged' => true,
                    'problem' => $primary['problem'] ?? null,
                    'severity' => $primary['severity'] ?? null,
                    'action' => $primary['action'] ?? null,
                    'reason' => $primary['reason'] ?? null,
                    'auto_apply' => ($primary['auto_apply'] ?? true) !== false,
                ];
            } else {
                $status = is_array($time) ? (string) ($time['status'] ?? '') : '';
                $critic = [
                    'flagged' => false,
                    'problem' => null,
                    'reason' => $status === 'OK'
                        ? 'TIME OK — semantic Critic не флажил'
                        : ($status === 'SPARE'
                            ? 'SPARE есть, но semantic-правил не сработало (не thin / не one_dimensional)'
                            : 'нет semantic-флага'),
                ];
            }

            $ev = $evidenceTrace[$date] ?? null;
            if ($primary && $ev === null) {
                $evidence = [
                    'searched' => false,
                    'candidates_found' => 0,
                    'accepted' => [],
                    'rejected' => [],
                    'note' => 'evidence не вызывался / нет ночёвки',
                ];
            } elseif ($ev) {
                $evidence = $ev;
            } else {
                $evidence = [
                    'searched' => false,
                    'candidates_found' => 0,
                    'accepted' => [],
                    'rejected' => [],
                    'note' => 'день не флажился — поиск не нужен',
                ];
            }

            $dec = $decisions[$date] ?? null;
            if ($dec) {
                $refiner = $dec;
            } elseif ($primary) {
                $daySkip = array_values(array_filter(
                    $skipped,
                    fn ($s) => str_starts_with((string) $s, $date)
                ));
                $refiner = [
                    'action' => in_array($date, $changed, true) ? 'APPLIED' : 'SKIP',
                    'reason' => $daySkip[0] ?? (in_array($date, $changed, true) ? 'applied' : 'unknown'),
                ];
            } else {
                $refiner = [
                    'action' => 'NOOP',
                    'reason' => 'not_flagged',
                ];
            }

            $out[] = [
                'date' => $date,
                'time' => $time,
                'critic' => $critic,
                'evidence' => $evidence,
                'refiner' => $refiner,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, list<array<string, mixed>>>  $evidence
     * @param  list<string>  $changed
     * @param  list<string>  $skipped
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildSuggestions(array $issues, array $evidence, array $changed, array $skipped): array
    {
        $out = [];
        foreach ($issues as $iss) {
            $day = (string) ($iss['day'] ?? '');
            if ($day === '') {
                continue;
            }
            $action = (string) ($iss['action'] ?? 'ADD_ACTIVITY');
            $maxH = isset($iss['search_scope']['max_hours'])
                ? (float) $iss['search_scope']['max_hours']
                : null;
            $auto = ($iss['auto_apply'] ?? true) !== false;
            $applied = in_array($day, $changed, true);
            $cols = [];
            foreach ($evidence[$day] ?? [] as $c) {
                $h = (float) ($c['hours'] ?? 99);
                $fits = $maxH === null || $h <= $maxH;
                $cols[] = [
                    'id' => $c['id'] ?? null,
                    'name' => $c['name'] ?? null,
                    'hours' => $c['hours'] ?? null,
                    'detour_km' => $c['detour_km'] ?? null,
                    'historical_score' => $c['historical_score'] ?? null,
                    'action' => $action,
                    'fits_auto' => $fits,
                    'note' => ! $fits
                        ? 'длиннее max_hours — только вручную'
                        : (! $auto ? 'на рассмотрение' : ($applied ? 'applied' : 'кандидат')),
                ];
            }
            $cols[] = [
                'id' => null,
                'name' => 'оставить как есть',
                'hours' => null,
                'action' => 'NO_ACTION',
                'fits_auto' => true,
                'note' => $applied ? '' : 'по умолчанию',
            ];
            $out[$day] = $cols;
        }

        return $out;
    }

    /**
     * @param  list<string>  $original
     * @param  list<string>  $refined
     * @param  list<string>  $changedDays
     * @param  list<array<string, mixed>>  $findings
     * @return array{day_plan: list<string>, changed_days: list<string>, reverted: list<string>}
     */
    private function rollbackMajorDays(array $original, array $refined, array $changedDays, array $findings): array
    {
        $bad = [];
        foreach ($findings as $f) {
            if (! in_array($f['severity'] ?? '', ['CRITICAL', 'MAJOR'], true)) {
                continue;
            }
            $d = (string) ($f['date'] ?? '');
            if ($d !== '' && in_array($d, $changedDays, true)) {
                $bad[$d] = true;
            }
        }
        if ($bad === []) {
            return ['day_plan' => $refined, 'changed_days' => $changedDays, 'reverted' => []];
        }

        $origByDate = [];
        foreach ($original as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $origByDate[$m[1]] = $line;
            }
        }
        $out = [];
        foreach ($refined as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m) && isset($bad[$m[1]], $origByDate[$m[1]])) {
                $out[] = $origByDate[$m[1]];
            } else {
                $out[] = $line;
            }
        }
        $still = array_values(array_filter($changedDays, fn ($d) => ! isset($bad[$d])));

        return [
            'day_plan' => $out,
            'changed_days' => $still,
            'reverted' => array_keys($bad),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dayBudgets
     * @return array<string, array<string, mixed>>
     */
    private function summarizeBudgets(array $dayBudgets): array
    {
        $out = [];
        foreach ($dayBudgets as $b) {
            $d = (string) ($b['date'] ?? '');
            if ($d === '') {
                continue;
            }
            $out[$d] = [
                'status' => $b['status'] ?? null,
                'act_min' => (int) ($b['activities_min'] ?? 0),
                'spare_min' => (int) ($b['spare_min'] ?? 0),
                'max_single_min' => (int) ($b['max_single_min'] ?? 0),
                'day_type' => $b['day_type'] ?? null,
            ];
        }

        return $out;
    }
}
