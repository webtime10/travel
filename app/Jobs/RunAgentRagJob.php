<?php

namespace App\Jobs;

use App\Services\AgentRagRunStore;
use App\Services\GeminiAgentRagService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunAgentRagJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $message,
        public ?string $systemPrompt = null,
    ) {}

    public function handle(GeminiAgentRagService $agent, AgentRagRunStore $store): void
    {
        $store->patch($this->runId, [
            'status' => 'running',
            'steps' => [
                [
                    'type' => 'start',
                    'title' => 'Worker взял задачу',
                    'detail' => 'Запуск цикла Gemini ↔ search_database',
                ],
            ],
        ]);

        try {
            $result = $agent->run(
                $this->message,
                $this->systemPrompt,
                function (array $steps) use ($store): void {
                    $store->patch($this->runId, [
                        'status' => 'running',
                        'steps' => $steps,
                    ]);
                }
            );

            $store->patch($this->runId, [
                'status' => 'done',
                'steps' => $result['steps'],
                'reply' => $result['reply'],
                'sources' => $result['sources'],
                'tool_calls' => $result['tool_calls'],
                'total_ms' => $result['total_ms'],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunAgentRagJob failed', [
                'run_id' => $this->runId,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($store, $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $store = app(AgentRagRunStore::class);
        $this->markFailed($store, $exception?->getMessage() ?: 'Job failed');
    }

    private function markFailed(AgentRagRunStore $store, string $message): void
    {
        $current = $store->get($this->runId) ?? [];
        if (($current['status'] ?? null) === 'failed') {
            return;
        }
        $steps = is_array($current['steps'] ?? null) ? $current['steps'] : [];
        $steps[] = [
            'type' => 'tool_error',
            'title' => 'Ошибка агента',
            'detail' => $message,
        ];
        $store->patch($this->runId, [
            'status' => 'failed',
            'steps' => $steps,
            'error' => $message,
        ]);
    }
}
