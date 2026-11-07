<?php

namespace App\Jobs;

use App\Models\FtShortRoute;
use App\Services\FtShortRouteReverseEngineer;
use App\Services\FtShortRouteRuTranslator;
use App\Services\ReverseEngineeringPipelineStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * FT Short reverse engineering через queue:work.
 * Gemini и Claude — разные пайплайны; RU-перевод всегда через Gemini.
 */
class ReverseEngineerFtShortRouteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** 0 = после исчерпания ключей сразу skip + streak, без 90с freeze. */
    private const MAX_QUOTA_ATTEMPTS = 0;

    public int $ftShortRouteId;

    /** @var 'gemini'|'claude' */
    public string $provider;

    /** Untyped so older queued payloads without this field still unserialize. */
    public $quotaAttempt = 0;

    public function __construct(int $ftShortRouteId, string $provider = 'gemini', int $quotaAttempt = 0)
    {
        $this->ftShortRouteId = $ftShortRouteId;
        $this->provider = in_array($provider, ['gemini', 'claude'], true) ? $provider : 'gemini';
        $this->quotaAttempt = $quotaAttempt;
    }

    public static function pipelineName(string $provider): string
    {
        return $provider === 'claude' ? 'ft_short_claude' : 'ft_short_gemini';
    }

    public function handle(FtShortRouteReverseEngineer $engineer, FtShortRouteRuTranslator $translator): void
    {
        $pipeline = ReverseEngineeringPipelineStore::for(self::pipelineName($this->provider));
        if ($pipeline->cancelled()) {
            return;
        }

        $route = FtShortRoute::query()->find($this->ftShortRouteId);
        if (! $route) {
            $pipeline->recordFailed('ft_short '.$this->ftShortRouteId.' not found');
            $this->dispatchNext($pipeline);

            return;
        }

        $pipeline->setCurrent('#'.$route->id.' '.$route->displayTitle(false).' · '.$this->provider);
        try {
            if ($this->provider === 'gemini') {
                $route->repairMisplacedRussianReverse();
                $route->refresh();
            }

            $engineer->extract($route->fresh(), $this->provider);
            $this->translateRu($translator, $route->fresh());
            $pipeline->setLastError(null);
            $pipeline->resetQuotaStreak();
            $pipeline->recordDone('ok');
        } catch (\Throwable $e) {
            Log::warning('ReverseEngineerFtShortRouteJob failed', [
                'id' => $this->ftShortRouteId,
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

    private function translateRu(FtShortRouteRuTranslator $translator, FtShortRoute $route): void
    {
        // Несколько чанков: RE + маршрут (Gemini) или только Claude RE.
        for ($i = 0; $i < 40; $i++) {
            $result = $this->provider === 'claude'
                ? $translator->translateClaudeReverse($route->fresh(), 40)
                : $translator->translateBatch($route->fresh(), 40);

            if (! empty($result['done'])) {
                return;
            }
            if ((int) ($result['translated'] ?? 0) === 0 && (int) ($result['remaining'] ?? 0) > 0) {
                throw new \RuntimeException($result['warning'] ?? 'Перевод RU: 0 units');
            }
            if ((int) ($result['remaining'] ?? 0) <= 0) {
                return;
            }
        }
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
            $pipeline->recordFailed($why.' #'.$this->ftShortRouteId.': '.mb_substr($e->getMessage(), 0, 180));
            $streak = $pipeline->bumpQuotaStreak();
            if ($streak >= 5) {
                $pipeline->pauseForQuota(
                    'Ключи/квота выгорели (429/402 ×'.$streak.' подряд, '.$this->provider
                    .'). Пауза. Пополните ключи и снова запустите пакетный разбор.'
                );

                return true;
            }
            $this->dispatchNext($pipeline);

            return true;
        }

        $next = $attempt + 1;
        $pipeline->setLastError(
            '429 quota — пауза 90с, повтор #'.$this->ftShortRouteId
            .' ('.$next.'/'.self::MAX_QUOTA_ATTEMPTS.')'
        );
        self::dispatch($this->ftShortRouteId, $this->provider, $next)->delay(now()->addSeconds(90));

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
