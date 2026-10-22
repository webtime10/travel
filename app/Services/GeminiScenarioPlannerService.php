<?php

namespace App\Services;

use App\Models\RouteSkeleton;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Тестовая сборка: сырые POI + образцы директора → Gemini сам пишет 10-дневный сценарий.
 * Без Packer / Refiner / TIME / Audit.
 */
class GeminiScenarioPlannerService
{
    public function __construct(
        private GeminiKeyPool $keys,
        private RagLocationSearchService $search,
        private RouteSkeletonMatcher $skeletons,
        private TransferRouteFitService $transferFit,
        private ScenarioPoiContextBuilder $poiContext,
    ) {}

    /**
     * @param  array{system_prompt?: string|null, user_prompt?: string|null}  $promptOverrides
     * @return array{
     *   ok: bool,
     *   query: string,
     *   days_requested: int,
     *   pois: list<array<string, mixed>>,
     *   samples: list<array<string, mixed>>,
     *   plan: array<string, mixed>|null,
     *   raw_text: string,
     *   gemini_ms: int,
     *   search_ms: int,
     *   message?: string
     * }
     */
    public function plan(
        string $query,
        int $days = 10,
        int $poiLimit = 48,
        int $sampleLimit = 5,
        array $promptOverrides = [],
    ): array {
        $query = trim($query);
        $days = max(5, min(14, $days));
        $poiLimit = max(12, min(60, $poiLimit));
        $sampleLimit = max(3, min(5, $sampleLimit));

        if ($query === '') {
            throw new RuntimeException('Пустой запрос');
        }

        $tSearch = microtime(true);
        $found = $this->search->searchRaw($query, $poiLimit);
        $pois = $this->normalizePois(is_array($found['locations'] ?? null) ? $found['locations'] : []);
        $searchMs = (int) round((microtime(true) - $tSearch) * 1000);

        $similar = $this->skeletons->findSimilar($query, $days, $sampleLimit, []);
        $samples = $this->normalizeSamples($similar);

        if ($pois === []) {
            return [
                'ok' => false,
                'query' => $query,
                'days_requested' => $days,
                'pois' => [],
                'samples' => $samples,
                'plan' => null,
                'raw_text' => '',
                'gemini_ms' => 0,
                'search_ms' => $searchMs,
                'transfer_geo' => null,
                'message' => 'Не найдено POI в каталоге по запросу',
            ];
        }

        $pois = $this->poiContext->enrichPois($pois);
        $transferCtx = $this->transferFit->buildTransferContext($pois, $similar);
        $priorByBase = $this->transferFit->priorBaseIdsForStayPool($pois);
        $clusterCtx = $this->poiContext->buildStayClusters($pois, $priorByBase);
        $transfersBlock = trim(
            (string) ($transferCtx['prompt_block'] ?? '')
            ."\n\n"
            .(string) ($clusterCtx['prompt_block'] ?? '')
            ."\n\n"
            .self::scenarioContextSystemHint()
        );

        $systemTpl = trim((string) ($promptOverrides['system_prompt'] ?? ''));
        if ($systemTpl === '') {
            $systemTpl = self::defaultSystemPrompt();
        }
        $userTpl = trim((string) ($promptOverrides['user_prompt'] ?? ''));
        if ($userTpl === '') {
            $userTpl = self::defaultUserPrompt();
        }
        // Гарантируем общие правила transfer + density, даже если в AppSetting старый промпт
        if (! str_contains($systemTpl, 'географический крюк')
            && ! str_contains($systemTpl, 'ГЕОГРАФИЯ TRANSFER (общее')) {
            $systemTpl .= "\n\n".self::transferGeoSystemAddon();
        }
        if (! str_contains($systemTpl, 'DENSITY IS NOT A HARD STOP')
            && ! str_contains($systemTpl, 'короткий STAY-день нельзя завершать сразу после выбора одного POI')
            && ! str_contains($systemTpl, 'nearby enrichment check')) {
            $systemTpl .= "\n\n".self::densityEnrichmentSystemAddon();
        }
        if (! str_contains($systemTpl, 'Backend has already performed the geographic filtering')) {
            $systemTpl .= "\n\n".self::scenarioContextSystemHint();
        }
        if ((! str_contains($systemTpl, 'SCENARIO QUALITY DECISION')
                && ! str_contains($systemTpl, 'улучшает ли этот POI день'))
            || ! str_contains($systemTpl, 'Не выравнивай нагрузку между днями искусственно')
            || ! str_contains($systemTpl, 'OLD→stops→NEW')) {
            $systemTpl .= "\n\n".self::scenarioQualitySystemAddon();
        }
        // Старые промпты с жёстким «только short_stop / не бери full stay» — перекрыть мягкой логикой
        if (str_contains($systemTpl, 'avoid_full_stay')
            || str_contains($systemTpl, 'prefer=short_stop only')
            || str_contains($systemTpl, 'never schedule a full 3–4h')
            || str_contains($systemTpl, '1× short_stop, не 3–4 ч')) {
            $systemTpl .= "\n\n".self::longTransferSystemAddon();
        } elseif (! str_contains($systemTpl, 'LONG TRANSFER')
            && ! str_contains($systemTpl, 'prefer=lighter_ok')) {
            $systemTpl .= "\n\n".self::longTransferSystemAddon();
        }
        if (! str_contains($userTpl, '{{TRANSFERS}}')) {
            $userTpl .= "\n\n{{TRANSFERS}}";
        }

        $system = $this->fillPrompt($systemTpl, $query, $days, $pois, $samples, '', '', $transfersBlock);
        $user = $this->fillPrompt($userTpl, $query, $days, $pois, $samples, '', '', $transfersBlock);

        // Диагностика Test→сценарий: снимок до Gemini / до scrub (не влияет на маршрут)
        $stayClustersPrompt = (string) ($clusterCtx['prompt_block'] ?? '');
        $transfersBlockSnap = $transfersBlock;

        $t0 = microtime(true);
        $gemini = $this->callGemini($system, $user, 0.55, 65536);
        $raw = $gemini['text'];
        $finishReason = $gemini['finish_reason'];
        $geminiMs = (int) round((microtime(true) - $t0) * 1000);

        $planBeforeScrub = $this->parseJsonPlan($raw);
        $plan = $planBeforeScrub;
        $scrubRemoved = [];
        if (is_array($plan) && ! is_array($plan['days'] ?? null)) {
            Log::warning('gemini_scenario_plan: JSON без days[]', [
                'finish_reason' => $finishReason,
                'raw_len' => mb_strlen($raw),
                'keys' => array_keys($plan),
            ]);
            $plan = null;
            $planBeforeScrub = null;
        }
        // Backfill hours + overnight ДО scrub (scrub может намеренно обнулить hours, напр. #32+#395)
        if (is_array($plan)) {
            $plan = $this->backfillPlanHoursFromCatalog($plan, $pois);
            $plan = $this->backfillOvernightInPlan($plan, $pois);
            $planBeforeScrub = $plan;
            $scrubbed = $this->transferFit->scrubTransferStopsInPlan($plan, $transferCtx, $pois, $query);
            $plan = $scrubbed['plan'];
            $scrubRemoved = $scrubbed['removed'];
            if ($scrubRemoved !== []) {
                $transferCtx['debug_lines'] = array_merge(
                    $transferCtx['debug_lines'] ?? [],
                    ['--- PHP scrub ---'],
                    $scrubRemoved
                );
            }
            // overnight мог остаться пустым после правок scrub — ещё раз
            $plan = $this->backfillOvernightInPlan($plan, $pois);
        }

        $debugLines = array_merge(
            $transferCtx['debug_lines'] ?? [],
            ['--- stay filter + clusters ---'],
            $clusterCtx['debug_lines'] ?? [],
            ['--- poi load sample ---'],
            $this->debugPoiLoadSample($pois)
        );

        $parseMessage = null;
        if ($plan === null) {
            $parseMessage = 'Gemini ответила, но JSON маршрута не разобран — смотри raw_text';
            if ($finishReason === 'MAX_TOKENS') {
                $parseMessage .= ' (ответ обрезан: finishReason=MAX_TOKENS)';
            } elseif ($finishReason !== '' && $finishReason !== 'STOP') {
                $parseMessage .= ' (finishReason='.$finishReason.')';
            }
            Log::warning('gemini_scenario_plan_unparsed', [
                'finish_reason' => $finishReason,
                'raw_len' => mb_strlen($raw),
                'raw_head' => mb_substr($raw, 0, 240),
            ]);
        }

        return [
            'ok' => $plan !== null,
            'query' => $query,
            'days_requested' => $days,
            'pois' => $pois,
            'samples' => $samples,
            'plan' => $plan,
            'raw_text' => $raw,
            'gemini_ms' => $geminiMs,
            'search_ms' => $searchMs,
            'transfer_geo' => [
                'legs' => $transferCtx['legs'] ?? [],
                'clusters' => $clusterCtx['clusters'] ?? [],
                'debug_lines' => $debugLines,
                'scrub_removed' => $scrubRemoved,
                // промежуточные снимки одного запуска (диагностика)
                'stay_clusters_prompt' => $stayClustersPrompt,
                'transfers_block' => $transfersBlockSnap,
                'gemini_raw_before_scrub' => $raw,
                'gemini_plan_before_scrub' => is_array($planBeforeScrub) ? $planBeforeScrub : null,
                'finish_reason' => $finishReason,
            ],
            'message' => $parseMessage,
        ];
    }

