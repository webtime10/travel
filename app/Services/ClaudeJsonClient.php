<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude (Anthropic) JSON ask — для FT Short reverse engineering.
 */
class ClaudeJsonClient
{
    /**
     * @return array<string, mixed>
     */
    public function ask(string $instruction, string $material, int $attempts = 3): array
    {
        for ($try = 1; ; $try++) {
            try {
                return $this->askOnce($instruction, $material);
            } catch (RuntimeException $e) {
                if ($try >= $attempts || ! str_starts_with($e->getMessage(), 'Claude вернул не JSON')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function askOnce(string $instruction, string $material): array
    {
        $apiKey = trim((string) config('services.claude.key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('CLAUDE_API_KEY пуст — добавь ключ в .env');
        }

        $model = trim((string) config('services.claude.model', 'claude-sonnet-5-5'));
        $timeout = max(60, (int) config('services.claude.chat_timeout', 900));
        $maxTokens = max(2048, (int) config('services.claude.max_tokens', 16384));

        $response = Http::timeout($timeout)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->asJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $instruction."\n\nОтветь одним JSON-объектом без markdown fences.",
                'messages' => [[
                    'role' => 'user',
                    'content' => "--- SOURCE ---\n".$material,
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Claude HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400)
            );
        }

        $parts = $response->json('content') ?? [];
        $raw = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $raw .= (string) $part['text'];
                }
            }
        }
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;

        // На случай текста вокруг JSON.
        if ($raw !== '' && ($raw[0] ?? '') !== '{') {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $raw = substr($raw, $start, $end - $start + 1);
            }
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Claude вернул не JSON: '.mb_substr($raw, 0, 200));
        }

        return $decoded;
    }
}
