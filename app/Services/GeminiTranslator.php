<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiTranslator
{
    /**
     * Translate Hebrew (or mixed) strings to Russian. Keys preserved.
     *
     * @param  array<int|string, string>  $items  id => source text
     * @return array<int|string, string> id => Russian text
     */
    public function translateMap(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $apiKey = trim((string) config('services.gemini.key', ''));
        $model = trim((string) config('services.gemini.model', 'gemini-3.6-flash'));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is empty');
        }

        $payloadItems = [];
        foreach ($items as $id => $text) {
            // Cap very long blocks so one monster line cannot break the whole batch
            $t = $text;
            if (mb_strlen($t) > 2500) {
                $t = mb_substr($t, 0, 2500).'…';
            }
            $payloadItems[] = [
                'id' => (string) $id,
                'text' => $t,
            ];
        }

        $instruction = <<<'PROMPT'
You are a professional translator HE→RU for Italian travel itineraries.
Translate each item's "text" from Hebrew (may contain dates, place names, URLs, English) into natural Russian.
Rules:
- Keep numbers, dates, URLs, emails, map links unchanged.
- Keep proper place names recognizable (Capri/Капри, Rome/Рим, etc.).
- Do not merge or drop items. Return the same ids.
- Reply with a single JSON object only, no markdown fences, no commentary.
- Schema: {"items":[{"id":"0","text_ru":"..."},{"id":"1","text_ru":"..."}]}
PROMPT;

        $material = json_encode(['items' => $payloadItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->chat($apiKey, $model, (string) $material, $instruction, $timeout);
        if ($raw === null || trim($raw) === '') {
            throw new RuntimeException('Empty Gemini response');
        }

        $out = $this->parseTranslationJson($raw);
        if ($out === []) {
            // one retry with stricter reminder
            $raw2 = $this->chat(
                $apiKey,
                $model,
                (string) $material,
                $instruction."\nIMPORTANT: Your previous reply was invalid. Output ONLY valid JSON object.",
                $timeout
            );
            if (is_string($raw2) && trim($raw2) !== '') {
                $out = $this->parseTranslationJson($raw2);
            }
        }

        if ($out === []) {
            throw new RuntimeException('Gemini returned non-JSON: '.mb_substr(preg_replace('/\s+/u', ' ', $raw) ?? $raw, 0, 180));
        }

        return $out;
    }

    /**
     * @return array<int|string, string>
     */
    private function parseTranslationJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $slice = $this->extractJsonObject($raw);
            if ($slice !== null) {
                $decoded = json_decode($slice, true);
            }
        }
        if (! is_array($decoded)) {
            return [];
        }

        $list = $decoded['items'] ?? null;
        if (! is_array($list)) {
            // Sometimes model returns a bare array
            if (array_is_list($decoded)) {
                $list = $decoded;
            } else {
                return [];
            }
        }

        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $ru = $row['text_ru'] ?? $row['text'] ?? $row['translation'] ?? null;
            if ($id === null || ! is_string($ru) || trim($ru) === '') {
                continue;
            }
            $out[(string) $id] = trim($ru);
        }

        return $out;
    }

    private function extractJsonObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            $start = strpos($raw, '[');
            if ($start === false) {
                return null;
            }
        }

        $open = $raw[$start];
        $close = $open === '{' ? '}' : ']';
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($raw);

        for ($i = $start; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function chat(string $apiKey, string $model, string $material, string $instruction, int $timeout): ?string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent?key='.$apiKey;

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $instruction."\n\n--- SOURCE JSON ---\n".$material]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 65536,
                'responseMimeType' => 'application/json',
            ],
        ];

        if (str_starts_with($model, 'gemini-3')) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini HTTP '.$response->status().': '.mb_substr($response->body(), 0, 400));
        }

        $data = $response->json();
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts) || $parts === []) {
            return null;
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $chunks[] = $part['text'];
            }
        }

        return $chunks === [] ? null : implode("\n", $chunks);
    }
}