    public static function scenarioContextSystemHint(): string
    {
        return <<<'PROMPT'
Backend has already performed the geographic filtering.
Do not independently invent geographic relationships.
Choose only from geographically valid candidates supplied by backend (TRANSFER CANDIDATES / STAY clusters).
GEOGRAPHICALLY INVALID → never use. GEOGRAPHICALLY VALID → not mandatory; evaluate whether the candidate improves the day.
Your task is not to maximize POI count or POI hours.
Your task is to create the best travel scenario: geographically coherent, comfortable, varied, physically balanced, and rhythmically balanced across the whole trip.
Hours are one factor, never the optimization target. 3–4h can be a correct day; 5–7h is a full day, not a quota.
Do not add POIs merely to reach a numerical hour target. Do not drop a valid candidate merely because the day is a transfer.
Evaluate the whole trip, not each day in isolation.
Use poi_duration and physical_load from backend when present; never invent physical_load.
For transfer days: 1 strong corridor stop is often enough; add a second only if it clearly improves the scenario (on-route, linked, no overload).
PROMPT;
    }

    public static function scenarioQualitySystemAddon(): string
    {
        return <<<'PROMPT'
SCENARIO QUALITY DECISION (главное правило выбора POI):
НЕ вопрос: «Как заполнить день до 5 часов?»
А вопрос: «Какой вариант создаёт лучший и наиболее логичный сценарий этого дня?»
Формула: geography + scenario quality + physical_load + day type + trip rhythm + duration.
Не: duration → добавить POI.

Часы НЕ цель. Запрещены эвристики: «<5 ч → обязательно добавить» и «≥5 ч → больше ничего не добавлять».
3–4 ч могут быть правильным днём. 5–7 ч обычно полноценный день. 7+ — осторожно. Это ориентиры, не жёсткие границы.

PHP уже отфильтровал географию (STAY / TRANSFER CANDIDATES). Не пересчитывай координаты сам.
INVALID → никогда. VALID → оценить: улучшает ли кандидат сценарий дня?

Добавляй кандидата, если: связан с якорем; естественно вписывается; не чрезмерная нагрузка; улучшает впечатления; не ломает ритм тура.
Не добавляй только ради часов. Не удаляй валидный stop только потому что theme=переезд.

MACRO HUBS: overnight = крупные базы (Ortisei/Brunico/Riva-Garda). Molveno-класс на пути к Garda = stop, не overnight.
Stay: STAY-radius только пул. Один cluster у ночёвки. После anchor ОБЯЗАТЕЛЬНО проверь остальные POI того же STAY cluster; если есть естественный 2-й/3-й — ДОБАВЬ (норма stay = 2–3 ACTIVITY). Один POI — только если после проверки подходящих нет. Не смешивай далёкие cluster_*. Не добивай до 5ч ради цифры. Не мешай Braies (СВ) с Monte Pana/Val Gardena (ЮЗ); Marmolada не после Brunico. Brunico: Braies #116 + Misurina #117 вместе, если оба в пуле.
Transfer: OLD→stops→NEW vs OLD→NEW и направление к NEW. POI у OLD base (Monte Pana/Alpacas в Val Gardena при переезде Ortisei→Brunico) — не corridor. Компактная Marmolada+Fedaia — stay Val Gardena ДО Brunico, не полный пакет на коротком Ortisei→Brunico. summary = фактические places. Часы не цель.
Garda-POI (Tenno/Varone/Ponale/Ledro) — только при overnight Riva-Garda.
Выезд (theme=выезд): НЕ применять stay-density. 4 ч только если время выезда/вылета позволяет; ранний выезд → короче программа. #395 переправа Malcesine→Limone и #32 Limone — один блок: не суммировать hours дважды, если duration #395 уже включает Limone.

Не максимизировать число POI / часов / активностей.
Не выравнивай нагрузку между днями искусственно. Разница между 3.5, 4.5, 5.5 и 6 часами сама по себе не является проблемой. Оценивай день относительно его типа, физической нагрузки, переезда и соседних дней. Короткий день допустим, если он логичен; насыщенный день допустим, если он не создаёт физического или логистического перегруза.
Пример нормального ритма: 2.5 → 4 → 3.5 → 3.5 → 5.5 → 6 → 4.5 → 4 → 6.5 → 4.
physical_load — только из данных backend; не выдумывать; не два тяжёлых дня подряд без необходимости.
PROMPT;
    }

