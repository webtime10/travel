<?php

namespace App\Jobs;

use App\Models\NewShortRoute;
use App\Services\NewShortRouteReverseEngineer;
use App\Services\ReverseEngineeringPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * New Short reverse engineering: full Word body only → JSON.
 */
class ReverseEngineerNewShortRouteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** 0 = после исчерпания ключей сразу skip + streak, без 90с freeze. */
    private const MAX_QUOTA_ATTEMPTS = 0;

    public int $newShortRouteId;

    /** @var 'gemini'|'claude' */
    public string $provider;

    /** Untyped so older queued payloads without this field still unserialize. */
    public $quotaAttempt = 0;

    public function __construct(int $newShortRouteId, string $provider = 'gemini', int $quotaAttempt = 0)
    {
        $this->newShortRouteId = $newShortRouteId;
        $this->provider = in_array($provider, ['gemini', 'claude'], true) ? $provider : 'gemini';
        $this->quotaAttempt = $quotaAttempt;
    }

    public static function pipelineName(string $provider = 'gemini'): string
    {
        return $provider === 'claude' ? 'new_short_claude' : 'new_short_gemini';
    }

    public function handle(NewShortRouteReverseEngineer $engineer): void
    {
        $pipeline = ReverseEngineeringPipelineStore::for(self::pipelineName($this->provider));
        if ($pipeline->cancelled()) {
            return;
        }

        $route = NewShortRoute::query()->find($this->newShortRouteId);
        if (! $route) {
            $pipeline->recordFailed('new_short '.$this->newShortRouteId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent('#'.$route->id.' '.$route->displayTitle(false).' · '.$this->provider);
        try {
            $engineer->extract($route->fresh(), $this->provider);
            $pipeline->setLastError(null);
            $pipeline->resetQuotaStreak();
            $pipeline->recordDone('ok');
        } catch (\Throwable $e) {
            Log::warning('ReverseEngineerNewShortRouteJob failed', [
                'id' => $this->newShortRouteId,
                'provider' => $this->provider,
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
        $pipeline = ReverseEngineeringPipelineStore::for(self::pipelineName($this->provider));
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

        if ($this->isBillingDepleted($e) || $attempt >= self::MAX_QUOTA_ATTEMPTS) {
            $why = $this->isBillingDepleted($e) ? '402/credits' : 'quota×'.($attempt + 1);
            $pipeline->recordFailed($why.' #'.$this->newShortRouteId.': '.mb_substr($e->getMessage(), 0, 180));
            $streak = $pipeline->bumpQuotaStreak();
            if ($streak >= 5) {
                $pipeline->pauseForQuota(
                    'Ключи/квота выгорели (429/402 ×'.$streak.' подряд, '.$this->provider
                    .'). Пауза. Пополните ключи и снова запустите пакетный разбор New Short.'
                );

                return true;
            }
            $this->dispatchNext($pipeline);

            return true;
        }

        $next = $attempt + 1;
        $pipeline->setLastError(
            '429 quota — пауза 90с, повтор #'.$this->newShortRouteId
            .' ('.$next.'/'.self::MAX_QUOTA_ATTEMPTS.')'
        );
        self::dispatch($this->newShortRouteId, $this->provider, $next)->delay(now()->addSeconds(90));

        return true;
    }

    private function isQuota(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, '429')
            || str_contains($msg, '402')
            || str_contains($msg, '503')
            || str_contains($msg, 'RESOURCE_EXHAUSTED')
            || str_contains($msg, 'cURL error 28')
            || str_contains(mb_strtolower($msg), 'timed out')
            || str_contains(mb_strtolower($msg), 'timeout')
            || str_contains(mb_strtolower($msg), 'quota')
            || str_contains(mb_strtolower($msg), 'rate limit')
            || str_contains(mb_strtolower($msg), 'credits are depleted')
            || str_contains(mb_strtolower($msg), 'overloaded');
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
            if ($this->provider === 'claude') {
                $delay = max($delay, (int) config('services.claude.pipeline_delay_seconds', 12));
            }
            self::dispatch($next, $this->provider)->delay(now()->addSeconds($delay));
        }
    }
}
