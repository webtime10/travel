<?php

namespace App\Jobs;

use App\Services\FullTourRunStore;
use App\Services\Itinerary\BuildItineraryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunFullTourCurateJob implements ShouldQueue
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

        $sources = $data['sources'] ?? [];
        if (! is_array($sources) || $sources === []) {
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => 'Нет сырья для отбора. Сначала найдите сырьё.',
            ]);

            return;
        }

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => 'В run нет исходного запроса',
            ]);

            return;
        }

        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $steps[] = [
            'type' => 'think',
            'title' => 'Отбор общего сырья',
            'detail' => 'Строки маршрута → карточки + PHP-дедуп по смыслу мест ('.count($sources).' источник(ов))…',
        ];
        $store->patch($this->runId, [
            'status' => 'curating',
            'mode' => 'curate',
            'steps' => $steps,
        ]);

        try {
            $sources = $builder->hydrateSourcesForProcessing($sources, 'ru');
            // Do not re-persist 399 hydrated sources here — keeps run payload small;
            // curate works from the in-memory $sources (route_lines already enough).
            $result = $builder->curateFromSources($message, $sources);
            $steps[] = [
                'type' => 'final',
                'title' => 'Карточки дней извлечены — дальше «Релевантный список (RU)»',
                'detail' => 'дней: '.$result['kept']
                    .' (извлечено сырых '.$result['extracted'].')'
                    .' · '.$result['curate_ms'].'ms'
                    .($result['brief_note'] !== '' ? "\n".$result['brief_note'] : ''),
            ];
            $store->patch($this->runId, [
                'status' => 'done',
                'mode' => 'curate',
                'steps' => $steps,
                'curated_days' => $result['curated_days'],
                'curated_text' => $result['curated_text'] ?? '',
                'dropped_days' => $result['dropped_days'] ?? [],
                'brief_note' => $result['brief_note'] ?? '',
                'preferred_regions' => $result['preferred_regions'],
                'curate_ms' => $result['curate_ms'],
                'cleaned_days' => [],
                'cleaned_text' => '',
                'clean_dropped_days' => [],
                'clean_note' => null,
                'clean_ms' => null,
                'reply' => null,
                'replies' => [],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunFullTourCurateJob failed', [
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
            'error' => $exception?->getMessage() ?: 'Curate job failed',
        ]);
    }
}
