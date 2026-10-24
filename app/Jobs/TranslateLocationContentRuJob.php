<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\LocationFactRuTranslator;
use App\Services\LocationRuPipelineStore;
use App\Services\LocationRuTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TranslateLocationContentRuJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $locationId) {}

    public function handle(LocationRuTranslator $translator, LocationFactRuTranslator $factsTranslator): void
    {
        $pipeline = LocationRuPipelineStore::content();
        if ($pipeline->cancelled()) {
            return;
        }

        $location = Location::with(['descriptions', 'facts.descriptions'])->find($this->locationId);
        if ($location) {
            $pipeline->setCurrent(($location->model ?? '').' '.($location->descriptions->first()->name ?? ''));
            try {
                if ($location->heMeta() !== null) {
                    $translator->translateAllContent($location);
                } else {
                    $translator->translateName($location);
                }
                $location->refresh()->load('facts.descriptions');
                if ($location->facts->isNotEmpty()) {
                    try {
                        $factsTranslator->translate($location);
                    } catch (\Throwable $e) {
                        // facts optional — name/meta already attempted
                        Log::info('TranslateLocationContentRuJob facts skip', [
                            'location_id' => $this->locationId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
                $pipeline->recordDone('ok');
            } catch (\Throwable $e) {
                Log::warning('TranslateLocationContentRuJob failed', [
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
        $pipeline = LocationRuPipelineStore::content();
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
            self::dispatch($next)->delay(now()->addSeconds(2));
        }
    }
}
