<?php

namespace App\Jobs;

use App\Services\FullTourRunStore;
use App\Services\Itinerary\BuildItineraryService;
use App\Services\Itinerary\FullTourPreviewTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunFullTourTranslateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $runId) {}

    public function handle(
        FullTourRunStore $store,
        FullTourPreviewTranslator $translator,
        BuildItineraryService $builder,
    ): void {
        $data = $store->get($this->runId);
        if ($data === null) {
            return;
        }

        $sources = $data['sources'] ?? $data['tours'] ?? [];
        if (! is_array($sources) || $sources === []) {
            $store->patch($this->runId, [
                'status' => 'failed',
                'error' => 'Нет источников для перевода. Сначала найдите сырьё.',
            ]);

            return;
        }

        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $steps[] = [
            'type' => 'think',
            'title' => 'Перевод HE→RU',
            'detail' => 'Переводим '.count($sources).' источник(ов)…',
        ];
        $store->patch($this->runId, [
            'status' => 'translating',
            'steps' => $steps,
        ]);

        try {
            $t0 = microtime(true);
            $sources = $builder->hydrateSourcesForProcessing($sources, 'he');
            $translated = $translator->translateAll($sources);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $steps[] = [
                'type' => 'final',
                'title' => 'Перевод готов',
                'detail' => count($translated).' источник(ов) · '.$ms.'ms',
            ];
            $store->patch($this->runId, [
                'status' => 'done',
                'sources' => $translated,
                'tours' => $translated,
                'steps' => $steps,
                'translate_ms' => $ms,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunFullTourTranslateJob failed', [
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
            'error' => $exception?->getMessage() ?: 'Translate job failed',
        ]);
    }
}
