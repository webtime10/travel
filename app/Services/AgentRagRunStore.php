<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AgentRagRunStore
{
    private function key(string $runId): string
    {
        return 'travel.agent.rag.run.'.$runId;
    }

    public function create(string $message): string
    {
        $runId = (string) Str::uuid();
        Cache::put($this->key($runId), [
            'status' => 'queued',
            'message' => $message,
            'steps' => [
                [
                    'type' => 'start',
                    'title' => 'В очереди',
                    'detail' => 'Ждём worker: php artisan queue:work (или queue:work-forever)',
                ],
            ],
            'reply' => null,
            'sources' => [],
            'tool_calls' => 0,
            'total_ms' => null,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        return $runId;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function patch(string $runId, array $patch): void
    {
        $data = Cache::get($this->key($runId), []);
        if (! is_array($data)) {
            $data = [];
        }
        $data = array_merge($data, $patch, [
            'updated_at' => now()->toIso8601String(),
        ]);
        Cache::put($this->key($runId), $data, now()->addHours(6));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $runId): ?array
    {
        $data = Cache::get($this->key($runId));

        return is_array($data) ? $data : null;
    }
}
