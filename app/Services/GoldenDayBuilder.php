<?php

namespace App\Services;

use App\Models\GoldenDay;
use App\Models\GoldenDayStop;
use App\Models\Location;
use App\Models\RouteSkeletonDay;
use Illuminate\Support\Facades\DB;

/**
 * Explode route_skeleton_days into unique golden_days (+ stops linked to locations.model).
 */
class GoldenDayBuilder
{
    /**
     * @param  array{fresh?: bool, min_stops?: int}  $options
     * @return array{created: int, reused: int, skipped: int, stops: int, linked: int}
     */
    public function buildFromSkeletons(array $options = []): array
    {
        $fresh = (bool) ($options['fresh'] ?? false);
        $minStops = max(1, (int) ($options['min_stops'] ?? 1));

        if ($fresh) {
            GoldenDayStop::query()->delete();
            GoldenDay::query()->delete();
        }

        $created = 0;
        $reused = 0;
        $skipped = 0;
        $stops = 0;
        $linked = 0;

        $locationByModel = Location::query()
            ->whereNotNull('model')
            ->where('model', '!=', '')
            ->pluck('id', 'model')
            ->map(fn ($id) => (int) $id)
            ->all();

        RouteSkeletonDay::query()
            ->with('skeleton')
            ->orderBy('id')
            ->chunkById(200, function ($days) use (
                $minStops,
                $locationByModel,
                &$created,
                &$reused,
                &$skipped,
                &$stops,
                &$linked
            ) {
                foreach ($days as $day) {
                    $places = $this->normalizePlaces((array) ($day->places ?? []));
                    if (count($places) < $minStops) {
                        $skipped++;

                        continue;
                    }

                    $signature = $this->signature($places);
                    $existing = GoldenDay::query()->where('signature', $signature)->first();
                    if ($existing) {
                        $existing->usage_count = (int) $existing->usage_count + 1;
                        $existing->save();
                        $reused++;

                        continue;
                    }

                    DB::transaction(function () use (
                        $day,
                        $places,
                        $signature,
                        $locationByModel,
                        &$created,
                        &$stops,
                        &$linked
                    ) {
                        $skeletonTitle = trim((string) ($day->skeleton?->title ?? ''));
                        $dayTitle = trim((string) ($day->title ?? ''));
                        $title = $dayTitle !== ''
                            ? $dayTitle
                            : ($skeletonTitle !== ''
                                ? $skeletonTitle.' · день '.((int) $day->day_index + 1)
                                : 'День #'.$day->id);

                        $golden = GoldenDay::create([
                            'title' => mb_substr($title, 0, 255),
                            'title_ru' => null,
                            'area_id' => null,
                            'source_skeleton_id' => $day->route_skeleton_id,
                            'source_skeleton_day_id' => $day->id,
                            'source_day_index' => (int) $day->day_index,
                            'start_point' => $day->start_point,
                            'end_point' => $day->end_point,
                            'signature' => $signature,
                            'stops_count' => count($places),
                            'usage_count' => 1,
                            'is_active' => true,
                            'meta' => [
                                'source' => 'route_skeleton_day',
                                'skeleton_title' => $day->skeleton?->title,
                            ],
                        ]);

                        foreach ($places as $i => $place) {
                            $model = $place['model'];
                            $locationId = ($model !== null && isset($locationByModel[$model]))
                                ? $locationByModel[$model]
                                : null;
                            if ($locationId) {
                                $linked++;
                            }
                            GoldenDayStop::create([
                                'golden_day_id' => $golden->id,
                                'sort_order' => $i,
                                'location_id' => $locationId,
                                'source_model' => $model,
                                'name' => $place['name'],
                            ]);
                            $stops++;
                        }
                        $created++;
                    });
                }
            });

        return compact('created', 'reused', 'skipped', 'stops', 'linked');
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array{model: ?string, name: string}>
     */
    private function normalizePlaces(array $raw): array
    {
        $out = [];
        foreach ($raw as $p) {
            if (! is_array($p)) {
                continue;
            }
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = $p['id'] ?? null;
            $model = null;
            if ($id !== null && $id !== '' && is_numeric($id)) {
                $model = (string) (int) $id;
            }
            $out[] = ['model' => $model, 'name' => $name];
        }

        return $out;
    }

    /**
     * @param  list<array{model: ?string, name: string}>  $places
     */
    private function signature(array $places): string
    {
        $keys = [];
        foreach ($places as $p) {
            $keys[] = ($p['model'] ?? '').'|'.mb_strtolower($p['name']);
        }

        return sha1(implode('>', $keys));
    }
}
