<?php

namespace App\Services\Itinerary;

use App\Models\PlaceCoordinate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve place names → lat/lng via seed dictionary + Nominatim cache.
 */
class PlaceGeocoder
{
    private static ?float $lastNominatimAt = null;

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array{days: list<array<string, mixed>>, geocoded: int, missing: int, ms: int, note: string}
     */
    public function enrichDays(array $days): array
    {
        $t0 = microtime(true);
        $geocoded = 0;
        $missing = 0;
        $out = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $enriched = $this->enrichDay($day);
            if ($enriched['lat'] !== null && $enriched['lng'] !== null) {
                $geocoded++;
            } else {
                $missing++;
            }
            $out[] = $enriched;
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $note = 'Гео: координат у '.$geocoded.' дн., без координат '.$missing.' · '.$ms.'ms';

        return [
            'days' => $out,
            'geocoded' => $geocoded,
            'missing' => $missing,
            'ms' => $ms,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    public function enrichDay(array $day): array
    {
        $places = [];
        foreach ((is_array($day['places'] ?? null) ? $day['places'] : []) as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $places[] = $p;
            }
        }
        if ($places === []) {
            $summary = trim((string) ($day['summary'] ?? ''));
            if ($summary !== '') {
                $places[] = $summary;
            }
        }

        $points = [];
        foreach ($places as $place) {
            // Skip vague region-only labels
            if ($this->isVagueLabel($place)) {
                continue;
            }
            $hit = $this->resolve($place, (string) ($day['region'] ?? 'other'));
            if ($hit === null) {
                continue;
            }
            $points[] = [
                'name' => $place,
                'lat' => $hit['lat'],
                'lng' => $hit['lng'],
                'source' => $hit['source'],
            ];
        }

        $day['places_geo'] = $points;
        if ($points === []) {
            $day['lat'] = null;
            $day['lng'] = null;
            $day['geo_span_km'] = null;
            $day['geo_ok'] = false;

            return $day;
        }

        $latSum = 0.0;
        $lngSum = 0.0;
        foreach ($points as $pt) {
            $latSum += $pt['lat'];
            $lngSum += $pt['lng'];
        }
        $n = count($points);
        $day['lat'] = round($latSum / $n, 5);
        $day['lng'] = round($lngSum / $n, 5);
        $day['geo_span_km'] = $this->maxSpanKm($points);
        $maxSpan = (float) config('services.nominatim.max_day_span_km', 70);
        $day['geo_ok'] = $day['geo_span_km'] <= $maxSpan;

        return $day;
    }

    /**
     * @return array{lat: float, lng: float, source: string}|null
     */
    public function resolve(string $place, string $region = 'other'): ?array
    {
        $key = $this->normalizeKey($place);
        if ($key === '') {
            return null;
        }

        $cached = PlaceCoordinate::query()->where('place_key', $key)->first();
        if ($cached !== null) {
            if ($cached->lat === null || $cached->lng === null) {
                return null;
            }

            return [
                'lat' => (float) $cached->lat,
                'lng' => (float) $cached->lng,
                'source' => (string) $cached->source,
            ];
        }

        $seed = $this->seedLookup($key, $place);
        if ($seed !== null) {
            $this->store($key, $place, $seed['lat'], $seed['lng'], $place, 'seed');

            return [
                'lat' => $seed['lat'],
                'lng' => $seed['lng'],
                'source' => 'seed',
            ];
        }

        $fromApi = $this->nominatimLookup($place, $region);
        if ($fromApi !== null) {
            $this->store(
                $key,
                $place,
                $fromApi['lat'],
                $fromApi['lng'],
                $fromApi['display_name'],
                'nominatim',
            );

            return [
                'lat' => $fromApi['lat'],
                'lng' => $fromApi['lng'],
                'source' => 'nominatim',
            ];
        }

        $this->store($key, $place, null, null, null, 'failed');

        return null;
    }

    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }

