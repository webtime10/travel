<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Round-robin Gemini API keys from GEMINI_API_KEY=k1,k2,k3
 */
class GeminiKeyPool
{
    private const CACHE_INDEX = 'travel.gemini.key_index';

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $raw = (string) config('services.gemini.key', '');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $k = trim($part, " \t\n\r\0\x0B\"'");
            if ($k !== '') {
                $keys[] = $k;
            }
        }

        return array_values(array_unique($keys));
    }

    public function count(): int
    {
        return count($this->keys());
    }

    public function current(): string
    {
        $keys = $this->keys();
        if ($keys === []) {
            throw new \RuntimeException('GEMINI_API_KEY is empty');
        }
        $i = (int) Cache::get(self::CACHE_INDEX, 0);
        if ($i < 0 || $i >= count($keys)) {
            $i = 0;
            Cache::put(self::CACHE_INDEX, 0, now()->addDays(7));
        }

        return $keys[$i];
    }

    public function rotate(): string
    {
        $keys = $this->keys();
        if ($keys === []) {
            throw new \RuntimeException('GEMINI_API_KEY is empty');
        }
        $i = ((int) Cache::get(self::CACHE_INDEX, 0) + 1) % count($keys);
        Cache::put(self::CACHE_INDEX, $i, now()->addDays(7));

        return $keys[$i];
    }

    /**
     * Run callback with current key; on failure rotate and retry across all keys.
     *
     * @template T
     *
     * @param  callable(string $apiKey): T  $callback
     * @return T
     */
    public function withKey(callable $callback, ?callable $shouldRotate = null): mixed
    {
        $keys = $this->keys();
        if ($keys === []) {
            throw new \RuntimeException('GEMINI_API_KEY is empty');
        }

        $shouldRotate ??= function (\Throwable $e): bool {
            $msg = $e->getMessage();

            return str_contains($msg, '429')
                || str_contains($msg, '401')
                || str_contains($msg, '403')
                || str_contains($msg, 'RESOURCE_EXHAUSTED')
                || str_contains($msg, 'API_KEY')
                || str_contains($msg, 'quota')
                || str_contains($msg, 'PERMISSION_DENIED');
        };

        $attempts = count($keys);
        $last = null;
        for ($n = 0; $n < $attempts; $n++) {
            $key = $this->current();
            try {
                return $callback($key);
            } catch (\Throwable $e) {
                $last = $e;
                if (! $shouldRotate($e) || $n === $attempts - 1) {
                    throw $e;
                }
                $this->rotate();
                usleep(400_000);
            }
        }

        throw $last ?? new \RuntimeException('Gemini key pool exhausted');
    }
}
