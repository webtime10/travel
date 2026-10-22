<?php

namespace App\Services;

/**
 * Меняет ТОЛЬКО flagged-дни по issues + approved candidates из БД.
 * Не пересобирает весь маршрут. Skip = норма, если кандидата нет.
 */
class RouteAuditRefiner
{
    private const FULL_DAY_H = 5.0;

    private const DAY_CAP_H = 6.5;

    /** Transfer-день: не больше 2 активностей и жёстче по часам */
    private const TRANSFER_CAP_H = 4.5;

    private const TRANSFER_MAX_PLACES = 2;

    /**
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $locations
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, list<array<string, mixed>>>  $candidatesByDay
     * @return array{
     *   day_plan: list<string>,
     *   locations: list<array<string, mixed>>,
     *   notes: list<string>,
     *   changed_days: list<string>,
     *   skipped: list<string>,
     *   decisions: array<string, array<string, mixed>>
     * }
     */
    public function apply(
        array $dayPlan,
        array $locations,
        array $issues,
        array $candidatesByDay,
    ): array {
        $byId = [];
        foreach ($locations as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $byId[(int) $row['id']] = $row;
            }
        }
        $notes = [];
        $changed = [];
        $skipped = [];
        /** @var array<string, array<string, mixed>> $decisions */
        $decisions = [];

        /** @var array<string, list<array<string, mixed>>> $issueByDay */
        $issueByDay = [];
        foreach ($issues as $iss) {
            $d = (string) ($iss['day'] ?? '');
            if ($d === '') {
                continue;
            }
            $issueByDay[$d][] = $iss;
        }
        foreach ($issueByDay as $d => $list) {
            usort($list, function ($a, $b) {
                $rank = ['critical' => 0, 'advisory' => 1];

                return ($rank[$a['severity'] ?? ''] ?? 2) <=> ($rank[$b['severity'] ?? ''] ?? 2);
            });
            $issueByDay[$d] = $list;
        }

        $out = [];
        foreach ($dayPlan as $line) {
            if (! preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $out[] = $line;
                continue;
            }
            $date = $m[1];
            if (! isset($issueByDay[$date])) {
                $out[] = $line;
                continue;
            }

            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                $out[] = $line;
                continue;
            }

            $cands = $candidatesByDay[$date] ?? [];
            if ($cands === []) {
                foreach ($issueByDay[$date] as $issue) {
                    $msg = $date.': audit '.$issue['problem'].' — нет кандидатов в БД';
                    $notes[] = $msg;
                    $skipped[] = $msg;
                    $decisions[$date] = [
                        'action' => 'SKIP',
                        'reason' => 'no_valid_candidate',
                        'problem' => $issue['problem'] ?? null,
                    ];
                }
                $out[] = $line;
                continue;
            }

            $before = $parsed['place_ids'];
            foreach ($issueByDay[$date] as $issue) {
                $result = $this->applyOneIssue($parsed, $byId, $issue, $cands, $date);
                $parsed = $result['parsed'];
                $byId = $result['byId'];
                foreach ($result['notes'] as $n) {
                    $notes[] = $n;
                }
                foreach ($result['skipped'] as $s) {
                    $skipped[] = $s;
                }
                if (isset($result['decision'])) {
                    $decisions[$date] = $result['decision'];
                }
            }

