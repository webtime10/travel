<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\LocationVectorPipelineStore;
use App\Services\RagLocationIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IndexLocationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public int $locationId) {}

    public function handle(RagLocationIndexer $indexer, LocationVectorPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }

        $location = Location::query()->find($this->locationId);
        if (! $location) {
            $pipeline->recordFailed('Location #'.$this->locationId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent(($location->model ?? '').' '.($location->nameFor() ?? ''));

        try {
            $result = $indexer->indexLocation($location, true);
            $pipeline->recordDone((int) ($result['chunks'] ?? 0));
        } catch (\Throwable $e) {
            Log::warning('IndexLocationJob failed', [
                'location_id' => $this->locationId,
                'message' => $e->getMessage(),
            ]);
            $pipeline->recordFailed($e->getMessage());
        } finally {
            unset($result, $location, $indexer);
            gc_collect_cycles();
        }

        if ($pipeline->cancelled()) {
            return;
        }

        $this->dispatchNext($pipeline);
    }

    private function dispatchNext(LocationVectorPipelineStore $pipeline): void
    {
        $next = $pipeline->shift();
        if ($next === null) {
            return;
        }
        $delay = max(2, (int) config('services.gemini.pipeline_delay_seconds', 8));
        self::dispatch($next)->delay(now()->addSeconds($delay));
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = app(LocationVectorPipelineStore::class);
        $pipeline->recordFailed($e->getMessage());
        if (! $pipeline->cancelled()) {
            $this->dispatchNext($pipeline);
        }
    }
}
