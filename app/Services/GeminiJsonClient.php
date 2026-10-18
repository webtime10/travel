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
        $model = trim((string) config('services.gemini.model', 'gemini-3.6-flash'));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        $raw = $this->keys->withKey(function (string $apiKey) use ($model, $instruction, $material, $timeout, $thinking) {
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
                $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $thinking];
            }

            $response = Http::timeout($timeout)->acceptJson()->asJson()->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.$apiKey,
                $payload
            );
            if (! $response->successful()) {
                throw new RuntimeException('Gemini HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400));
            }

            return collect($response->json('candidates.0.content.parts', []))
                ->pluck('text')
                ->filter(fn ($t) => is_string($t))
                ->implode("\n");
        });

        $raw = trim((string) $raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini вернул не JSON: '.mb_substr($raw, 0, 200));
        }

        return $decoded;
    }
}