    public static function longTransferSystemAddon(): string
    {
        $longKm = (float) config('travel_route_fit.long_transfer_direct_km', 120);

        return <<<PROMPT
LONG TRANSFER / OVERRIDE (общее, любой маршрут):
- [LONG TRANSFER] / direct≥{$longKm} km: длинная дорога уже часть дня; 1 сильный transfer_stop + база часто достаточно.
- НЕ удаляй валидный кандидат из CANDIDATES только потому что theme=переезд или «мало часов нормально».
- НЕ добавляй 2–3 stop автоматически и НЕ добивай часы до 5.
- Если PHP дал ещё один кандидат с хорошим route_fit / малым detour, логично связанный с основным stop и без чрезмерной нагрузки — сравни варианты (A: один stop; B: stop+кандидат) и бери B только если день реально лучше.
- prefer=lighter_ok | evaluate — подсказки PHP, не бан-лист. avoid_full_stay больше не использовать как запрет.
- Пример логики: «Тувель → Рива» на 3.5 ч OK; если Мольвено тоже в CANDIDATES с хорошим fit — оцени Тувель+Мольвено+Рива vs только Тувель+Рива по улучшению сценария, не по часам.
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $pois
     * @return list<string>
     */
    private function debugPoiLoadSample(array $pois): array
    {
        $lines = [];
        $n = 0;
        foreach ($pois as $p) {
            if (! empty($p['is_base'])) {
                continue;
            }
            $lines[] = sprintf(
                '  #%s %s | duration=%sч | load=%s',
                $p['id'] ?? '?',
                $p['name'] ?? '',
                $p['poi_duration'] ?? '?',
                $p['physical_load'] ?? '?'
            );
            if (++$n >= 24) {
                break;
            }
        }

        return $lines;
    }

    public static function transferGeoSystemAddon(): string
    {
        return <<<'PROMPT'
ГЕОГРАФИЯ TRANSFER (общее правило, любой маршрут):
- На theme=переезд используй ТОЛЬКО id из TRANSFER CANDIDATES соответствующего плеча (блок {{TRANSFERS}}).
- Не угадывай «по пути» из полного каталога: если id нет в CANDIDATES — это географический крюк, не бери.
- Не привязывайся к конкретным названиям озёр/городов — смотри только списки backend.
- INVALID → никогда. VALID → оценить улучшение дня, не обязанность брать.
- Мало hours на переезде — нормально; это НЕ команда «удали второй валидный stop».
- Stay: бери POI из STAY near ночёвки; не тащи точки чужого района на чужой переезд.
- For transfer days, use only POIs supplied by the backend as geographically valid candidates. Do not independently infer geography or introduce POIs outside the supplied candidate set.
PROMPT;
    }

    public static function densityEnrichmentSystemAddon(): string
    {
        return <<<'PROMPT'
DENSITY IS NOT A HARD STOP.
Hours are not a goal. Forbidden: "<5h → must add" and "≥5h → add nothing".
3–4h can be a complete correct day (arrival, departure, long transfer, heavy day, one strong anchor).
5–7h is usually a full day, not a mandatory quota.

STAY day (обязательный порядок, не «можно проверить»):
1. Выбери основной POI (anchor) из одного STAY cluster у ночёвки.
2. После этого ОБЯЗАТЕЛЬНО проверь остальные POI из ТОГО ЖЕ STAY cluster.
3. Если есть второй POI, который географически естественно сочетается с anchor и реально улучшает день — добавь его.
4. При необходимости проверь и третий POI, если он также естественно сочетается.
5. Не добавляй POI только ради увеличения количества часов.
6. Не ставь искусственную цель 5 часов.
7. 2–3 часа могут остаться нормальным днём, если других действительно подходящих POI нет.
8. Не смешивай далёкие cluster_*.
9. Не используй POI из другого STAY cluster только ради увеличения продолжительности.
Ключевое: короткий STAY-день нельзя завершать сразу после выбора одного POI. Перед завершением дня обязательно проверь оставшиеся POI того же cluster и выбери дополнительные, если они образуют естественную связку.
Two thin stay days in a row (~2h each) are suspicious if strong linked nearby candidates remain in the same cluster — you must have checked them before finishing the day.
Never fill to 5h.

Transfer: evaluate OLD→stops→NEW path detour, not only base radius.
Usually one strong stop OR a compact linked group from CANDIDATES. Compact Marmolada+Fedaia-class is OK if path detour is moderate.
Do not pack a mountain package with an unrelated stop on a long transfer.
Do not reuse the same transfer_stop on another day without a clear reason.
Do not auto-add stops for hours; do not auto-delete a valid stop merely because it is a transfer day.
Do not prefer an empty transfer when a good corridor candidate exists.
summary must match actual places (no "via X" if X was not kept).

Do NOT add POIs merely to increase hours. Do NOT drop valid POIs merely to keep a transfer day "thin".
If a geographically compatible POI improves the day — may add; if none — leave a short day.
Decision criterion: does this POI improve the day / trip rhythm — not hour arithmetic.
Do not artificially equalize load across days. A spread of 3.5 / 4.5 / 5.5 / 6 hours is fine by itself. Judge each day by type, physical_load, transfer, and neighbors — not by making every day the same length.
PROMPT;
    }

    /**
     * Второй проход: аудит уже собранного плана (перегруз/недогруз + nearby).
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $pois
     * @param  list<array<string, mixed>>  $samples
     * @param  array{system_prompt?: string|null, user_prompt?: string|null}  $promptOverrides
     * @return array{
     *   ok: bool,
     *   audit: array<string, mixed>|null,
     *   raw_text: string,
     *   gemini_ms: int,
     *   message?: string
     * }
     */
    public function audit(
        string $query,
        array $plan,
        array $pois,
        array $samples = [],
        array $promptOverrides = [],
    ): array {
        $query = trim($query);
        $pois = $this->normalizePois($pois);
        $days = max(1, count(is_array($plan['days'] ?? null) ? $plan['days'] : []));

        if ($plan === []) {
            throw new RuntimeException('Пустой план для аудита');
        }
        if ($pois === []) {
            throw new RuntimeException('Пустой каталог POI для аудита');
        }

        $systemTpl = trim((string) ($promptOverrides['system_prompt'] ?? ''));
        if ($systemTpl === '') {
            $systemTpl = self::defaultAuditSystemPrompt();
        }
        $userTpl = trim((string) ($promptOverrides['user_prompt'] ?? ''));
        if ($userTpl === '') {
            $userTpl = self::defaultAuditUserPrompt();
        }

        $planJson = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($planJson)) {
            $planJson = '{}';
        }

        $system = $this->fillPrompt($systemTpl, $query, $days, $pois, $samples, $planJson);
        $user = $this->fillPrompt($userTpl, $query, $days, $pois, $samples, $planJson);

        $t0 = microtime(true);
        $gemini = $this->callGemini($system, $user, 0.35, 32768);
        $raw = $gemini['text'];
        $geminiMs = (int) round((microtime(true) - $t0) * 1000);

        $audit = $this->parseJsonPlan($raw);

        return [
            'ok' => $audit !== null,
            'audit' => $audit,
            'raw_text' => $raw,
            'gemini_ms' => $geminiMs,
            'message' => $audit === null ? 'Аудит пришёл, но JSON не разобран — смотри raw_text' : null,
        ];
    }

