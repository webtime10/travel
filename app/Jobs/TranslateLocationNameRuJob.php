<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\LocationRuPipelineStore;
use App\Services\LocationRuTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TranslateLocationNameRuJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $locationId) {}

    public function handle(LocationRuTranslator $translator): void
    {
        $pipeline = LocationRuPipelineStore::names();
        if ($pipeline->cancelled()) {
            return;
        }

        $location = Location::with('descriptions')->find($this->locationId);
        if ($location) {
            $pipeline->setCurrent(($location->model ?? '').' '.($location->descriptions->first()->name ?? ''));
            try {
                $translator->translateName($location);
                $pipeline->recordDone('ok');
            } catch (\Throwable $e) {
                Log::warning('TranslateLocationNameRuJob failed', [
                    'location_id' => $this->locationId,
                    'message' => $e->getMessage(),
                ]);
                $pipeline->recordFailed($e->getMessage());
            }
        }

        $this->dispatchNext($pipeline);
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = LocationRuPipelineStore::names();
        $pipeline->recordFailed($e->getMessage());
        $this->dispatchNext($pipeline);
    }

    private function dispatchNext(LocationRuPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }
        $next = $pipeline->shift();
        if ($next !== null) {
            self::dispatch($next)->delay(now()->addSeconds(1));
        }
    }
}
