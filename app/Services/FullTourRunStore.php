<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FullTourRunStore
{
    /** Keep full-tour runs for restore after page reload / return next day. */
    private const TTL_HOURS = 48;

    /**
     * File store: HE/RU payloads exceed MySQL max_allowed_packet (4MB) and
     * get truncated mid-UTF-8 → "Incorrect string value" on database cache.
     */
    private function cache(): Repository
    {
        return Cache::store('file');
    }

    private function key(string $runId): string
    {
        return 'travel.full_tour.run.'.$runId;
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addHours(self::TTL_HOURS);
    }

    public function create(string $message): string
    {
        $runId = (string) Str::uuid();
        $this->cache()->put($this->key($runId), [
            'status' => 'queued',
            'message' => $message,
            'steps' => [
                [
                    'type' => 'start',
                    'title' => 'В очереди',
                    'detail' => 'Ждём worker: php artisan queue:work',
                ],
            ],
            'mode' => 'preview',
            'reply' => null,
            'sources' => [],
            'tours' => [],
            'product_id' => null,
            'model' => null,
            'meta_lang' => null,
            'search_ms' => null,
            'format_ms' => null,
            'total_ms' => null,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ], $this->ttl());

        return $runId;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function patch(string $runId, array $patch): void
    {
        $data = $this->cache()->get($this->key($runId), []);
        if (! is_array($data)) {
            $data = [];
        }
        if (isset($patch['sources']) && is_array($patch['sources'])) {
            $patch['sources'] = $this->compactSources($patch['sources']);
        }
        if (isset($patch['tours']) && is_array($patch['tours'])) {
            $patch['tours'] = $this->compactSources($patch['tours']);
        }
        if (isset($patch['curated_days']) && is_array($patch['curated_days'])) {
            $patch['curated_days'] = $this->compactDays($patch['curated_days']);
        }
        if (isset($patch['cleaned_days']) && is_array($patch['cleaned_days'])) {
            $patch['cleaned_days'] = $this->compactDays($patch['cleaned_days']);
        }
        if (isset($patch['dropped_days']) && is_array($patch['dropped_days'])) {
            $patch['dropped_days'] = $this->compactDays($patch['dropped_days'], 80);
        }
        if (isset($patch['clean_dropped_days']) && is_array($patch['clean_dropped_days'])) {
            $patch['clean_dropped_days'] = $this->compactDays($patch['clean_dropped_days'], 80);
        }
        if (isset($patch['curated_text']) && is_string($patch['curated_text'])) {
            $patch['curated_text'] = $this->safeUtf8($patch['curated_text'], 200000);
        }
        if (isset($patch['cleaned_text']) && is_string($patch['cleaned_text'])) {
            $patch['cleaned_text'] = $this->safeUtf8($patch['cleaned_text'], 200000);
        }
        if (isset($patch['brief_note']) && is_string($patch['brief_note'])) {
            $patch['brief_note'] = $this->safeUtf8($patch['brief_note'], 2000);
        }
        if (isset($patch['clean_note']) && is_string($patch['clean_note'])) {
            $patch['clean_note'] = $this->safeUtf8($patch['clean_note'], 2000);
        }
        if (isset($patch['error']) && is_string($patch['error'])) {
            $patch['error'] = $this->safeUtf8($patch['error'], 4000);
        }
        if (isset($patch['steps']) && is_array($patch['steps'])) {
            $patch['steps'] = $this->utf8Deep($patch['steps']);
        }

        $merged = $this->utf8Deep(array_merge($data, $patch, [
            'updated_at' => now()->toIso8601String(),
        ]));
        if (! is_array($merged)) {
            $merged = ['status' => 'failed', 'error' => 'Invalid run payload'];
        }

        $this->cache()->put($this->key($runId), $merged, $this->ttl());
    }

    /**
     * Keep cache small: drop huge preview bodies from accordion list payloads.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function compactSources(array $sources): array
    {
        $out = [];
        foreach ($sources as $src) {
            if (! is_array($src)) {
                continue;
            }
            $preview = $this->safeUtf8((string) ($src['preview_text'] ?? ''), 600);
            $deferred = ! empty($src['preview_deferred']) || $preview === '';
            if ($deferred) {
                $preview = '';
            }
            $routeLines = [];
            if (is_array($src['route_lines'] ?? null)) {
                foreach (array_slice($src['route_lines'], 0, 12) as $line) {
                    if (! is_string($line)) {
                        continue;
                    }
                    $line = $this->safeUtf8(trim($line), 220);
                    if ($line === '') {
                        continue;
                    }
                    $routeLines[] = $line;
                }
            }
            if ($preview === '' && $routeLines !== []) {
                $preview = implode("\n", $routeLines);
                $deferred = false;
            }
            $matched = [];
            foreach ((is_array($src['matched_locations'] ?? null) ? $src['matched_locations'] : []) as $loc) {
                $loc = $this->safeUtf8((string) $loc, 80);
                if ($loc !== '') {
                    $matched[] = $loc;
                }
            }
            $out[] = [
                'product_id' => $src['product_id'] ?? null,
                'model' => isset($src['model']) && $src['model'] !== null ? $this->safeUtf8((string) $src['model'], 40) : null,
                'title' => isset($src['title']) ? $this->safeUtf8((string) $src['title'], 200) : null,
                'score' => $src['score'] ?? null,
                'estimated_days' => $src['estimated_days'] ?? null,
                'meta_lang' => $src['meta_lang'] ?? null,
                'block_count' => $src['block_count'] ?? 0,
                'matched_locations' => $matched,
                'route_lines' => $routeLines,
                'route_line_count' => (int) ($src['route_line_count'] ?? count($routeLines)),
                'match_note' => isset($src['match_note']) ? $this->safeUtf8((string) $src['match_note'], 200) : null,
                'preview_text' => $preview,
                'preview_text_ru' => isset($src['preview_text_ru']) && is_string($src['preview_text_ru'])
                    ? $this->safeUtf8($src['preview_text_ru'], 600)
                    : ($src['preview_text_ru'] ?? null),
                'preview_deferred' => $deferred,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function compactDays(array $days, int $bodyMax = 500): array
    {
        $out = [];
        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $summary = $this->safeUtf8(trim((string) ($day['summary'] ?? '')), 180);
            $body = $this->safeUtf8(trim((string) ($day['body'] ?? '')), $bodyMax);
            $places = [];
            foreach ((is_array($day['places'] ?? null) ? $day['places'] : []) as $p) {
                $p = $this->safeUtf8(trim((string) $p), 80);
                if ($p === '') {
                    continue;
                }
                $places[] = $p;
                if (count($places) >= 8) {
                    break;
                }
            }
            $placesGeo = [];
            foreach ((is_array($day['places_geo'] ?? null) ? $day['places_geo'] : []) as $pg) {
                if (! is_array($pg)) {
                    continue;
                }
                $placesGeo[] = [
                    'name' => $this->safeUtf8((string) ($pg['name'] ?? ''), 80),
                    'lat' => isset($pg['lat']) ? (float) $pg['lat'] : null,
                    'lng' => isset($pg['lng']) ? (float) $pg['lng'] : null,
                    'source' => isset($pg['source']) ? $this->safeUtf8((string) $pg['source'], 20) : null,
                ];
                if (count($placesGeo) >= 8) {
                    break;
                }
            }
            $row = [
                'id' => $day['id'] ?? null,
                'product_id' => $day['product_id'] ?? null,
                'model' => isset($day['model']) && $day['model'] !== null ? $this->safeUtf8((string) $day['model'], 40) : null,
                'places' => $places,
                'region' => $day['region'] ?? 'other',
                'summary' => $summary,
                'kind' => $day['kind'] ?? 'program',
                'body' => $body,
                'fit' => $day['fit'] ?? true,
                'drop_reason' => isset($day['drop_reason']) ? $this->safeUtf8((string) $day['drop_reason'], 120) : null,
            ];
            if (isset($day['lat']) && $day['lat'] !== null) {
                $row['lat'] = (float) $day['lat'];
            }
            if (isset($day['lng']) && $day['lng'] !== null) {
                $row['lng'] = (float) $day['lng'];
            }
            if (isset($day['geo_span_km']) && is_numeric($day['geo_span_km'])) {
                $row['geo_span_km'] = (float) $day['geo_span_km'];
            }
            if (array_key_exists('geo_ok', $day)) {
                $row['geo_ok'] = (bool) $day['geo_ok'];
            }
            if ($placesGeo !== []) {
                $row['places_geo'] = $placesGeo;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Strip invalid UTF-8 so json_encode / file cache never blow up on HE corpus bytes.
     */
    private function safeUtf8(string $value, ?int $maxChars = null): string
    {
        if ($value === '') {
            return '';
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = is_string($fixed) ? $fixed : '';
        }
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        // Drop leftover control chars except newline/tab
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
        if ($maxChars !== null && mb_strlen($value) > $maxChars) {
            $value = mb_substr($value, 0, $maxChars).'…';
        }

        return $value;
    }

    private function utf8Deep(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->safeUtf8($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $item) {
            $out[$k] = $this->utf8Deep($item);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $runId): ?array
    {
        $data = $this->cache()->get($this->key($runId));
        if (! is_array($data)) {
            return null;
        }
        $clean = $this->utf8Deep($data);

        return is_array($clean) ? $clean : null;
    }
}
