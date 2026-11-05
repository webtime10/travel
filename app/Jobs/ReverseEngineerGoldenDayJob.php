<?php

namespace App\Jobs;

use App\Models\GoldenDay;
use App\Services\GoldenDayReverseEngineer;
use App\Services\ReverseEngineeringPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReverseEngineerGoldenDayJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** 0 = after KeyPool exhausts keys, skip and continue (no 90s freeze). */
    private const MAX_QUOTA_ATTEMPTS = 0;

    public int $goldenDayId;

    /** Untyped so older queued payloads without this field still unserialize. */
    public $quotaAttempt = 0;

    public function __construct(int $goldenDayId, int $quotaAttempt = 0)
    {
        $this->goldenDayId = $goldenDayId;
        $this->quotaAttempt = $quotaAttempt;
    }

    public function handle(GoldenDayReverseEngineer $engineer): void
    {
        $pipeline = ReverseEngineeringPipelineStore::for('golden_days');
        if ($pipeline->cancelled()) {
            return;
        }

        $day = GoldenDay::with('stops')->find($this->goldenDayId);
        if (! $day) {
            $pipeline->recordFailed('golden day '.$this->goldenDayId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent('#'.$day->id.' '.$day->displayTitle());
        try {
            if ($day->stops->isEmpty()) {
                $day->reverse_tags = [
                    'skipped' => true,
                    'reason' => 'no_stops',
                    'extracted_at' => now()->toIso8601String(),
                ];
                $day->save();
                $pipeline->recordDone('skipped');
            } else {
                $engineer->extract($day);
                $pipeline->setLastError(null);
                $pipeline->resetQuotaStreak();
                $pipeline->recordDone('ok');
            }
        } catch (\Throwable $e) {
            Log::warning('ReverseEngineerGoldenDayJob failed', [
                'id' => $this->goldenDayId,
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
        $pipeline = ReverseEngineeringPipelineStore::for('golden_days');
        if ($this->handleQuotaOrBilling($pipeline, $e)) {
            return;
        }
        $pipeline->recordFailed($e->getMessage());
        $this->dispatchNext($pipeline);
    }

    /**
     * @return bool true if handled
     */
    private function handleQuotaOrBilling(ReverseEngineeringPipelineStore $pipeline, \Throwable $e): bool
    {
        if (! $this->isQuota($e)) {
            return false;
        }

        $attempt = (int) $this->quotaAttempt;

        if ($this->isBillingDepleted($e) || $attempt >= self::MAX_QUOTA_ATTEMPTS) {
            $why = $this->isBillingDepleted($e) ? '402/credits' : 'quota×'.($attempt + 1);
            $pipeline->recordFailed($why.' #'.$this->goldenDayId.': '.mb_substr($e->getMessage(), 0, 180));
            $streak = $pipeline->bumpQuotaStreak();
            if ($streak >= 5) {
                $pipeline->pauseForQuota(
                    'Ключи Gemini выгорели (429/402 ×'.$streak.' подряд). Пауза. Пополните ключи и снова нажмите старт.'
                );

                return true;
            }
            $this->dispatchNext($pipeline);

            return true;
        }

        $next = $attempt + 1;
        $pipeline->setLastError(
            '429 quota — пауза 90с, повтор #'.$this->goldenDayId
            .' ('.$next.'/'.self::MAX_QUOTA_ATTEMPTS.')'
        );
        self::dispatch($this->goldenDayId, $next)->delay(now()->addSeconds(90));

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