            if ($parsed['place_ids'] !== $before) {
                $changed[] = $date;
                if (! isset($decisions[$date]['action']) || $decisions[$date]['action'] === 'SKIP') {
                    $decisions[$date]['action'] = 'APPLIED';
                }
            }
            $out[] = $this->formatLine($parsed, $byId);
        }

        return [
            'day_plan' => $out,
            'locations' => array_values($byId),
            'notes' => $notes,
            'changed_days' => array_values(array_unique($changed)),
            'skipped' => $skipped,
            'decisions' => $decisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $cands
     * @return array{parsed: array<string, mixed>, byId: array<int, array<string, mixed>>, notes: list<string>, skipped: list<string>, decision: array<string, mixed>}
     */
    private function applyOneIssue(array $parsed, array $byId, array $issue, array $cands, string $date): array
    {
        $notes = [];
        $skipped = [];
        $decision = ['action' => 'SKIP', 'reason' => 'unknown', 'problem' => $issue['problem'] ?? null];
        $action = (string) ($issue['action'] ?? 'ADD_ACTIVITY');
        $actH = $this->actHours($parsed['place_ids'], $byId);
        $isTransfer = ! empty($parsed['is_transfer']);
        $preferred = (array) ($issue['search_scope']['preferred_types'] ?? []);
        $avoid = (array) ($issue['search_scope']['avoid_types'] ?? []);
        $cap = $isTransfer ? self::TRANSFER_CAP_H : self::DAY_CAP_H;
        $problem = (string) ($issue['problem'] ?? '');
        $maxH = isset($issue['search_scope']['max_hours'])
            ? (float) $issue['search_scope']['max_hours']
            : null;
        $autoApply = ($issue['auto_apply'] ?? true) !== false;

        if (! $autoApply) {
            $msg = $date.': suggest only — без автоправки ['.$problem.']';
            $notes[] = $msg;
            $skipped[] = $msg;
            $decision = [
                'action' => 'SKIP',
                'reason' => 'suggest_only',
                'problem' => $problem,
                'detail' => 'auto_apply=false — только на рассмотрение',
            ];

            return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
        }

        if ($action === 'REPLACE_ACTIVITY') {
            $pick = $this->pickBestCandidate(
                $cands,
                $parsed,
                $byId,
                0.0,
                $cap,
                $isTransfer,
                $preferred,
                $avoid,
                true,
                $maxH
            );
            if ($pick === null) {
                if ($isTransfer && count($parsed['place_ids']) < self::TRANSFER_MAX_PLACES) {
                    $pick = $this->pickBestCandidate(
                        $cands,
                        $parsed,
                        $byId,
                        $actH,
                        $cap,
                        $isTransfer,
                        $preferred,
                        $avoid,
                        false,
                        $maxH ?? 2.0
                    );
                    if ($pick !== null) {
                        $pid = (int) $pick['id'];
                        $row = $pick['row'];
                        $parsed['place_ids'][] = $pid;
                        $byId[$pid] = $row;
                        $notes[] = $date.': ADD (fallback REPLACE) #'.$pid.' ('.$this->name($row).') ['.$problem.']';
                        $decision = [
                            'action' => 'ADD',
                            'reason' => 'fallback_after_replace_miss',
                            'problem' => $problem,
                            'picked_id' => $pid,
                        ];

                        return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
                    }
                }
                $msg = $date.': REPLACE — нет короткого кандидата (suggest)'
                    .($maxH !== null ? ' max_h='.$maxH : '');
                $notes[] = $msg;
                $skipped[] = $msg;
                $decision = [
                    'action' => 'SKIP',
                    'reason' => 'no_valid_candidate',
                    'problem' => $problem,
                    'detail' => $maxH !== null ? 'max_hours='.$maxH : null,
                ];

                return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
            }
            $pid = (int) ($pick['id'] ?? 0);
            $row = $pick['row'] ?? null;
            if ($pid <= 0 || ! is_array($row)) {
                $decision = ['action' => 'SKIP', 'reason' => 'bad_candidate', 'problem' => $problem];

                return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
            }
            $replaced = false;
            $oldId = null;
            foreach ($parsed['place_ids'] as $i => $oid) {
                $old = $byId[$oid] ?? null;
                if ($old && $this->isAvoidType($old, $avoid)) {
                    $parsed['place_ids'][$i] = $pid;
                    $byId[$pid] = $row;
                    $replaced = true;
                    $oldId = $oid;
                    $notes[] = $date.': REPLACE #'.$oid.' → #'.$pid.' ('.$this->name($row).') ['.$problem.']';
                    break;
                }
            }
            if (! $replaced) {
                $msg = $date.': REPLACE не нашёл avoid_types цель';
                $notes[] = $msg;
                $skipped[] = $msg;
                $decision = [
                    'action' => 'SKIP',
                    'reason' => 'no_avoid_target',
                    'problem' => $problem,
                ];
            } else {
                $decision = [
                    'action' => 'REPLACE',
                    'reason' => 'applied',
                    'problem' => $problem,
                    'from_id' => $oldId,
                    'to_id' => $pid,
                    'to_name' => $this->name($row),
                ];
            }

            return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
        }

        if (in_array($action, ['ADD_ACTIVITY', 'ADD_LOCAL_ACTIVITY'], true)) {
            if ($actH >= self::FULL_DAY_H || $this->maxSingle($parsed['place_ids'], $byId) >= self::FULL_DAY_H) {
                $msg = $date.': пропуск ADD — день уже full-day';
                $notes[] = $msg;
                $skipped[] = $msg;
                $decision = [
                    'action' => 'SKIP',
                    'reason' => 'full_day',
                    'problem' => $problem,
                ];

                return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
            }
            if ($isTransfer && count($parsed['place_ids']) >= self::TRANSFER_MAX_PLACES) {
                $msg = $date.': пропуск ADD — transfer уже '.count($parsed['place_ids']).' точек';
                $notes[] = $msg;
                $skipped[] = $msg;
                $decision = [
                    'action' => 'SKIP',
                    'reason' => 'transfer_max_places',
                    'problem' => $problem,
                ];

                return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
            }
            $pick = $this->pickBestCandidate(
                $cands,
                $parsed,
                $byId,
                $actH,
                $cap,
                $isTransfer,
                $preferred,
                $avoid,
                false,
                $maxH
            );
            if ($pick === null) {
                $msg = $date.': нет подходящего кандидата под cap='.$cap.'h'.($isTransfer ? ' (transfer)' : '');
                $notes[] = $msg;
                $skipped[] = $msg;
                $decision = [
                    'action' => 'SKIP',
                    'reason' => 'no_valid_candidate',
                    'problem' => $problem,
                ];

                return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
            }
            $pid = (int) ($pick['id'] ?? 0);
            $row = $pick['row'] ?? null;
            $pickH = (float) ($pick['hours'] ?? 1.5);
            if ($pid <= 0 || ! is_array($row) || $actH + $pickH > $cap) {
                $msg = $date.': пропуск ADD #'.$pid.' — перегруз/битый кандидат';
                $notes[] = $msg;
                $skipped[] = $msg;
                $decision = [
                    'action' => 'SKIP',
                    'reason' => 'overload',
                    'problem' => $problem,
                ];

                return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
            }
            $parsed['place_ids'][] = $pid;
            $byId[$pid] = $row;
            $notes[] = $date.': ADD #'.$pid.' ('.$this->name($row)
                .', hist='.($pick['historical_score'] ?? '?')
                .') ['.$problem.']';
            $decision = [
                'action' => 'ADD',
                'reason' => 'applied',
                'problem' => $problem,
                'picked_id' => $pid,
                'picked_name' => $this->name($row),
            ];

            return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
        }

        $msg = $date.': '.$action.' — без автоправки';
        $notes[] = $msg;
        $skipped[] = $msg;
        $decision = [
            'action' => 'SKIP',
            'reason' => 'action_not_auto',
            'problem' => $problem,
        ];

        return compact('parsed', 'byId', 'notes', 'skipped', 'decision');
    }

    /**
     * @param  list<array<string, mixed>>  $cands
     * @param  array<string, mixed>  $parsed
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<string>  $preferred
     * @param  list<string>  $avoid
     * @return array<string, mixed>|null
     */
    private function pickBestCandidate(
        array $cands,
        array $parsed,
        array $byId,
        float $actH,
        float $cap,
        bool $isTransfer,
        array $preferred = [],
        array $avoid = [],
        bool $forReplace = false,
        ?float $scopeMaxH = null,
    ): ?array {
        // transfer REPLACE: короткий кандидат (иначе TIME MAJOR / rollback)
        $defaultMax = $forReplace
            ? ($isTransfer ? 2.0 : 3.0)
            : ($isTransfer ? 2.5 : 4.0);
        $maxH = $scopeMaxH !== null && $scopeMaxH > 0 ? $scopeMaxH : $defaultMax;
        foreach ($cands as $c) {
            $pid = (int) ($c['id'] ?? 0);
            $row = $c['row'] ?? null;
            $h = (float) ($c['hours'] ?? 1.5);
            if ($pid <= 0 || ! is_array($row)) {
                continue;
            }
            if (in_array($pid, $parsed['place_ids'], true)) {
                continue;
            }
            if ($parsed['night_id'] && $pid === $parsed['night_id']) {
                continue;
            }
            if ($h >= self::FULL_DAY_H || $h > $maxH) {
                continue;
            }
            if ($this->isAvoidType($row, $avoid) || $this->isJunkAttraction($row)) {
                continue;
            }
            if ($preferred !== [] && ! $this->matchesPreferredName($row, $preferred)) {
                continue;
            }
            if ($actH + $h > $cap && ! $forReplace) {
                continue;
            }

            return $c;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isJunkAttraction(array $row): bool
    {
        $n = mb_strtolower($this->name($row));

        return (bool) preg_match('/funbob|bob|מגלש|skyscraper|x-?treme|extreme|rope|חבלים|emozion/ui', $n);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $preferred
     */
    private function matchesPreferredName(array $row, array $preferred): bool
    {
        $n = mb_strtolower($this->name($row));
        foreach ($preferred as $p) {
            $p = strtolower((string) $p);
            if ($p === 'nature' && preg_match('/lago|אגם|cascata|מפל|monte|מונטה|panoram|cadini|braies|forest|waterfall/ui', $n)) {
                return true;
            }
            if ($p === 'panorama' && preg_match('/panoram|viewpoint|cadini|baldo|מונטה/ui', $n)) {
                return true;
            }
            if ($p === 'short_hike' && preg_match('/מסלול|hike|טיול|walk|bulla/ui', $n)) {
                return true;
            }
            if ($p === 'lake' && preg_match('/lago|אגם|озеро|braies|molveno|dobbiaco|misurina|ledro/ui', $n)) {
                return true;
            }
            if ($p === 'town_walk' && preg_match('/טיול|walk|ortisei|אורטיז|chiusa|קיוז/ui', $n)) {
                return true;
            }
            if ($p === 'waterfall' && preg_match('/cascata|מפל|waterfall|varone|riva|tervela/ui', $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $avoid
     */
    private function isAvoidType(array $row, array $avoid): bool
    {
        $n = mb_strtolower($this->name($row));
        foreach ($avoid as $a) {
            $a = strtolower((string) $a);
            if ($a === 'rope_park' && preg_match('/rope|חבלים|col\s*de\s*flam|emozion|adventure\s*park/ui', $n)) {
                return true;
            }
            if ($a === 'extreme' && preg_match('/skyscraper|x-?treme|extreme/ui', $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function actHours(array $ids, array $byId): float
    {
        $h = 0.0;
        foreach ($ids as $id) {
            $h += $this->hoursFor($byId[$id] ?? ['id' => $id, 'name' => '']);
        }

        return $h;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function maxSingle(array $ids, array $byId): float
    {
        $m = 0.0;
        foreach ($ids as $id) {
            $m = max($m, $this->hoursFor($byId[$id] ?? ['id' => $id, 'name' => '']));
        }

        return $m;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hoursFor(array $row): float
    {
        return app(LocationDurationResolver::class)->hoursForId((int) ($row['id'] ?? 0), $this->name($row));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function name(array $row): string
    {
        return trim((string) ($row['name'] ?? ''));
    }

    /**
     * @return array{date: string, place_ids: list<int>, night_id: int|null, is_transfer: bool, night_label: string}|null
     */
    private function parseLine(string $line): ?array
    {
        if (! preg_match('/^(\d{2}\.\d{2})\s*[—\-]/u', $line, $m)) {
            return null;
        }
        $date = $m[1];
        preg_match_all('/\[id=(\d+)\]/', $line, $ids);
        $all = array_map('intval', $ids[1] ?? []);
        $nightId = null;
        $nightLabel = '';
        if (preg_match('/Ночёвка:\s*(.+?)\[id=(\d+)\]/u', $line, $nm)) {
            $nightLabel = trim($nm[1]);
            $nightId = (int) $nm[2];
        }
        $placeIds = array_values(array_filter($all, fn ($id) => $id !== $nightId));

        return [
            'date' => $date,
            'place_ids' => $placeIds,
            'night_id' => $nightId,
            'is_transfer' => str_contains($line, 'Переезд') || str_contains($line, 'переезд'),
            'night_label' => $nightLabel,
            'transfer_label' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  array<int, array<string, mixed>>  $byId
     */
    private function formatLine(array $day, array $byId): string
    {
        $parts = [];
        foreach ($day['place_ids'] as $id) {
            $n = $this->name($byId[$id] ?? []);
            $parts[] = ($n !== '' ? $n : '#'.$id).' [id='.$id.']';
        }
        $body = $parts !== [] ? implode(', ', $parts) : '';
        $line = $day['date'].' — '.$body;
        if (! empty($day['is_transfer'])) {
            $hub = $day['night_label'] !== '' ? $day['night_label'] : 'следующий район';
            $line = rtrim($line, ' —');
            if ($body === '') {
                $line = $day['date'].' — Переезд в Район '.$hub;
            } else {
                $line .= '. Переезд в Район '.$hub;
            }
        }
        if ($day['night_id']) {
            $nn = $this->name($byId[$day['night_id']] ?? []);
            $line .= '. Ночёвка: '.($nn !== '' ? $nn : $day['night_label']).' [id='.$day['night_id'].']';
        }

        return $line;
    }
}