    /**
     * Тест 3: V1 → geo → day → coverage → V2 (arbiter/rebuild).
     *
     * @param  array<string, string>  $prompts  ключи: architect_system/user, geo_*, day_*, coverage_*, rebuild_*
     * @return array<string, mixed>
     */
    public function runChain(
        string $query,
        int $days = 10,
        int $poiLimit = 48,
        int $sampleLimit = 5,
        array $prompts = [],
    ): array {
        $pick = function (string $key, string $fallback) use ($prompts): string {
            $v = trim((string) ($prompts[$key] ?? ''));

            return $v !== '' ? $v : $fallback;
        };

        $v1 = $this->plan($query, $days, $poiLimit, $sampleLimit, [
            'system_prompt' => $pick('architect_system', self::defaultSystemPrompt()),
            'user_prompt' => $pick('architect_user', self::defaultUserPrompt()),
        ]);

        if (! ($v1['ok'] ?? false) || ! is_array($v1['plan'] ?? null)) {
            return [
                'ok' => false,
                'query' => $query,
                'pois' => $v1['pois'] ?? [],
                'samples' => $v1['samples'] ?? [],
                'plan_v1' => $v1['plan'] ?? null,
                'audits' => [],
                'plan_v2' => null,
                'steps_ms' => ['search' => $v1['search_ms'] ?? 0, 'architect' => $v1['gemini_ms'] ?? 0],
                'raw' => ['architect' => $v1['raw_text'] ?? ''],
                'message' => $v1['message'] ?? 'V1 не собран — цепочка остановлена',
            ];
        }

        $pois = $v1['pois'];
        $samples = $v1['samples'];
        $plan = $v1['plan'];
        $stepsMs = [
            'search' => $v1['search_ms'] ?? 0,
            'architect' => $v1['gemini_ms'] ?? 0,
        ];
        $raw = ['architect' => $v1['raw_text'] ?? ''];
        $audits = [];

        $layers = [
            'geo' => [
                'system' => $pick('geo_system', self::defaultChainGeoSystemPrompt()),
                'user' => $pick('geo_user', self::defaultChainGeoUserPrompt()),
            ],
            'day' => [
                'system' => $pick('day_system', self::defaultChainDaySystemPrompt()),
                'user' => $pick('day_user', self::defaultChainDayUserPrompt()),
            ],
            'coverage' => [
                'system' => $pick('coverage_system', self::defaultChainCoverageSystemPrompt()),
                'user' => $pick('coverage_user', self::defaultChainCoverageUserPrompt()),
            ],
        ];

        foreach ($layers as $name => $tpl) {
            $layer = $this->audit($query, $plan, $pois, $samples, [
                'system_prompt' => $tpl['system'],
                'user_prompt' => $tpl['user'],
            ]);
            $stepsMs[$name] = $layer['gemini_ms'] ?? 0;
            $raw[$name] = $layer['raw_text'] ?? '';
            $audits[$name] = $layer['audit'];
            if (! ($layer['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'query' => $query,
                    'pois' => $pois,
                    'samples' => $samples,
                    'plan_v1' => $plan,
                    'audits' => $audits,
                    'plan_v2' => null,
                    'steps_ms' => $stepsMs,
                    'raw' => $raw,
                    'message' => 'Слой '.$name.': '.($layer['message'] ?? 'JSON не разобран'),
                ];
            }
        }

        $findings = [
            'geo' => $audits['geo'],
            'day' => $audits['day'],
            'coverage' => $audits['coverage'],
        ];
        $findingsJson = json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($findingsJson)) {
            $findingsJson = '{}';
        }

        $rebuild = $this->rebuild(
            $query,
            $plan,
            $pois,
            $samples,
            $findingsJson,
            [
                'system_prompt' => $pick('rebuild_system', self::defaultChainRebuildSystemPrompt()),
                'user_prompt' => $pick('rebuild_user', self::defaultChainRebuildUserPrompt()),
            ],
        );
        $stepsMs['rebuild'] = $rebuild['gemini_ms'] ?? 0;
        $raw['rebuild'] = $rebuild['raw_text'] ?? '';

        return [
            'ok' => (bool) ($rebuild['ok'] ?? false),
            'query' => $query,
            'pois' => $pois,
            'samples' => $samples,
            'plan_v1' => $plan,
            'audits' => $audits,
            'plan_v2' => $rebuild['plan'] ?? null,
            'steps_ms' => $stepsMs,
            'raw' => $raw,
            'message' => $rebuild['message'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $pois
     * @param  list<array<string, mixed>>  $samples
     * @param  array{system_prompt?: string|null, user_prompt?: string|null}  $promptOverrides
     * @return array{ok: bool, plan: array<string, mixed>|null, raw_text: string, gemini_ms: int, message?: string}
     */
    public function rebuild(
        string $query,
        array $plan,
        array $pois,
        array $samples,
        string $findingsJson,
        array $promptOverrides = [],
    ): array {
        $query = trim($query);
        $pois = $this->normalizePois($pois);
        $days = max(1, count(is_array($plan['days'] ?? null) ? $plan['days'] : []));

        $systemTpl = trim((string) ($promptOverrides['system_prompt'] ?? ''));
        if ($systemTpl === '') {
            $systemTpl = self::defaultChainRebuildSystemPrompt();
        }
        $userTpl = trim((string) ($promptOverrides['user_prompt'] ?? ''));
        if ($userTpl === '') {
            $userTpl = self::defaultChainRebuildUserPrompt();
        }

        $planJson = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($planJson)) {
            $planJson = '{}';
        }

        $system = $this->fillPrompt($systemTpl, $query, $days, $pois, $samples, $planJson, $findingsJson);
        $user = $this->fillPrompt($userTpl, $query, $days, $pois, $samples, $planJson, $findingsJson);

        $t0 = microtime(true);
        $gemini = $this->callGemini($system, $user, 0.4, 65536);
        $raw = $gemini['text'];
        $geminiMs = (int) round((microtime(true) - $t0) * 1000);
        $out = $this->parseJsonPlan($raw);
        if (is_array($out) && is_array($out['days'] ?? null)) {
            $out = $this->backfillPlanHoursFromCatalog($out, $pois);
            $out = $this->backfillOvernightInPlan($out, $pois);
        }

        return [
            'ok' => $out !== null,
            'plan' => $out,
            'raw_text' => $raw,
            'gemini_ms' => $geminiMs,
            'message' => $out === null ? 'Rebuild JSON не разобран — смотри raw' : null,
        ];
    }

    public static function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — архитектор сценария путешествия, а не бухгалтер по часам.

Твоя задача: по запросу клиента, образцам стиля директора и списку POI из каталога собрать цельный {{DAYS}}-дневный маршрут.

Главная цель — не использовать как можно больше POI, а построить логичное, географически связное и комфортное путешествие с правильным ритмом нагрузки.

ЯЗЫК (обязательно):
- Весь человекочитаемый текст ТОЛЬКО на русском.
- title, theme, summary, name у мест и ночёвок, note, notes — только русский.
- Если в каталоге имя на иврите / английском / другом языке — переведи на русский.
- id мест не меняй.
- Не оставляй иврит или английские названия в человекочитаемом тексте.

ПЕРЕД СОЗДАНИЕМ МАРШРУТА ОБЯЗАТЕЛЬНО СДЕЛАЙ ВНУТРЕННИЙ МАКРОАНАЛИЗ.
Не показывай этот анализ в ответе, но используй его при построении.

1. ГЕОГРАФИЯ И ПОСЛЕДОВАТЕЛЬНОСТЬ
- MACRO HUBS: overnight только крупные базы маршрута (Ortisei / Brunico / Riva-Garda и аналоги). Промежуточное озеро (Molveno и т.п.) на пути к Garda = transfer_stop, НЕ overnight на 1–2 ночи.
- Типичная макроось Dolomites→Garda: Ortisei → Brunico → Riva (не Ortisei → Brunico → Molveno → Riva).
- STAY-radius / CANDIDATES = только пул; НЕ значит «всё из пула можно в один день».
- Stay: мысленно BASE→POI1→POI2→BASE — одна ось/кластер; после anchor обязательно проверь остальные POI того же STAY cluster; не склеивай противоположные стороны / далёкие cluster_*.
- Brunico-stay: Braies #116 + Misurina #117 в ОДНОМ stay-дне (не Braies на переезде Ortisei→Brunico и Misurina отдельно).
- На Ortisei→Brunico предпочитай лёгкий corridor stop; Braies лучше оставить для stay Brunico с Misurina.
- Не клеить valley-short у базы (Tervela) к дню high-pass (Sella/Pordoi/Marmolada).
- Long transfer Brunico→Garda: 1–2 corridor stop из CANDIDATES (Molveno/Nardis/Tovel-класс), не пустой и не один тонкий без причины.
- theme=выезд: лёгкий финал (Limone/#395), не тяжёлый трек Busatte.
- Marmolada / Fedaia — stay у Val Gardena ДО переезда в Brunico; на коротком Ortisei→Brunico не ставь полный Marmolada-пакет как transfer.
- Transfer: OLD→stops→NEW vs прямой OLD→NEW; существенный path-detour → убери stop(s). Компактная связка из CANDIDATES с умеренным detour — можно оставить.
- Stop в зоне OLD base без продвижения к NEW (Monte Pana/Alpacas при Ortisei→Brunico) — не transfer corridor.
- Не возвращайся в район предыдущей базы (Val Gardena после переезда в Brunico и т.п.).
- Tenno / Varone / Ponale / Ledro / Busatte — stay у Riva-Garda, не у Molveno.
- не добавляй место только ради часов; большой крюк — лучше не брать.
- summary/narrative дня = только фактические places; если stop убрали — не пиши «через …».
- Stay 3–3.5 ч ок; не искусственно добавляй POI до 5 ч.
- theme=выезд: не stay-density; программа по времени выезда/вылета; #395+#32 Limone — один блок hours.

2. РЕАЛЬНОЕ ВРЕМЯ ДНЯ
- hours = пребывание на POI; реальный день = POI + переезды + парковка + канатки/лодки + ожидание + запас на сезон/горы;
- переездный день (база A → POI → база B) нельзя оценивать только суммой hours.

3. ПЛОТНОСТЬ ДНЯ — НЕ ЖЁСТКИЙ СТОП (DENSITY IS NOT A HARD STOP)
- Часы не цель. Запрещено: «<5 ч → добавить» / «≥5 ч → стоп».
- 3–4 ч могут быть полностью нормальным днём (заезд/выезд/длинный переезд/тяжёлый день/один сильный якорь).
- 5–7 ч — обычно полноценный день, но не квота; 7+ — только при связности и без тяжёлого трека.
- Stay: anchor → ОБЯЗАТЕЛЬНО проверь остальные POI того же STAY cluster → добавь 2-й/3-й если естественная связка и улучшают день; короткий день нельзя завершать сразу после одного POI; не смешивай далёкие cluster_*; 2–3 ч ок если подходящих нет; не цель 5 ч.
- Transfer: enrichment ТОЛЬКО внутри TRANSFER CANDIDATES; критерий — улучшение сценария, не часы. Не переходи к «лучше пустой переезд», если есть хороший corridor stop. Не ставь Val Gardena stay (Monte Pana/Alpacas) на переезд к Brunico.
- theme=выезд: не stay-density; лимит по времени выезда; #395+#32 — один hours-блок.
- НЕ добавляй POI ради hours; НЕ удаляй валидный кандидат «потому что transfer».

4. ПЕРЕЕЗДНЫЕ ДНИ
- ось: OLD → stop(s) → NEW; сравни path с OLD→NEW (не только POI↔база);
- обычно 1 сильный stop ИЛИ компактная связанная группа без существенного path-detour;
- полноценный горный сценарий (несколько длинных/тяжёлых) не смешивать с посторонним stop на long transfer;
- не повторять один transfer_stop в другом дне без явной причины;
- мало hours на переезде — нормально ≠ underload; часы не цель;
- summary переезда: «через A и B» только если A и B реально в places.

5. ФИЗИЧЕСКАЯ НАГРУЗКА
- используй physical_load из данных backend; не выдумывай, если поля нет;
- не два тяжёлых экстрима/трека в один день и не два больших трека подряд без необходимости;
- после тяжёлого — более спокойный день;
- длинный переезд + тяжёлый трек — только если необходимо.

6. БАЗА НОЧЁВКИ — ЧАСТЬ ПУТЕШЕСТВИЯ
- город / набережная / озеро / вечер — часть сценария;
- не добавляй часы базы искусственно, если день уже плотный.

7. ЛУЧШАЯ КОМБИНАЦИЯ POI (SCENARIO QUALITY DECISION)
- не «один главный POI и стоп», а лучшая связка из географически допустимых;
- не обязан использовать все POI; между конкурирующими — более сильный и логичный;
- критерий: улучшает ли этот POI день / ритм тура, а не сколько часов добавляет.

8. КЛЮЧЕВЫЕ МЕСТА ИЗ КАТАЛОГА
- сильный nearby в той же зоне — кандидат на ДОБАВЛЕНИЕ к дню (enrichment) или на замену слабого;
- не выдумывай места вне каталога / вне CANDIDATES на переезде.

9. КРЮКИ
- мысленно: база → POI1 → POI2 → база/следующая база;
- большой непропорциональный крюк — убери, даже если красиво.

10. РАЗНООБРАЗИЕ
- чередуй горы / озёра / города / панорамы / лёгкие прогулки / активность.

11. РИТМ ВСЕГО ТУРА
- не три–четыре тяжёлых дня подряд;
- маршрут должен менять характер, а не быть случайным списком;
- не выравнивай нагрузку между днями искусственно: разница 3.5 / 4.5 / 5.5 / 6 ч сама по себе не проблема;
- короткий день ок, если логичен; насыщенный ок, если нет физ./логистического перегруза.

12. ФИНАЛЬНАЯ ПРОВЕРКА
- все id из каталога; русские имена; одна ось дня; нет крюков и явного перегруза;
- total_hours — сумма hours мест, НЕ цель оптимизации и НЕ включает переезд;
- в notes НЕ писать «маршрут сбалансирован» только из-за часов — сначала география.

13. ГЕОГРАФИЯ + КАЧЕСТВО СЦЕНАРИЯ
- PHP уже отфильтровал corridor; на переезде — ТОЛЬКО id из TRANSFER CANDIDATES плеча.
- INVALID → никогда. VALID → оценить «улучшает ли этот POI день», не обязанность брать и не бан.
- Stay — из одного STAY cluster у ночёвки.
- Не максимизировать POI/часы; оптимизировать качество дня + связность + ритм тура.
- Backend has already performed the geographic filtering. Do not invent geography.
- Best scenario > max hours; physical_load из данных важнее «набрать 5 ч».

ЖЁСТКИЕ ОГРАНИЧЕНИЯ:
- Только id из каталога. Никаких выдуманных мест.
- place: {"id": N, "name": "русское имя", "hours": число, "role": "activity|base|transfer_stop"}
- Ночёвка — id базы из каталога, если есть.
- Ответ — ТОЛЬКО валидный JSON, без markdown и пояснений.

Формат:
{
  "title": "краткое название тура по-русски",
  "days": [
    {
      "date": "ДД.ММ или день 1",
      "theme": "заезд | stay | переезд | выезд",
      "summary": "1–2 предложения по-русски",
      "overnight": {"id": 0, "name": "русское имя базы"},
      "places": [
        {"id": 0, "name": "русское имя", "hours": 2.0, "role": "activity", "note": "по-русски, опционально"}
      ],
      "total_hours": 6.0
    }
  ],
  "notes": ["короткие замечания по-русски, если нужно"]
}
PROMPT;
    }

    public static function defaultUserPrompt(): string
    {
        return <<<'PROMPT'
ЯЗЫК ОТВЕТА: только русский (все title, theme, summary, name, note, notes).
Имена мест из каталога переведи на русский; id не меняй.
Иврит / английский в тексте ответа запрещены.

ЗАПРОС КЛИЕНТА:
{{QUERY}}

НУЖНО ДНЕЙ: {{DAYS}}

ОБРАЗЦЫ СТИЛЯ ДИРЕКТОРА (как он строит дни — ритм, связки, ночёвки):
{{SAMPLES}}

КАТАЛОГ POI (можно использовать ТОЛЬКО эти id):
{{POIS}}

{{TRANSFERS}}

Собери {{DAYS}}-дневный маршрут с нуля в JSON по правилам system instruction. Весь текст — на русском.
PROMPT;
    }

    public static function defaultAuditSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — логист-аудитор уже собранного маршрута (не архитектор с нуля и не бухгалтер по сумме hours).

Тебе дали: запрос клиента, готовый план JSON, каталог POI (только эти id), исторические паттерны участков (не готовые точки для вставки).

Задача: проверить КАЖДЫЙ день и весь тур. Не переписывай тур целиком. Сначала разбор, потом точечные patch.

ЯЗЫК: весь текст аудита только на русском. Имена — по-русски. id не выдумывай.

=== BASE vs ACTIVITY В КАТАЛОГЕ {{POIS}} ===
Каталог плоский: в нём и базы ночёвки, и activity. Различай по полям:
- role=base ИЛИ base=yes/true → БАЗА НОЧЁВКИ, не activity candidate.
- Такую точку НЕльзя объявлять missing_nearby только потому что она «рядом» с текущим POI.
- Нельзя автоматически предлагать base в nearby_unused / places_suggested / patch=enrich как обычную достопримечательность.
- Пример: Misurina #117 с role=base / base=yes — это overnight hub, НЕ обязательный nearby к Braies #116 и НЕ «пропущенная активность».
- Исключение: только если текущий план уже использует эту базу как посещаемую activity-точку (есть в places дня с role≠base) — тогда оценивай как обычную точку дня, не как «добавить базу в маршрут».
- Для missing_nearby / enrich кандидатами считай только ACTIVITY (role≠base и base≠yes), либо явно activity/transfer_stop в каталоге.

=== КОРОТКИЙ ДЕНЬ ≠ ОШИБКА ===
3–4 ч POI сами по себе не underload и не missing_nearby. Нормально для: прибытия, выезда, разгрузки, дня после тяжёлого, физически насыщенного дня, ограничений дороги/доступа. Сначала спроси: день намеренно лёгкий? Если да → load=light/ok, patch=keep, не досыпай.

=== ЧЕКЛИСТ АУДИТА ===

1. ГЕОГРАФИЯ — рядом / по пути / крюк / возврат назад.
2. РЕАЛЬНОЕ ВРЕМЯ — hours = только POI; день = POI + дорога + парковка + канатки/лодки + очереди + обед + запас на август.
3. ПЛОТНОСТЬ load: light | ok | heavy | overload | underload (3–5 комфорт; 5–7 полноценный; 7+ осторожно). Короткий осознанный день = light/ok, не underload.
4. ПЕРЕЕЗД — одна ось; stopover ≠ раздутая экскурсия; длинная дорога → меньше остановок.
5. НАГРУЗКА — не два тяжёлых подряд; тип активностей, не только часы.
6. БАЗА — часть путешествия, не досыпать часы если день уже плотный; base из каталога ≠ activity для enrich.
7. NEARBY из каталога — только ACTIVITY; цепочка решения: есть nearby? → activity или base? → real underload? → география/направление естественно? → добавление реально улучшает день? → затем missing_nearby | add_if_light | optional_evening | keep. Иерархия: replace > deepen > add_if_light > optional_evening. Вне каталога запрещено. «Nearby есть» ≠ автоматически missing.
8. РИТМ ТУРА — drama_ok; не серия тяжёлых дней.
9. ПРАКТИКА — бронь доступа, расписание кораблика/канатки как якорь дня.
10. HISTORICAL {{SAMPLES}} — только паттерн/логика участка; не превращай concrete historical POI в обязательный nearby.

=== ТИПОВЫЕ ОШИБКИ (severity по силе кейса, не авто-critical) ===

A) СИЛЬНАЯ ПРОПУЩЕННАЯ ACTIVITY-СВЯЗКА (не «любой nearby»)
Иллюстрация (не жёсткое правило имён): у восточных Доломитов день из одной слабой «витрины», а в каталоге есть сильный ACTIVITY рядом (напр. Tre Cime / Три Пика / Lavaredo с role≠base), и связка географически естественна и реально улучшает день.
Перед issue пройди ВСЕ шаги чеклиста §7. Запрещено: Braies + рядом Misurina-base → critical missing_nearby; короткий день + любой nearby → critical.
→ Если все условия да: можно enrich; type=missing_nearby; severity=critical только для действительно сильной пропущенной связки, иначе major/minor или без issue — suggestion=add_if_light / optional_evening.
→ Если день разгрузочный / прибытие / выезд / после тяжёлого → patch=keep, осознанный light/underload, не missing_nearby.
→ Не выдумывай id; base (напр. Misurina #117 base=yes) не подставляй как activity enrich.

B) ДАЛЬНИЙ ВЫЕЗД ИЗ БАЗЫ «ТУДА-ОБРАТНО» + ВТОРОЙ ВТОРОСТЕПЕННЫЙ ГОРОД
Паттерн: Riva/Гарда → Sirmione + ещё Peschiera/другой южный городок → обратно.
Формально 4–5 ч POI, реально дорога+парковка+август → длинный день.
→ type=transfer_underestimated или hook; кандидат на trim — вторичный пункт (часто Peschiera), больше времени главному (Sirmione).

C) ДЛИННЫЙ МЕЖРЕГИОНАЛЬНЫЙ ПЕРЕЕЗД + ДВЕ ПОЛНОЦЕННЫЕ ОЗЁРНЫЕ ОСТАНОВКИ
Паттерн: Brunico/Pusteria → Tovel + Molveno → Riva/Garda.
Часы POI выглядят ок, реальный день тяжёлый.
→ type=transfer_underestimated; не ADD; скорее trim/rebalance (сократить одну остановку), гибкость часов важнее точных цифр.

D) ПОСЛЕ ПЕШЕГО/АКТИВНОГО ДНЯ СЛЕДУЮЩИЙ СНОВА ТРЕК
Если день N = длинная ходьба/зиплайн/активность, день N+1 не должен стать тяжёлым треком — лучше панорамы/канатка/озеро.
→ type=consecutive_hard / load_type.

