<?php

namespace App\Services;

use App\Models\GoldenDay;
use App\Models\GoldenLabel;
use App\Models\Location;

/**
 * Reverse-engineer a golden day brick: why composed + tags (kids/kosher/overnight/…).
 * Writes reverse_tags on the day; syncs known GoldenLabel slugs; lightly fills location passport_tags.
 */
class GoldenDayReverseEngineer
{
    public function __construct(private GeminiJsonClient $gemini) {}

    /**
     * @return array{tags: array<string, mixed>}
     */
    public function extract(GoldenDay $day): array
    {
        $day->loadMissing(['stops.location']);
        if ($day->stops->isEmpty()) {
            $tags = [
                'skipped' => true,
                'reason' => 'no_stops',
                'extracted_at' => now()->toIso8601String(),
            ];
            $day->reverse_tags = $tags;
            $day->save();

            return ['tags' => $tags];
        }

        $stops = [];
        foreach ($day->stops as $stop) {
            $loc = $stop->location;
            $stops[] = [
                'stop_id' => $stop->id,
                'location_id' => $stop->location_id,
                'name' => $stop->name_ru ?: $stop->name ?: ($loc?->nameFor() ?? ''),
                'lat' => $loc?->lat,
                'lng' => $loc?->lng,
                'trip_role' => $loc?->trip_role,
                'kosher_food' => $loc?->kosher_food,
                'visit_hours' => $loc?->visit_hours,
            ];
        }

        $material = json_encode([
            'golden_day_id' => $day->id,
            'title' => $day->displayTitle(),
            'hub_key' => $day->hub_key,
            'overnight_axis' => $day->overnightAxis(true),
            'start_point' => $day->start_point_ru ?: $day->start_point,
            'end_point' => $day->end_point_ru ?: $day->end_point,
            'stops' => $stops,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $instruction = <<<'PROMPT'
You reverse-engineer a READY one-day travel brick (golden day). Use ONLY the given stops (our catalog). Do not invent places.

Explain why this day is composed this way and produce structured tags.

Reply JSON only:
{
  "audience": "family"|"adults"|"mixed"|"unknown",
  "kids": "yes"|"no"|"unknown",
  "kosher": "yes"|"no"|"unknown",
  "overnight_location_id": number|null,
  "mobility": "low"|"medium"|"high"|"unknown",
  "day_load": "short"|"full"|"long"|"unknown",
  "transfer_day": true|false|null,
  "geo_link_reason": "string — why stops are linked (same hub, along road, radius…)",
  "order_reason": "string — why this stop order",
  "core_location_ids": [numbers],
  "optional_location_ids": [numbers],
  "principle": "string — 1-3 sentences: principle of this day",
  "label_slugs": ["family","no-kids","kosher-friendly","active","active-trek","city","nature","short-day","long-day"],
  "stops": [
    {
      "location_id": number,
      "overnight": true|false|null,
      "kids": "yes"|"no"|"unknown",
      "kosher_food": "yes"|"no"|"unknown",
      "visit_hours": number|null,
      "mobility": "low"|"medium"|"high"|"unknown"
    }
  ],
  "confidence": "high"|"medium"|"low"
}

Rules:
- location_id must be from the input list only.
- label_slugs: only from the allowed list, only if supported by the day.
- If unclear → unknown / null. Do not invent kosher/kids.
PROMPT;

        $raw = $this->gemini->ask($instruction, $material, 'minimal');
        $tags = $this->normalize($raw, $stops);
        $tags['extracted_at'] = now()->toIso8601String();

        $day->reverse_tags = $tags;
        $day->save();

        $this->syncLabels($day, $tags);
        $this->touchLocations($tags);

        return ['tags' => $tags];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, mixed>>  $stops
     * @return array<string, mixed>
     */
    private function normalize(array $raw, array $stops): array
    {
        $validIds = [];
        foreach ($stops as $s) {
            if (! empty($s['location_id'])) {
                $validIds[(int) $s['location_id']] = true;
            }
        }

        $audience = $raw['audience'] ?? 'unknown';
        if (! in_array($audience, ['family', 'adults', 'mixed', 'unknown'], true)) {
            $audience = 'unknown';
        }
        $kids = $raw['kids'] ?? 'unknown';
        if (! in_array($kids, ['yes', 'no', 'unknown'], true)) {
            $kids = 'unknown';
        }
        $kosher = $raw['kosher'] ?? 'unknown';
        if (! in_array($kosher, ['yes', 'no', 'unknown'], true)) {
            $kosher = 'unknown';
        }
        $mobility = $raw['mobility'] ?? 'unknown';
        if (! in_array($mobility, ['low', 'medium', 'high', 'unknown'], true)) {
            $mobility = 'unknown';
        }
        $load = $raw['day_load'] ?? 'unknown';
        if (! in_array($load, ['short', 'full', 'long', 'unknown'], true)) {
            $load = 'unknown';
        }

        $overnightId = isset($raw['overnight_location_id']) && is_numeric($raw['overnight_location_id'])
            ? (int) $raw['overnight_location_id'] : null;
        if ($overnightId && empty($validIds[$overnightId])) {
            $overnightId = null;
        }

        $allowedSlugs = [
            'family', 'no-kids', 'kosher-friendly', 'active', 'active-trek',
            'city', 'nature', 'short-day', 'long-day', 'sea', 'mountains', 'food',
        ];
        $slugs = [];
        foreach ((array) ($raw['label_slugs'] ?? []) as $slug) {
            $slug = (string) $slug;
            if (in_array($slug, $allowedSlugs, true)) {
                $slugs[] = $slug;
            }
        }
        if ($kids === 'yes' && ! in_array('family', $slugs, true)) {
            $slugs[] = 'family';
        }
        if ($kids === 'no' && ! in_array('no-kids', $slugs, true)) {
            $slugs[] = 'no-kids';
        }
        if ($kosher === 'yes' && ! in_array('kosher-friendly', $slugs, true)) {
            $slugs[] = 'kosher-friendly';
        }

        $stopTags = [];
        foreach ((array) ($raw['stops'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lid = (int) ($row['location_id'] ?? 0);
            if ($lid <= 0 || empty($validIds[$lid])) {
                continue;
            }
            $kh = $row['kosher_food'] ?? 'unknown';
            if (! in_array($kh, ['yes', 'no', 'unknown'], true)) {
                $kh = 'unknown';
            }
            $kidsS = $row['kids'] ?? 'unknown';
            if (! in_array($kidsS, ['yes', 'no', 'unknown'], true)) {
                $kidsS = 'unknown';
            }
            $mob = $row['mobility'] ?? 'unknown';
            if (! in_array($mob, ['low', 'medium', 'high', 'unknown'], true)) {
                $mob = 'unknown';
            }
            $hours = $row['visit_hours'] ?? null;
            $hours = is_numeric($hours) ? round((float) $hours, 1) : null;
            if ($hours !== null && ($hours <= 0 || $hours > 24)) {
                $hours = null;
            }
            $overnight = $row['overnight'] ?? null;
            if (! is_bool($overnight)) {
                $overnight = null;
            }
            $stopTags[] = [
                'location_id' => $lid,
                'overnight' => $overnight,
                'kids' => $kidsS,
                'kosher_food' => $kh,
                'visit_hours' => $hours,
                'mobility' => $mob,
            ];
        }

        $filterIds = function ($list) use ($validIds) {
            $out = [];
            foreach ((array) $list as $id) {
                $id = (int) $id;
                if ($id > 0 && ! empty($validIds[$id])) {
                    $out[] = $id;
                }
            }

            return array_values(array_unique($out));
        };

        return [
            'audience' => $audience,
            'kids' => $kids,
            'kosher' => $kosher,
            'overnight_location_id' => $overnightId,
            'mobility' => $mobility,
            'day_load' => $load,
            'transfer_day' => is_bool($raw['transfer_day'] ?? null) ? $raw['transfer_day'] : null,
            'geo_link_reason' => $this->str($raw['geo_link_reason'] ?? null, 500),
            'order_reason' => $this->str($raw['order_reason'] ?? null, 500),
            'principle' => $this->str($raw['principle'] ?? null, 1000),
            'core_location_ids' => $filterIds($raw['core_location_ids'] ?? []),
            'optional_location_ids' => $filterIds($raw['optional_location_ids'] ?? []),
            'label_slugs' => array_values(array_unique($slugs)),
            'stops' => $stopTags,
            'confidence' => in_array($raw['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? $raw['confidence'] : 'low',
        ];
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function syncLabels(GoldenDay $day, array $tags): void
    {
        $slugs = (array) ($tags['label_slugs'] ?? []);
        if ($slugs === []) {
            return;
        }
        $ids = GoldenLabel::query()
            ->where('status', true)
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->all();
        if ($ids !== []) {
            $day->labels()->syncWithoutDetaching($ids);
        }
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function touchLocations(array $tags): void
    {
        foreach ((array) ($tags['stops'] ?? []) as $row) {
            $lid = (int) ($row['location_id'] ?? 0);
            if ($lid <= 0) {
                continue;
            }
            $loc = Location::find($lid);
            if (! $loc) {
                continue;
            }
            $existing = is_array($loc->passport_tags) ? $loc->passport_tags : [];
            if (! empty($existing) && empty($existing['skipped']) && empty($existing['from_golden_reverse'])) {
                // Keep a fuller location passport if already present.
                continue;
            }
            $loc->passport_tags = [
                'overnight' => $row['overnight'] ?? null,
                'kids' => $row['kids'] ?? 'unknown',
                'kosher_food' => $row['kosher_food'] ?? 'unknown',
                'visit_hours' => $row['visit_hours'] ?? null,
                'mobility' => $row['mobility'] ?? 'unknown',
                'from_golden_reverse' => true,
                'extracted_at' => now()->toIso8601String(),
                'confidence' => $tags['confidence'] ?? 'low',
            ];
            if (($row['kosher_food'] ?? '') === 'yes' && ($loc->kosher_food ?? 'unknown') === 'unknown') {
                $loc->kosher_food = 'yes';
            }
            if (! empty($row['visit_hours']) && $loc->visit_hours === null) {
                $loc->visit_hours = $row['visit_hours'];
            }
            if (($row['overnight'] ?? null) === true && ! $loc->trip_role) {
                $loc->trip_role = 'base';
            }
            $loc->save();
        }
    }

    private function str(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
