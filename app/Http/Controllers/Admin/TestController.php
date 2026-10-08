<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\GeminiChatService;
use App\Services\GeminiKeyPool;
use App\Services\RagSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestController extends Controller
{
    public const PROMPT_KEY = 'test.system_prompt';

    public function index(GeminiKeyPool $keys)
    {
        $pageTitle = 'Test';
        $systemPrompt = AppSetting::getValue(self::PROMPT_KEY);
        if ($systemPrompt === null || trim($systemPrompt) === '') {
            $systemPrompt = $this->defaultPrompt();
        }

        return view('admin.test.index', [
            'pageTitle' => $pageTitle,
            'geminiKeys' => $keys->count(),
            'systemPrompt' => $systemPrompt,
        ]);
    }

    public function savePrompt(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'nullable|string|max:50000',
        ]);

        $prompt = (string) $request->input('prompt', '');
        AppSetting::putValue(self::PROMPT_KEY, $prompt);

        return response()->json([
            'ok' => true,
            'message' => 'Prompt saved',
            'length' => mb_strlen($prompt),
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
}
