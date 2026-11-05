<?php

namespace App\Jobs;

use App\Models\NewShortRoute;
use App\Services\NewShortRouteVectorPipelineStore;
use App\Services\RagNewShortRouteIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IndexNewShortRouteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public int $routeId) {}

    public function handle(RagNewShortRouteIndexer $indexer, NewShortRouteVectorPipelineStore $pipeline): void
    {
        if ($pipeline->cancelled()) {
            return;
        }

        $route = NewShortRoute::query()->find($this->routeId);
        if (! $route) {
            $pipeline->recordFailed('New Short #'.$this->routeId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $label = trim(($route->doc_code ? $route->doc_code.' ' : '').($route->title_ru ?: $route->title ?: '#'.$route->id));
        $pipeline->setCurrent($label);

        $cooldown = 0;
        try {
            $result = $indexer->indexRoute($route, true);
            $pipeline->recordDone((int) ($result['chunks'] ?? 0));
        } catch (\Throwable $e) {
            Log::warning('IndexNewShortRouteJob failed', [
                'new_short_route_id' => $this->routeId,
                'message' => $e->getMessage(),
            ]);
            $pipeline->recordFailed($e->getMessage());
            // Free-tier / RPM: длинная пауза перед следующим, как у локаций.
            if ($this->isRateLimited($e->getMessage())) {
                $cooldown = max(60, (int) config('services.gemini.nsr_rate_limit_cooldown_seconds', 90));
            }
        } finally {
            unset($result, $route, $indexer);
            gc_collect_cycles();
        }

        if ($pipeline->cancelled()) {
            return;
        }

        $this->dispatchNext($pipeline, $cooldown);
    }

    private function dispatchNext(NewShortRouteVectorPipelineStore $pipeline, int $extraCooldown = 0): void
    {
        $next = $pipeline->shift();
        if ($next === null) {
            return;
        }
        // Free keys: медленнее обычного пайплайна (эмбеддинг пачками).
        $base = (int) config('services.gemini.nsr_pipeline_delay_seconds', 0);
        if ($base < 1) {
            $base = (int) config('services.gemini.pipeline_delay_seconds', 8);
        }
        $delay = max(15, $base) + max(0, $extraCooldown);
        self::dispatch($next)->delay(now()->addSeconds($delay));
    }

    private function isRateLimited(string $message): bool
    {
        $m = mb_strtolower($message);

        return str_contains($m, '429')
            || str_contains($m, 'resource_exhausted')
            || str_contains($m, 'quota')
            || str_contains($m, 'rate')
            || str_contains($m, 'free_tier')
            || str_contains($m, 'free tier');
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = app(NewShortRouteVectorPipelineStore::class);
        $pipeline->recordFailed($e->getMessage());
        if (! $pipeline->cancelled()) {
            $extra = $this->isRateLimited($e->getMessage()) ? 90 : 0;
            $this->dispatchNext($pipeline, $extra);
        }
    }
}
