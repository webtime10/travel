<?php

namespace App\Services;

use App\Models\RouteSkeleton;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemini Route Auditor: смысловые дыры. Не придумывает POI — только problem + action + search_scope.
 * TIME/GEO считает PHP; сюда передаются готовые day_budgets.
 */
class RouteAuditorService
{
    public function __construct(
        private GeminiJsonClient $gemini,
        private RouteSkeletonMatcher $matcher,
    ) {}

    /**
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $dayBudgets  from LocationPlanValidator
     * @param  list<RouteSkeleton>  $skeletons
     * @param  list<array<string, mixed>>  $locations
     * @return array{
     *   status: string,
     *   issues: list<array<string, mixed>>,
     *   source: string,
     *   raw?: array<string, mixed>|null,
     *   error?: string|null
     * }
     */
    public function audit(array $dayPlan, array $dayBudgets, array $skeletons, array $locations = []): array
    {
        $seed = $this->seedIssuesFromBudgets($dayPlan, $dayBudgets, $locations);
        $material = $this->buildMaterial($dayPlan, $dayBudgets, $skeletons, $seed);

        try {
            $raw = $this->gemini->ask($this->instruction(), $material, 'low', 2);
            $geminiIssues = $this->normalizeIssues($raw['issues'] ?? $raw);
            $geminiIssues = $this->filterGeminiAgainstBudgets($geminiIssues, $dayBudgets);
            $merged = $this->mergeIssues($seed, $geminiIssues);

            return [
                'status' => $merged === [] ? 'OK' : 'NEEDS_REFINEMENT',
                'issues' => $merged,
                'source' => 'gemini+php_seed',
                'raw' => $raw,
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('RouteAuditor Gemini failed: '.$e->getMessage());

            return [
                'status' => $seed === [] ? 'OK' : 'NEEDS_REFINEMENT',
                'issues' => $seed,
                'source' => 'php_seed_only',
                'raw' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function instruction(): string
    {
        return <<<'TXT'
Проанализируй готовый маршрут как эксперт по составлению автомобильных туристических маршрутов (Гарда/Доломиты).

Твоя задача НЕ перестраивать маршрут и НЕ придумывать новые достопримечательности.
Определи только семантические проблемы, которые не всегда выявляются детерминированными валидаторами:

1. день содержательно недозаполнен (мало смысла/сочетаний при большом spare — не путать с «мало POI»);
2. после переезда в новый район не используется сама новая база/местность;
3. день состоит из однотипных активностей (только extreme / только аттракционы);
4. повторяется один и тот же тип активности (rope_park и т.п.);
5. пропущена важная связка из исторических маршрутов компании (только из блока HISTORICAL PATTERNS);
6. активность логично добавить по направлению следующего переезда;
7. день логически хороший — менять не нужно (не включай в issues).

PHP УЖЕ посчитал TIME budgets. Не пересчитывай часы. Опирайся на STATUS/spare/act/max_single.

Верни ТОЛЬКО JSON:
{
  "status": "OK" | "NEEDS_REFINEMENT",
  "issues": [
    {
      "day": "16.08",
      "severity": "critical" | "advisory",
      "problem": "underfilled_day|destination_not_explored|repeated_activity_type|day_is_one_dimensional|missing_historical_pattern|along_transfer_gap",
      "reason": "кратко почему",
      "action": "ADD_ACTIVITY|REPLACE_ACTIVITY|ADD_LOCAL_ACTIVITY|NO_ACTION",
      "search_scope": {
        "base": "Riva del Garda",
        "direction": "local|toward_next_base|along_transfer",
        "preferred_types": ["nature","panorama","short_hike","lake","town_walk","waterfall"],
        "avoid_types": ["rope_park","extreme"],
        "historical_candidates": []
      }
    }
  ]
}

ВАЖНО:
- Не придумывай POI и не добавляй названия вне PLAN / HISTORICAL PATTERNS / php_seed.
- Реальные кандидаты найдёт PHP в БД позже.
- Не меняй маршрут целиком. Не трогай дни без явной проблемы.
- Не дублируй то, что уже доказано TIME как CRITICAL/MAJOR перегруз.
- Если TIME STATUS=OK и max_single≥5ч (рафтинг/полный трек) — НЕ ставь underfilled_day только из-за одного POI.
- Если STATUS=SPARE и одна короткая/средняя активность при большом остатке — underfilled_day / ADD допустим (смысл, не «счётчик POI»).
- Учитывай направление следующего переезда и сочетание типов активностей.
- destination_not_explored: переезд в Molveno/Ortisei/Misurina без активности у самой базы.
- day_is_one_dimensional: два extreme подряд без природы → REPLACE или ADD природы.
- Максимум 8 issues. PHP_SEED_ISSUES можно уточнить/дополнить, не игнорировать без причины.
TXT;
    }

    /**
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $dayBudgets
     * @param  list<RouteSkeleton>  $skeletons
     * @param  list<array<string, mixed>>  $seed
     */
    private function buildMaterial(array $dayPlan, array $dayBudgets, array $skeletons, array $seed): string
    {
        $budgetLines = [];
        foreach ($dayBudgets as $b) {
            $budgetLines[] = sprintf(
                "DAY %s type=%s STATUS=%s act=%dm transfer=%dm total=%dm available=%dm spare=%dm max_single=%dm\n  %s\n  rec: %s",
                $b['date'] ?? '',
                $b['day_type'] ?? '',
                $b['status'] ?? '',
                (int) ($b['activities_min'] ?? 0),
                (int) ($b['transfers_min'] ?? 0),
                (int) ($b['total_min'] ?? 0),
                (int) ($b['available_min'] ?? 0),
                (int) ($b['spare_min'] ?? 0),
                (int) ($b['max_single_min'] ?? 0),
                implode('; ', array_map(
                    fn ($l) => ($l['label'] ?? '').'='.((int) ($l['minutes'] ?? 0)).'m',
                    $b['lines'] ?? []
                )),
                (string) ($b['recommendation'] ?? '—')
            );
        }

        $patterns = $this->matcher->formatForPrompt(array_slice($skeletons, 0, 3));
        $seedJson = json_encode(['php_seed_issues' => $seed], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "PLAN:\n".implode("\n", $dayPlan)
            ."\n\nTIME BUDGETS (PHP):\n".implode("\n\n", $budgetLines)
            ."\n\nPHP_SEED_ISSUES (можно уточнить/дополнить, не игнорировать без причины):\n".$seedJson
            ."\n\nHISTORICAL PATTERNS (brief):\n".($patterns !== '' ? $patterns : '(none)');
    }

    /**
     * Детерминированные семена из TIME budgets + простых правил (работают без Gemini).
     *
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $dayBudgets
     * @param  list<array<string, mixed>>  $locations
     * @return list<array<string, mixed>>
     */
    public function seedIssuesFromBudgets(array $dayPlan, array $dayBudgets, array $locations = []): array
    {
        $issues = [];
        $byDate = [];
        foreach ($dayPlan as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $byDate[$m[1]] = $line;
            }
        }

        $ropeDays = [];
        $extremeDays = [];
        foreach ($byDate as $date => $line) {
            if (preg_match('/rope|חבלים|col\s*de\s*flam|emozion|adventure\s*park|веревоч/ui', $line)) {
                $ropeDays[] = $date;
            }
            if (preg_match('/skyscraper|x-?treme|extreme|אקסטרים/ui', $line)) {
                $extremeDays[] = $date;
            }
        }

        foreach ($dayBudgets as $b) {
            $date = (string) ($b['date'] ?? '');
            $line = $byDate[$date] ?? '';
            $status = (string) ($b['status'] ?? '');
            $spare = (int) ($b['spare_min'] ?? 0);
            $base = $this->nightLabel($line);

            $actMin = (int) ($b['activities_min'] ?? 0);
            $maxSingle = (int) ($b['max_single_min'] ?? 0);
            // Программа дня без ночёвки и без фразы «Переезд в Район X» (иначе X ложно = «локальная активность»).
            $prog = preg_replace('/Ночёвка:.*/u', '', $line) ?? $line;
            $prog = preg_replace('/\.\s*Переезд\s+в\s+Район\s+[^.]*/u', '', $prog) ?? $prog;
            $placeCount = substr_count($prog, '[id=');
            $isTransfer = str_contains($line, 'Переезд') || str_contains($line, 'переезд');

            // Тонкий день: SPARE + нет full-day (≥5ч) + (мало минут ИЛИ одна активность при большом spare).
            // Не трогаем классические 2-POI дни с act≥3ч и spare<5ч без «одной дыры».
            // Цель дня ~6–8ч: если активностей <4.5ч при запасе времени — underfilled
            $thinSingle = $placeCount <= 1 && $maxSingle < 300 && $spare >= 180;
            $thinLowAct = $actMin < 270 && $spare >= 120;
            if ($status === 'SPARE' && $maxSingle < 300 && ($thinLowAct || $thinSingle)) {
                $issues[] = $this->issue(
                    $date,
                    $thinSingle ? 'critical' : 'advisory',
                    'underfilled_day',
                    $thinSingle
                        ? 'Одна активность ≈'.round($maxSingle / 60, 1).'ч при spare ≈'.$spare.' мин'
                        : 'TIME: остаток ≈'.$spare.' мин, активностей ≈'.$actMin.' мин',
                    'ADD_ACTIVITY',
                    [
                        'base' => $base,
                        'direction' => $isTransfer ? 'along_transfer' : 'local',
                        'preferred_types' => ['nature', 'panorama', 'short_hike', 'lake'],
                        'avoid_types' => ['rope_park', 'extreme'],
                        'historical_candidates' => $this->guessHistorical($base, $line),
                    ]
                );
            }

            // Мольвено: в программе должно быть само озеро/база, не только аттракцион «в районе»
            $nightIsMolveno = (bool) preg_match('/мольвен|molveno|מולבנ/ui', $this->nightLabel($line).' '.$line);
            if ($nightIsMolveno) {
                $progHasLake = (bool) preg_match(
                    '/אגם מולבנ|озеро\s*мольвен|lake\s*molveno|lago\s*di\s*molveno|мольвен|molveno|מולבנ|\[id=71[123]\]/ui',
                    $prog
                );
                if (! $progHasLake) {
                    $fullDay = $maxSingle >= 300 || $status === 'OK';
                    $issues[] = $this->issue(
                        $date,
                        $fullDay ? 'advisory' : 'critical',
                        'destination_not_explored',
                        $fullDay
                            ? 'Мольвено: само озеро не посещено, день уже заполнен — рекомендация'
                            : 'Ночёвка Мольвено, но само озеро/база в программе отсутствуют',
                        'ADD_LOCAL_ACTIVITY',
                        [
                            'base' => 'Molveno',
                            'direction' => 'local',
                            'preferred_types' => ['lake', 'nature', 'panorama'],
                            'avoid_types' => ['extreme', 'rope_park'],
                            'historical_candidates' => ['Molveno', 'אגם מולבנו', 'Lago di Molveno'],
                            'max_hours' => 2.0,
                        ],
                        ! $fullDay
                    );
                }
            }

            // Рива: только Тенно/Вароне без самой Ривы / Лимоне — база не прожита
            $nightIsRiva = (bool) preg_match('/рива|riva|ריבה|garda|гард/ui', $this->nightLabel($line));
            if ($nightIsRiva && ! $isTransfer) {
                $hasRivaCore = (bool) preg_match(
                    '/riva\s*del|рива-?дель|рива\s*дель|limone|лимоне|malcesine|lungolago|набереж/ui',
                    $prog
                );
                $onlySide = (bool) preg_match('/tenno|тенн|varone|варон|טנו|וארנ/ui', $prog);
                if ($onlySide && ! $hasRivaCore) {
                    $issues[] = $this->issue(
                        $date,
                        'advisory',
                        'destination_not_explored',
                        'Ночёвка Рива-дель-Гарда: в программе боковые точки, сама Рива не посещена',
                        'ADD_LOCAL_ACTIVITY',
                        [
                            'base' => 'Riva del Garda',
                            'direction' => 'local',
                            'preferred_types' => ['town_walk', 'panorama', 'nature'],
                            'avoid_types' => ['extreme', 'rope_park'],
                            'historical_candidates' => ['Riva', 'Limone', 'Malcesine'],
                            'max_hours' => 2.5,
                        ],
                        false
                    );
                }
            }

            // Ortisei: Chiusa+Maddalena по дороге = нормальный день, не ошибка.
            // Флаг только если переезд почти пустой (0–1 точка в программе).
            if ($isTransfer && preg_match('/ортизе|ortisei|אורטיז/ui', $this->nightLabel($line).' '.$line)) {
                $progHasLocal = (bool) preg_match(
                    '/ortisei|אורטיז|ортизе|bulla|בולה/ui',
                    $prog
                );
                if (! $progHasLocal && $placeCount <= 1) {
                    $issues[] = $this->issue(
                        $date,
                        'advisory',
                        'destination_not_explored',
                        'Пустой переезд в Ортизей — стоит короткая локальная активность у базы',
                        'ADD_LOCAL_ACTIVITY',
                        [
                            'base' => 'Ortisei',
                            'direction' => 'local',
                            'preferred_types' => ['town_walk', 'short_hike', 'panorama'],
                            'avoid_types' => ['rope_park'],
                            'historical_candidates' => ['Ortisei', 'Bulla'],
                            'max_hours' => 2.0,
                        ]
                    );
                }
            }

            // Два экстрима/hard adventure в одном дне (каньон+феррата, xtreme+skyscraper…)
            $extremeCount = preg_match_all(
                '/skyscraper|x-?treme|extreme|אקסטרים|canyon|каньон|קניון|bueno|via\s*ferrata|ferrato|ferrata|פראט|raft(?:ing)?|ראפט|рафтинг|quad|квадро/ui',
                $line
            ) ?: 0;
            if ($extremeCount >= 2) {
                $issues[] = $this->issue(
                    $date,
                    'advisory',
                    'day_is_one_dimensional',
                    'В дне два развлекательных/экстрим-слота — лучше одно развлечение + природа',
                    'REPLACE_ACTIVITY',
                    [
                        'base' => $base,
                        'direction' => $isTransfer ? 'along_transfer' : 'local',
                        'preferred_types' => ['nature', 'panorama', 'waterfall', 'short_hike', 'lake'],
                        'avoid_types' => ['extreme', 'rope_park'],
                        'historical_candidates' => [],
                        'max_hours' => $isTransfer ? 2.0 : 3.0,
                    ],
                    false // suggest: систему уже чинит refiner; директору — рекомендация
                );
            }

            // Крюк: Браес/Доббиако при ночёвке Мизурина (не на переезде)
            $nightIsMisurina = (bool) preg_match('/мизурин|misurina|מיזורינ|cortina|кортин/ui', $this->nightLabel($line));
            if ($nightIsMisurina && ! $isTransfer
                && preg_match('/braies|прагс|בראייס|dobbiaco|доббиак|דוביאק|toblach/ui', $prog)) {
                $issues[] = $this->issue(
                    $date,
                    'advisory',
                    'along_transfer_gap',
                    'Браес/Доббиако логичнее на переезде из Брунико в Мизурину, а не крюком из Мизурины',
                    'REPLACE_ACTIVITY',
                    [
                        'base' => $base,
                        'direction' => 'along_transfer',
                        'preferred_types' => ['lake', 'nature', 'panorama'],
                        'avoid_types' => [],
                        'historical_candidates' => ['Braies', 'Dobbiaco'],
                        'max_hours' => 2.5,
                    ],
                    false
                );
            }

            // Сезон: Cadini / Tre Cime — заранее парковка Auronzo
            if (preg_match('/cadini|кадини|tre\s*cime|lavaredo|лаваредо/ui', $prog)) {
                $issues[] = $this->issue(
                    $date,
                    'advisory',
                    'missing_historical_pattern',
                    'Август: к Refugio Auronzo (Cadini/Tre Cime) — платная дорога и очередь с утра; парковку лучше бронировать заранее',
                    'ADD_LOCAL_ACTIVITY',
                    [
                        'base' => $base,
                        'direction' => 'local',
                        'preferred_types' => ['panorama', 'short_hike'],
                        'avoid_types' => [],
                        'historical_candidates' => [],
                        'max_hours' => 1.0,
                    ],
                    false
                );
            }
        }

        // Два тяжёлых трека в соседних днях плана
        $isHardLine = static fn (string $ln): bool => (bool) preg_match(
            '/sorapis|сорапис|cinque\s*torri|чинкве|tre\s*cime|cadini|кадини|lavaredo/ui',
            $ln
        );
        $planDates = [];
        foreach ($dayBudgets as $b) {
            $d = (string) ($b['date'] ?? '');
            if ($d !== '') {
                $planDates[] = $d;
            }
        }
        for ($i = 1; $i < count($planDates); $i++) {
            $prev = $planDates[$i - 1];
            $cur = $planDates[$i];
            if ($isHardLine($byDate[$prev] ?? '') && $isHardLine($byDate[$cur] ?? '')) {
                $issues[] = $this->issue(
                    $cur,
                    'advisory',
                    'repeated_activity_type',
                    'Тяжёлые треки подряд ('.$prev.' и '.$cur.') — после тяжёлого дня лучше спокойная программа',
                    'REPLACE_ACTIVITY',
                    [
                        'base' => $this->nightLabel($byDate[$cur] ?? ''),
                        'direction' => 'local',
                        'preferred_types' => ['nature', 'lake', 'panorama', 'town_walk'],
                        'avoid_types' => ['extreme'],
                        'historical_candidates' => [],
                        'max_hours' => 3.0,
                    ],
                    false
                );
            }
        }

        // ferrata/via earlier + rope later → разнообразие (семейный тур)
        $ferrataDays = [];
        foreach ($byDate as $date => $line) {
            if (preg_match('/ferrata|פראטה|via\s*ferr|albano|ויא\s*פר/ui', $line)) {
                $ferrataDays[] = $date;
            }
        }
        if ($ferrataDays !== [] && $ropeDays !== []) {
            $issues[] = $this->issue(
                $ropeDays[0],
                'advisory',
                'repeated_activity_type',
                'Верёвочная активность после via ferrata ('.$ferrataDays[0].') — проверить разнообразие',
                'REPLACE_ACTIVITY',
                [
                    'base' => $this->nightLabel($byDate[$ropeDays[0]] ?? ''),
                    'direction' => 'local',
                    'preferred_types' => ['nature', 'short_hike', 'panorama', 'waterfall', 'town_walk'],
                    'avoid_types' => ['rope_park'],
                    'historical_candidates' => [],
                    'max_hours' => 2.5,
                ],
                false // suggest: не авто-менять семейный день без короткого кандидата в apply
            );
        }

        if (count($ropeDays) >= 2) {
            $issues[] = $this->issue(
                $ropeDays[1],
                'critical',
                'repeated_activity_type',
                'Верёвочный/adventure park уже был '.$ropeDays[0],
                'REPLACE_ACTIVITY',
                [
                    'base' => $this->nightLabel($byDate[$ropeDays[1]] ?? ''),
                    'direction' => 'local',
                    'preferred_types' => ['nature', 'short_hike', 'panorama', 'waterfall'],
                    'avoid_types' => ['rope_park'],
                    'historical_candidates' => [],
                    'max_hours' => 2.5,
                ]
            );
        }

        return $this->dedupeIssues($issues);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function issue(
        string $day,
        string $severity,
        string $problem,
        string $reason,
        string $action,
        array $scope,
        bool $autoApply = true,
    ): array {
        return [
            'day' => $day,
            'severity' => $severity,
            'problem' => $problem,
            'reason' => $reason,
            'action' => $action,
            'auto_apply' => $autoApply,
            'search_scope' => $scope,
        ];
    }

    private function nightLabel(string $line): string
    {
        if (preg_match('/Ночёвка:\s*(.+?)(?:\s*\[id=|\s*$)/u', $line, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function guessHistorical(string $base, string $line): array
    {
        $out = [];
        if (preg_match('/riva|ריבה|рива|garda|גארד/ui', $base.' '.$line)) {
            $out = ['Monte Baldo', 'Varone', 'Tenno', 'Limone', 'Malcesine'];
        } elseif (preg_match('/misurina|מיזור|мизурин|braies|בראייס/ui', $base.' '.$line)) {
            $out = ['Braies', 'Cadini', 'Dobbiaco', 'Tre Cime', 'Cinque Torri'];
        } elseif (preg_match('/ortisei|אורטיז|ортизе/ui', $base.' '.$line)) {
            $out = ['Santa Maddalena', 'Chiusa', 'Bulla'];
        } elseif (preg_match('/molveno|מולבנ/ui', $base.' '.$line)) {
            $out = ['Molveno', 'אגם מולבנו'];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeIssues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $list = isset($raw[0]) ? $raw : (is_array($raw['issues'] ?? null) ? $raw['issues'] : []);
        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $day = trim((string) ($row['day'] ?? $row['date'] ?? ''));
            if ($day === '' || ! preg_match('/^\d{2}\.\d{2}/', $day)) {
                continue;
            }
            $action = (string) ($row['action'] ?? 'ADD_ACTIVITY');
            if (in_array($action, ['NO_ACTION', 'KEEP_CHECK'], true)) {
                continue;
            }
            $scope = is_array($row['search_scope'] ?? null) ? $row['search_scope'] : [];
            $out[] = [
                'day' => preg_match('/^(\d{2}\.\d{2})/', $day, $m) ? $m[1] : $day,
                'severity' => in_array($row['severity'] ?? '', ['critical', 'advisory'], true)
                    ? $row['severity'] : 'advisory',
                'problem' => (string) ($row['problem'] ?? 'underfilled_day'),
                'reason' => (string) ($row['reason'] ?? ''),
                'action' => $action,
                'auto_apply' => array_key_exists('auto_apply', $row) ? (bool) $row['auto_apply'] : true,
                'search_scope' => [
                    'base' => (string) ($scope['base'] ?? $scope['area'] ?? ''),
                    'direction' => (string) ($scope['direction'] ?? 'local'),
                    'preferred_types' => array_values(array_filter((array) ($scope['preferred_types'] ?? []))),
                    'avoid_types' => array_values(array_filter((array) ($scope['avoid_types'] ?? []))),
                    'historical_candidates' => array_values(array_filter((array) ($scope['historical_candidates'] ?? []))),
                    'max_hours' => isset($scope['max_hours']) ? (float) $scope['max_hours'] : null,
                ],
            ];
        }

        return $this->dedupeIssues($out);
    }

    /**
     * Режем только явный конфликт с TIME: STATUS=OK или full-day ≥5ч.
     * SPARE + 3–4ч одной активности (каньон) — semantic underfilled допустим.
     *
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $dayBudgets
     * @return list<array<string, mixed>>
     */
    private function filterGeminiAgainstBudgets(array $issues, array $dayBudgets): array
    {
        $byDate = [];
        foreach ($dayBudgets as $b) {
            $d = (string) ($b['date'] ?? '');
            if ($d !== '') {
                $byDate[$d] = $b;
            }
        }
        $out = [];
        foreach ($issues as $iss) {
            $day = (string) ($iss['day'] ?? '');
            $problem = (string) ($iss['problem'] ?? '');
            $b = $byDate[$day] ?? null;
            if ($b && $problem === 'underfilled_day') {
                $status = (string) ($b['status'] ?? '');
                $max = (int) ($b['max_single_min'] ?? 0);
                if ($status === 'OK' || $max >= 300) {
                    continue;
                }
            }
            $out[] = $iss;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $seed
     * @param  list<array<string, mixed>>  $gemini
     * @return list<array<string, mixed>>
     */
    private function mergeIssues(array $seed, array $gemini): array
    {
        return $this->dedupeIssues(array_merge($seed, $gemini));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function dedupeIssues(array $issues): array
    {
        $seen = [];
        $out = [];
        foreach ($issues as $i) {
            $key = ($i['day'] ?? '').'|'.($i['problem'] ?? '').'|'.($i['action'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $i;
        }

        return array_slice($out, 0, 8);
    }
}
