<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Multi-step agent RAG: Gemini chooses search_database tool, Laravel runs RagSearchService.
 */
class GeminiAgentRagService
{
    private const MAX_STEPS = 6;

    public function __construct(
        private GeminiKeyPool $keys,
        private RagSearchService $rag,
    ) {}

    /**
     * @return array{
     *   reply: string,
     *   steps: list<array<string, mixed>>,
     *   sources: list<array{product_id: int, model: string|null, title: string|null, score: float}>,
     *   tool_calls: int,
     *   total_ms: int
     * }
     */
    public function run(string $userMessage, ?string $systemPrompt = null): array
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            throw new RuntimeException('Empty message');
        }

        $t0 = microtime(true);
        $steps = [];
        $allSources = [];
        $toolCalls = 0;

        $systemPrompt = trim((string) ($systemPrompt ?: $this->defaultSystemPrompt()));
        $contents = [
            [
                'role' => 'user',
                'parts' => [['text' => mb_substr($userMessage, 0, 12000)]],
            ],
        ];

        $steps[] = [
            'type' => 'start',
            'title' => 'Старт агента',
            'detail' => 'Передан запрос пользователя. Gemini может вызывать search_database несколько раз.',
        ];

        for ($step = 1; $step <= self::MAX_STEPS; $step++) {
            $steps[] = [
                'type' => 'think',
                'title' => 'Итерация '.$step.' — запрос к Gemini',
                'detail' => 'Модель решает: вызвать search_database или дать финальный ответ.',
            ];

            $response = $this->generate($contents, $systemPrompt);
            $candidate = $response['candidates'][0]['content'] ?? null;
            if (! is_array($candidate)) {
                throw new RuntimeException('Agent: empty candidate from Gemini');
            }

            $parts = $candidate['parts'] ?? [];
            if (! is_array($parts) || $parts === []) {
                throw new RuntimeException('Agent: empty parts from Gemini');
            }

            $functionCalls = [];
            $textChunks = [];
            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                    $functionCalls[] = $part;
                }
                if (isset($part['text'])) {
                    $textChunks[] = (string) $part['text'];
                }
            }

            // Append model turn exactly (keeps thoughtSignature / id for Gemini 3).
            $contents[] = [
                'role' => 'model',
                'parts' => $parts,
            ];

            if ($functionCalls === []) {
                $reply = trim(implode("\n", $textChunks));
                if ($reply === '') {
                    throw new RuntimeException('Agent: final reply empty');
                }
                $steps[] = [
                    'type' => 'final',
                    'title' => 'Финальный ответ',
                    'detail' => 'Данных достаточно — Gemini собрал ответ без нового поиска.',
                ];

                return [
                    'reply' => $reply,
                    'steps' => $steps,
                    'sources' => $this->uniqueSources($allSources),
                    'tool_calls' => $toolCalls,
                    'total_ms' => (int) round((microtime(true) - $t0) * 1000),
                ];
            }

            $responseParts = [];
            foreach ($functionCalls as $part) {
                $call = $part['functionCall'];
                $name = (string) ($call['name'] ?? '');
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $callId = isset($call['id']) ? (string) $call['id'] : null;

                if ($name !== 'search_database') {
                    $payload = [
                        'ok' => false,
                        'error' => 'Unknown tool: '.$name,
                    ];
                    $steps[] = [
                        'type' => 'tool_error',
                        'title' => 'Неизвестный tool: '.$name,
                        'detail' => json_encode($args, JSON_UNESCAPED_UNICODE),
                    ];
                } else {
                    $query = trim((string) ($args['query'] ?? ''));
                    $limit = max(1, min(12, (int) ($args['limit'] ?? 8)));
                    $steps[] = [
                        'type' => 'tool_call',
                        'title' => 'Tool: search_database',
                        'detail' => 'query="'.$query.'" · limit='.$limit,
                        'query' => $query,
                    ];

                    $tSearch = microtime(true);
                    try {
                        $result = $this->rag->search($query, $limit);
                        $hits = $result['hits'];
                        $context = $this->rag->buildContext($hits);
                        $sources = $this->rag->sources($hits);
                        foreach ($sources as $s) {
                            $allSources[] = $s;
                        }
                        $toolCalls++;
                        $ms = (int) round((microtime(true) - $tSearch) * 1000);
                        $payload = [
                            'ok' => true,
                            'query' => $query,
                            'hits' => count($hits),
                            'scanned' => $result['scanned'],
                            'model_filter' => $result['model_filter'],
                            'embed_ms' => $result['embed_ms'],
                            'search_ms' => $result['search_ms'],
                            'elapsed_ms' => $ms,
                            'sources' => $sources,
                            'context' => mb_substr($context, 0, 16000),
                        ];
                        $steps[] = [
                            'type' => 'tool_result',
                            'title' => 'Результат поиска',
                            'detail' => 'hits='.count($hits)
                                .' · scanned='.$result['scanned']
                                .' · context='.mb_strlen($context).' chars'
                                .' · '.$ms.'ms'
                                .($result['model_filter'] ? ' · filter='.$result['model_filter'] : ''),
                            'sources' => $sources,
                        ];
                    } catch (\Throwable $e) {
                        Log::warning('Agent search_database failed', ['message' => $e->getMessage()]);
                        $payload = [
                            'ok' => false,
                            'error' => $e->getMessage(),
                        ];
                        $steps[] = [
                            'type' => 'tool_error',
                            'title' => 'Ошибка search_database',
                            'detail' => $e->getMessage(),
                        ];
                    }
                }

                $fnResponse = [
                    'name' => $name !== '' ? $name : 'search_database',
                    'response' => $payload,
                ];
                if ($callId !== null && $callId !== '') {
                    $fnResponse['id'] = $callId;
                }
                $responseParts[] = ['functionResponse' => $fnResponse];
            }

            $contents[] = [
                'role' => 'user',
                'parts' => $responseParts,
            ];
        }

        throw new RuntimeException('Agent: exceeded max steps ('.self::MAX_STEPS.') without final answer');
    }

    /**
     * @param  list<array{role: string, parts: list<array<string, mixed>>}>  $contents
     * @return array<string, mixed>
     */
    private function generate(array $contents, string $systemPrompt): array
    {
        $model = trim((string) config('services.gemini.model', 'gemini-3.6-flash'));
        $timeout = max(30, (int) config('services.gemini.chat_timeout', 900));

        return $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $contents, $systemPrompt) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':generateContent?key='.$apiKey;

            $payload = [
                'contents' => $contents,
                'tools' => [
                    [
                        'functionDeclarations' => [
                            [
                                'name' => 'search_database',
                                'description' => 'Search the indexed travel catalog (PostgreSQL RAG / embeddings). '
                                    .'Use for places, days, attractions, parks, parking, regions (Garda, Venice, etc.). '
                                    .'Call multiple times with different queries to cover each day or theme.',
                                'parameters' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'query' => [
                                            'type' => 'string',
                                            'description' => 'Search query in Hebrew or Russian, e.g. "Сирмионе Гардаленд семья"',
                                        ],
                                        'limit' => [
                                            'type' => 'integer',
                                            'description' => 'Max hits to return (1-12). Default 8.',
                                        ],
                                    ],
                                    'required' => ['query'],
                                ],
                            ],
                        ],
                    ],
                ],
                'toolConfig' => [
                    'functionCallingConfig' => [
                        'mode' => 'AUTO',
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.35,
                    'maxOutputTokens' => 8192,
                ],
            ];

            if ($systemPrompt !== '') {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => mb_substr($systemPrompt, 0, 20000)]],
                ];
            }
            if (str_starts_with($model, 'gemini-3')) {
                $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
            }

            $wait = 2;
            $last = 'Agent Gemini failed';
            for ($i = 1; $i <= 4; $i++) {
                $response = Http::timeout($timeout)->acceptJson()->asJson()->post($url, $payload);
                if ($response->successful()) {
                    $json = $response->json();
                    if (! is_array($json)) {
                        throw new RuntimeException('Agent: non-JSON Gemini response');
                    }

                    return $json;
                }
                $last = 'Agent Gemini HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400);
                if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $i < 4) {
                    sleep($wait);
                    $wait = min(30, $wait * 2);

                    continue;
                }
                throw new RuntimeException($last);
            }

            throw new RuntimeException($last);
        });
    }

    /**
     * @param  list<array{product_id: int, model: string|null, title: string|null, score: float}>  $sources
     * @return list<array{product_id: int, model: string|null, title: string|null, score: float}>
     */
    private function uniqueSources(array $sources): array
    {
        $seen = [];
        $out = [];
        foreach ($sources as $s) {
            $id = (int) ($s['product_id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $s;
        }

        return $out;
    }

    private function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты туристический агент по каталогу туров. У тебя есть инструмент search_database.

Правила:
1) Сначала ищи в базе через search_database (можно несколько раз: по дням, регионам, местам).
2) Собирай маршрут из найденного CONTEXT инструмента. Если чего-то нет в базе — можно кратко дополнить своими знаниями, но приоритет у базы.
3) Язык ответа = язык пользователя.
4) Формат маршрутов: по дням, кратко. В конце укажи model источников, если были.
5) Когда данных достаточно — ответь обычным текстом без вызова tool.
PROMPT;
    }
}
