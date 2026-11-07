<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GeminiJsonClient
{
    public function __construct(private GeminiKeyPool $keys) {}

    /**
     * Single Gemini call that must answer with a JSON object.
     *
     * @return array<string, mixed>
     */
    public function ask(string $instruction, string $material, string $thinking = 'low', int $attempts = 3): array
    {
        for ($try = 1; ; $try++) {
            try {
                return $this->askOnce($instruction, $material, $thinking);
            } catch (RuntimeException $e) {
                if ($try >= $attempts || ! str_starts_with($e->getMessage(), 'Gemini вернул не JSON')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function askOnce(string $instruction, string $material, string $thinking): array
    {
        $primary = trim((string) config('services.gemini.model', 'gemini-3.6-flash'));
        $fallback = trim((string) config('services.gemini.fallback_model', 'gemini-2.0-flash'));
        // Цепочка: primary → fallback. Мёртвые 2.0 / 2.5 / flash-latest не трогаем.
        $models = array_values(array_unique(array_filter([
            $primary,
            $fallback !== '' ? $fallback : null,
        ])));
        // Полный таймаут чтения большой; connect — короткий, чтобы мёртвый сокет не жевал 15 мин.
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));
        $connectTimeout = max(5, min(60, (int) config('services.gemini.connect_timeout', 30)));

        $raw = $this->keys->withKey(function (string $apiKey) use ($models, $instruction, $material, $timeout, $connectTimeout, $thinking) {
            $last = null;
            foreach ($models as $modelIndex => $model) {
                $payload = [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $instruction."\n\n--- SOURCE ---\n".$material]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 65536,
                        'responseMimeType' => 'application/json',
                    ],
                ];
                if (str_starts_with($model, 'gemini-3')) {
                    // gemini-3.8+ rejects MINIMAL; floor is LOW. API accepts low|medium|high.
                    $level = strtolower(trim($thinking));
                    if ($level === '' || $level === 'minimal') {
                        $level = 'low';
                    }
                    if (! in_array($level, ['low', 'medium', 'high'], true)) {
                        $level = 'low';
                    }
                    $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $level];
                }

                // 401/402/403 / hard free-tier quota → throw (KeyPool rotates).
                // Soft 429 (RPM) → backoff on the same key, then throw to rotate.
                // Transient 5xx / timeout → short retry, then next model.
                $wait = 2;
                $tries = $modelIndex === 0 ? 3 : 2;
                for ($i = 1; $i <= $tries; $i++) {
                    try {
                        $response = Http::timeout($timeout)
                            ->connectTimeout($connectTimeout)
                            ->acceptJson()
                            ->asJson()
                            ->post(
                                'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.$apiKey,
                                $payload
                            );
                    } catch (ConnectionException $e) {
                        $last = 'Gemini timeout/connect ['.$model.']: '.mb_substr($e->getMessage(), 0, 220);
                        // Следующая модель сразу — не крутить 900с×N на мёртвом endpoint.
                        if ($modelIndex < count($models) - 1) {
                            break;
                        }
                        throw new RuntimeException($last, 0, $e);
                    } catch (Throwable $e) {
                        $msg = $e->getMessage();
                        if (str_contains($msg, 'timed out') || str_contains($msg, 'cURL error 28')) {
                            $last = 'Gemini timeout ['.$model.']: '.mb_substr($msg, 0, 220);
                            if ($modelIndex < count($models) - 1) {
                                break;
                            }
                            throw new RuntimeException($last, 0, $e);
                        }
                        throw $e;
                    }

                    if ($response->successful()) {
                        $blockReason = (string) ($response->json('promptFeedback.blockReason') ?? '');
                        if ($blockReason !== '') {
                            throw new RuntimeException(
                                'Gemini blocked prompt ['.$model.']: '.$blockReason
                            );
                        }

                        $finishReason = (string) ($response->json('candidates.0.finishReason') ?? '');
                        $parts = collect($response->json('candidates.0.content.parts', []) ?: []);
                        // Gemini 3: thinking parts have thought=true — в JSON ответа их не смешиваем.
                        $text = $parts
                            ->filter(static function ($part): bool {
                                if (! is_array($part)) {
                                    return false;
                                }
                                if (! empty($part['thought'])) {
                                    return false;
                                }

                                return is_string($part['text'] ?? null) && trim((string) $part['text']) !== '';
                            })
                            ->map(static fn (array $part): string => (string) $part['text'])
                            ->implode("\n");

                        if (trim($text) === '') {
                            $last = 'Gemini empty answer ['.$model.']'
                                .($finishReason !== '' ? ' finishReason='.$finishReason : '');
                            if ($modelIndex < count($models) - 1) {
                                break;
                            }
                            throw new RuntimeException($last);
                        }

                        if ($finishReason !== '' && ! in_array($finishReason, ['STOP', 'MAX_TOKENS'], true)) {
                            $last = 'Gemini finishReason='.$finishReason.' ['.$model.']: '.mb_substr($text, 0, 200);
                            if (in_array($finishReason, ['SAFETY', 'RECITATION', 'OTHER', 'BLOCKLIST'], true)) {
                                throw new RuntimeException($last);
                            }
                        }

                        return $text;
                    }
                    $status = $response->status();
                    $body = mb_substr($response->body(), 0, 400);
                    $last = 'Gemini HTTP '.$status.' ['.$model.']: '.$body;

                    if (in_array($status, [401, 402, 403], true)) {
                        throw new RuntimeException($last);
                    }

                    if ($status === 429) {
                        if ($this->isHardQuota($body) || $i >= $tries) {
                            // Try next model if any, else rotate key.
                            if ($modelIndex < count($models) - 1) {
                                break;
                            }
                            throw new RuntimeException($last);
                        }
                        sleep(min(30, $wait * 2));
                        $wait = min(30, $wait * 2);

                        continue;
                    }

                    if (in_array($status, [500, 502, 503, 504], true) && $i < $tries) {
                        // 503 high demand — подождать подольше, чем на 5xx.
                        $pause = $status === 503 ? min(45, max(8, $wait * 3)) : $wait;
                        sleep($pause);
                        $wait = min(30, $wait * 2);

                        continue;
                    }

                    // Exhausted retries on this model → try fallback if available.
                    if ($modelIndex < count($models) - 1 && in_array($status, [500, 502, 503, 504, 404], true)) {
                        break;
                    }
                    throw new RuntimeException($last);
                }
            }

            throw new RuntimeException($last ?? 'Gemini JSON empty');
        });

        $raw = trim((string) $raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini вернул не JSON: '.mb_substr($raw, 0, 200));
        }

        return $decoded;
    }

    private function isHardQuota(string $body): bool
    {
        $b = mb_strtolower($body);

        return str_contains($b, 'free_tier')
            || str_contains($b, 'free tier')
            || str_contains($b, 'generate_content_free_tier')
            || str_contains($b, 'check your plan and billing')
            || str_contains($b, 'credits are depleted')
            || str_contains($b, 'prepayment')
            || str_contains($b, 'billing details');
    }
}
