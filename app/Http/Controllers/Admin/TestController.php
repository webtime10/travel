<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\GeminiChatService;
use App\Services\GeminiKeyPool;
use App\Services\GeminiScenarioPlannerService;
use App\Services\LocationCandidateFilter;
use App\Services\LocationDayPacker;
use App\Services\LocationPlanValidator;
use App\Services\RagDatabaseService;
use App\Services\RagLocationSearchService;
use App\Services\RouteAuditPipeline;
use App\Services\RouteSkeletonMatcher;
use App\Services\RouteSkeletonRefiner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestController extends Controller
{
    public const LOCATIONS_PROMPT_KEY = 'test.locations_system_prompt';

    public const SCENARIO_SYSTEM_PROMPT_KEY = 'test.scenario_system_prompt';

    public const SCENARIO_USER_PROMPT_KEY = 'test.scenario_user_prompt';

    public const SCENARIO_AUDIT_SYSTEM_PROMPT_KEY = 'test.scenario_audit_system_prompt';

    public const SCENARIO_AUDIT_USER_PROMPT_KEY = 'test.scenario_audit_user_prompt';

    public const CHAIN_PROMPT_KEYS = [
        'architect_system' => 'test.chain.architect_system',
        'architect_user' => 'test.chain.architect_user',
        'geo_system' => 'test.chain.geo_system',
        'geo_user' => 'test.chain.geo_user',
        'day_system' => 'test.chain.day_system',
        'day_user' => 'test.chain.day_user',
        'coverage_system' => 'test.chain.coverage_system',
        'coverage_user' => 'test.chain.coverage_user',
        'rebuild_system' => 'test.chain.rebuild_system',
        'rebuild_user' => 'test.chain.rebuild_user',
    ];

    public function index(GeminiKeyPool $keys, RagDatabaseService $ragDb)
    {
        $indexed = 0;
        try {
            $indexed = $ragDb->indexedLocationCount();
        } catch (Throwable) {
        }

        return view('admin.test.index', [
            'pageTitle' => 'Test',
            'geminiKeys' => $keys->count(),
            'indexed' => $indexed,
            'testMode' => 'index',
        ]);
    }

    public function locations(GeminiKeyPool $keys, RagDatabaseService $ragDb)
    {
        $indexed = 0;
        try {
            $indexed = $ragDb->indexedLocationCount();
        } catch (Throwable) {
        }

        $systemPrompt = AppSetting::getValue(self::LOCATIONS_PROMPT_KEY);
        $fresh = $this->defaultLocationsPrompt();
        if ($systemPrompt === null || trim($systemPrompt) === ''
            || ! str_contains((string) $systemPrompt, 'ПЛАН ДНЕЙ уже готов')
            || ! str_contains((string) $systemPrompt, 'не меняй id')) {
            AppSetting::putValue(self::LOCATIONS_PROMPT_KEY, $fresh);
            $systemPrompt = $fresh;
        }

        return view('admin.test.locations', [
            'pageTitle' => 'Test — Поиск мест',
            'geminiKeys' => $keys->count(),
            'indexed' => $indexed,
            'systemPrompt' => $systemPrompt,
            'testMode' => 'locations',
        ]);
    }

    public function scenario(GeminiKeyPool $keys, RagDatabaseService $ragDb)
    {
        $indexed = 0;
        try {
            $indexed = $ragDb->indexedLocationCount();
        } catch (Throwable) {
        }

        [$systemPrompt, $userPrompt] = $this->scenarioPrompts();
        [$auditSystemPrompt, $auditUserPrompt] = $this->scenarioAuditPrompts();

        return view('admin.test.scenario', [
            'pageTitle' => 'Test — Gemini сценарий',
            'geminiKeys' => $keys->count(),
            'indexed' => $indexed,
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $userPrompt,
            'defaultSystemPrompt' => GeminiScenarioPlannerService::defaultSystemPrompt(),
            'defaultUserPrompt' => GeminiScenarioPlannerService::defaultUserPrompt(),
            'auditSystemPrompt' => $auditSystemPrompt,
            'auditUserPrompt' => $auditUserPrompt,
            'defaultAuditSystemPrompt' => GeminiScenarioPlannerService::defaultAuditSystemPrompt(),
            'defaultAuditUserPrompt' => GeminiScenarioPlannerService::defaultAuditUserPrompt(),
            'testMode' => 'scenario',
        ]);
    }

    public function scenarioPlan(Request $request, GeminiScenarioPlannerService $planner): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:8000',
            'days' => 'nullable|integer|min:5|max:14',
            'poi_limit' => 'nullable|integer|min:12|max:60',
            'sample_limit' => 'nullable|integer|min:3|max:5',
            'system_prompt' => 'nullable|string|max:50000',
            'user_prompt' => 'nullable|string|max:50000',
        ]);

        @set_time_limit(900);

        [$defaultSystem, $defaultUser] = $this->scenarioPrompts();
        $systemPrompt = trim((string) $request->input('system_prompt', ''));
        $userPrompt = trim((string) $request->input('user_prompt', ''));
        if ($systemPrompt === '') {
            $systemPrompt = $defaultSystem;
        }
        if ($userPrompt === '') {
            $userPrompt = $defaultUser;
        }

        try {
            $result = $planner->plan(
                (string) $request->input('message'),
                (int) $request->input('days', 10),
                (int) $request->input('poi_limit', 48),
                (int) $request->input('sample_limit', 5),
                [
                    'system_prompt' => $systemPrompt,
                    'user_prompt' => $userPrompt,
                ],
            );
        } catch (Throwable $e) {
            return $this->errorResponse('scenario', $e);
        }

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'step' => 'scenario',
            'query' => $result['query'] ?? '',
            'days_requested' => $result['days_requested'] ?? 10,
            'pois' => $result['pois'] ?? [],
            'samples' => $result['samples'] ?? [],
            'plan' => $result['plan'] ?? null,
            'raw_text' => $result['raw_text'] ?? '',
            'gemini_ms' => $result['gemini_ms'] ?? 0,
            'search_ms' => $result['search_ms'] ?? 0,
            'transfer_geo' => $result['transfer_geo'] ?? null,
            'message' => $result['message'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    public function scenarioAudit(Request $request, GeminiScenarioPlannerService $planner): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:8000',
            'plan' => 'required|array',
            'pois' => 'required|array|min:1|max:80',
            'pois.*.id' => 'required|integer',
            'samples' => 'nullable|array|max:8',
            'system_prompt' => 'nullable|string|max:50000',
            'user_prompt' => 'nullable|string|max:50000',
        ]);

        @set_time_limit(900);

        [$defaultSystem, $defaultUser] = $this->scenarioAuditPrompts();
        $systemPrompt = trim((string) $request->input('system_prompt', ''));
        $userPrompt = trim((string) $request->input('user_prompt', ''));
        if ($systemPrompt === '') {
            $systemPrompt = $defaultSystem;
        }
        if ($userPrompt === '') {
            $userPrompt = $defaultUser;
        }

        try {
            $result = $planner->audit(
                (string) $request->input('message'),
                (array) $request->input('plan', []),
                (array) $request->input('pois', []),
                (array) $request->input('samples', []),
                [
                    'system_prompt' => $systemPrompt,
                    'user_prompt' => $userPrompt,
                ],
            );
        } catch (Throwable $e) {
            return $this->errorResponse('scenario_audit', $e);
        }

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'step' => 'scenario_audit',
            'audit' => $result['audit'] ?? null,
            'raw_text' => $result['raw_text'] ?? '',
            'gemini_ms' => $result['gemini_ms'] ?? 0,
            'message' => $result['message'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    public function chain(GeminiKeyPool $keys, RagDatabaseService $ragDb)
    {
        $indexed = 0;
        try {
            $indexed = $ragDb->indexedLocationCount();
        } catch (Throwable) {
        }

        $prompts = $this->chainPrompts();

        return view('admin.test.chain', [
            'pageTitle' => 'Test 3 — Цепочка',
            'geminiKeys' => $keys->count(),
            'indexed' => $indexed,
            'prompts' => $prompts,
            'defaults' => $this->chainPromptDefaults(),
            'testMode' => 'chain',
        ]);
    }

    public function chainRun(Request $request, GeminiScenarioPlannerService $planner): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:8000',
            'days' => 'nullable|integer|min:5|max:14',
            'poi_limit' => 'nullable|integer|min:12|max:60',
            'sample_limit' => 'nullable|integer|min:3|max:5',
            'prompts' => 'nullable|array',
        ]);

        @set_time_limit(1200);

        $stored = $this->chainPrompts();
        $incoming = (array) $request->input('prompts', []);
        $prompts = [];
        foreach (array_keys(self::CHAIN_PROMPT_KEYS) as $key) {
            $v = trim((string) ($incoming[$key] ?? ''));
            $prompts[$key] = $v !== '' ? $v : ($stored[$key] ?? '');
        }

        try {
            $result = $planner->runChain(
                (string) $request->input('message'),
                (int) $request->input('days', 10),
                (int) $request->input('poi_limit', 48),
                (int) $request->input('sample_limit', 5),
                $prompts,
            );
        } catch (Throwable $e) {
            return $this->errorResponse('chain', $e);
        }

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'step' => 'chain',
            'query' => $result['query'] ?? '',
            'pois' => $result['pois'] ?? [],
            'samples' => $result['samples'] ?? [],
            'plan_v1' => $result['plan_v1'] ?? null,
            'audits' => $result['audits'] ?? [],
            'plan_v2' => $result['plan_v2'] ?? null,
            'steps_ms' => $result['steps_ms'] ?? [],
            'raw' => $result['raw'] ?? [],
            'message' => $result['message'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    public function locationsSearch(Request $request, RagLocationSearchService $search): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:8000',
            'limit' => 'nullable|integer|min:1|max:48',
        ]);

        @set_time_limit(180);

        try {
            $result = $search->search((string) $request->input('message'), (int) $request->input('limit', 36));
        } catch (Throwable $e) {
            return $this->errorResponse('locations', $e);
        }

        return response()->json([
            'ok' => true,
            'step' => 'locations',
            'locations' => $result['locations'],
            'filters' => $result['filters'],
            'day_plan' => $result['filters']['day_plan'] ?? [],
            'hits' => count($result['locations']),
            'scanned' => $result['scanned'],
            'candidates' => $result['candidates'],
            'embed_ms' => $result['embed_ms'],
            'search_ms' => $result['search_ms'],
        ]);
    }

    public function locationsAssemble(
        Request $request,
        GeminiChatService $chat,
        LocationDayPacker $packer,
        LocationCandidateFilter $filter,
        LocationPlanValidator $validator,
        RouteSkeletonMatcher $skeletons,
        RouteSkeletonRefiner $refiner,
        RouteAuditPipeline $auditPipeline,
    ): JsonResponse {
        $request->validate([
            'message' => 'required|string|max:8000',
            'prompt' => 'nullable|string|max:50000',
            'locations' => 'required|array|min:1|max:80',
            'locations.*.id' => 'required|integer',
            'locations.*.name' => 'nullable|string',
            'locations.*.country' => 'nullable|string',
            'locations.*.region' => 'nullable|string',
            'locations.*.lat' => 'nullable|numeric',
            'locations.*.lng' => 'nullable|numeric',
            'locations.*.snippet' => 'nullable|string',
            'locations.*.block' => 'nullable|string',
            'locations.*.block_label' => 'nullable|string',
            'locations.*.role' => 'nullable|string',
            'locations.*.is_base' => 'nullable|boolean',
            'locations.*.date_from' => 'nullable|string',
            'locations.*.date_to' => 'nullable|string',
            'day_plan' => 'nullable|array',
            'day_plan.*' => 'nullable|string',
        ]);

        @set_time_limit(600);

        $prompt = trim((string) $request->input('prompt', ''));
        if ($prompt === '' || ! str_contains($prompt, 'ПЛАН ДНЕЙ') || ! str_contains($prompt, 'не меняй id')) {
            $stored = AppSetting::getValue(self::LOCATIONS_PROMPT_KEY);
            $prompt = ($stored !== null && trim($stored) !== ''
                && str_contains((string) $stored, 'ПЛАН ДНЕЙ')
                && str_contains((string) $stored, 'не меняй id'))
                ? $stored
                : $this->defaultLocationsPrompt();
        }

        $message = (string) $request->input('message');
        $locations = (array) $request->input('locations', []);
        $filtered = $filter->filter($locations);
        $locations = $filtered['locations'];
        $dayPlan = array_values(array_filter((array) $request->input('day_plan', []), fn ($l) => is_string($l) && trim($l) !== ''));
        $packed = $packer->pack($locations, $message);
        if (($packed['day_plan'] ?? []) !== []) {
            $locations = $packed['locations'];
            $dayPlan = $packed['day_plan'];
        }

        $dayCount = max(1, count($dayPlan));
        $similar = $skeletons->findSimilar($message, $dayCount, 8, $dayPlan);
        $refined = $refiner->refine($dayPlan, $locations, $similar, $message);
        $dayPlan = $refined['day_plan'] !== [] ? $refined['day_plan'] : $dayPlan;
        $locations = $refined['locations'];
        $refineNotes = $refined['notes'];
        $provenance = is_array($refined['provenance'] ?? null) ? $refined['provenance'] : [];

        $skeletonBlock = $skeletons->formatForPrompt($similar);
        if ($refineNotes !== []) {
            $skeletonBlock = ($skeletonBlock !== '' ? $skeletonBlock."\n\n" : '')
                .'Правки по образцам/правилам:'."\n- ".implode("\n- ", $refineNotes);
        }

        $context = $this->formatLocationsContext($locations, $dayPlan, $skeletonBlock);
        if ($context === '') {
            return response()->json(['ok' => false, 'message' => 'Пустой список мест'], 422);
        }

        // Канон по id — PHP refine. На экран — русский (Gemini переводит имена; если ломает структуру — PHP-имена).
        $canonicalPlan = implode("\n", $dayPlan);
        $chatMs = 0;
        $reply = $canonicalPlan;
        $planSource = 'php_refined';

        try {
            $t0 = microtime(true);
            $geminiReply = $chat->reply(
                $message,
                [],
                $prompt,
                $context,
                true,
            );
            $chatMs = (int) round((microtime(true) - $t0) * 1000);
            if ($this->samePlanStructure((string) $geminiReply, $dayPlan)) {
                $reply = trim((string) $geminiReply);
                $dayPlan = $this->linesFromPlanText($reply);
                $planSource = 'gemini_translate_ru';
            } else {
                $refineNotes[] = 'модель меняла дни/id — показан PHP-план с русскими именами';
                $planSource = 'php_refined_ru';
            }
        } catch (Throwable $e) {
            $refineNotes[] = 'перевод Gemini недоступен — PHP-план с русскими именами';
            $planSource = 'php_refined_ru';
            $chatMs = 0;
            Log::warning('locations_assemble ru fallback: '.$e->getMessage());
        }

        // Всегда русские имена для экрана (иврит в meta не показываем)
        $dayPlan = $this->planWithRussianNames($dayPlan, $locations);
        $reply = implode("\n", $dayPlan);

        $checked = $validator->validate((string) $reply, $locations, $dayPlan);
        if ($dayPlan !== []) {
            $planCheck = $validator->validate(implode("\n", $dayPlan), $locations, $dayPlan);
            $merged = array_merge($planCheck['findings'] ?? [], $checked['findings'] ?? []);
            $seen = [];
            $unique = [];
            foreach ($merged as $f) {
                $key = ($f['severity'] ?? '').'|'.($f['date'] ?? '').'|'.($f['problem'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $f;
            }
            $checked['findings'] = $unique;
            $timeReport = (string) ($planCheck['time_report'] ?? $checked['time_report'] ?? '');
            $findingsReport = $this->buildValidatorReport($unique);
            $checked['report'] = trim($timeReport !== '' ? $timeReport."\n\n".$findingsReport : $findingsReport);
            $checked['time_report'] = $timeReport;
            $checked['day_budgets'] = $planCheck['day_budgets'] ?? ($checked['day_budgets'] ?? []);
            $checked['ok'] = ! array_filter(
                $unique,
                fn ($f) => in_array($f['severity'] ?? '', ['CRITICAL', 'MAJOR'], true)
            );
            $checked['issues'] = array_map(
                fn ($f) => '['.$f['severity'].'] '.$f['date'].': '.$f['problem'],
                $unique
            );
        }

        // Critic → Evidence → Refiner (только flagged дни) → re-validate
        $auditResult = null;
        $visualReport = [];
        $humanReport = null;
        $planBeforeAudit = $dayPlan;
        try {
            $auditResult = $auditPipeline->run(
                $dayPlan,
                $locations,
                $similar,
                $checked['day_budgets'] ?? [],
            );
            $planBeforeAudit = $this->planWithRussianNames(
                $auditResult['plan_before_audit'] ?? $dayPlan,
                $locations
            );
            if (($auditResult['changed_days'] ?? []) !== []) {
                $dayPlan = $auditResult['day_plan'];
                $locations = array_values(array_merge(
                    $locations,
                    is_array($auditResult['locations'] ?? null) ? $auditResult['locations'] : []
                ));
                $refineNotes = array_merge($refineNotes, $auditResult['refine_notes'] ?? []);
                $after = $auditResult['validation_after'];
                if (is_array($after)) {
                    $checked = $after;
                }
            } elseif (($auditResult['refine_notes'] ?? []) !== []) {
                $refineNotes = array_merge($refineNotes, $auditResult['refine_notes']);
            }
            // После audit снова русские имена (новые id из Evidence часто на иврите)
            $dayPlan = $this->planWithRussianNames($dayPlan, $locations);
            $reply = implode("\n", $dayPlan);
            $visualReport = $this->visualReportWithRussianNames(
                $auditResult['visual_report'] ?? ($auditResult['pipeline_log']['visual_report'] ?? []),
                $locations,
                $planBeforeAudit,
                $dayPlan
            );
            $provenance = $this->provenanceWithRussianNames($provenance, $locations);
            $humanReport = $auditPipeline->buildHumanDirectorReport(
                $visualReport,
                $planBeforeAudit,
                $dayPlan,
                $provenance
            );
            if (is_array($auditResult['pipeline_log'] ?? null)) {
                $auditResult['pipeline_log']['visual_report'] = $visualReport;
                $auditResult['pipeline_log']['human_report'] = $humanReport;
                $auditResult['pipeline_log']['plan_before_audit'] = $planBeforeAudit;
                $auditResult['pipeline_log']['plan_after_audit'] = $dayPlan;
            }
            $auditResult['human_report'] = $humanReport;
        } catch (Throwable $e) {
            Log::warning('route_audit_pipeline: '.$e->getMessage());
            $refineNotes[] = 'audit pipeline: '.$e->getMessage();
            $dayPlan = $this->planWithRussianNames($dayPlan, $locations);
            $reply = implode("\n", $dayPlan);
            $provenance = $this->provenanceWithRussianNames($provenance, $locations);
            $humanReport = $auditPipeline->buildHumanDirectorReport([], $dayPlan, $dayPlan, $provenance);
        }

        return response()->json([
            'ok' => true,
            'step' => 'locations_assemble',
            'reply' => $reply,
            'validator_ok' => $checked['ok'],
            'validator_issues' => $checked['issues'],
            'validator_findings' => $checked['findings'] ?? [],
            'validator_report' => $checked['report'] ?? ($checked['ok'] ? 'validator: ok' : ''),
            'validator_time_report' => $checked['time_report'] ?? '',
            'day_budgets' => $checked['day_budgets'] ?? [],
            'day_plan' => $dayPlan,
            'skeleton_ids' => array_map(
                fn ($s) => [
                    'id' => $s->id,
                    'source_route_id' => $s->source_route_id,
                    'title' => $s->displayTitle(true) ?: $s->title,
                    'days_count' => $s->days_count,
                ],
                $similar
            ),
            'refine_notes' => $refineNotes,
            'audit_status' => $auditResult['audit']['status'] ?? null,
            'audit_source' => $auditResult['audit']['source'] ?? null,
            'audit_issues' => $auditResult['audit']['issues'] ?? [],
            'audit_changed_days' => $auditResult['changed_days'] ?? [],
            'audit_skipped' => $auditResult['skipped'] ?? [],
            'audit_suggestions' => $auditResult['suggestions'] ?? ($auditResult['pipeline_log']['suggestions'] ?? []),
            'audit_trace' => $auditResult['audit_trace'] ?? ($auditResult['pipeline_log']['audit_trace'] ?? []),
            'audit_result' => $auditResult['audit_result'] ?? ($auditResult['pipeline_log']['audit_result'] ?? []),
            'audit_result_text' => $auditResult['audit_result_text']
                ?? ($auditResult['pipeline_log']['audit_result_text'] ?? null),
            'plan_before_audit' => $planBeforeAudit,
            'visual_report' => $visualReport,
            'human_report' => $humanReport,
            'audit_evidence' => array_map(
                fn ($cands) => array_map(
                    function ($c) {
                        $id = (int) ($c['id'] ?? 0);

                        return [
                            'id' => $id > 0 ? $id : null,
                            'name' => $id > 0
                                ? $this->readableRuName((string) ($c['name'] ?? ''), $id)
                                : (string) ($c['name'] ?? ''),
                            'hours' => $c['hours'] ?? null,
                            'historical_score' => $c['historical_score'] ?? null,
                            'detour_km' => $c['detour_km'] ?? null,
                        ];
                    },
                    $cands
                ),
                $auditResult['evidence_by_day'] ?? []
            ),
            'audit_pipeline' => $auditResult['pipeline_log'] ?? null,
            'plan_source' => $planSource,
            'provenance' => $provenance,
            'dropped' => $filtered['dropped'],
            'chat_ms' => $chatMs,
            'context_chars' => mb_strlen($context),
        ]);
    }

    /**
     * Имена в provenance — русские для директора.
     *
     * @param  array<string, mixed>  $provenance
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, mixed>
     */
    private function provenanceWithRussianNames(array $provenance, array $locations): array
    {
        $days = is_array($provenance['days'] ?? null) ? $provenance['days'] : [];
        foreach ($days as &$pd) {
            foreach (['construction', 'catalog'] as $key) {
                $list = is_array($pd[$key] ?? null) ? $pd[$key] : [];
                foreach ($list as &$p) {
                    $id = (int) ($p['id'] ?? 0);
                    $name = (string) ($p['name'] ?? '');
                    if ($id > 0) {
                        $p['name'] = $this->readableRuName($name, $id);
                    }
                }
                unset($p);
                $pd[$key] = $list;
            }
        }
        unset($pd);
        $provenance['days'] = $days;

        return $provenance;
    }

    /**
     * @return list<string>
     */
    private function linesFromPlanText(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^\d{1,2}\.\d{1,2}\s*[—\-–]/u', $line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Цветной visual_report — те же русские имена по id.
     *
     * @param  list<array<string, mixed>>  $report
     * @param  list<array<string, mixed>>  $locations
     * @param  list<string>  $beforeRu
     * @param  list<string>  $afterRu
     * @return list<array<string, mixed>>
     */
    private function visualReportWithRussianNames(
        array $report,
        array $locations,
        array $beforeRu,
        array $afterRu,
    ): array {
        $beforeBy = [];
        foreach ($beforeRu as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $beforeBy[$m[1]] = $line;
            }
        }
        $afterBy = [];
        foreach ($afterRu as $line) {
            if (preg_match('/^(\d{2}\.\d{2})/u', $line, $m)) {
                $afterBy[$m[1]] = $line;
            }
        }

        foreach ($report as &$day) {
            $date = (string) ($day['date'] ?? '');
            if ($date !== '' && isset($beforeBy[$date])) {
                $day['line_before'] = $beforeBy[$date];
            } elseif (! empty($day['line_before'])) {
                $day['line_before'] = $this->planWithRussianNames([(string) $day['line_before']], $locations)[0] ?? $day['line_before'];
            }
            if ($date !== '' && isset($afterBy[$date])) {
                $day['line_after'] = $afterBy[$date];
            } elseif (! empty($day['line_after'])) {
                $day['line_after'] = $this->planWithRussianNames([(string) $day['line_after']], $locations)[0] ?? $day['line_after'];
            }
            if (! empty($day['fix']) && is_array($day['fix'])) {
                $fid = (int) ($day['fix']['id'] ?? $day['fix']['picked_id'] ?? $day['fix']['to_id'] ?? 0);
                if ($fid > 0) {
                    $day['fix']['name'] = $this->readableRuName((string) ($day['fix']['name'] ?? ''), $fid);
                } elseif (! empty($day['fix']['name'])) {
                    $day['fix']['name'] = $this->readableRuName((string) $day['fix']['name'], 0);
                }
            }
            foreach (['candidates', 'rejected'] as $key) {
                if (empty($day[$key]) || ! is_array($day[$key])) {
                    continue;
                }
                foreach ($day[$key] as &$c) {
                    $id = (int) ($c['id'] ?? 0);
                    if ($id > 0) {
                        $c['name'] = $this->readableRuName((string) ($c['name'] ?? ''), $id);
                    } elseif (! empty($c['name'])) {
                        $c['name'] = $this->readableRuName((string) $c['name'], 0);
                    }
                }
                unset($c);
            }
        }
        unset($day);

        return $report;
    }

    /**
     * Подставить русские/латинские читаемые имена, id не трогать.
     *
     * @param  list<string>  $dayPlan
     * @param  list<array<string, mixed>>  $locations
     * @return list<string>
     */
    private function planWithRussianNames(array $dayPlan, array $locations): array
    {
        $ruById = [];
        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ruById[$id] = $this->readableRuName((string) ($row['name'] ?? ''), $id);
        }

        $rename = function (string $rawName, int $id) use (&$ruById): string {
            if (! isset($ruById[$id])) {
                $ruById[$id] = $this->readableRuName(trim($rawName), $id);
            }

            return $ruById[$id].' [id='.$id.']';
        };

        $renamePlace = function (string $rawName, int $id) use (&$ruById): string {
            // Снять старый (~N ч) / «Прогулка у…» перед переводом имени
            $core = trim($rawName);
            $core = preg_replace('/\s*\(~\s*[\d.,]+\s*ч\)\s*$/u', '', $core) ?? $core;
            $core = preg_replace('/^(?:Время\s+на\s+(?:озере\s+)?|Прогулка\s+у\s+(?:озера\s+)?|Посещение\s+(?:озера\s+)?|Водопад\s+|Прогулка\s+по\s+)/ui', '', $core) ?? $core;
            $core = preg_replace('/\s*\(прогулка\s+у\s+воды\)\s*/ui', '', $core) ?? $core;
            if (! isset($ruById[$id])) {
                $ruById[$id] = $this->readableRuName(trim($core), $id);
            }
            $phrase = $this->humanActivityPhrase($ruById[$id], $id);

            return $phrase.' [id='.$id.']';
        };

        $out = [];
        foreach ($dayPlan as $line) {
            // Ночёвка: имя [id=] — без «прогулки», только база
            $line = preg_replace_callback(
                '/Ночёвка:\s*([^\[\]]+?)\s*\[id=(\d+)\]/u',
                fn (array $m) => 'Ночёвка: '.$rename($m[1], (int) $m[2]),
                $line
            ) ?? $line;
            // Места после «— » или «, » — с типом активности и часами
            $line = preg_replace_callback(
                '/(?<=[—\-–]\s|,\s)([^\[\].,]+?)\s*\[id=(\d+)\]/u',
                fn (array $m) => $renamePlace($m[1], (int) $m[2]),
                $line
            ) ?? $line;
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Человеческая фраза: «Прогулка у озера Тенно (~1.5 ч)», не голое имя местности.
     */
    private function humanActivityPhrase(string $ruName, int $id): string
    {
        $name = trim($ruName);
        if ($name === '') {
            return $name;
        }
        if (preg_match('/прогулк|посещени|~\s*\d/ui', $name)) {
            return $name;
        }

        $hours = 0.0;
        try {
            $hours = app(\App\Services\LocationDurationResolver::class)->hoursForId($id, $name);
        } catch (Throwable) {
            $hours = 0.0;
        }
        $hLabel = '';
        if ($hours > 0) {
            $hLabel = ' (~'.(fmod($hours, 1.0) < 0.05
                ? (string) (int) $hours
                : rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.')).' ч)';
        }

        $n = mb_strtolower($name);
        if (preg_match('/lago|озеро|tenno|тенн|molveno|мольвен|braies|прагс|tovel|товел|ledro|ледро|carezza|карец|misurina|мизурин|tobl|тобл|dobbiaco|доббиак/ui', $n)
            && ! preg_match('/cascata|водопад|raft|рафт|canyon|каньон/ui', $n)) {
            if (preg_match('/озер|lago|lake/ui', $name)) {
                return 'Время на '.$name.' (прогулка у воды)'.$hLabel;
            }

            return 'Время на озере '.$name.' (прогулка у воды)'.$hLabel;
        }
        if (preg_match('/cascata|водопад|varone|варон|nardis|нардис/ui', $n)) {
            if (! preg_match('/водопад|cascata/ui', $name)) {
                return 'Водопад '.$name.$hLabel;
            }

            return $name.$hLabel;
        }
        if (preg_match('/riva\s*del|рива-?дель|lungolago|набереж/ui', $n)) {
            return 'Прогулка по '.$name.$hLabel;
        }

        return $name.$hLabel;
    }

    private function readableRuName(string $name, int $id): string
    {
        static $cache = [];
        $cacheKey = $id > 0 ? $id : ('n:'.md5($name));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $fromDb = null;
        if ($id > 0) {
            try {
                $loc = \App\Models\Location::query()->find($id, ['id', 'meta', 'meta_ru']);
                if ($loc) {
                    $ru = is_array($loc->meta_ru) ? $loc->meta_ru : [];
                    $he = is_array($loc->meta) ? $loc->meta : [];
                    $fromDb = trim((string) ($ru['title'] ?? ''));
                    if ($fromDb === '') {
                        $name = trim((string) ($he['title'] ?? $name));
                    }
                }
            } catch (Throwable) {
            }
        }

        if ($fromDb !== null && $fromDb !== '') {
            return $cache[$cacheKey] = $fromDb;
        }

        $map = self::HE_RU_PLACE_NAMES;
        $n = trim($name);
        if (isset($map[$n])) {
            return $cache[$cacheKey] = $map[$n];
        }
        foreach ($map as $he => $ru) {
            if ($he !== '' && str_contains($n, $he)) {
                return $cache[$cacheKey] = $ru;
            }
        }

        // Латиница / смешанное — убрать иврит
        if (preg_match('/[A-Za-zА-Яа-яЁё]{3,}/u', $n)) {
            $clean = trim(preg_replace('/[\x{0590}-\x{05FF}]+/u', ' ', $n) ?? $n);
            $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
            $clean = trim($clean, " \t-–,()");
            if ($clean !== '') {
                return $cache[$cacheKey] = $clean;
            }
        }

        // Чистый иврит без словаря — хотя бы #id, не сырой иврит на экране
        if ($id > 0 && preg_match('/[\x{0590}-\x{05FF}]/u', $n)) {
            return $cache[$cacheKey] = '#'.$id;
        }

        return $cache[$cacheKey] = ($n !== '' ? $n : ($id > 0 ? '#'.$id : ''));
    }

    /** Частые места оси Гарда/Доломиты — пока meta_ru пустой */
    private const HE_RU_PLACE_NAMES = [
        'טנו' => 'Тенно',
        'ריבה דל גארדה' => 'Рива-дель-Гарда',
        'מסלול בוסאטה - Busatte to Tempesta' => 'Тропа Busatte — Tempesta',
        'מפלי וארנה' => 'Водопады Вароне',
        'מלצסינה שייט ללימונה' => 'Мальчезине — паром в Лимоне',
        'מונטה באלדו, מלצסינה, שייט לימונה' => 'Монте-Бальдо, Мальчезине, паром Лимоне',
        'שייט לימונה' => 'Паром Лимоне',
        'לימונה סול גארדה' => 'Лимоне-суль-Гарда',
        'אגם מולבנו בלי רכבל' => 'Озеро Мольвено (без каната)',
        'אורטיזיי ,Ortisei' => 'Ортизеи (Ortisei)',
        'אורטיזיי' => 'Ортизеи',
        'סנטה מגדלנה' => 'Санта-Маддалена (Валь-ди-Фунес)',
        'פארק חבלים באורטיזיי' => 'Верёвочный парк в Ортизеи',
        'ברוניקו (Brunico)' => 'Брунико (Brunico)',
        'מיזורינה' => 'Мизурина',
        '5 פסגות דולומיטים' => 'Пять вершин Доломитовых Альп',
        'אגם דוביאקו Lago Di Dobbiaco' => 'Озеро Доббиако (Toblach)',
        'אגם בראייס' => 'Озеро Браес',
        'אגם לדרו' => 'Озеро Ледро',
        'מפל נארדיס (Cascata di Nardis)' => 'Водопад Нардис',
        'מסלול רגלי במעבר הרים סלה' => 'Трек через перевал Селла',
        'ראפטינג בדרך למולבנו (ריבר ספיריט)' => 'Рафтинг по пути к Мольвено (River Spirit)',
        'ראפטינג בדרך למולבנו' => 'Рафтинг по пути к Мольвено',
        'אגם מולבנו למשפחות' => 'Озеро Мольвено (семейное)',
        'אגם מולבנו למבוגרים' => 'Озеро Мольвено (для взрослых)',
        'אגם מולבנו' => 'Озеро Мольвено',
        'מסלול רגלי לאגם סורפיס' => 'Трек к озеру Сорапис',
        'ריבה דל גארדה - טיול רגלי (קצר)' => 'Рива — короткая прогулка',
        'Skyscraper al Plan de Corones' => 'Скайскрапер на Plan de Corones (Кронплац)',
        'טיול רגלי באורטיזיי Bulla (Mont Seuc >> Ortisei)' => 'Пеший маршрут Bulla (Mont Seuc → Ortisei)',
        'Punto Panoramico Cadini' => 'Смотровая Cadini',
        'קיוזה' => 'Кьюза (Chiusa)',
        'סס פורדוי' => 'Перевал Сасс-Пордой',
        'ויא פראטה -Monte Albano' => 'Виа феррата Монте Альбано',
        'ויא פראטה' => 'Виа феррата',
        'קניונינג בצפון של אגם גארדה - BUENO CANYONING' => 'Каньонинг на севере Гарды (Bueno)',
        'קניונינג אגם גארדה - BUENO CANYONING' => 'Каньонинг Гарда (Bueno)',
        'קניונינג' => 'Каньонинг',
        'טיול רגלי למפל Cascate Di Riva' => 'Прогулка к водопаду Cascate di Riva',
        'מפל טרוולה' => 'Водопад Тервела',
        '(Cascata Tervela) מפל טרוולה' => 'Водопад Тервела (Cascata Tervela)',
        'טיול אופניים סן קנדידו -לינץ' => 'Веломаршрут Сан-Кандидо — Линц',
        'אגם טובל' => 'Озеро Тобль',
        'מגלשות הרים – FUNBOB CANDIDO' => 'Горные санки FUNBOB Candido',
        'השכרת טרקטורונים ואופנועים בלימונה סול גארדה Tombola Rent' => 'Прокат квадроциклов в Лимоне (Tombola Rent)',
        'X-Treme Adventures' => 'X-Treme Adventures',
    ];

    /**
     * Те же даты и те же наборы id по дням (порядок мест внутри дня не важен).
     *
     * @param  list<string>  $dayPlan
     */
    private function samePlanStructure(string $reply, array $dayPlan): bool
    {
        $replyDays = $this->planDayIdSets($reply);
        $planDays = $this->planDayIdSets(implode("\n", $dayPlan));
        if ($replyDays === [] || count($replyDays) !== count($planDays)) {
            return false;
        }
        foreach ($planDays as $date => $ids) {
            if (! isset($replyDays[$date])) {
                return false;
            }
            $a = $replyDays[$date];
            sort($a);
            $b = $ids;
            sort($b);
            if ($a !== $b) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, list<int>> date => sorted unique ids (places + night)
     */
    private function planDayIdSets(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if (! preg_match('/^(\d{1,2}\.\d{1,2})\s*[—\-–]/u', $line, $m)) {
                continue;
            }
            $date = $m[1];
            $ids = [];
            if (preg_match_all('/\[id=(\d+)\]/u', $line, $im)) {
                foreach ($im[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
            $out[$date] = array_values(array_unique($ids));
        }

        return $out;
    }

    public function savePrompt(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'nullable|string|max:50000',
            'system_prompt' => 'nullable|string|max:50000',
            'user_prompt' => 'nullable|string|max:50000',
            'audit_system_prompt' => 'nullable|string|max:50000',
            'audit_user_prompt' => 'nullable|string|max:50000',
            'mode' => 'nullable|string|in:locations,scenario,chain',
            'prompts' => 'nullable|array',
        ]);

        $mode = (string) $request->input('mode', 'locations');
        if ($mode === 'chain') {
            $prompts = (array) $request->input('prompts', []);
            $len = 0;
            foreach (self::CHAIN_PROMPT_KEYS as $key => $settingKey) {
                $text = (string) ($prompts[$key] ?? '');
                AppSetting::putValue($settingKey, $text);
                $len += mb_strlen($text);
            }

            return response()->json([
                'ok' => true,
                'message' => 'Prompt saved',
                'length' => $len,
                'mode' => 'chain',
            ]);
        }
        if ($mode === 'scenario') {
            $system = (string) $request->input('system_prompt', $request->input('prompt', ''));
            $user = (string) $request->input('user_prompt', '');
            $auditSystem = (string) $request->input('audit_system_prompt', '');
            $auditUser = (string) $request->input('audit_user_prompt', '');
            AppSetting::putValue(self::SCENARIO_SYSTEM_PROMPT_KEY, $system);
            AppSetting::putValue(self::SCENARIO_USER_PROMPT_KEY, $user);
            AppSetting::putValue(self::SCENARIO_AUDIT_SYSTEM_PROMPT_KEY, $auditSystem);
            AppSetting::putValue(self::SCENARIO_AUDIT_USER_PROMPT_KEY, $auditUser);

            return response()->json([
                'ok' => true,
                'message' => 'Prompt saved',
                'length' => mb_strlen($system) + mb_strlen($user) + mb_strlen($auditSystem) + mb_strlen($auditUser),
                'mode' => 'scenario',
            ]);
        }

        $prompt = (string) $request->input('prompt', '');
        AppSetting::putValue(self::LOCATIONS_PROMPT_KEY, $prompt);

        return response()->json([
            'ok' => true,
            'message' => 'Prompt saved',
            'length' => mb_strlen($prompt),
            'mode' => 'locations',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scenarioPrompts(): array
    {
        $freshSystem = GeminiScenarioPlannerService::defaultSystemPrompt();
        $freshUser = GeminiScenarioPlannerService::defaultUserPrompt();

        $system = AppSetting::getValue(self::SCENARIO_SYSTEM_PROMPT_KEY);
        if ($system === null || trim((string) $system) === ''
            || ! str_contains((string) $system, 'не бухгалтер по часам')
            || ! str_contains((string) $system, 'ВНУТРЕННИЙ МАКРОАНАЛИЗ')
            || ! str_contains((string) $system, 'DENSITY IS NOT A HARD STOP')
            || ! str_contains((string) $system, 'Backend has already performed the geographic filtering')
            || ! str_contains((string) $system, 'улучшает ли этот POI день')
            || ! str_contains((string) $system, 'Не выравнивай нагрузку между днями искусственно')
            || ! str_contains((string) $system, 'OLD → stop(s) → NEW')
            || ! str_contains((string) $system, 'BASE→POI1→POI2→BASE')
            || ! str_contains((string) $system, 'выезд')
            || ! str_contains((string) $system, 'summary/narrative дня')
            || ! str_contains((string) $system, 'компактная связка из CANDIDATES')
            || ! str_contains((string) $system, 'Stop в зоне OLD base')
            || ! str_contains((string) $system, '#395+#32')
            || ! str_contains((string) $system, 'короткий день нельзя завершать сразу после одного POI')
            || ! str_contains((string) $system, 'образцам стиля директора')
            || ! str_contains((string) $system, 'MACRO HUBS')
            || ! str_contains((string) $system, 'Braies #116 и Misurina #117')
            || ! str_contains((string) $system, 'Braies лучше оставить для stay Brunico')
            || ! str_contains((string) $system, 'Long transfer Brunico→Garda')
            || str_contains((string) $system, 'CURRENT CANDIDATES vs HISTORICAL VECTOR MATCHES')
            || str_contains((string) $system, '=== ENRICHMENT CHECK ===')
            || str_contains((string) $system, 'REFERENCE RHYTHM')
            || str_contains((string) $system, 'ЗАПРЕТ ТОНКОЙ СЕРИИ')
            || str_contains((string) $system, 'OVERNIGHT REQUIRED')
            || str_contains((string) $system, 'avoid_full_stay')
            || str_contains((string) $system, 'pick main anchor → enrichment check')
            || str_contains((string) $system, '1× short_stop, не 3–4 ч')
            || str_contains((string) $system, 'prefer=short_stop only')) {
            AppSetting::putValue(self::SCENARIO_SYSTEM_PROMPT_KEY, $freshSystem);
            $system = $freshSystem;
        }

        $user = AppSetting::getValue(self::SCENARIO_USER_PROMPT_KEY);
        if ($user === null || trim((string) $user) === ''
            || ! str_contains((string) $user, '{{QUERY}}')
            || ! str_contains((string) $user, '{{SAMPLES}}')
            || ! str_contains((string) $user, '{{POIS}}')
            || ! str_contains((string) $user, '{{TRANSFERS}}')
            || ! str_contains((string) $user, 'ОБРАЗЦЫ СТИЛЯ ДИРЕКТОРА')
            || str_contains((string) $user, 'ИСТОРИЧЕСКАЯ ЛОГИКА УЧАСТКА')
            || str_contains((string) $user, 'CURRENT CANDIDATES')
            || str_contains((string) $user, 'REFERENCE RHYTHM')) {
            AppSetting::putValue(self::SCENARIO_USER_PROMPT_KEY, $freshUser);
            $user = $freshUser;
        }

        return [(string) $system, (string) $user];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scenarioAuditPrompts(): array
    {
        $freshSystem = GeminiScenarioPlannerService::defaultAuditSystemPrompt();
        $freshUser = GeminiScenarioPlannerService::defaultAuditUserPrompt();

        $system = AppSetting::getValue(self::SCENARIO_AUDIT_SYSTEM_PROMPT_KEY);
        if ($system === null || trim((string) $system) === ''
            || ! str_contains((string) $system, 'ТИПОВЫЕ ОШИБКИ')
            || ! str_contains((string) $system, 'BASE vs ACTIVITY В КАТАЛОГЕ')
            || ! str_contains((string) $system, 'СИЛЬНАЯ ПРОПУЩЕННАЯ ACTIVITY-СВЯЗКА')
            || str_contains((string) $system, 'РАЙОН С ГЛАВНЫМ POI, А В ДНЕ ТОЛЬКО')) {
            AppSetting::putValue(self::SCENARIO_AUDIT_SYSTEM_PROMPT_KEY, $freshSystem);
            $system = $freshSystem;
        }

        $user = AppSetting::getValue(self::SCENARIO_AUDIT_USER_PROMPT_KEY);
        if ($user === null || trim((string) $user) === ''
            || ! str_contains((string) $user, '{{PLAN}}')
            || ! str_contains((string) $user, '{{POIS}}')
            || ! str_contains((string) $user, 'ИСТОРИЧЕСКАЯ ЛОГИКА УЧАСТКА')
            || ! str_contains((string) $user, 'сначала activity vs base')
            || str_contains((string) $user, 'ОБРАЗЦЫ СТИЛЯ ДИРЕКТОРА')) {
            AppSetting::putValue(self::SCENARIO_AUDIT_USER_PROMPT_KEY, $freshUser);
            $user = $freshUser;
        }

        return [(string) $system, (string) $user];
    }

    /**
     * @return array<string, string>
     */
    private function chainPromptDefaults(): array
    {
        return [
            'architect_system' => GeminiScenarioPlannerService::defaultSystemPrompt(),
            'architect_user' => GeminiScenarioPlannerService::defaultUserPrompt(),
            'geo_system' => GeminiScenarioPlannerService::defaultChainGeoSystemPrompt(),
            'geo_user' => GeminiScenarioPlannerService::defaultChainGeoUserPrompt(),
            'day_system' => GeminiScenarioPlannerService::defaultChainDaySystemPrompt(),
            'day_user' => GeminiScenarioPlannerService::defaultChainDayUserPrompt(),
            'coverage_system' => GeminiScenarioPlannerService::defaultChainCoverageSystemPrompt(),
            'coverage_user' => GeminiScenarioPlannerService::defaultChainCoverageUserPrompt(),
            'rebuild_system' => GeminiScenarioPlannerService::defaultChainRebuildSystemPrompt(),
            'rebuild_user' => GeminiScenarioPlannerService::defaultChainRebuildUserPrompt(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function chainPrompts(): array
    {
        $defaults = $this->chainPromptDefaults();
        $out = [];
        foreach (self::CHAIN_PROMPT_KEYS as $key => $settingKey) {
            $stored = AppSetting::getValue($settingKey);
            if ($stored === null || trim((string) $stored) === '') {
                AppSetting::putValue($settingKey, $defaults[$key]);
                $out[$key] = $defaults[$key];
            } else {
                $out[$key] = (string) $stored;
            }
        }

        // force-refresh markers if old stubs without chain markers
        if (! str_contains($out['geo_system'], 'аудит ГЕОГРАФИИ')
            || ! str_contains($out['rebuild_system'], 'ФИНАЛЬНЫЙ АРБИТР')
            || ! str_contains($out['coverage_system'], 'BASE vs ACTIVITY')
            || str_contains($out['coverage_system'], 'Жёстко. Только каталог.')) {
            foreach ($defaults as $key => $text) {
                AppSetting::putValue(self::CHAIN_PROMPT_KEYS[$key], $text);
                $out[$key] = $text;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function buildValidatorReport(array $findings): string
    {
        if ($findings === []) {
            return 'validator: ok';
        }
        $order = ['CRITICAL' => 0, 'MAJOR' => 1, 'MINOR' => 2];
        usort($findings, fn ($a, $b) => ($order[$a['severity'] ?? ''] ?? 9) <=> ($order[$b['severity'] ?? ''] ?? 9));
        $blocks = [];
        foreach ($findings as $f) {
            $blocks[] = '['.($f['severity'] ?? '?')."]\n"
                .'дата: '.($f['date'] ?? '—')."\n"
                .'проблема: '.($f['problem'] ?? '')."\n"
                .'объекты: '.(isset($f['objects']) && $f['objects'] !== [] ? implode(', ', $f['objects']) : '—')."\n"
                .'почему: '.($f['why'] ?? '')."\n"
                .'данные, на основании которых принято решение: '.($f['data'] ?? '');
        }

        return implode("\n\n", $blocks);
    }

    private function errorResponse(string $step, Throwable $e): JsonResponse
    {
        Log::warning('Test locations failed', [
            'step' => $step,
            'message' => $e->getMessage(),
            'class' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'ok' => false,
            'step' => $step,
            'message' => $e->getMessage(),
            'error_class' => class_basename($e),
            'error_file' => basename($e->getFile()).':'.$e->getLine(),
        ], 500);
    }

    private function defaultLocationsPrompt(): string
    {
        return <<<'PROMPT'
ПЛАН ДНЕЙ уже готов (после PHP-правок). Только перепиши его по-русски для проверки.

Правила:
1) Те же даты, те же id мест и ночёвок — не меняй id, не добавляй и не убирай точки, не переставляй между днями.
2) Если есть «Переезд в …» — оставь как в плане.
3) Можно перевести названия на русский; id в скобках [id=…] оставь как есть.
4) Формат: «14.08 — место [id=…]. Ночёвка: база [id=…].»
5) Коротко. В конце — список использованных id.
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  list<string>  $dayPlan
     */
    private function formatLocationsContext(array $locations, array $dayPlan = [], string $skeletonBlock = ''): string
    {
        $calendar = [];
        $lines = [];
        foreach ($locations as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $n = $i + 1;
            $line = "[{$n}] id={$id}";
            $block = trim((string) ($row['block_label'] ?? $row['block'] ?? ''));
            if ($block !== '') {
                $line .= " block={$block}";
            }
            $from = trim((string) ($row['date_from'] ?? ''));
            $to = trim((string) ($row['date_to'] ?? ''));
            if ($from !== '' || $to !== '') {
                $line .= ' days='.($from !== '' ? $from : '?').'–'.($to !== '' ? $to : '?');
                $cal = ($block !== '' ? $block : 'район').': '.($from !== '' ? $from : '?').'–'.($to !== '' ? $to : '?');
                $calendar[$cal] = $cal;
            }
            if (! empty($row['is_base']) || ($row['role'] ?? '') === 'base') {
                $line .= ' role=base';
            } else {
                $line .= ' role=place';
            }
            foreach (['name' => 'name', 'country' => 'country', 'region' => 'region'] as $key => $label) {
                $val = trim((string) ($row[$key] ?? ''));
                if ($val !== '') {
                    $line .= " {$label}={$val}";
                }
            }
            if (isset($row['lat'], $row['lng']) && $row['lat'] !== null && $row['lng'] !== null) {
                $line .= ' coords='.$row['lat'].','.$row['lng'];
            }
            $snippet = trim((string) ($row['snippet'] ?? ''));
            if ($snippet !== '') {
                $line .= "\n".mb_substr($snippet, 0, 240);
            }
            $lines[] = $line;
        }

        $head = '';
        if ($dayPlan !== []) {
            $head .= "ПЛАН ДНЕЙ (черновик по координатам — даты и ночёвки сохранить, наполнение можно уточнить по образцам):\n"
                .implode("\n", $dayPlan)."\n\n";
        }
        if ($skeletonBlock !== '') {
            $head .= "ОБРАЗЦЫ МАРШРУТОВ ДИРЕКТОРА (логика дней, плотность, оси — ориентир):\n"
                .$skeletonBlock."\n\n";
        }
        if ($calendar !== []) {
            $head .= "РАЙОНЫ ПО ДАТАМ:\n".implode("\n", array_values($calendar))."\n\n";
        }

        return $head.implode("\n\n", $lines);
    }
}
