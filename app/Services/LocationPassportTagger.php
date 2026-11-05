<?php

namespace App\Services;

use App\Models\Location;

/**
 * Trial: ask Gemini to propose passport tags from source Word text.
 * Does NOT modify documents / meta text — only stores draft in passport_tags.
 */
class LocationPassportTagger
{
    public function __construct(private GeminiJsonClient $gemini) {}

    public function hasSourceText(Location $location): bool
    {
        try {
            $src = app(LocationFactExtractor::class)->sources($location);

            return mb_strlen(trim($src['text'])) > 50;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{tags: array<string, mixed>, source_chars: int}
     */
    public function extract(Location $location): array
    {
        $extractor = app(LocationFactExtractor::class);
        $src = $extractor->sources($location);
        $title = (string) ($location->heMeta()['title'] ?? $location->model ?? '#'.$location->id);
        $material = mb_substr($src['material'], 0, 28000);

        $instruction = <<<PROMPT
You extract STRUCTURED PASSPORT TAGS for a travel location from the source guide text.
Location title: "{$title}".

Rules:
- Use ONLY what the text supports. If unclear, use null / "unknown" — do NOT invent.
- Keep short evidence quotes from the source (same language as source) when possible.
- Do NOT rewrite or summarize the whole article — tags only.
- Reply with JSON only, no markdown.

Schema:
{
  "overnight": true|false|null,
  "trip_role": "base"|"place"|"end"|null,
  "kids": "yes"|"no"|"unknown"|null,
  "kosher_food": "yes"|"no"|"unknown"|null,
  "visit_hours": number|null,
  "visit_hours_note": string|null,
  "mobility": "low"|"medium"|"high"|"unknown"|null,
  "notes": string|null,
  "evidence": {
    "overnight": string|null,
    "kids": string|null,
    "kosher_food": string|null,
    "visit_hours": string|null,
    "mobility": string|null
  },
  "confidence": "high"|"medium"|"low"
}

Meaning:
- overnight / trip_role base = suitable as overnight base (hotel/camping/lodge mentioned as stay).
- kids = suitable for children / family.
- kosher_food = kosher food available or explicitly kosher-friendly.
- visit_hours = typical time to spend here in hours (e.g. 1.5, 3, 8). If only "half day"/"full day", convert roughly.
- mobility: low = calm/local, medium = some transfer, high = active/trek/long drive day feel for THIS place alone.
PROMPT;

        $decoded = $this->gemini->ask($instruction, $material, 'minimal');

        $tags = $this->normalize($decoded);
        $tags['extracted_at'] = now()->toIso8601String();
        $tags['source_chars'] = mb_strlen($src['text']);

        $location->passport_tags = $tags;
        $location->save();

        return [
            'tags' => $tags,
            'source_chars' => (int) $tags['source_chars'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $overnight = $raw['overnight'] ?? null;
        if (! is_bool($overnight) && $overnight !== null) {
            $overnight = null;
        }

        $tripRole = $raw['trip_role'] ?? null;
        if (! in_array($tripRole, ['base', 'place', 'end'], true)) {
            $tripRole = null;
        }

        $kids = $raw['kids'] ?? null;
        if (! in_array($kids, ['yes', 'no', 'unknown'], true)) {
            $kids = 'unknown';
        }

        $kosher = $raw['kosher_food'] ?? null;
        if (! in_array($kosher, ['yes', 'no', 'unknown'], true)) {
            $kosher = 'unknown';
        }

        $hours = $raw['visit_hours'] ?? null;
        if ($hours !== null && is_numeric($hours)) {
            $hours = round((float) $hours, 1);
            if ($hours <= 0 || $hours > 24) {
                $hours = null;
            }
        } else {
            $hours = null;
        }

        $mobility = $raw['mobility'] ?? null;
        if (! in_array($mobility, ['low', 'medium', 'high', 'unknown'], true)) {
            $mobility = 'unknown';
        }

        $confidence = $raw['confidence'] ?? 'low';
        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'low';
        }

        $evidence = is_array($raw['evidence'] ?? null) ? $raw['evidence'] : [];

        return [
            'overnight' => $overnight,
            'trip_role' => $tripRole,
            'kids' => $kids,
            'kosher_food' => $kosher,
            'visit_hours' => $hours,
            'visit_hours_note' => $this->strOrNull($raw['visit_hours_note'] ?? null),
            'mobility' => $mobility,
            'notes' => $this->strOrNull($raw['notes'] ?? null),
            'evidence' => [
                'overnight' => $this->strOrNull($evidence['overnight'] ?? null),
                'kids' => $this->strOrNull($evidence['kids'] ?? null),
                'kosher_food' => $this->strOrNull($evidence['kosher_food'] ?? null),
                'visit_hours' => $this->strOrNull($evidence['visit_hours'] ?? null),
                'mobility' => $this->strOrNull($evidence['mobility'] ?? null),
            ],
            'confidence' => $confidence,
        ];
    }

    private function strOrNull(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, 500);
    }
}
