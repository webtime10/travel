<?php

namespace App\Services;

use App\Models\FtShortRoute;
use RuntimeException;
use Throwable;

/**
 * HE → RU для FT Short (маршрут + обратный разбор).
 */
class FtShortRouteRuTranslator
{
    public function __construct(
        private GeminiTranslator $gemini,
        private FtShortRouteNormalizer $normalizer,
        private FtShortRouteReverseEngineer $reverseEngineer,
    ) {}

    /**
     * @return array{translated: int, remaining: int, total: int, already: int, done: bool, warning?: string}
     */
    public function translateBatch(FtShortRoute $route, int $limit = 20): array
    {
        $units = $this->units($route);
        if ($units === []) {
            throw new RuntimeException('Нечего переводить (пустой HE)');
        }

        $done = is_array($route->ru_units) ? $route->ru_units : [];
        $done = array_intersect_key($done, $units);
        $already = count($done);
        $pending = array_slice(array_diff_key($units, $done), 0, max(1, $limit), true);

        $translated = 0;
        $lastError = null;
        if ($pending !== []) {
            try {
                $map = $this->gemini->translateMap($pending);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $map = [];
                foreach ($pending as $key => $text) {
                    try {
                        $map += $this->gemini->translateMap([$key => $text]);
                    } catch (Throwable $e2) {
                        $lastError = $e2->getMessage();
                    }
                }
            }
            foreach ($pending as $key => $_) {
                $ru = trim((string) ($map[$key] ?? ''));
                if ($ru !== '') {
                    $done[$key] = $ru;
                    $translated++;
                }
            }
        }

        $this->applyRu($route, $done);
        $route->ru_units = $done;
        $route->save();

        $total = count($units);
        $remaining = max(0, $total - count($done));
        // Если дней нет — route_text_ru не из чего собрать; не держим в очереди.
        if ((int) $route->days_count <= 0 && trim((string) ($route->route_text ?? '')) === '') {
            if (trim((string) ($route->route_text_ru ?? '')) === '') {
                $route->route_text_ru = '—';
                $route->save();
            }
            $remaining = 0;
        }
        $out = [
            'translated' => $translated,
            'remaining' => $remaining,
            'total' => $total,
            'already' => $already,
            'done' => $remaining === 0,
        ];
        if ($translated === 0 && $remaining > 0) {
            $out['warning'] = $lastError ?: 'Gemini не вернул перевод — нажмите ещё раз.';
        }

        return $out;
    }

