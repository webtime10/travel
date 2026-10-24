<?php

namespace App\Services;

use App\Models\GoldenDay;
use App\Models\GoldenLabel;

/**
 * Map golden_days.hub_key → golden labels (hub-*).
 */
class GoldenDayLabelSync
{
    /**
     * @return list<int> attached label ids
     */
    public function attachFromHub(GoldenDay $day): array
    {
        $ids = [];
        $hub = strtolower(trim((string) ($day->hub_key ?? '')));
        if ($hub !== '') {
            $slug = 'hub-'.$hub;
            // garda hub_key → hub-garda (not lake-garda)
            $labelId = GoldenLabel::query()->where('slug', $slug)->where('status', true)->value('id');
            if ($labelId) {
                $ids[] = (int) $labelId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $day->labels()->syncWithoutDetaching($ids);

        return $ids;
    }

    /**
     * @return array{days: int, attached: int}
     */
    public function syncAllFromHubKeys(): array
    {
        $days = 0;
        $attached = 0;

        GoldenDay::query()
            ->whereNotNull('hub_key')
            ->where('hub_key', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$days, &$attached) {
                foreach ($chunk as $day) {
                    $ids = $this->attachFromHub($day);
                    $days++;
                    $attached += count($ids);
                }
            });

        return compact('days', 'attached');
    }
}
