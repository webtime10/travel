<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LocationFactsPipelineStore
{
    private const KEY = 'travel.pipeline.location_facts';

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
            'warn' => 0,
            'bad' => 0,
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
            'warn' => 0,
            'bad' => 0,
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

    public function recordDone(string $status): void
    {
        $data = $this->get();
        $data['done'] = (int) ($data['done'] ?? 0) + 1;
        if (in_array($status, ['ok', 'warn', 'bad'], true)) {
            $data[$status] = (int) ($data[$status] ?? 0) + 1;
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

    private function get(): array
    {
        $data = Cache::get(self::KEY, []);

        return is_array($data) ? $data : [];
    }

    private function put(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::KEY, $data, now()->addDays(7));
    }
}
