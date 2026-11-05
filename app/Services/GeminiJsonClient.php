<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

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
        $fallback = trim((string) config('services.gemini.fallback_model', 'gemini-2.5-flash'));
        // Цепочка при 503 high demand: primary → fallback → более «старые» стабильные.
        $models = array_values(array_unique(array_filter([
            $primary,
            $fallback,
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-flash-latest',
        ])));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        $raw = $this->keys->withKey(function (string $apiKey) use ($models, $instruction, $material, $timeout, $thinking) {
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
                    // gemini-3.8+ rejects MINIMAL; map to LOW.
                    $level = strtoupper($thinking) === 'MINIMAL' && str_contains($model, '3.8')
                        ? 'low'
                        : $thinking;
                    $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $level];
                }

                // 401/402/403 / hard free-tier quota → throw (KeyPool rotates).
                // Soft 429 (RPM) → backoff on the same key, then throw to rotate.
                // Transient 5xx → short retry on the same key, then try fallback model.
                $wait = 2;
                $tries = $modelIndex === 0 ? 3 : 2;
                for ($i = 1; $i <= $tries; $i++) {
                    $response = Http::timeout($timeout)->acceptJson()->asJson()->post(
                        'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.$apiKey,
                        $payload
                    );
                    if ($response->successful()) {
                        return collect($response->json('candidates.0.content.parts', []))
                            ->pluck('text')
                            ->filter(fn ($t) => is_string($t))
                            ->implode("\n");
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
