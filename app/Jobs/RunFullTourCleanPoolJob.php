<?php

namespace App\Jobs;

use App\Services\FullTourRunStore;
use App\Services\Itinerary\BuildItineraryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunFullTourCleanPoolJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    public function handle(FullTourRunStore $store, BuildItineraryService $builder): void
    {
        $data = $store->get($this->runId);
        if ($data === null) {
            return;
        }

        $days = $data['curated_days'] ?? [];
        if (! is_array($days) || $days === []) {
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => 'Нет извлечённых дней. Сначала «Извлечь дни».',
            ]);

            return;
        }

        $plan = is_array($data['plan'] ?? null) ? $data['plan'] : [];
        $planLocations = is_array($plan['locations'] ?? null) ? $plan['locations'] : [];

        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $steps[] = [
            'type' => 'think',
            'title' => 'Релевантный RU-список посещений',
            'detail' => 'PHP-дедуп по смыслу → Gemini: полный каталог на русском (не маршрут на N дней), '
                .count($days).' карточек…',
        ];
        $store->patch($this->runId, [
            'status' => 'cleaning',
            'mode' => 'clean',
            'steps' => $steps,
        ]);

        try {
            $result = $builder->cleanCuratedDays($days, $planLocations);
            $steps[] = [
                'type' => 'final',
                'title' => 'Релевантный список готов (RU) — из него потом соберём маршруты',
                'detail' => 'было '.$result['before']
                    .' → каталог '.$result['kept']
                    .' · '.$result['clean_ms'].'ms'
                    .(isset($result['geo_geocoded'])
                        ? (' · гео '.$result['geo_geocoded'].'/'.$result['kept'])
                        : '')
                    .($result['clean_note'] !== '' ? "\n".$result['clean_note'] : ''),
            ];
            $store->patch($this->runId, [
                'status' => 'done',
                'mode' => 'clean',
                'steps' => $steps,
                'cleaned_days' => $result['cleaned_days'],
                'cleaned_text' => $result['cleaned_text'],
                'clean_dropped_days' => $result['dropped_days'] ?? [],
                'clean_note' => $result['clean_note'] ?? '',
                'clean_ms' => $result['clean_ms'],
                'reply' => null,
                'replies' => [],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunFullTourCleanPoolJob failed', [
                'run_id' => $this->runId,
                'message' => $e->getMessage(),
            ]);
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(FullTourRunStore::class)->patch($this->runId, [
            'status' => 'failed',
            'error' => $exception?->getMessage() ?: 'Clean pool job failed',
        ]);
    }
}
