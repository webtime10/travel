<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\LocationFactChecker;
use App\Services\LocationFactExtractor;
use App\Services\LocationFactsPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SplitLocationFactsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $locationId) {}

    public function handle(LocationFactExtractor $extractor, LocationFactChecker $checker, LocationFactsPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }

        $location = Location::with('descriptions')->find($this->locationId);
        if ($location) {
            $pipeline->setCurrent(($location->model ?? '').' '.($location->descriptions->first()->name ?? ''));
            try {
                $extractor->extract($location);
                $result = $checker->checkAndRepair($location->refresh());
                $pipeline->recordDone($result['status']);
            } catch (\Throwable $e) {
                Log::warning('SplitLocationFactsJob failed', ['location_id' => $this->locationId, 'message' => $e->getMessage()]);
                $location->forceFill([
                    'facts_status' => 'error',
                    'facts_review' => ['error' => mb_substr($e->getMessage(), 0, 500), 'checked_at' => now()->toIso8601String()],
                ])->save();
                $pipeline->recordFailed($e->getMessage());
            }
        }

        $this->dispatchNext($pipeline);
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = app(LocationFactsPipelineStore::class);
        $pipeline->recordFailed($e->getMessage());
        $this->dispatchNext($pipeline);
    }

    private function dispatchNext(LocationFactsPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }
        $next = $pipeline->shift();
        if ($next !== null) {
            self::dispatch($next)->delay(now()->addSeconds(2));
        }
    }
}
