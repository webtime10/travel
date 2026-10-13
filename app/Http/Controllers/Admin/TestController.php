<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunAgentRagJob;
use App\Jobs\RunFullTourCleanPoolJob;
use App\Jobs\RunFullTourCurateJob;
use App\Jobs\RunFullTourJob;
use App\Jobs\RunFullTourTranslateJob;
use App\Jobs\RunFullTourTranslatePoolJob;
use App\Models\AppSetting;
use App\Services\AgentRagRunStore;
use App\Services\FullTourRunStore;
use App\Services\GeminiChatService;
use App\Services\GeminiKeyPool;
use App\Services\Itinerary\BuildItineraryService;
use App\Services\RagSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestController extends Controller
{
    public const PROMPT_KEY = 'test.system_prompt';

    public const AGENT_PROMPT_KEY = 'test.agent_system_prompt';

    public const FULL_TOUR_PROMPT_KEY = 'test.full_tour_system_prompt';

    public function index(GeminiKeyPool $keys)
    {
        $pageTitle = 'Test — Агентный RAG';
        $systemPrompt = AppSetting::getValue(self::AGENT_PROMPT_KEY);
        if ($systemPrompt === null || trim($systemPrompt) === '') {
            $systemPrompt = $this->defaultAgentPrompt();
        }

        return view('admin.test.index', [
            'pageTitle' => $pageTitle,
            'geminiKeys' => $keys->count(),
            'systemPrompt' => $systemPrompt,
            'testMode' => 'agent',
        ]);
    }

    public function ragVector(GeminiKeyPool $keys)
    {
        $pageTitle = 'Test — Vector RAG';
        $systemPrompt = AppSetting::getValue(self::PROMPT_KEY);
        if ($systemPrompt === null || trim($systemPrompt) === '') {
            $systemPrompt = $this->defaultPrompt();
        }

        return view('admin.test.rag-vector', [
            'pageTitle' => $pageTitle,
            'geminiKeys' => $keys->count(),
            'systemPrompt' => $systemPrompt,
            'testMode' => 'vector',
        ]);
    }

    public function fullTour(GeminiKeyPool $keys)
    {
        $pageTitle = 'Test — Полный тур';
        $systemPrompt = AppSetting::getValue(self::FULL_TOUR_PROMPT_KEY);
        if ($systemPrompt === null || trim($systemPrompt) === '' || ! str_contains((string) $systemPrompt, 'tours_from_database')) {
            $systemPrompt = $this->defaultFullTourPrompt();
        }

        return view('admin.test.full-tour', [
            'pageTitle' => $pageTitle,
            'geminiKeys' => $keys->count(),
            'systemPrompt' => $systemPrompt,
            'testMode' => 'full_tour',
        ]);
    }

    public function savePrompt(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'nullable|string|max:50000',
            'mode' => 'nullable|string|in:vector,agent,full_tour',
        ]);

        $mode = (string) $request->input('mode', 'vector');
        $key = match ($mode) {
            'agent' => self::AGENT_PROMPT_KEY,
            'full_tour' => self::FULL_TOUR_PROMPT_KEY,
            default => self::PROMPT_KEY,
        };
        $prompt = (string) $request->input('prompt', '');
        AppSetting::putValue($key, $prompt);

        return response()->json([
            'ok' => true,
            'message' => 'Prompt saved',
            'length' => mb_strlen($prompt),
            'mode' => $mode,
        ]);
    }

    public function fullTourPlan(Request $request, FullTourRunStore $store, BuildItineraryService $builder): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:12000',
        ]);

        $message = trim((string) $request->input('message'));
        $plan = $builder->planSearch($message);
        $runId = $store->create($message);
        $store->patch($runId, [
            'status' => 'done',
            'mode' => 'plan',
            'search_plan' => $plan,
            'reply' => $plan['reply'] ?? '',
            'steps' => [
                [
                    'type' => 'final',
                    'title' => 'План поиска (вектор ещё не вызывали)',
                    'detail' => 'Регионы: '.implode(', ', $plan['regions'] ?? [])
                        ."\nЛокации: ".implode(', ', array_slice($plan['locations'] ?? [], 0, 8)),
                ],
            ],
            'error' => null,
        ]);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_plan',
            'run_id' => $runId,
            'status' => 'done',
            'mode' => 'plan',
            'search_plan' => $plan,
            'reply' => $plan['reply'] ?? '',
            'message' => 'План готов. Подтвердите поиск или уточните запрос.',
        ]);
    }

    public function fullTourRefinePlan(string $runId, Request $request, FullTourRunStore $store, BuildItineraryService $builder): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:12000',
        ]);

        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_refine_plan',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $plan = is_array($data['search_plan'] ?? null) ? $data['search_plan'] : [];
        if ($plan === []) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_refine_plan',
                'message' => 'Сначала разберите исходный запрос',
            ], 422);
        }

        $followUp = trim((string) $request->input('message'));
        $next = $builder->refineSearchPlan($plan, $followUp);
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $steps[] = [
            'type' => 'think',
            'title' => 'Уточнение плана',
            'detail' => $followUp,
        ];
        $steps[] = [
            'type' => 'final',
            'title' => 'План обновлён',
            'detail' => 'Регионы: '.implode(', ', $next['regions'] ?? []),
        ];
        $store->patch($runId, [
            'status' => 'done',
            'mode' => 'plan',
            'search_plan' => $next,
            'reply' => $next['reply'] ?? '',
            'steps' => $steps,
            'error' => null,
        ]);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_plan_refined',
            'run_id' => $runId,
            'status' => 'done',
            'mode' => 'plan',
            'search_plan' => $next,
            'reply' => $next['reply'] ?? '',
            'message' => 'План обновлён',
        ]);
    }

    public function fullTourStart(Request $request, FullTourRunStore $store): JsonResponse
    {
        $request->validate([
            'message' => 'nullable|string|max:12000',
            'run_id' => 'nullable|string|max:80',
            'prompt' => 'nullable|string|max:50000',
            'mode' => 'nullable|string|in:preview,assemble',
        ]);

        $mode = (string) $request->input('mode', 'preview');
        $prompt = $request->filled('prompt') && trim((string) $request->input('prompt')) !== ''
            ? (string) $request->input('prompt')
            : (string) (AppSetting::getValue(self::FULL_TOUR_PROMPT_KEY) ?: $this->defaultFullTourPrompt());

        $existingRunId = trim((string) $request->input('run_id', ''));
        if ($existingRunId !== '') {
            $data = $store->get($existingRunId);
            if ($data === null) {
                return response()->json([
                    'ok' => false,
                    'step' => 'full_tour_start',
                    'message' => 'Run not found or expired',
                ], 404);
            }
            $message = trim((string) ($data['message'] ?? $request->input('message', '')));
            if ($message === '') {
                return response()->json([
                    'ok' => false,
                    'step' => 'full_tour_start',
                    'message' => 'В run нет исходного запроса',
                ], 422);
            }
            $store->patch($existingRunId, [
                'status' => 'queued',
                'mode' => $mode,
                'reply' => null,
            ]);
            RunFullTourJob::dispatch($existingRunId, $message, $prompt, $mode);

            return response()->json([
                'ok' => true,
                'step' => 'full_tour_queued',
                'run_id' => $existingRunId,
                'status' => 'queued',
                'mode' => $mode,
                'message' => 'Поиск сырья в очереди. Нужен php artisan queue:work',
            ]);
        }

        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_start',
                'message' => 'Нужен message или run_id с планом',
            ], 422);
        }

        $runId = $store->create($message);
        $store->patch($runId, ['mode' => $mode]);
        RunFullTourJob::dispatch($runId, $message, $prompt, $mode);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'mode' => $mode,
            'message' => 'Задача в очереди. Нужен php artisan queue:work',
        ]);
    }

    public function fullTourTranslate(string $runId, FullTourRunStore $store): JsonResponse
    {
        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_translate',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $sources = $data['sources'] ?? $data['tours'] ?? [];
        if (! is_array($sources) || $sources === []) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_translate',
                'message' => 'Сначала найдите сырьё (Найти сырьё)',
            ], 422);
        }

        $store->patch($runId, [
            'status' => 'queued',
            'mode' => 'translate',
        ]);
        RunFullTourTranslateJob::dispatch($runId);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_translate_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'mode' => 'translate',
            'message' => 'Перевод в очереди. Нужен php artisan queue:work',
        ]);
    }

    public function fullTourCurate(string $runId, FullTourRunStore $store): JsonResponse
    {
        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_curate',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $sources = $data['sources'] ?? $data['tours'] ?? [];
        if (! is_array($sources) || $sources === []) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_curate',
                'message' => 'Сначала найдите сырьё (Найти сырьё)',
            ], 422);
        }

        $store->patch($runId, [
            'status' => 'queued',
            'mode' => 'curate',
            'reply' => null,
        ]);
        RunFullTourCurateJob::dispatch($runId);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_curate_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'mode' => 'curate',
            'message' => 'Отбор сырья в очереди. Нужен php artisan queue:work',
        ]);
    }

    public function fullTourCleanPool(string $runId, Request $request, FullTourRunStore $store): JsonResponse
    {
        $request->validate([
            'curated_text' => 'nullable|string|max:200000',
        ]);

        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_clean',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $curated = $data['curated_days'] ?? [];
        $curatedText = trim((string) $request->input('curated_text', $data['curated_text'] ?? ''));

        if ($curatedText !== '') {
            $parsed = app(BuildItineraryService::class)->parseCuratedText($curatedText);
            if ($parsed !== []) {
                $curated = $parsed;
            }
        }

        if (! is_array($curated) || $curated === []) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_clean',
                'message' => 'Сначала нажмите «Извлечь дни»',
            ], 422);
        }

        $store->patch($runId, [
            'status' => 'queued',
            'mode' => 'clean',
            'curated_days' => $curated,
            'curated_text' => $curatedText !== '' ? $curatedText : ($data['curated_text'] ?? ''),
            'reply' => null,
            'replies' => [],
        ]);
        RunFullTourCleanPoolJob::dispatch($runId);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_clean_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'mode' => 'clean',
            'message' => 'Очистка пула в очереди. Нужен php artisan queue:work',
        ]);
    }

    public function fullTourTranslatePool(string $runId, Request $request, FullTourRunStore $store): JsonResponse
    {
        $request->validate([
            'cleaned_text' => 'nullable|string|max:200000',
        ]);

        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_translate_pool',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $days = $data['cleaned_days'] ?? [];
        $cleanedText = trim((string) $request->input('cleaned_text', $data['cleaned_text'] ?? ''));

        if ($cleanedText !== '') {
            $parsed = app(BuildItineraryService::class)->parseCuratedText($cleanedText);
            if ($parsed !== []) {
                $days = $parsed;
            }
        }

        if ((! is_array($days) || $days === []) && is_array($data['curated_days'] ?? null)) {
            $days = $data['curated_days'];
        }

        if (! is_array($days) || $days === []) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_translate_pool',
                'message' => 'Сначала «Почистить пул» (или «Извлечь дни»)',
            ], 422);
        }

        $store->patch($runId, [
            'status' => 'queued',
            'mode' => 'translate_pool',
            'cleaned_days' => $days,
            'cleaned_text' => $cleanedText !== '' ? $cleanedText : ($data['cleaned_text'] ?? ''),
            'reply' => null,
            'replies' => [],
        ]);
        RunFullTourTranslatePoolJob::dispatch($runId);

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_translate_pool_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'mode' => 'translate_pool',
            'message' => 'Перевод пула в очереди. Нужен php artisan queue:work',
        ]);
    }

    public function fullTourAssemble(string $runId, Request $request, FullTourRunStore $store): JsonResponse
    {
        $request->validate([
            'prompt' => 'nullable|string|max:50000',
            'variants' => 'nullable|integer|min:1|max:2',
            'curated_text' => 'nullable|string|max:200000',
            'cleaned_text' => 'nullable|string|max:200000',
        ]);

        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_assemble',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $sources = $data['sources'] ?? $data['tours'] ?? [];
        $rawCurated = is_array($data['curated_days'] ?? null) ? $data['curated_days'] : [];
        $cleaned = is_array($data['cleaned_days'] ?? null) ? $data['cleaned_days'] : [];

        $cleanedText = trim((string) $request->input('cleaned_text', $data['cleaned_text'] ?? ''));
        $curatedText = trim((string) $request->input('curated_text', $data['curated_text'] ?? ''));

        if ($curatedText !== '') {
            $parsedRaw = app(BuildItineraryService::class)->parseCuratedText($curatedText);
            if ($parsedRaw !== []) {
                $rawCurated = $parsedRaw;
            }
        }
        if ($cleanedText !== '') {
            $parsedClean = app(BuildItineraryService::class)->parseCuratedText($cleanedText);
            if ($parsedClean !== []) {
                $cleaned = $parsedClean;
            }
        }

        $assemblePool = $cleaned !== [] ? $cleaned : $rawCurated;
        if ($assemblePool === []) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_assemble',
                'message' => 'Сначала «Извлечь дни» (лучше ещё «Почистить пул») или заполните textarea',
            ], 422);
        }
        if (! is_array($sources)) {
            $sources = [];
        }

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_assemble',
                'message' => 'В run нет исходного запроса',
            ], 422);
        }

        $prompt = $request->filled('prompt') && trim((string) $request->input('prompt')) !== ''
            ? (string) $request->input('prompt')
            : (string) (AppSetting::getValue(self::FULL_TOUR_PROMPT_KEY) ?: $this->defaultFullTourPrompt());

        $variants = max(1, min(2, (int) $request->input('variants', 1)));

        $store->patch($runId, [
            'status' => 'queued',
            'mode' => 'assemble',
            'variants' => $variants,
            'curated_days' => $rawCurated !== [] ? $rawCurated : $assemblePool,
            'curated_text' => $curatedText !== '' ? $curatedText : ($data['curated_text'] ?? ''),
            'cleaned_days' => $cleaned,
            'cleaned_text' => $cleanedText !== '' ? $cleanedText : ($data['cleaned_text'] ?? ''),
            'assemble_days' => $assemblePool,
            'reply' => null,
            'replies' => [],
        ]);
        RunFullTourJob::dispatch($runId, $message, $prompt, 'assemble');

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_assemble_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'mode' => 'assemble',
            'variants' => $variants,
            'message' => 'Сборка в очереди. Нужен php artisan queue:work',
        ]);
    }

    public function fullTourStatus(string $runId, FullTourRunStore $store): JsonResponse
    {
        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'full_tour_status',
                'message' => 'Run not found or expired',
            ], 404);
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        return response()->json([
            'ok' => true,
            'step' => 'full_tour_status',
            'run_id' => $runId,
            'status' => $data['status'] ?? 'unknown',
            'mode' => $data['mode'] ?? 'preview',
            'message' => $data['message'] ?? null,
            'steps' => $data['steps'] ?? [],
            'reply' => $data['reply'] ?? null,
            'search_plan' => $data['search_plan'] ?? null,
            'sources' => $data['sources'] ?? $data['tours'] ?? [],
            'curated_days' => $data['curated_days'] ?? [],
            'curated_text' => $data['curated_text'] ?? null,
            'cleaned_days' => $data['cleaned_days'] ?? [],
            'cleaned_text' => $data['cleaned_text'] ?? null,
            'clean_dropped_days' => $data['clean_dropped_days'] ?? [],
            'clean_note' => $data['clean_note'] ?? null,
            'dropped_days' => $data['dropped_days'] ?? [],
            'brief_note' => $data['brief_note'] ?? null,
            'preferred_regions' => $data['preferred_regions'] ?? [],
            'replies' => $data['replies'] ?? [],
            'tours' => $data['tours'] ?? [],
            'product_id' => $data['product_id'] ?? null,
            'model' => $data['model'] ?? null,
            'meta_lang' => $data['meta_lang'] ?? null,
            'search_ms' => $data['search_ms'] ?? null,
            'format_ms' => $data['format_ms'] ?? null,
            'translate_ms' => $data['translate_ms'] ?? null,
            'curate_ms' => $data['curate_ms'] ?? null,
            'clean_ms' => $data['clean_ms'] ?? null,
            'pool_translate_ms' => $data['pool_translate_ms'] ?? null,
            'total_ms' => $data['total_ms'] ?? null,
            'pool_days' => $data['pool_days'] ?? null,
            'error' => $data['error'] ?? null,
        ], 200, [], $flags);
    }

    public function agent(Request $request, AgentRagRunStore $store): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:12000',
            'prompt' => 'nullable|string|max:50000',
        ]);

        $message = (string) $request->input('message');
        $prompt = $request->filled('prompt') && trim((string) $request->input('prompt')) !== ''
            ? (string) $request->input('prompt')
            : (string) (AppSetting::getValue(self::AGENT_PROMPT_KEY) ?: $this->defaultAgentPrompt());

        $runId = $store->create($message);
        RunAgentRagJob::dispatch($runId, $message, $prompt);

        return response()->json([
            'ok' => true,
            'step' => 'agent_queued',
            'run_id' => $runId,
            'status' => 'queued',
            'message' => 'Задача в очереди. Нужен worker: php artisan queue:work или queue:work-forever',
        ]);
    }

    public function agentStatus(string $runId, AgentRagRunStore $store): JsonResponse
    {
        $data = $store->get($runId);
        if ($data === null) {
            return response()->json([
                'ok' => false,
                'step' => 'agent_status',
                'message' => 'Run not found or expired',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'step' => 'agent_status',
            'run_id' => $runId,
            'status' => $data['status'] ?? 'unknown',
            'steps' => $data['steps'] ?? [],
            'reply' => $data['reply'] ?? null,
            'sources' => $data['sources'] ?? [],
            'tool_calls' => $data['tool_calls'] ?? 0,
            'total_ms' => $data['total_ms'] ?? null,
            'error' => $data['error'] ?? null,
        ]);
    }

    public function search(Request $request, RagSearchService $rag): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:8000',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        @set_time_limit(300);

        $message = (string) $request->input('message');
        $limit = (int) $request->input('limit', 12);

        try {
            $result = $rag->search($message, $limit);
            $hits = $result['hits'];
            $context = $rag->buildContext($hits);
            $sources = $rag->sources($hits);
        } catch (Throwable $e) {
            return $this->errorResponse('search', $e);
        }

        return response()->json([
            'ok' => true,
            'step' => 'search',
            'hits' => count($hits),
            'scanned' => $result['scanned'],
            'model_filter' => $result['model_filter'],
            'embed_ms' => $result['embed_ms'],
            'search_ms' => $result['search_ms'],
            'sources' => $sources,
            'context' => $context,
            'context_chars' => mb_strlen($context),
        ]);
    }

    public function chat(Request $request, GeminiChatService $chat, RagSearchService $rag): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:8000',
            'history' => 'nullable|array',
            'history.*.role' => 'nullable|string',
            'history.*.text' => 'nullable|string',
            'prompt' => 'nullable|string|max:50000',
            'context' => 'nullable|string|max:100000',
            'skip_search' => 'nullable|boolean',
        ]);

        @set_time_limit(600);

        $prompt = $this->resolvePrompt($request);
        $message = (string) $request->input('message');
        $skipSearch = (bool) $request->boolean('skip_search');
        $context = trim((string) $request->input('context', ''));
        $sources = [];
        $meta = [
            'hits' => 0,
            'scanned' => 0,
            'model_filter' => null,
            'embed_ms' => 0,
            'search_ms' => 0,
        ];

        try {
            if (! $skipSearch || $context === '') {
                $result = $rag->search($message, 12);
                $hits = $result['hits'];
                $context = $rag->buildContext($hits);
                $sources = $rag->sources($hits);
                $meta = [
                    'hits' => count($hits),
                    'scanned' => $result['scanned'],
                    'model_filter' => $result['model_filter'],
                    'embed_ms' => $result['embed_ms'],
                    'search_ms' => $result['search_ms'],
                ];
            }

            $t0 = microtime(true);
            $reply = $chat->reply(
                $message,
                (array) $request->input('history', []),
                $prompt,
                $context
            );
            $chatMs = (int) round((microtime(true) - $t0) * 1000);
        } catch (Throwable $e) {
            return $this->errorResponse($skipSearch ? 'chat' : 'chat_with_search', $e);
        }

        return response()->json([
            'ok' => true,
            'step' => 'chat',
            'reply' => $reply,
            'sources' => $sources,
            'hits' => $meta['hits'],
            'scanned' => $meta['scanned'],
            'model_filter' => $meta['model_filter'],
            'embed_ms' => $meta['embed_ms'],
            'search_ms' => $meta['search_ms'],
            'chat_ms' => $chatMs,
            'context_chars' => mb_strlen($context),
        ]);
    }

    private function resolvePrompt(Request $request): string
    {
        if ($request->filled('prompt') && trim((string) $request->input('prompt')) !== '') {
            return (string) $request->input('prompt');
        }

        $stored = AppSetting::getValue(self::PROMPT_KEY);

        return ($stored !== null && trim($stored) !== '')
            ? $stored
            : $this->defaultPrompt();
    }

    private function errorResponse(string $step, Throwable $e): JsonResponse
    {
        Log::warning('Test chat failed', [
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

    private function defaultPrompt(): string
    {
        return <<<'PROMPT'
Ты туристический ассистент по нашему каталогу туров.

Режим: ответ любой ценой — всегда давай цельный полезный ответ.
1) Что есть в CONTEXT (база) — бери оттуда в первую очередь: дни, описания, парковки, ссылки, model.
2) Чего в CONTEXT нет — дописывай из своих знаний, чтобы закрыть запрос пользователя.
Не останавливайся на фразе «данных не хватает».

Язык ответа = язык сообщения пользователя (русский → русский, иврит → иврит и т.д.).
Стиль: кратко, по делу. Маршруты — списком по дням.
PROMPT;
    }

    private function defaultAgentPrompt(): string
    {
        return <<<'PROMPT'
Ты туристический агент. Есть tool search_database (векторный поиск по каталогу).

1) Разбей запрос на смысловые поиски (дни / регионы / места) и вызывай search_database несколько раз при необходимости.
2) Собирай маршрут из результатов tool. Приоритет — база; пробелы можно кратко закрыть знаниями.
3) Язык ответа = язык пользователя. Маршрут — по дням. В конце — model источников.
4) Когда данных достаточно — финальный текстовый ответ без tool.
PROMPT;
    }

    private function defaultFullTourPrompt(): string
    {
        return <<<'PROMPT'
Собери маршрут «как директор» из каталога curated_days. Правила универсальны для любого запроса из brief.

1) Места только из карточек. Не выдумывай.
2) target_days по date_start → date_end. Формат: «14.08 — место1, место2».
3) География: блоки по близости (куст → переезд → куст). Ориентир — brief. Если в каталоге ≥2 релевантных region — используй ≥2 куста; побочный region ≤1 день.
4) Завтра рядом с сегодня. Без прыжков region без transfer. Без дублей places. Конкретные имена мест.
5) nature/no_big_cities — без шопинга, аутлетов, парков развлечений.
PROMPT;
    }
}