    /**
     * @return array{
     *   processed:int,
     *   translated_units:int,
     *   remaining_routes:int,
     *   route_id:?int,
     *   done:bool,
     *   ok:bool,
     *   message?:string
     * }
     */
    public function translateNextPending(int $routesLimit = 1, int $unitsLimit = 40): array
    {
        // Только маршруты с днями. Пустые (days=0) раньше крутились вечно: title уже RU, route_text пуст.
        $pendingQuery = FtShortRoute::query()
            ->orderBy('id')
            ->where('days_count', '>', 0)
            ->where(function ($w) {
                $w->where(function ($q) {
                    $q->whereNull('title_ru')->orWhere('title_ru', '');
                })->orWhere(function ($q) {
                    $q->whereNull('route_text_ru')->orWhere('route_text_ru', '');
                })->orWhere(function ($q) {
                    // Есть RE на HE, но нет RU-разбора
                    $q->whereNotNull('reverse_principle')
                        ->where('reverse_principle', '!=', '[]')
                        ->where('reverse_principle', 'not like', '%"skipped"%')
                        ->where(function ($r) {
                            $r->whereNull('reverse_principle_ru')
                                ->orWhere('reverse_principle_ru', '')
                                ->orWhere('reverse_principle_ru', '[]');
                        });
                });
            });

        $remainingBefore = (clone $pendingQuery)->count();
        $routes = (clone $pendingQuery)->limit(max(1, $routesLimit))->get();

        if ($routes->isEmpty()) {
            return [
                'processed' => 0,
                'translated_units' => 0,
                'remaining_routes' => 0,
                'route_id' => null,
                'done' => true,
                'ok' => true,
                'message' => 'Все FT Short уже с русским',
            ];
        }

        $processed = 0;
        $translatedUnits = 0;
        $lastId = null;
        $warnings = [];
        foreach ($routes as $route) {
            $lastId = $route->id;
            try {
                // Русский ошибочно в HE → в RU-слот; потом настоящий HE-разбор.
                if (! $route->hasReversePrinciple()) {
                    $route->repairMisplacedRussianReverse();
                    $route = $route->fresh();
                }
                if (! $route->hasReversePrinciple()) {
                    $this->reverseEngineer->extract($route->fresh());
                    $route = $route->fresh();
                }

                $guard = 0;
                $gotAny = false;
                do {
                    $result = $this->translateBatch($route->fresh(), $unitsLimit);
                    $got = (int) ($result['translated'] ?? 0);
                    $translatedUnits += $got;
                    if ($got > 0) {
                        $gotAny = true;
                    }
                    $guard++;
                    if (! empty($result['warning']) && ! ($result['done'] ?? false) && $got === 0) {
                        $warnings[] = '#'.$route->id.': '.$result['warning'];
                        break;
                    }
                } while (! ($result['done'] ?? false) && $got > 0 && $guard < 30);

                $routeFresh = $route->fresh();
                $reallyHasRu = $routeFresh
                    && trim((string) ($routeFresh->title_ru ?? '')) !== ''
                    && trim((string) ($routeFresh->route_text_ru ?? '')) !== '';

                if ($gotAny && $reallyHasRu) {
                    $processed++;
                } elseif ($gotAny && ! $reallyHasRu) {
                    // Частичный перевод (например только title) — не считаем готовым.
                    $warnings[] = '#'.$route->id.': частичный перевод, route_text_ru ещё пуст — продолжите';
                    $processed++; // сдвиг очереди только если units сдвинулись; иначе зациклимся
                } elseif ($warnings === []) {
                    $warnings[] = '#'.$route->id.': Gemini ничего не перевёл (0 units)';
                }
            } catch (Throwable $e) {
                $warnings[] = '#'.$route->id.': '.$e->getMessage();
            }
        }

        $remaining = (clone $pendingQuery)->count();

        $ok = $translatedUnits > 0 || $remaining < $remainingBefore || $remaining === 0;
        $out = [
            'processed' => $processed,
            'translated_units' => $translatedUnits,
            'remaining_routes' => $remaining,
            'route_id' => $lastId,
            'done' => $remaining === 0,
            'ok' => $ok && $warnings === [],
        ];
        if ($warnings !== []) {
            $out['ok'] = false;
            $out['message'] = implode('; ', array_slice($warnings, 0, 3));
        } elseif ($remainingBefore > 0) {
            $out['message'] = 'Было без RU: '.$remainingBefore.' → сейчас '.$remaining
                .' · units +'.$translatedUnits;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function units(FtShortRoute $route): array
    {
        $units = [];
        $title = trim((string) ($route->title ?? ''));
        if ($title !== '') {
            $units['title'] = $title;
        }

        $days = is_array($route->days) ? $route->days : [];
        $dayUnits = 0;
        foreach ($days as $d) {
            if (! is_array($d)) {
                continue;
            }
            $i = (int) ($d['day_index'] ?? 0);
            $places = trim((string) ($d['places'] ?? ''));
            if ($places !== '') {
                $units['d'.$i.'_places'] = $places;
                $dayUnits++;
            }
            foreach (array_values($d['options'] ?? []) as $oi => $opt) {
                $opt = trim((string) $opt);
                if ($opt !== '') {
                    $units['d'.$i.'_opt'.$oi] = $opt;
                    $dayUnits++;
                }
            }
        }

        // Fallback: дней в JSON нет, но есть чистый route_text.
        if ($dayUnits === 0) {
            $rt = trim((string) ($route->route_text ?? ''));
            if ($rt !== '' && $rt !== '—') {
                if (mb_strlen($rt) > 1200) {
                    $len = mb_strlen($rt);
                    for ($i = 0, $ci = 0; $i < $len; $i += 1000, $ci++) {
                        $units['route_text_'.$ci] = mb_substr($rt, $i, 1000);
                    }
                } else {
                    $units['route_text'] = $rt;
                }
            }
        }

        $rp = is_array($route->reverse_principle) ? $route->reverse_principle : [];
        if ($rp !== [] && empty($rp['skipped'])) {
            $units = array_merge($units, $this->reverseUnits($rp));
        }

        return $units;
    }

    /**
     * @param  array<string, mixed>  $rp
     * @return array<string, string>
     */
    private function reverseUnits(array $rp): array
    {
        $units = [];
        $ta = is_array($rp['target_audience'] ?? null) ? $rp['target_audience'] : [];
        $macro = is_array($rp['macro_architecture'] ?? null) ? $rp['macro_architecture'] : [];
        $ft = is_array($rp['fine_tune'] ?? null) ? $rp['fine_tune'] : [];
        $answer = is_array($ft['answer'] ?? null) ? $ft['answer'] : [];

        foreach ([
            'rp_group' => $ta['group_profile'] ?? null,
            'rp_pace' => $ta['activity_and_pace_level'] ?? null,
            'rp_why' => $ta['why_route_fits_audience'] ?? null,
            'rp_region' => $macro['region_selection_logic'] ?? null,
            'rp_bases' => $macro['base_camps_strategy'] ?? $macro['routing_strategy'] ?? null,
            'rp_transit' => $macro['transit_days_handling'] ?? null,
            'rp_ft_question' => $ft['question'] ?? null,
            'rp_ft_summary' => $answer['summary'] ?? null,
            'rp_abstract' => $rp['abstract_user_prompt'] ?? null,
        ] as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $units[$k] = $v;
            }
        }

        foreach (array_values($ta['special_constraints'] ?? []) as $i => $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $units['rp_constraint_'.$i] = $c;
            }
        }
        foreach (array_values($ta['potential_user_queries'] ?? []) as $i => $q) {
            $q = trim((string) $q);
            if ($q !== '') {
                $units['rp_query_'.$i] = $q;
            }
        }

        foreach (array_values($rp['micro_clustering'] ?? []) as $i => $m) {
            if (! is_array($m)) {
                continue;
            }
            foreach ([
                'desc' => $m['day_description'] ?? null,
                'logic' => $m['audience_adapted_logic'] ?? $m['practical_logic'] ?? null,
                'log' => $m['logistics_and_timing'] ?? $m['logistics_and_fatigue'] ?? null,
            ] as $suf => $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $units['rp_micro_'.$i.'_'.$suf] = $v;
                }
            }
        }

