<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiEmbeddingService
{
    public function __construct(private GeminiKeyPool $keys) {}

    public function model(): string
    {
        return trim((string) config('services.gemini.embedding_model', 'gemini-embedding-001'));
    }

    public function dimensions(): int
    {
        return max(1, (int) config('services.gemini.embedding_dimensions', 768));
    }

    public function batchSize(): int
    {
        return max(5, min(100, (int) config('services.gemini.embedding_batch', 40)));
    }

    public function timeoutSeconds(): int
    {
        return max(60, (int) config('services.gemini.embedding_timeout', 300));
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts): array
    {
        $texts = array_values(array_map(fn ($t) => trim((string) $t), $texts));
        if ($texts === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($texts, $this->batchSize()) as $chunk) {
            foreach ($this->batchEmbedContents($chunk) as $v) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @return list<float>
     */
    public function embedOne(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Empty text for embedding');
        }

        return $this->keys->withKey(function (string $apiKey) use ($text) {
            $model = $this->model();
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':embedContent?key='.$apiKey;

            $payload = [
                'model' => 'models/'.$model,
                'content' => [
                    'parts' => [['text' => mb_substr($text, 0, 8000)]],
                ],
                'outputDimensionality' => $this->dimensions(),
            ];

            $data = $this->postJsonWithRetry($url, $payload);
            $values = $data['embedding']['values'] ?? null;
            if (! is_array($values) || $values === []) {
                throw new RuntimeException('Empty embedding response');
            }

            return $this->normalizeVector($values);
        });
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function batchEmbedContents(array $texts): array
    {
        return $this->keys->withKey(function (string $apiKey) use ($texts) {
            $model = $this->model();
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':batchEmbedContents?key='.$apiKey;

            $dim = $this->dimensions();
            $requests = [];
            foreach ($texts as $text) {
                $requests[] = [
                    'model' => 'models/'.$model,
                    'content' => [
                        'parts' => [['text' => mb_substr($text, 0, 8000)]],
                    ],
                    'outputDimensionality' => $dim,
                ];
            }

            $data = $this->postJsonWithRetry($url, ['requests' => $requests]);
            $embeddings = $data['embeddings'] ?? null;
            if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
                throw new RuntimeException(
                    'batchEmbedContents size mismatch: got '
                    .(is_array($embeddings) ? count($embeddings) : 0)
                    .' expected '.count($texts)
                );
            }

            $out = [];
            foreach ($embeddings as $row) {
                $values = is_array($row) ? ($row['values'] ?? null) : null;
                if (! is_array($values)) {
                    throw new RuntimeException('Invalid embedding item');
                }
                $out[] = $this->normalizeVector($values);
            }

            return $out;
        });
    }

    /**
     * @param  array<mixed>  $values
     * @return list<float>
     */
    private function normalizeVector(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = (float) $v;
        }
        $expected = $this->dimensions();
        if (count($out) !== $expected) {
            throw new RuntimeException('Unexpected embedding dim '.count($out).' (expected '.$expected.')');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postJsonWithRetry(string $url, array $payload, int $tries = 6): array
    {
        $wait = 2;
        $lastError = 'Gemini embed failed';
        $timeout = $this->timeoutSeconds();

        for ($i = 1; $i <= $tries; $i++) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(30)
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);

                $lastStatus = $response->status();
                $lastBody = mb_substr($response->body(), 0, 400);
                $lastError = 'Gemini embed HTTP '.$lastStatus.': '.$lastBody;

                if ($response->successful()) {
                    $json = $response->json();
                    if (! is_array($json)) {
                        throw new RuntimeException('Gemini embed non-JSON response');
                    }

                    return $json;
                }

                $retryableHttp = in_array($lastStatus, [429, 500, 502, 503, 504], true);
                if ($retryableHttp && $i < $tries) {
                    sleep($wait);
                    $wait = min(60, $wait * 2);

                    continue;
                }

                throw new RuntimeException($lastError);
            } catch (ConnectionException $e) {
                $lastError = 'Gemini embed connection: '.$e->getMessage();
                if ($i >= $tries) {
                    throw new RuntimeException($lastError, 0, $e);
                }
                sleep($wait);
                $wait = min(60, $wait * 2);
            } catch (RuntimeException $e) {
                if ($i >= $tries || ! $this->isRetryableMessage($e->getMessage())) {
                    throw $e;
                }
                $lastError = $e->getMessage();
                sleep($wait);
                $wait = min(60, $wait * 2);
            }
        }

        throw new RuntimeException($lastError);
    }

    private function isRetryableMessage(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, '429')
            || str_contains($message, '503')
            || str_contains($message, '502')
            || str_contains($message, '504')
            || str_contains($message, 'unavailable');
    }
}