E) ХОРОШИЙ ДЕНЬ — НЕ ДОСЫПАТЬ
Если день уже ok/heavy и географически цел (перевалы+панорама+озеро; Ponale+Varone+Tenno; Limone+кораблик) — в do_not_add, не nearby «ещё одно».

F) ТРАНСПОРТНЫЙ ЯКОРЬ
Кораблик / фиксированный сеанс — проверить, что день строится вокруг расписания (type=timing / access_booking), не только hours.

G) ПЕРЕЕЗД С ОДНОЙ СИЛЬНОЙ ТОЧКОЙ ПО ПУТИ
Ortisei → Braies → Brunico — это ХОРОШИЙ паттерн (transfer_stop). Не ломать. Не помечать underload только из-за 4–5 ч POI.

=== ЧТО НЕ ДЕЛАТЬ ===
- не собирать новый тур с нуля;
- не максимизировать total_hours;
- не добавлять «потому что красиво», если ломает ритм;
- не выдумывать id вне каталога.

=== ФОРМАТ (ТОЛЬКО JSON) ===
{
  "overall_score": 0-10,
  "overall_summary": "2–4 предложения",
  "drama_ok": true,
  "days": [
    {
      "day": 1,
      "date": "как в плане",
      "load": "ok | light | heavy | overload | underload",
      "real_day_hours_estimate": "например 8–9",
      "verdict": "кратко",
      "issues": [
        {
          "type": "overload | underload | transfer_underestimated | hook | timing | load_type | consecutive_hard | missing_nearby | access_booking | other",
          "severity": "critical | major | minor",
          "problem": "...",
          "why": "...",
          "evidence": "дорога / hours / соседние id из каталога"
        }
      ],
      "nearby_unused": [
        {
          "id": 0,
          "name": "русское имя",
          "relation": "на оси / рядом / у базы",
          "suggestion": "replace | deepen | add_if_light | optional_evening",
          "instead_of_id": null,
          "note": "почему"
        }
      ],
      "patch": {
        "action": "keep | trim | rebalance | enrich",
        "places_suggested": [
          {"id": 0, "name": "...", "hours": 2.0, "role": "activity|base|transfer_stop", "note": "..."}
        ],
        "overnight": {"id": 0, "name": "..."},
        "summary": "1–2 фразы после правки"
      }
    }
  ],
  "top_fixes": ["1–5 главных правок"],
  "do_not_add": ["соблазнительно, но не надо — с причиной"]
}

