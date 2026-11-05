<?php

namespace App\Jobs;

use App\Models\RouteSkeleton;
use App\Services\ReverseEngineeringPipelineStore;
use App\Services\RouteSkeletonReverseEngineer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReverseEngineerRouteSkeletonJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * Extra delayed retries after KeyPool already tried every key.
     * 0 = on 429/402 skip immediately and continue (don't freeze the pipeline).
     */
    private const MAX_QUOTA_ATTEMPTS = 0;

    public int $routeSkeletonId;

    /** Untyped so older queued payloads without this field still unserialize. */
    public $quotaAttempt = 0;

    public function __construct(int $routeSkeletonId, int $quotaAttempt = 0)
    {
        $this->routeSkeletonId = $routeSkeletonId;
        $this->quotaAttempt = $quotaAttempt;
    }

    public function handle(RouteSkeletonReverseEngineer $engineer): void
    {
        $pipeline = ReverseEngineeringPipelineStore::for('route_skeletons');
        if ($pipeline->cancelled()) {
            return;
        }

        $route = RouteSkeleton::with('days')->find($this->routeSkeletonId);
        if (! $route) {
            $pipeline->recordFailed('skeleton '.$this->routeSkeletonId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent('#'.$route->id.' '.$route->displayTitle(true));
        try {
            if ($route->days->isEmpty()) {
                $route->reverse_principle = [
                    'skipped' => true,
                    'reason' => 'no_days',
                    'extracted_at' => now()->toIso8601String(),
                ];
                $route->save();
                $pipeline->recordDone('skipped');
            } else {
                $engineer->extract($route);
                $pipeline->setLastError(null);
                $pipeline->resetQuotaStreak();
                $pipeline->recordDone('ok');
            }
        } catch (\Throwable $e) {
            Log::warning('ReverseEngineerRouteSkeletonJob failed', [
                'id' => $this->routeSkeletonId,
                'attempt' => (int) $this->quotaAttempt,
                'message' => $e->getMessage(),
            ]);
            if ($this->handleQuotaOrBilling($pipeline, $e)) {
                return;
            }
            $pipeline->recordFailed($e->getMessage());
        }

        $this->dispatchNext($pipeline);
    }

    public function failed(\Throwable $e): void
    {
        $pipeline = ReverseEngineeringPipelineStore::for('route_skeletons');
        if ($this->handleQuotaOrBilling($pipeline, $e)) {
            return;
        }
        $pipeline->recordFailed($e->getMessage());
        $this->dispatchNext($pipeline);
    }

    /**
     * @return bool true if this method fully handled the error (caller must return)
     */
    private function handleQuotaOrBilling(ReverseEngineeringPipelineStore $pipeline, \Throwable $e): bool
    {
        if (! $this->isQuota($e)) {
            return false;
        }

        $attempt = (int) $this->quotaAttempt;

        // 402 / prepaid empty — waiting 90s rarely helps; skip and continue.
        if ($this->isBillingDepleted($e) || $attempt >= self::MAX_QUOTA_ATTEMPTS) {
            $why = $this->isBillingDepleted($e) ? '402/credits' : 'quota×'.($attempt + 1);
            $pipeline->recordFailed($why.' #'.$this->routeSkeletonId.': '.mb_substr($e->getMessage(), 0, 180));
            $streak = $pipeline->bumpQuotaStreak();
            // All keys dead → don't burn the remaining ~thousands as failed.
            if ($streak >= 5) {
                $pipeline->pauseForQuota(
                    'Ключи Gemini выгорели (429/402 ×'.$streak.' подряд). Пауза. Пополните ключи и снова нажмите «Принцип маршрута».'
                );

                return true;
            }
            $this->dispatchNext($pipeline);

            return true;
        }

        $next = $attempt + 1;
        $pipeline->setLastError(
            '429 quota — пауза 90с, повтор #'.$this->routeSkeletonId
            .' ('.$next.'/'.self::MAX_QUOTA_ATTEMPTS.')'
        );
        self::dispatch($this->routeSkeletonId, $next)->delay(now()->addSeconds(90));

        return true;
    }

    private function isQuota(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, '429')
            || str_contains($msg, '402')
            || str_contains($msg, 'RESOURCE_EXHAUSTED')
            || str_contains(mb_strtolower($msg), 'quota')
            || str_contains(mb_strtolower($msg), 'credits are depleted');
    }

    private function isBillingDepleted(\Throwable $e): bool
    {
        $msg = mb_strtolower($e->getMessage());

        return str_contains($msg, '402')
            || str_contains($msg, 'credits are depleted')
            || str_contains($msg, 'prepayment');
    }

    private function dispatchNext(ReverseEngineeringPipelineStore $pipeline): void
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
