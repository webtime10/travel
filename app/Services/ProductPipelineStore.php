<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ProductPipelineStore
{
    private const IMPORT_KEY = 'travel.pipeline.import';

    private const TRANSLATE_KEY = 'travel.pipeline.translate';

    /**
     * @return array<string, mixed>
     */
    public function importStatus(): array
    {
        return $this->mergeDefaults(
            Cache::get(self::IMPORT_KEY, []),
            [
                'state' => 'idle',
                'total' => 0,
                'done' => 0,
                'failed' => 0,
                'current' => null,
                'last_error' => null,
                'pending' => 0,
            ]
        );
    }

    /**
     * @param  list<array{n: int, path: string, source: string, model: string}>  $queue
     */
    public function beginImport(array $queue): void
    {
        Cache::put(self::IMPORT_KEY, [
            'state' => 'running',
            'total' => count($queue),
            'done' => 0,
            'failed' => 0,
            'current' => null,
            'last_error' => null,
            'queue' => $queue,
            'cancel' => false,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDays(2));
    }

    public function cancelImport(): void
    {
        $data = Cache::get(self::IMPORT_KEY, []);
        if (! is_array($data)) {
            $data = [];
        }
        $data['cancel'] = true;
        $data['state'] = 'cancelled';
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::IMPORT_KEY, $data, now()->addDays(2));
    }

    public function importCancelled(): bool
    {
        $data = Cache::get(self::IMPORT_KEY, []);

        return is_array($data) && ! empty($data['cancel']);
    }

    /**
     * @return array{n: int, path: string, source: string, model: string}|null
     */
    public function shiftImportQueue(): ?array
    {
        $data = Cache::get(self::IMPORT_KEY, []);
        if (! is_array($data)) {
            return null;
        }
        $queue = $data['queue'] ?? [];
        if (! is_array($queue) || $queue === []) {
            return null;
        }
        $next = array_shift($queue);
        $data['queue'] = $queue;
        $data['current'] = is_array($next) ? ($next['source'] ?? null) : null;
        $data['pending'] = count($queue);
        Cache::put(self::IMPORT_KEY, $data, now()->addDays(2));

        return is_array($next) ? $next : null;
    }

    /**
     * @param  array{ok: bool, message?: string, source?: string}  $result
     */
    public function recordImportResult(array $result): void
    {
        $data = Cache::get(self::IMPORT_KEY, []);
        if (! is_array($data)) {
            return;
        }
        if ($result['ok']) {
            $data['done'] = (int) ($data['done'] ?? 0) + 1;
            $data['last_error'] = null;
        } else {
            $data['failed'] = (int) ($data['failed'] ?? 0) + 1;
            $data['last_error'] = $result['message'] ?? 'Unknown error';
        }
        $queue = $data['queue'] ?? [];
        $data['pending'] = is_array($queue) ? count($queue) : 0;
        $total = (int) ($data['total'] ?? 0);
        $done = (int) ($data['done'] ?? 0);
        $failed = (int) ($data['failed'] ?? 0);
        if ($data['pending'] === 0 && ! $this->importCancelled()) {
            $data['state'] = ($done + $failed) >= $total ? 'done' : 'done';
            $data['current'] = null;
        }
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::IMPORT_KEY, $data, now()->addDays(2));
    }

    public function finishImportIfEmpty(): void
    {
        $data = Cache::get(self::IMPORT_KEY, []);
        if (! is_array($data)) {
            return;
        }
        $queue = $data['queue'] ?? [];
        if (is_array($queue) && $queue === [] && ($data['state'] ?? '') === 'running') {
            $data['state'] = $this->importCancelled() ? 'cancelled' : 'done';
            $data['current'] = null;
            $data['updated_at'] = now()->toIso8601String();
            Cache::put(self::IMPORT_KEY, $data, now()->addDays(2));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function translateStatus(): array
    {
        return $this->mergeDefaults(
            Cache::get(self::TRANSLATE_KEY, []),
            [
                'state' => 'idle',
                'total' => 0,
                'products_done' => 0,
                'current_product_id' => null,
                'current_model' => null,
                'blocks_done' => 0,
                'blocks_total' => 0,
                'last_error' => null,
            ]
        );
    }

    /**
     * @param  list<int>  $productIds
     */
    public function beginTranslate(array $productIds, int $blocksTotalEstimate = 0): void
    {
        Cache::put(self::TRANSLATE_KEY, [
            'state' => 'running',
            'total' => count($productIds),
            'products_done' => 0,
            'queue' => array_values($productIds),
            'current_product_id' => null,
            'current_model' => null,
            'blocks_done' => 0,
            'blocks_total' => $blocksTotalEstimate,
            'last_error' => null,
            'cancel' => false,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDays(7));
    }

    public function cancelTranslate(): void
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);
        if (! is_array($data)) {
            $data = [];
        }
        $data['cancel'] = true;
        $data['state'] = 'cancelled';
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));
    }

    public function translateCancelled(): bool
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);

        return is_array($data) && ! empty($data['cancel']);
    }

    public function setTranslateCurrent(int $productId, ?string $model): void
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);
        if (! is_array($data)) {
            return;
        }
        $data['current_product_id'] = $productId;
        $data['current_model'] = $model;
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));
    }

    public function updateTranslateBlocks(int $blocksDone, int $blocksTotal): void
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);
        if (! is_array($data)) {
            return;
        }
        $data['blocks_done'] = $blocksDone;
        if ($blocksTotal > 0) {
            $data['blocks_total'] = $blocksTotal;
        }
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));
    }

    public function recordTranslateProductDone(): void
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);
        if (! is_array($data)) {
            return;
        }
        $data['products_done'] = (int) ($data['products_done'] ?? 0) + 1;
        $data['current_product_id'] = null;
        $data['current_model'] = null;
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));
    }

    public function recordTranslateError(string $message): void
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);
        if (! is_array($data)) {
            return;
        }
        $data['last_error'] = $message;
        $data['updated_at'] = now()->toIso8601String();
        Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));
    }

    /**
     * @return int|null next product id
     */
    public function shiftTranslateQueue(): ?int
    {
        $data = Cache::get(self::TRANSLATE_KEY, []);
        if (! is_array($data)) {
            return null;
        }
        $queue = $data['queue'] ?? [];
        if (! is_array($queue) || $queue === []) {
            if (($data['state'] ?? '') === 'running') {
                $data['state'] = $this->translateCancelled() ? 'cancelled' : 'done';
                $data['updated_at'] = now()->toIso8601String();
                Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));
            }

            return null;
        }
        $next = (int) array_shift($queue);
        $data['queue'] = $queue;
        Cache::put(self::TRANSLATE_KEY, $data, now()->addDays(7));

        return $next > 0 ? $next : null;
    }

    public function importRunning(): bool
    {
        return ($this->importStatus()['state'] ?? '') === 'running';
    }

    public function translateRunning(): bool
    {
        return ($this->translateStatus()['state'] ?? '') === 'running';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function mergeDefaults(array $data, array $defaults): array
    {
        $merged = array_merge($defaults, $data);
        unset($merged['queue'], $merged['cancel']);

        return $merged;
    }
}
