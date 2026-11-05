<?php

namespace App\Jobs;

use App\Models\Location;
use App\Services\LocationPassportTagger;
use App\Services\LocationPassportTagsPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractLocationPassportTagsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $locationId) {}

    public function handle(LocationPassportTagger $tagger, LocationPassportTagsPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }

        $location = Location::with('descriptions')->find($this->locationId);
        if (! $location) {
            $pipeline->recordFailed('location '.$this->locationId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent(($location->model ?? '').' '.($location->descriptions->first()->name ?? '#'.$location->id));
        try {
            if (! $tagger->hasSourceText($location)) {
                $location->passport_tags = [
                    'skipped' => true,
                    'reason' => 'empty_text',
                    'extracted_at' => now()->toIso8601String(),
                ];
                $location->save();
                $pipeline->recordDone('skipped');
            } else {
                $tagger->extract($location);
                $pipeline->setLastError(null);
                $pipeline->recordDone('ok');
            }
        } catch (\Throwable $e) {
            Log::warning('ExtractLocationPassportTagsJob failed', [
                'location_id' => $this->locationId,
                'message' => $e->getMessage(),
            ]);

            if ($this->isQuotaError($e)) {
                // Don't burn the queue: wait and retry the same location.
                $pipeline->setLastError('429 quota — пауза 90с, повтор #'.$this->locationId.'. Остановите параллельный RU/другие Gemini-пайплайны.');
                self::dispatch($this->locationId)->delay(now()->addSeconds(90));

                return;
            }

            $pipeline->recordFailed($e->getMessage());
        }

        $this->dispatchNext($pipeline);
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = app(LocationPassportTagsPipelineStore::class);
        if ($this->isQuotaError($e)) {
            $pipeline->setLastError('429 quota — пауза 90с, повтор #'.$this->locationId);
            self::dispatch($this->locationId)->delay(now()->addSeconds(90));

            return;
        }
        $pipeline->recordFailed($e->getMessage());
        $this->dispatchNext($pipeline);
    }

    private function isQuotaError(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, '429')
            || str_contains($msg, 'RESOURCE_EXHAUSTED')
            || str_contains(mb_strtolower($msg), 'quota');
    }

    private function dispatchNext(LocationPassportTagsPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }
        $next = $pipeline->shift();
        if ($next !== null) {
            $delay = max(8, (int) config('services.gemini.pipeline_delay_seconds', 8));
            self::dispatch($next)->delay(now()->addSeconds($delay));
        }
    }
}