        foreach (array_values($answer['days'] ?? []) as $i => $fd) {
            if (! is_array($fd)) {
                continue;
            }
            $t = trim((string) ($fd['title'] ?? ''));
            if ($t !== '') {
                $units['rp_ftday_'.$i.'_title'] = $t;
            }
        }

        return $units;
    }

    /**
     * @param  array<string, string>  $done
     */
    private function applyRu(FtShortRoute $route, array $done): void
    {
        if (isset($done['title'])) {
            $route->title_ru = $done['title'];
        }

        $days = is_array($route->days) ? $route->days : [];
        foreach ($days as &$d) {
            if (! is_array($d)) {
                continue;
            }
            $i = (int) ($d['day_index'] ?? 0);
            if (isset($done['d'.$i.'_places'])) {
                $d['places_ru'] = $done['d'.$i.'_places'];
            }
            $optsRu = [];
            foreach (array_values($d['options'] ?? []) as $oi => $_) {
                $key = 'd'.$i.'_opt'.$oi;
                if (isset($done[$key])) {
                    $optsRu[$oi] = $done[$key];
                } elseif (isset($d['options_ru'][$oi])) {
                    $optsRu[$oi] = $d['options_ru'][$oi];
                }
            }
            $d['options_ru'] = array_values($optsRu);
        }
        unset($d);

        $route->days = $days;
        $fromDays = $this->normalizer->formatRouteText($days, true);
        if ($fromDays !== '') {
            $route->route_text_ru = $fromDays;
        } elseif (isset($done['route_text'])) {
            $route->route_text_ru = $done['route_text'];
        } else {
            $parts = [];
            foreach ($done as $k => $ru) {
                if (str_starts_with($k, 'route_text_')) {
                    $parts[(int) substr($k, strlen('route_text_'))] = $ru;
                }
            }
            if ($parts !== []) {
                ksort($parts);
                $route->route_text_ru = implode("\n", $parts);
            }
        }

        $heRp = is_array($route->reverse_principle) ? $route->reverse_principle : [];
        if ($heRp !== [] && empty($heRp['skipped'])) {
            $route->reverse_principle_ru = $this->buildReverseRu($heRp, $done);
        }
    }

    /**
     * @param  array<string, mixed>  $he
     * @param  array<string, string>  $done
     * @return array<string, mixed>
     */
    private function buildReverseRu(array $he, array $done): array
    {
        $taHe = is_array($he['target_audience'] ?? null) ? $he['target_audience'] : [];
        $macroHe = is_array($he['macro_architecture'] ?? null) ? $he['macro_architecture'] : [];
        $ftHe = is_array($he['fine_tune'] ?? null) ? $he['fine_tune'] : [];
        $answerHe = is_array($ftHe['answer'] ?? null) ? $ftHe['answer'] : [];

        $constraints = [];
        foreach (array_values($taHe['special_constraints'] ?? []) as $i => $_) {
            if (isset($done['rp_constraint_'.$i])) {
                $constraints[] = $done['rp_constraint_'.$i];
            }
        }
        $queries = [];
        foreach (array_values($taHe['potential_user_queries'] ?? []) as $i => $_) {
            if (isset($done['rp_query_'.$i])) {
                $queries[] = $done['rp_query_'.$i];
            }
        }

        $micro = [];
        foreach (array_values($he['micro_clustering'] ?? []) as $i => $m) {
            if (! is_array($m)) {
                continue;
            }
            $micro[] = [
                'day_number' => (int) ($m['day_number'] ?? 0),
                'day_description' => $done['rp_micro_'.$i.'_desc'] ?? null,
                'audience_adapted_logic' => $done['rp_micro_'.$i.'_logic'] ?? null,
                'logistics_and_timing' => $done['rp_micro_'.$i.'_log'] ?? null,
                'practical_logic' => $done['rp_micro_'.$i.'_logic'] ?? null,
                'logistics_and_fatigue' => $done['rp_micro_'.$i.'_log'] ?? null,
            ];
        }

        $ftDays = [];
        foreach (array_values($answerHe['days'] ?? []) as $i => $fd) {
            if (! is_array($fd)) {
                continue;
            }
            $ftDays[] = [
                'day_index' => (int) ($fd['day_index'] ?? $i + 1),
                'title' => $done['rp_ftday_'.$i.'_title'] ?? null,
                // places_text оставляем HE (имена мест) — или уже есть places_ru в days маршрута
                'places_text' => $fd['places_text'] ?? null,
            ];
        }

        $group = $done['rp_group'] ?? null;
        $pace = $done['rp_pace'] ?? null;
        $why = $done['rp_why'] ?? null;

        return [
            'target_audience' => [
                'group_profile' => $group,
                'activity_and_pace_level' => $pace,
                'special_constraints' => $constraints,
                'why_route_fits_audience' => $why,
                'potential_user_queries' => $queries,
            ],
            'audience_portrait' => [
                'group_composition' => $group,
                'activity_and_comfort' => $pace,
                'constraints' => $constraints !== [] ? implode('; ', $constraints) : null,
            ],
            'audience_fit' => [
                'why_this_route_fits' => $why,
                'possible_queries' => $queries,
            ],
            'abstract_user_prompt' => $done['rp_abstract'] ?? null,
            'synthetic_query' => $done['rp_abstract'] ?? null,
            'macro_architecture' => [
                'region_selection_logic' => $done['rp_region'] ?? null,
                'base_camps_strategy' => $done['rp_bases'] ?? null,
                'transit_days_handling' => $done['rp_transit'] ?? null,
                'routing_strategy' => $done['rp_bases'] ?? null,
            ],
            'micro_clustering' => $micro,
            'fine_tune' => [
                'question' => $done['rp_ft_question'] ?? null,
                'answer' => [
                    'summary' => $done['rp_ft_summary'] ?? null,
                    'days' => $ftDays,
                ],
            ],
            'principle' => $done['rp_region'] ?? null,
            'geo_logic' => $done['rp_bases'] ?? null,
            'schema_version' => $he['schema_version'] ?? 4,
            'translated_from_he' => true,
            'extracted_at' => now()->toIso8601String(),
        ];
    }
}
