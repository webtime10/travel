<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiChatService
{
    public function __construct(private GeminiKeyPool $keys) {}

    /**
     * Simple chat reply for Test page.
     *
     * @param  list<array{role?: string, text?: string}>  $history
     */
    public function reply(
        string $userMessage,
        array $history = [],
        ?string $systemPrompt = null,
        ?string $ragContext = null,
    ): string {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            throw new RuntimeException('Empty message');
        }

        $model = trim((string) config('services.gemini.model', 'gemini-3.6-flash'));
        $timeout = max(30, (int) config('services.gemini.chat_timeout', 900));
        $systemPrompt = $this->composeSystemPrompt($systemPrompt, $ragContext);

        $contents = [];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => mb_substr($text, 0, 8000)]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => mb_substr($userMessage, 0, 8000)]],
        ];

        return $this->keys->withKey(function (string $apiKey) use ($model, $timeout, $contents, $systemPrompt) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':generateContent?key='.$apiKey;

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => 4096,
                ],
            ];
            if ($systemPrompt !== '') {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => mb_substr($systemPrompt, 0, 45000)]],
                ];
            }
            if (str_starts_with($model, 'gemini-3')) {
                $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
            }

            $wait = 2;
            $last = null;
            for ($i = 1; $i <= 4; $i++) {
                $response = Http::timeout($timeout)->acceptJson()->asJson()->post($url, $payload);
                if ($response->successful()) {
                    $parts = $response->json('candidates.0.content.parts') ?? [];
                    $chunks = [];
                    if (is_array($parts)) {
                        foreach ($parts as $part) {
                            if (is_array($part) && isset($part['text'])) {
                                $chunks[] = (string) $part['text'];
                            }
                        }
                    }
                    $text = trim(implode("\n", $chunks));
                    if ($text === '') {
                        throw new RuntimeException('Empty Gemini chat reply');
                    }

                    return $text;
                }
                $last = 'Gemini chat HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300);
                if ($response->status() === 429 && $i < 4) {
                    sleep($wait);
                    $wait = min(30, $wait * 2);

                    continue;
                }
                throw new RuntimeException($last);
            }
            throw new RuntimeException($last ?? 'Gemini chat failed');
        });
    }

    private function composeSystemPrompt(?string $systemPrompt, ?string $ragContext): string
    {
        $systemPrompt = trim((string) $systemPrompt);
        $ragContext = trim((string) $ragContext);

        $ragRules = $ragContext !== ''
            ? "ГИБРИДНЫЙ РЕЖИМ (ответ любой ценой — цельный полезный ответ обязателен):\n"
                ."- Приоритет 1: всё, что есть в CONTEXT — используй дословно по смыслу (описания дней, адреса парковок, Waze/Google Maps, model). Не подменяй это «общими знаниями».\n"
                ."- Приоритет 2: если пользователь просит места/детали, которых НЕТ в CONTEXT (или CONTEXT обрывочный) — ДОПИСАЙ их из своих знаний, чтобы маршрут/ответ был полным. Не отказывайся и не отвечай только «данных не хватает».\n"
                ."- Если уместно, укажи model из CONTEXT для кусков из базы.\n"
                ."- Язык ответа = язык сообщения пользователя.\n"
                ."- Стиль: кратко, по делу; маршруты — списком по дням.\n\n"
                ."CONTEXT:\n".$ragContext
            : "Векторный поиск не нашёл CONTEXT в каталоге. Всё равно дай полезный ответ по запросу из своих знаний (туристический помощник). "
                .'Язык ответа = язык сообщения пользователя. Кратко, по делу.';

        if ($systemPrompt === '') {
            return $ragRules;
        }

        return $systemPrompt."\n\n".$ragRules;
    }
}