Проверь каждый день. Пустые issues ок, но load и verdict обязательны.
В top_fixes — только реально сильные правки (в т.ч. missing_nearby/critical при сильной ACTIVITY-связке). Не заноси туда каждый nearby и не критикуй короткие осознанные дни.
PROMPT;
    }

    public static function defaultAuditUserPrompt(): string
    {
        return <<<'PROMPT'
Проведи аудит маршрута по правилам system instruction.

ЗАПРОС КЛИЕНТА:
{{QUERY}}

ИСХОДНЫЙ ПЛАН (JSON):
{{PLAN}}

КАТАЛОГ POI (только эти id; role=base / base=yes = ночёвка, не activity-кандидат для missing_nearby):
{{POIS}}

ИСТОРИЧЕСКАЯ ЛОГИКА УЧАСТКА (historical examples — не обязательные POI; nearby/patch только из ACTIVITY каталога):
{{SAMPLES}}

Нужно:
1) пройти КАЖДЫЙ день: перегруз / недогруз / недооценка дороги на переезде; короткий день сам по себе не ошибка;
2) для nearby: сначала activity vs base → underload? → география/направление? → ценность добавления? → затем missing_nearby | add_if_light | optional_evening | keep (не авто-critical);
3) top_fixes и do_not_add;
4) ответ — только JSON аудита, на русском.
PROMPT;
    }

    public static function defaultChainGeoSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — аудит ГЕОГРАФИИ маршрута. Только география. Не считай hours. Не оценивай красоту.

Проверь каждый день и весь тур:
- крюки, возвраты назад, повтор одной территории;
- точки «по пути» между базами vs отдельный заезд;
- нарушено ли естественное направление (восток→юг и т.п.);
- челноки туда-сюда.

Только id из каталога. Русский. Ответ — JSON аудита:
{"layer":"geo","overall_summary":"...","days":[{"day":1,"verdict":"...","issues":[{"type":"hook|direction|shuttle|other","severity":"critical|major|minor","problem":"...","why":"...","evidence":"..."}],"patch":{"action":"keep|trim|rebalance|move","summary":"..."}}],"top_fixes":[],"do_not_add":[]}
PROMPT;
    }

    public static function defaultChainGeoUserPrompt(): string
    {
        return <<<'PROMPT'
Аудит географии.

ЗАПРОС: {{QUERY}}
ПЛАН: {{PLAN}}
POI: {{POIS}}
ИСТОРИЧЕСКАЯ ЛОГИКА (не готовые POI): {{SAMPLES}}

Только JSON, русский.
PROMPT;
    }

    public static function defaultChainDaySystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — аудит РЕАЛЬНОГО ДНЯ: логистика + нагрузка + переезды. Жёстко.

hours в плане = только POI. Реальный день = POI + дорога + парковка + канатки + очереди + обед + запас на сезон.

По каждому дню: load = light|ok|heavy|overload|underload, real_day_hours_estimate.
Лови:
- переезд A→…→B с 2+ полноценными остановками при длинной дороге (transfer_underestimated);
- дальний выезд туда-обратно + вторичный город (trim вторичного);
- два тяжёлых подряд / пеший день → снова трек (consecutive_hard);
- «витринный» короткий день отмечай underload только если он не выглядит осознанной разгрузкой/прибытием/выездом; покрытие nearby — следующий слой, без авто-critical.