    public function normalizeKey(string $place): string
    {
        $s = mb_strtolower(trim($place));
        $s = str_replace(['ё'], ['е'], $s);
        $s = preg_replace('/[«»"\'`]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        // Drop generic prefixes
        $s = preg_replace('/^(озеро|озера|город|г\.|парк|водопад|перевал|термы|термальный парк)\s+/ui', '', $s) ?? $s;

        return mb_substr(trim($s), 0, 180);
    }

    private function isVagueLabel(string $place): bool
    {
        $p = mb_strtolower(trim($place));

        return (bool) preg_match(
            '/^(озеро\s+гарда|озеро\s+комо|доломитовые?\s+альпы|оз[её]ра\s+доломит|италия|italy|аутлет|outlet)$/ui',
            $p
        );
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     */
    private function maxSpanKm(array $points): float
    {
        $max = 0.0;
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = $this->distanceKm(
                    $points[$i]['lat'],
                    $points[$i]['lng'],
                    $points[$j]['lat'],
                    $points[$j]['lng'],
                );
                if ($d > $max) {
                    $max = $d;
                }
            }
        }

        return $max;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function seedLookup(string $key, string $original): ?array
    {
        $map = $this->seedMap();
        if (isset($map[$key])) {
            return $map[$key];
        }
        // Try latin-ish original lowercased
        $alt = $this->normalizeKey($original);
        if (isset($map[$alt])) {
            return $map[$alt];
        }
        foreach ($map as $name => $coords) {
            if ($key !== '' && (str_contains($name, $key) || str_contains($key, $name))) {
                if (mb_strlen($key) >= 5 && mb_strlen($name) >= 5) {
                    return $coords;
                }
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float, display_name: string}|null
     */
    private function nominatimLookup(string $place, string $region): ?array
    {
        if (! (bool) config('services.nominatim.enabled', true)) {
            return null;
        }

        $this->throttleNominatim();

        $query = $place;
        if (! preg_match('/\b(italy|italia|schweiz|switzerland|österreich|austria)\b/iu', $place)) {
            $query .= match ($region) {
                'como' => ', Lake Como, Italy',
                'garda' => ', Lake Garda, Italy',
                'dolomiti' => ', Dolomites, Italy',
                'milan' => ', Milan, Italy',
                'rome' => ', Rome, Italy',
                'tuscany' => ', Tuscany, Italy',
                default => ', Italy',
            };
        }

        $base = rtrim((string) config('services.nominatim.base_url', 'https://nominatim.openstreetmap.org'), '/');
        $ua = (string) config('services.nominatim.user_agent', 'travel.loc-itinerary/1.0');
        $email = trim((string) config('services.nominatim.email', ''));

        try {
            $request = Http::connectTimeout(3)
                ->timeout(12)
                ->withHeaders([
                    'User-Agent' => $ua,
                    'Accept-Language' => 'en,it,ru',
                ])
                ->get($base.'/search', array_filter([
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => (string) config('services.nominatim.country_codes', 'it,ch,at'),
                    'email' => $email !== '' ? $email : null,
                ]));
        } catch (\Throwable $e) {
            Log::warning('Nominatim request failed', ['place' => $place, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $request->successful()) {
            Log::warning('Nominatim non-OK', ['place' => $place, 'status' => $request->status()]);

            return null;
        }

        $row = $request->json('0');
        if (! is_array($row)) {
            return null;
        }
        $lat = isset($row['lat']) ? (float) $row['lat'] : null;
        $lng = isset($row['lon']) ? (float) $row['lon'] : null;
        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'display_name' => (string) ($row['display_name'] ?? $place),
        ];
    }

    private function throttleNominatim(): void
    {
        $minMs = max(1000, (int) config('services.nominatim.rate_limit_ms', 1100));
        $now = microtime(true);
        if (self::$lastNominatimAt !== null) {
            $elapsedMs = (int) round(($now - self::$lastNominatimAt) * 1000);
            if ($elapsedMs < $minMs) {
                usleep(($minMs - $elapsedMs) * 1000);
            }
        }
        self::$lastNominatimAt = microtime(true);
    }

    private function store(
        string $key,
        string $query,
        ?float $lat,
        ?float $lng,
        ?string $displayName,
        string $source,
    ): void {
        PlaceCoordinate::query()->updateOrCreate(
            ['place_key' => $key],
            [
                'query' => mb_substr($query, 0, 255),
                'lat' => $lat,
                'lng' => $lng,
                'display_name' => $displayName !== null ? mb_substr($displayName, 0, 500) : null,
                'source' => $source,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * Seeded Italian lake / alpine hubs (approx. town centers).
     *
     * @return array<string, array{lat: float, lng: float}>
     */
    private function seedMap(): array
    {
        return [
            // Garda
            'мальчезине' => ['lat' => 45.7660, 'lng' => 10.8090],
            'malcesine' => ['lat' => 45.7660, 'lng' => 10.8090],
            'лимоне-суль-гарда' => ['lat' => 45.8150, 'lng' => 10.7910],
            'лимоне суль гарда' => ['lat' => 45.8150, 'lng' => 10.7910],
            'limone sul garda' => ['lat' => 45.8150, 'lng' => 10.7910],
            'лимоне' => ['lat' => 45.8150, 'lng' => 10.7910],
            'рива-дель-гарда' => ['lat' => 45.8850, 'lng' => 10.8420],
            'рива дель гарда' => ['lat' => 45.8850, 'lng' => 10.8420],
            'riva del garda' => ['lat' => 45.8850, 'lng' => 10.8420],
            'сирмионе' => ['lat' => 45.4970, 'lng' => 10.6060],
            'sirmione' => ['lat' => 45.4970, 'lng' => 10.6060],
            'пескьера-дель-гарда' => ['lat' => 45.4380, 'lng' => 10.6950],
            'пескьера дель гарда' => ['lat' => 45.4380, 'lng' => 10.6950],
            'peschiera del garda' => ['lat' => 45.4380, 'lng' => 10.6950],
            'пескьера' => ['lat' => 45.4380, 'lng' => 10.6950],
            'дезенцано-дель-гарда' => ['lat' => 45.4690, 'lng' => 10.5350],
            'дезенцано' => ['lat' => 45.4690, 'lng' => 10.5350],
            'desenzano del garda' => ['lat' => 45.4690, 'lng' => 10.5350],
            'бардолино' => ['lat' => 45.5470, 'lng' => 10.7210],
            'bardolino' => ['lat' => 45.5470, 'lng' => 10.7210],
            'лацизе' => ['lat' => 45.5640, 'lng' => 10.7330],
            'lazise' => ['lat' => 45.5640, 'lng' => 10.7330],
            'торри-дель-бенако' => ['lat' => 45.6100, 'lng' => 10.6870],
            'torri del benaco' => ['lat' => 45.6100, 'lng' => 10.6870],
            'сало' => ['lat' => 45.6060, 'lng' => 10.5310],
            'salò' => ['lat' => 45.6060, 'lng' => 10.5310],
            'salo' => ['lat' => 45.6060, 'lng' => 10.5310],
            'гарда' => ['lat' => 45.5760, 'lng' => 10.7110],
            'garda' => ['lat' => 45.5760, 'lng' => 10.7110],
            'боргетто' => ['lat' => 45.4220, 'lng' => 10.7270],
            'borghetto' => ['lat' => 45.4220, 'lng' => 10.7270],
            'монте-бальдо' => ['lat' => 45.7280, 'lng' => 10.8380],
            'монте бальдо' => ['lat' => 45.7280, 'lng' => 10.8380],
            'monte baldo' => ['lat' => 45.7280, 'lng' => 10.8380],
            'тенно' => ['lat' => 45.9200, 'lng' => 10.8330],
            'tenno' => ['lat' => 45.9200, 'lng' => 10.8330],
            'озеро тенно' => ['lat' => 45.9180, 'lng' => 10.8290],
            'вароне' => ['lat' => 45.8900, 'lng' => 10.8360],
            'водопад вароне' => ['lat' => 45.8900, 'lng' => 10.8360],
            'varone' => ['lat' => 45.8900, 'lng' => 10.8360],
            'ледро' => ['lat' => 45.8750, 'lng' => 10.7500],
            'озеро ледро' => ['lat' => 45.8750, 'lng' => 10.7500],
            'ledro' => ['lat' => 45.8750, 'lng' => 10.7500],
            'мольвено' => ['lat' => 46.1420, 'lng' => 10.9680],
            'озеро мольвено' => ['lat' => 46.1420, 'lng' => 10.9680],
            'molveno' => ['lat' => 46.1420, 'lng' => 10.9680],
            'товель' => ['lat' => 46.2640, 'lng' => 10.9490],
            'озеро товель' => ['lat' => 46.2640, 'lng' => 10.9490],
            'тувель' => ['lat' => 46.2640, 'lng' => 10.9490],
            'нардис' => ['lat' => 46.1780, 'lng' => 10.7470],
            'водопад нардис' => ['lat' => 46.1780, 'lng' => 10.7470],
            'водопады нардис' => ['lat' => 46.1780, 'lng' => 10.7470],
            'гардаленд' => ['lat' => 45.4570, 'lng' => 10.7140],
            'gardaland' => ['lat' => 45.4570, 'lng' => 10.7140],
            'сигурта' => ['lat' => 45.4860, 'lng' => 10.7420],
            'парк сигурта' => ['lat' => 45.4860, 'lng' => 10.7420],
            'sigurta' => ['lat' => 45.4860, 'lng' => 10.7420],
            'канева' => ['lat' => 45.5530, 'lng' => 10.7200],
            'аквапарк канева' => ['lat' => 45.5530, 'lng' => 10.7200],
            'caneva' => ['lat' => 45.5530, 'lng' => 10.7200],
            'aquardens' => ['lat' => 45.4380, 'lng' => 11.0100],
            'термы aquardens' => ['lat' => 45.4380, 'lng' => 11.0100],
            'кола' => ['lat' => 45.5100, 'lng' => 10.7600],
            'термы кола' => ['lat' => 45.5100, 'lng' => 10.7600],
            'верона' => ['lat' => 45.4380, 'lng' => 10.9920],
            'verona' => ['lat' => 45.4380, 'lng' => 10.9920],
            'мантуя' => ['lat' => 45.1560, 'lng' => 10.7910],
            'mantova' => ['lat' => 45.1560, 'lng' => 10.7910],
            'тренто' => ['lat' => 46.0670, 'lng' => 11.1210],
            'trento' => ['lat' => 46.0670, 'lng' => 11.1210],
            'венеция' => ['lat' => 45.4400, 'lng' => 12.3160],
            'venice' => ['lat' => 45.4400, 'lng' => 12.3160],
            'брешиа' => ['lat' => 45.5410, 'lng' => 10.2120],
            'brescia' => ['lat' => 45.5410, 'lng' => 10.2120],
            // Como / Maggiore
            'комо' => ['lat' => 45.8080, 'lng' => 9.0850],
            'como' => ['lat' => 45.8080, 'lng' => 9.0850],
            'белладжо' => ['lat' => 45.9870, 'lng' => 9.2570],
            'bellagio' => ['lat' => 45.9870, 'lng' => 9.2570],
            'варенна' => ['lat' => 46.0090, 'lng' => 9.2840],
            'varenna' => ['lat' => 46.0090, 'lng' => 9.2840],
            'лекко' => ['lat' => 45.8560, 'lng' => 9.3970],
            'lecco' => ['lat' => 45.8560, 'lng' => 9.3970],
            'менаджо' => ['lat' => 46.0190, 'lng' => 9.2390],
            'menaggio' => ['lat' => 46.0190, 'lng' => 9.2390],
            'тремеццо' => ['lat' => 45.9860, 'lng' => 9.2260],
            'tremezzo' => ['lat' => 45.9860, 'lng' => 9.2260],
            'торно' => ['lat' => 45.8560, 'lng' => 9.1170],
            'torno' => ['lat' => 45.8560, 'lng' => 9.1170],
            'нессо' => ['lat' => 45.9120, 'lng' => 9.1570],
            'орридо-ди-нессо' => ['lat' => 45.9120, 'lng' => 9.1570],
            'орридо ди беллано' => ['lat' => 46.0430, 'lng' => 9.3040],
            'беллано' => ['lat' => 46.0430, 'lng' => 9.3040],
            'балкон италии' => ['lat' => 46.0530, 'lng' => 9.0660],
            'балконе д\'италия' => ['lat' => 46.0530, 'lng' => 9.0660],
            'balcone d\'italia' => ['lat' => 46.0530, 'lng' => 9.0660],
            'стреза' => ['lat' => 45.8840, 'lng' => 8.5430],
            'stresa' => ['lat' => 45.8840, 'lng' => 8.5430],
            'арона' => ['lat' => 45.7570, 'lng' => 8.5570],
            'arona' => ['lat' => 45.7570, 'lng' => 8.5570],
            'борромейские острова' => ['lat' => 45.9000, 'lng' => 8.5400],
            'borromean islands' => ['lat' => 45.9000, 'lng' => 8.5400],
            'альпиленд' => ['lat' => 46.1700, 'lng' => 8.7900],
            'alpiland' => ['lat' => 46.1700, 'lng' => 8.7900],
            'порлецца' => ['lat' => 46.0380, 'lng' => 9.1320],
            'porlezza' => ['lat' => 46.0380, 'lng' => 9.1320],
            // Milan / hubs
            'милан' => ['lat' => 45.4640, 'lng' => 9.1900],
            'milan' => ['lat' => 45.4640, 'lng' => 9.1900],
            'мальпенса' => ['lat' => 45.6300, 'lng' => 8.7230],
            'malpensa' => ['lat' => 45.6300, 'lng' => 8.7230],
            // Dolomiti
            'ортизеи' => ['lat' => 46.5760, 'lng' => 11.6720],
            'ortisei' => ['lat' => 46.5760, 'lng' => 11.6720],
            'сельва-ди-валь-гардена' => ['lat' => 46.5560, 'lng' => 11.7600],
            'selva' => ['lat' => 46.5560, 'lng' => 11.7600],
            'перевал селла' => ['lat' => 46.5080, 'lng' => 11.7670],
            'passo sella' => ['lat' => 46.5080, 'lng' => 11.7670],
            'селла' => ['lat' => 46.5080, 'lng' => 11.7670],
            'кортина-д’ампеццо' => ['lat' => 46.5400, 'lng' => 12.1350],
            'кортина-д\'ампеццо' => ['lat' => 46.5400, 'lng' => 12.1350],
            'cortina' => ['lat' => 46.5400, 'lng' => 12.1350],
            'сан-кассиано' => ['lat' => 46.5720, 'lng' => 11.9320],
            'озеро карецца' => ['lat' => 46.4120, 'lng' => 11.5750],
            'карецца' => ['lat' => 46.4120, 'lng' => 11.5750],
            'carezza' => ['lat' => 46.4120, 'lng' => 11.5750],
            'моэна' => ['lat' => 46.3770, 'lng' => 11.6600],
            'moena' => ['lat' => 46.3770, 'lng' => 11.6600],
            'сасс пордой' => ['lat' => 46.4920, 'lng' => 11.8120],
            'латемар' => ['lat' => 46.3800, 'lng' => 11.5600],
        ];
    }
}
