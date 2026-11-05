<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Generic queue status for reverse-engineering pipelines (keyed by name).
 */
class ReverseEngineeringPipelineStore
{
    public function __construct(private string $name) {}

    public static function for(string $name): self
    {
        return new self($name);
    }

    private function key(): string
    {
        return 'travel.pipeline.reverse.'.$this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $data = array_merge([
            'state' => 'idle',
            'total' => 0,
            'done' => 0,
            'failed' => 0,
            'ok' => 0,
            'skipped' => 0,
            'current' => null,
            'last_error' => null,
        ], $this->get());
        $data['pending'] = count((array) ($data['queue'] ?? []));
        unset($data['queue'], $data['cancel']);

        return $data;
    }

    public function running(): bool
    {
        return ($this->get()['state'] ?? '') === 'running';
    }

    /**
     * @param  list<int>  $ids
     */
    public function begin(array $ids): void
    {
        $this->put([
            'state' => 'running',
            'total' => count($ids),
            'done' => 0,
            'failed' => 0,
            'ok' => 0,
            'skipped' => 0,
            'current' => null,
            'last_error' => null,
            'queue' => array_values($ids),
            'cancel' => false,
        ]);
    }

    public function cancel(): void
    {
        $data = $this->get();
        $data['cancel'] = true;
        $data['state'] = 'cancelled';
        $data['queue'] = [];
        $this->put($data);
    }

    public function cancelled(): bool
    {
        return ! empty($this->get()['cancel']);
    }

    public function shift(): ?int
    {
        $data = $this->get();
        $queue = (array) ($data['queue'] ?? []);
        if ($queue === []) {
            if (($data['state'] ?? '') === 'running') {
                $data['state'] = 'done';
                $data['current'] = null;
                $this->put($data);
            }

            return null;
        }
        $next = (int) array_shift($queue);
        $data['queue'] = $queue;
        $this->put($data);

        return $next > 0 ? $next : null;
    }

    public function setCurrent(?string $label): void
    {
        $data = $this->get();
        $data['current'] = $label;
        $this->put($data);
    }

    public function setLastError(?string $message): void
    {
        $data = $this->get();
        $data['last_error'] = $message === null ? null : mb_substr($message, 0, 300);
        $this->put($data);
    }

    public function recordDone(string $kind = 'ok'): void
    {
        $data = $this->get();
        $data['done'] = (int) ($data['done'] ?? 0) + 1;
        if ($kind === 'skipped') {
            $data['skipped'] = (int) ($data['skipped'] ?? 0) + 1;
        } else {
            $data['ok'] = (int) ($data['ok'] ?? 0) + 1;
        }
        $this->put($data);
    }

    public function recordFailed(string $message): void
    {
        $data = $this->get();
        $data['failed'] = (int) ($data['failed'] ?? 0) + 1;
        $data['last_error'] = mb_substr($message, 0, 300);
        $this->put($data);
    }

    public function resetQuotaStreak(): void
    {
        $data = $this->get();
        $data['quota_streak'] = 0;
        $this->put($data);
    }

    public function bumpQuotaStreak(): int
    {
        $data = $this->get();
        $streak = (int) ($data['quota_streak'] ?? 0) + 1;
        $data['quota_streak'] = $streak;
        $this->put($data);

        return $streak;
    }

    /**
     * Soft-stop: keep remaining queue for a later restart, but stop dispatching.
     */
    public function pauseForQuota(string $message): void
    {
        $data = $this->get();
        $data['state'] = 'paused_quota';
        $data['last_error'] = mb_substr($message, 0, 300);
        $data['current'] = null;
        $this->put($data);
    }

    private function get(): array
    {
        $data = Cache::get($this->key(), []);

        return is_array($data) ? $data : [];
    }

    private function put(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put($this->key(), $data, now()->addDays(7));
    }
}