Не добавляй POI ради часов. Не выдумывай id. Короткий день ≠ ошибка. Русский.
JSON: {"layer":"day","overall_summary":"...","days":[{"day":1,"load":"ok","real_day_hours_estimate":"8-9","verdict":"...","issues":[{"type":"overload|underload|transfer_underestimated|consecutive_hard|timing|other","severity":"critical|major|minor","problem":"...","why":"...","evidence":"..."}],"patch":{"action":"keep|trim|rebalance","summary":"..."}}],"top_fixes":[],"do_not_add":[]}
PROMPT;
    }

    public static function defaultChainDayUserPrompt(): string
    {
        return <<<'PROMPT'
Аудит реального дня / нагрузки / переездов.

ЗАПРОС: {{QUERY}}
ПЛАН: {{PLAN}}
POI: {{POIS}}

Только JSON, русский.
PROMPT;
    }

    public static function defaultChainCoverageSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — аудит ПОКРЫТИЯ POI. Только каталог. Не авто-critical.

BASE vs ACTIVITY: role=base или base=yes → ночёвка, НЕ activity candidate. Нельзя missing_nearby / enrich только потому что base «рядом» (пример: Misurina #117 base рядом с Braies ≠ пропущенная активность).

Цепочка перед issue: nearby есть? → это ACTIVITY (не base)? → реальный underload (короткий день сам по себе не ошибка)? → география/направление естественны? → добавление реально улучшает день? → затем missing_nearby | add_if_light | optional_evening | keep.

Иллюстрация (не жёсткое правило имён): слабая «витрина» без сильного ACTIVITY рядом (напр. без Tre Cime / Lavaredo с role≠base) — можно enrich; «Misurina без Tre Cime» — только если Misurina/Tre Cime в каталоге как ACTIVITY и связка уместна, не если Misurina = base overnight.

Правила:
- replace > deepen > add_if_light > optional_evening;
- missing_nearby/critical — только сильная пропущенная ACTIVITY-связка; иначе add_if_light / optional / keep;
- не досыпай overload/heavy без trim; хорошие и осознанно короткие дни — do_not_add / keep;
- historical samples не делают POI обязательным;
- вне каталога запрещено.

Русский. JSON:
{"layer":"coverage","overall_summary":"...","days":[{"day":1,"verdict":"...","issues":[{"type":"missing_nearby|weak_choice|other","severity":"critical|major|minor","problem":"...","why":"...","evidence":"id..."}],"nearby_unused":[{"id":0,"name":"...","suggestion":"replace|deepen|add_if_light|optional_evening","instead_of_id":null,"note":"..."}],"patch":{"action":"keep|enrich|replace","summary":"..."}}],"top_fixes":[],"do_not_add":[]}
В top_fixes — только сильные правки; critical missing_nearby не штампуй на каждый nearby.
PROMPT;
    }

    public static function defaultChainCoverageUserPrompt(): string
    {
        return <<<'PROMPT'
Аудит покрытия / nearby из каталога (ACTIVITY only; base≠candidate; короткий день≠ошибка).

ЗАПРОС: {{QUERY}}
ПЛАН: {{PLAN}}
POI: {{POIS}}

Только JSON, русский.
PROMPT;
    }

    public static function defaultChainRebuildSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — ФИНАЛЬНЫЙ АРБИТР и REBUILDER маршрута.

Дан ROUTE V1 + findings слоёв geo, day, coverage.
Собери ROUTE V2: тот же формат JSON тура, что у архитектора.

Правила арбитража:
1) critical/major из coverage (missing_nearby) применяй только для сильной ACTIVITY-связки (не base), если день не станет overload после day-аудита; add_if_light/optional не раздувай в обязательный enrich;
2) при конфликте «добавь» vs «день тяжёлый» — сначала trim/rebalance, потом enrich;
3) geo-крюки — убирай/переноси;
4) не выдумывай id вне каталога;
5) не максимизируй total_hours;
6) весь текст по-русски;
7) сохрани сильную макроось V1, если findings не требуют её ломать.

