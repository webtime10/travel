<?php

namespace App\Services;

use App\Models\GoldenDay;

/**
 * Auto-tag golden days: area/region from stop locations, hub from text, deactivate junk.
 */
class GoldenDayTagger
{
    public function __construct(private RouteSkeletonMatcher $hubs) {}

    /**
     * @return array{tagged: int, junk_off: int, with_area: int, with_category: int, with_hub: int}
     */
    public function tagAll(): array
    {
        $tagged = 0;
        $junkOff = 0;
        $withArea = 0;
        $withCategory = 0;
        $withHub = 0;

        GoldenDay::query()
            ->with(['stops.location.categories'])
            ->orderBy('id')
            ->chunkById(100, function ($days) use (&$tagged, &$junkOff, &$withArea, &$withCategory, &$withHub) {
                foreach ($days as $day) {
                    $junk = $this->detectJunk($day);
                    $areaId = $this->majorityAreaId($day);
                    $categoryId = $this->majorityCategoryId($day);
                    $blob = $this->buildSearchBlob($day);
                    $hubList = $this->hubs->detectHubs($blob);
                    $hubKey = $hubList[0] ?? null;

                    $day->area_id = $areaId;
                    $day->category_id = $categoryId;
                    $day->hub_key = $hubKey;
                    $day->search_blob = $blob;
                    $day->junk_reason = $junk;

                    if ($junk !== null) {
                        $day->is_active = false;
                        $junkOff++;
                    }

                    $day->save();
                    $tagged++;
                    if ($areaId) {
                        $withArea++;
                    }
                    if ($categoryId) {
                        $withCategory++;
                    }
                    if ($hubKey) {
                        $withHub++;
                    }
                }
            });

        return [
            'tagged' => $tagged,
            'junk_off' => $junkOff,
            'with_area' => $withArea,
            'with_category' => $withCategory,
            'with_hub' => $withHub,
        ];
    }

    private function detectJunk(GoldenDay $day): ?string
    {
        if ((int) $day->stops_count < 2) {
            return 'too_few_stops';
        }

        $names = [];
        foreach ($day->stops as $s) {
            $names[] = mb_strtolower(trim((string) ($s->name ?? '')));
        }
        $blob = implode(' ', $names).' '.mb_strtolower((string) ($day->title ?? ''));

        if (preg_match('/car rental|השכרת רכב|аренда авто|driving tips|טיפים לנהיג|exclusive offers for car/ui', $blob)) {
            return 'service_rental_tips';
        }
        if (preg_match('/^hotel\b|מלון|resort|overnight only/ui', $blob) && count($names) <= 2) {
            return 'hotel_only';
        }

        $tourist = 0;
        foreach ($names as $n) {
            if ($n === '') {
                continue;
            }
            if (preg_match('/hotel|מלון|resort|rental|tips|טיפ|parking|חניה/ui', $n)) {
                continue;
            }
            $tourist++;
        }
        if ($tourist < 1) {
            return 'no_tourist_stops';
        }

        return null;
    }

    private function majorityAreaId(GoldenDay $day): ?int
    {
        $votes = [];
        foreach ($day->stops as $s) {
            $aid = $s->location?->area_id;
            if ($aid) {
                $votes[(int) $aid] = ($votes[(int) $aid] ?? 0) + 1;
            }
        }
        if ($votes === []) {
            return null;
        }
        arsort($votes);

        return (int) array_key_first($votes);
    }

    private function majorityCategoryId(GoldenDay $day): ?int
    {
        $votes = [];
        foreach ($day->stops as $s) {
            $loc = $s->location;
            if (! $loc) {
                continue;
            }
            foreach ($loc->categories as $c) {
                // Prefer leaf-ish regions: skip very generic if parent exists — count all
                $votes[(int) $c->id] = ($votes[(int) $c->id] ?? 0) + 1;
            }
        }
        if ($votes === []) {
            return null;
        }
        arsort($votes);

        return (int) array_key_first($votes);
    }

    private function buildSearchBlob(GoldenDay $day): string
    {
        $parts = [
            (string) ($day->title ?? ''),
            (string) ($day->start_point ?? ''),
            (string) ($day->end_point ?? ''),
        ];
        foreach ($day->stops as $s) {
            $parts[] = (string) ($s->name ?? '');
            if ($s->location) {
                $parts[] = $s->location->nameFor();
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }
}
