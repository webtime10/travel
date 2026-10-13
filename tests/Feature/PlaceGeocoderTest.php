<?php

namespace Tests\Feature;

use App\Services\Itinerary\PlaceGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceGeocoderTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_places_enrich_coherent_garda_day(): void
    {
        $geo = app(PlaceGeocoder::class);
        $day = $geo->enrichDay([
            'id' => 't1',
            'region' => 'garda',
            'kind' => 'program',
            'summary' => 'Север Гарды',
            'places' => ['Мальчезине', 'Лимоне-суль-Гарда'],
            'body' => '',
        ]);

        $this->assertNotNull($day['lat']);
        $this->assertNotNull($day['lng']);
        $this->assertTrue($day['geo_ok']);
        $this->assertLessThanOrEqual(70, (float) $day['geo_span_km']);
        $this->assertCount(2, $day['places_geo']);
    }

    public function test_mixed_como_and_garda_day_is_not_geo_ok(): void
    {
        $geo = app(PlaceGeocoder::class);
        $day = $geo->enrichDay([
            'id' => 't2',
            'region' => 'garda',
            'kind' => 'program',
            'summary' => 'Сирмионе и Комо',
            'places' => ['Сирмионе', 'Комо'],
            'body' => '',
        ]);

        $this->assertNotNull($day['lat']);
        $this->assertFalse($day['geo_ok']);
        $this->assertGreaterThan(70, (float) $day['geo_span_km']);
    }

    public function test_distance_between_malcesine_and_sirmione_is_plausible(): void
    {
        $geo = app(PlaceGeocoder::class);
        $km = $geo->distanceKm(45.7660, 10.8090, 45.4970, 10.6060);

        $this->assertGreaterThan(25, $km);
        $this->assertLessThan(45, $km);
    }
}