Ответ — ТОЛЬКО JSON плана V2 (title, days[], notes), без markdown.
Формат дня как у архитектора: date, theme, summary, overnight, places[{id,name,hours,role,note?}], total_hours.
PROMPT;
    }

    public static function defaultChainRebuildUserPrompt(): string
    {
        return <<<'PROMPT'
Собери ROUTE V2.

ЗАПРОС: {{QUERY}}
ДНЕЙ: {{DAYS}}
ROUTE V1: {{PLAN}}
FINDINGS (geo/day/coverage): {{FINDINGS}}
КАТАЛОГ POI: {{POIS}}
ИСТОРИЧЕСКАЯ ЛОГИКА (не готовые POI): {{SAMPLES}}

Только JSON плана V2, русский.
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return list<array<string, mixed>>
     */
    private function normalizePois(array $locations): array
    {
        $out = [];
        $seen = [];
        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => trim((string) ($row['name'] ?? '')),
                'country' => (string) ($row['country'] ?? ''),
                'region' => (string) ($row['region'] ?? ''),
                'lat' => isset($row['lat']) && is_numeric($row['lat']) ? (float) $row['lat'] : null,
                'lng' => isset($row['lng']) && is_numeric($row['lng']) ? (float) $row['lng'] : null,
                'role' => (string) ($row['role'] ?? ''),
                'is_base' => (bool) ($row['is_base'] ?? false),
                'snippet' => mb_substr(trim((string) ($row['snippet'] ?? '')), 0, 180),
                'block' => (string) ($row['block'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  list<RouteSkeleton>  $skeletons
     * @return list<array<string, mixed>>
     */
    private function normalizeSamples(array $skeletons): array
    {
        $out = [];
        foreach ($skeletons as $s) {
            if (! $s instanceof RouteSkeleton) {
                continue;
            }
            $s->loadMissing('days');
            $out[] = [
                'id' => (int) $s->id,
                'source_route_id' => (int) ($s->source_route_id ?? 0),
                'title' => $s->displayTitle(true) ?: (string) ($s->title ?? ''),
                'days_count' => (int) ($s->days_count ?? 0),
                'brief' => $s->toBriefText(true),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $pois
     * @param  list<array<string, mixed>>  $samples
     */
    private function fillPrompt(
        string $template,
        string $query,
        int $days,
        array $pois,
        array $samples,
        string $planJson = '',
        string $findingsJson = '',
        string $transfersBlock = '',
    ): string {
        $poiLines = [];
        foreach ($pois as $p) {
            $poiLines[] = sprintf(
                '- id=%d | %s | role=%s | base=%s | duration=%sч | load=%s | %s,%s | %s',
                $p['id'],
                $p['name'],
                $p['role'] !== '' ? $p['role'] : '—',
                $p['is_base'] ? 'yes' : 'no',
                $p['poi_duration'] ?? '?',
                $p['physical_load'] ?? '?',
                $p['lat'] ?? '?',
                $p['lng'] ?? '?',
                $p['snippet'] !== '' ? $p['snippet'] : '—'
            );
        }

        $sampleBlocks = [];
        foreach ($samples as $i => $s) {
            if (! is_array($s)) {
                continue;
            }
            $sampleBlocks[] = '=== ОБРАЗЕЦ '.($i + 1).': '.($s['title'] ?? '')
                .' (source_route_id='.($s['source_route_id'] ?? $s['id'] ?? '').', '
                .($s['days_count'] ?? '?').' дн.) ==='."\n"
                .($s['brief'] ?? '');
        }
        $samplesText = $sampleBlocks !== []
            ? implode("\n\n", $sampleBlocks)
            : '(похожих полных туров директора не найдено — опирайся на здравый смысл и список POI)';

        return str_replace(
            ['{{QUERY}}', '{{DAYS}}', '{{SAMPLES}}', '{{POIS}}', '{{PLAN}}', '{{FINDINGS}}', '{{TRANSFERS}}'],
            [$query, (string) $days, $samplesText, implode("\n", $poiLines), $planJson, $findingsJson, $transfersBlock],
            $template
        );
    }

    /**
     * @return array{text: string, finish_reason: string}
     */
    private function callGemini(
        string $system,
        string $userMessage,
        float $temperature = 0.55,
        int $maxOutputTokens = 65536,
    ): array {
        $model = trim((string) config('services.gemini.model', 'gemini-2.0-flash'));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));
        $maxOutputTokens = max(2048, $maxOutputTokens);

        return $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $system, $userMessage, $temperature, $maxOutputTokens) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':generateContent?key='.$apiKey;

            $payload = [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => mb_substr($userMessage, 0, 100000)]],
                ]],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxOutputTokens,
                    'responseMimeType' => 'application/json',
                ],
                'systemInstruction' => [
                    'parts' => [['text' => mb_substr($system, 0, 30000)]],
                ],
            ];
            // gemini-3 thinking съедает maxOutputTokens и иногда кладёт thought в parts —
            // для длинного JSON маршрута thinking не включаем; thought-parts всё равно отфильтровываем.

            $wait = 2;
            $last = null;
            for ($i = 1; $i <= 4; $i++) {
                $response = Http::timeout($timeout)->acceptJson()->asJson()->post($url, $payload);
                if ($response->successful()) {
                    $parts = $response->json('candidates.0.content.parts') ?? [];
                    $finishReason = (string) ($response->json('candidates.0.finishReason') ?? '');
                    $chunks = [];
                    if (is_array($parts)) {
                        foreach ($parts as $part) {
                            if (! is_array($part) || ! isset($part['text'])) {
                                continue;
                            }
                            // gemini-3: не склеивать thought-текст с JSON ответа
                            if (! empty($part['thought'])) {
                                continue;
                            }
                            $chunks[] = (string) $part['text'];
                        }
                    }
                    $text = trim(implode("\n", $chunks));
                    if ($text === '') {
                        throw new RuntimeException(
                            'Empty Gemini scenario reply'
                            .($finishReason !== '' ? ' (finishReason='.$finishReason.')' : '')
                        );
                    }
                    if ($finishReason !== '' && $finishReason !== 'STOP') {
                        Log::warning('gemini_scenario_finish', [
                            'finish_reason' => $finishReason,
                            'raw_len' => mb_strlen($text),
                        ]);
                    }

                    return [
                        'text' => $text,
                        'finish_reason' => $finishReason,
                    ];
                }
                $last = 'Gemini scenario HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400);
                if ($response->status() === 429 && $i < 4) {
                    sleep($wait);
                    $wait = min(30, $wait * 2);

                    continue;
                }
                throw new RuntimeException($last);
            }
            throw new RuntimeException($last ?? 'Gemini scenario failed');
        });
    }

    /**
     * Fallback duration из текущего каталога: missing/null/≤0 hours → poi_duration.
     * Не добавляет POI; не меняет poi_duration в каталоге; hours>0 от Gemini сохраняет.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $pois
     * @return array<string, mixed>
     */
    private function backfillPlanHoursFromCatalog(array $plan, array $pois): array
    {
        $durById = [];
        foreach ($pois as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (is_numeric($p['poi_duration'] ?? null)) {
                $durById[$id] = (float) $p['poi_duration'];
            }
        }

        $days = is_array($plan['days'] ?? null) ? $plan['days'] : [];
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $places = is_array($day['places'] ?? null) ? $day['places'] : [];
            $sum = 0.0;
            foreach ($places as $j => $place) {
                if (! is_array($place)) {
                    continue;
                }
                $pid = (int) ($place['id'] ?? 0);
                $hours = null;
                if (isset($place['hours']) && is_numeric($place['hours'])) {
                    $hours = (float) $place['hours'];
                }
                if (($hours === null || $hours <= 0.0) && $pid > 0 && isset($durById[$pid]) && $durById[$pid] > 0) {
                    $hours = $durById[$pid];
                    $places[$j]['hours'] = round($hours, 1);
                }
                if ($hours !== null && $hours > 0) {
                    $sum += $hours;
                }
            }
            $days[$i]['places'] = $places;
            $days[$i]['total_hours'] = round($sum, 1);
        }
        $plan['days'] = $days;

        return $plan;
    }

    /**
     * Если Gemini забыла overnight — восстановить из place role=base / каталога / предыдущего дня.
     * Не выдумывает новые id вне плана/каталога.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $pois
     * @return array<string, mixed>
     */
    private function backfillOvernightInPlan(array $plan, array $pois): array
    {
        $meta = [];
        foreach ($pois as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $meta[$id] = [
                'name' => (string) ($p['name'] ?? ''),
                'is_base' => ! empty($p['is_base']) || (($p['role'] ?? '') === 'base'),
            ];
        }

        $days = is_array($plan['days'] ?? null) ? $plan['days'] : [];
        $prev = null;
        foreach ($days as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $ov = is_array($day['overnight'] ?? null) ? $day['overnight'] : null;
            $oid = is_array($ov) ? (int) ($ov['id'] ?? 0) : 0;
            $oname = is_array($ov) ? trim((string) ($ov['name'] ?? '')) : '';

            if ($oid <= 0) {
                $places = is_array($day['places'] ?? null) ? $day['places'] : [];
                foreach ($places as $place) {
                    if (! is_array($place)) {
                        continue;
                    }
                    $pid = (int) ($place['id'] ?? 0);
                    if ($pid <= 0) {
                        continue;
                    }
                    $role = (string) ($place['role'] ?? '');
                    if ($role === 'base' || ! empty($meta[$pid]['is_base'])) {
                        $oid = $pid;
                        if ($oname === '') {
                            $oname = trim((string) ($place['name'] ?? ''));
                        }
                        break;
                    }
                }
            }

            if ($oid <= 0 && is_array($prev)) {
                $oid = (int) ($prev['id'] ?? 0);
                if ($oname === '') {
                    $oname = trim((string) ($prev['name'] ?? ''));
                }
            }

            if ($oid > 0) {
                if ($oname === '' && isset($meta[$oid]['name']) && $meta[$oid]['name'] !== '') {
                    $oname = $meta[$oid]['name'];
                }
                if ($oname === '') {
                    $oname = '#'.$oid;
                }
                $days[$i]['overnight'] = ['id' => $oid, 'name' => $oname];
                $prev = $days[$i]['overnight'];
            } elseif (is_array($prev)) {
                $days[$i]['overnight'] = $prev;
            }
        }
        $plan['days'] = $days;

        return $plan;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonPlan(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        // на случай обёртки ```json
        if (preg_match('/```(?:json)?\s*([\[{].*?[\]}])\s*```/us', $text, $m)) {
            $text = trim($m[1]);
        }
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            // массив дней [...] или объект {...}
            $startObj = strpos($text, '{');
            $startArr = strpos($text, '[');
            if ($startArr !== false && ($startObj === false || $startArr < $startObj)) {
                $start = $startArr;
                $end = strrpos($text, ']');
            } else {
                $start = $startObj;
                $end = strrpos($text, '}');
            }
            if ($start === false || $end === false || $end <= $start) {
                Log::warning('gemini_scenario_json: '.$e->getMessage());

                return null;
            }
            try {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e2) {
                Log::warning('gemini_scenario_json2: '.$e2->getMessage());

                return null;
            }
        }

        if (! is_array($decoded)) {
            return null;
        }

        // Gemini иногда отдаёт сразу массив дней вместо { title, days: [...] }
        return $this->normalizePlanShape($decoded);
    }

    /**
     * @param  array<mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizePlanShape(array $decoded): array
    {
        if ($this->looksLikeDaysList($decoded)) {
            return [
                'title' => '',
                'days' => array_values($decoded),
                'notes' => [],
            ];
        }

        // { "route": [ days ] } / { "plan": { "days": ... } } — редкие обёртки
        if (! isset($decoded['days']) || ! is_array($decoded['days'])) {
            foreach (['route', 'plan', 'itinerary', 'scenario'] as $key) {
                $inner = $decoded[$key] ?? null;
                if ($this->looksLikeDaysList(is_array($inner) ? $inner : [])) {
                    $decoded['days'] = array_values($inner);
                    unset($decoded[$key]);
                    break;
                }
                if (is_array($inner) && isset($inner['days']) && is_array($inner['days'])) {
                    return $this->normalizePlanShape($inner);
                }
            }
        }

        return $decoded;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function looksLikeDaysList(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }
        $first = $value[0] ?? null;
        if (! is_array($first)) {
            return false;
        }

        return isset($first['day'])
            || isset($first['date'])
            || isset($first['places'])
            || isset($first['theme'])
            || isset($first['overnight']);
    }
}
