<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CategoryVectorPipelineStore
{
    private function key(int $categoryId): string
    {
        return 'travel.pipeline.vector.category.'.$categoryId;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $categoryId): array
    {
        $data = Cache::get($this->key($categoryId), []);
        if (! is_array($data)) {
            $data = [];
        }

        return array_merge([
            'state' => 'idle',
            'category_id' => $categoryId,
            'total' => 0,
            'done' => 0,
            'failed' => 0,
            'chunks' => 0,
            'current_product_id' => null,
            'current_model' => null,
            'last_error' => null,
        ], $data, ['queue' => null, 'cancel' => null]);
    }

    /**
     * @param  list<int>  $productIds
     */
    public function begin(int $categoryId, array $productIds): void
    {
        Cache::put($this->key($categoryId), [
            'state' => 'running',
            'category_id' => $categoryId,
            'total' => count($productIds),
            'done' => 0,
            'failed' => 0,
            'chunks' => 0,
            'queue' => array_values($productIds),
            'current_product_id' => null,
            'current_model' => null,
            'last_error' => null,
            'cancel' => false,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDays(3));
    }

    public function cancel(int $categoryId): void
    {
        $data = Cache::get($this->key($categoryId), []);
        if (! is_array($data)) {
            $data = [];
        }
        $data['cancel'] = true;
        $data['state'] = 'cancelled';
        $data['updated_at'] = now()->toIso8601String();
        Cache::put($this->key($categoryId), $data, now()->addDays(3));
    }

    public function cancelled(int $categoryId): bool
    {
        $data = Cache::get($this->key($categoryId), []);

        return is_array($data) && ! empty($data['cancel']);
    }

    public function running(int $categoryId): bool
    {
        return ($this->status($categoryId)['state'] ?? '') === 'running';
    }

    public function setCurrent(int $categoryId, int $productId, ?string $model): void
    {
        $data = Cache::get($this->key($categoryId), []);
        if (! is_array($data)) {
            return;
        }
        $data['current_product_id'] = $productId;
        $data['current_model'] = $model;
        $data['updated_at'] = now()->toIso8601String();
        Cache::put($this->key($categoryId), $data, now()->addDays(3));
    }

    public function recordDone(int $categoryId, int $chunks): void
    {
        $data = Cache::get($this->key($categoryId), []);
        if (! is_array($data)) {
            return;
        }
        $data['done'] = (int) ($data['done'] ?? 0) + 1;
        $data['chunks'] = (int) ($data['chunks'] ?? 0) + $chunks;
        $data['current_product_id'] = null;
        $data['current_model'] = null;
        $data['updated_at'] = now()->toIso8601String();
        Cache::put($this->key($categoryId), $data, now()->addDays(3));
    }

    public function recordFailed(int $categoryId, string $message): void
    {
        $data = Cache::get($this->key($categoryId), []);
        if (! is_array($data)) {
            return;
        }
        $data['failed'] = (int) ($data['failed'] ?? 0) + 1;
        $data['last_error'] = $message;
        $data['current_product_id'] = null;
        $data['current_model'] = null;
        $data['updated_at'] = now()->toIso8601String();
        Cache::put($this->key($categoryId), $data, now()->addDays(3));
    }

    public function shiftQueue(int $categoryId): ?int
    {
        $data = Cache::get($this->key($categoryId), []);
        if (! is_array($data)) {
            return null;
        }
        $queue = $data['queue'] ?? [];
        if (! is_array($queue) || $queue === []) {
            if (($data['state'] ?? '') === 'running') {
                $data['state'] = ! empty($data['cancel']) ? 'cancelled' : 'done';
                $data['updated_at'] = now()->toIso8601String();
                Cache::put($this->key($categoryId), $data, now()->addDays(3));
            }

            return null;
        }
        $next = (int) array_shift($queue);
        $data['queue'] = $queue;
        Cache::put($this->key($categoryId), $data, now()->addDays(3));

        return $next > 0 ? $next : null;
    }
}
