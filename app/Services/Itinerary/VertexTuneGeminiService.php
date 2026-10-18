<?php

namespace App\Services\Itinerary;

use App\Models\Product;
use Google\Client as GoogleClient;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Export tour metas → Vertex AI supervised fine-tuning dataset + GCS upload + tuningJobs.create.
 */
class VertexTuneGeminiService
{
    private const SYSTEM = 'Ты — эксперт-архитектор по планированию туристических маршрутов. Отвечай строго валидным JSON по заданной структуре. Без воды, без прыжков между далёкими регионами в один день.';

    /**
     * Экспорт метаданных туров из БД в файл датасета .jsonl для Vertex AI.
     *
     * @param  int  $limit  Максимальное количество маршрутов для экспорта (по умолчанию 1200)
     * @param  string|null  $path  Путь к сохраняемому файлу (если null — сохранит в storage/app/dataset.jsonl)
     * @return array{path: string, count: int, skipped: int} Статистика экспорта
     */
    public function exportDataset(int $limit = 1200, ?string $path = null): array
    {
        // 1. Ограничиваем $limit рамками от 1 до 5000, чтобы избежать некорректных значений
        $limit = max(1, min(5000, $limit));

        // 2. Если путь к файлу не передан, задаем дефолтный: storage/app/dataset.jsonl
        $path ??= storage_path('app/dataset.jsonl');

        // 3. Получаем путь к директории, в которой должен лежать файл
        $dir = dirname($path);

        // 4. Проверяем существование директории. Если её нет — пробуем создать (с правами 0755, рекурсивно)
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            // Если папку создать не удалось — выбрасываем исключение
            throw new RuntimeException('Cannot create directory: '.$dir);
        }

        // 5. Открываем (или создаем) файл для записи в бинарном режиме ('wb')
        $fh = fopen($path, 'wb');

        // 6. Если дескриптор файла не создался (нет прав и т.д.) — выбрасываем ошибку
        if ($fh === false) {
            throw new RuntimeException('Cannot write dataset: '.$path);
        }

        // 7. Инициализируем счетчики: успешно выгруженных ($count) и пропущенных ($skipped) записей
        $count = 0;
        $skipped = 0;

        // 8. Строим SQL-запрос к модели Product (таблица products)
        Product::query()
            // Выбираем только те товары, у которых заполнены JSON-поля meta_ru или meta
            ->where(function ($q) {
                $q->whereNotNull('meta_ru')->orWhereNotNull('meta');
            })
            // Сортируем по возрастанию ID для стабильной порционной выборки
            ->orderBy('id')
            // Пакетируем выборку по 100 записей (chunkById использует первичный ключ и не перегружает память)
            ->chunkById(100, function ($products) use ($fh, $limit, &$count, &$skipped) {

                // 9. Перебираем каждый товар из текущей порции (100 шт)
                foreach ($products as $product) {

                    // Если достигли желаемого лимита — останавливаем цикл перебора
                    if ($count >= $limit) {
                        return false; // Завершает выполнение chunkById
                    }

                    // Трансформируем товар в структуру примера для Gemini (system/user/model)
                    $example = $this->productToExample($product);

                    // Если структура не сформировалась (нет дней, пустой текст и т.д.) — пропускаем
                    if ($example === null) {
                        $skipped++; // Увеличиваем счетчик пропущенных

                        continue;   // Переходим к следующему товару
                    }

                    // Преобразуем массив в JSON-строку без экранирования кириллицы и слэшей
                    $line = json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    // Если кодирование в JSON завершилось ошибкой — пропускаем
                    if ($line === false) {
                        $skipped++;

                        continue;
                    }

                    // Записываем JSON-строку в файл с переносом на новую строку \n (формат JSONL)
                    fwrite($fh, $line."\n");

                    // Увеличиваем счетчик успешно записанных маршрутов
                    $count++;
                }

                // Возвращаем true, если нужно продолжать подгружать следующие 100 товаров из БД
                return $count < $limit;
            });

        // 10. Закрываем дескриптор файла после завершения записи
        fclose($fh);

        // 11. Если не удалось записать ни одного маршрута — выбрасываем ошибку
        if ($count === 0) {
            throw new RuntimeException('Не удалось выгрузить ни одного маршрута из products (meta/meta_ru пусты или без дней)');
        }

        // 12. Возвращаем итоговый отчет с результатами работы
        return ['path' => $path, 'count' => $count, 'skipped' => $skipped];
    }

    /**
     * @return array{gs_uri: string, object: string, bytes: int}
     */
    public function uploadToGcs(string $localPath, ?string $objectName = null): array
    {
        $credentials = $this->bootstrapApplicationCredentials();
        $bucketName = (string) config('services.vertex.gcs_bucket');
        $objectName ??= 'dataset.jsonl';

        if (! is_file($localPath)) {
            throw new RuntimeException('Local dataset missing: '.$localPath);
        }

        $storage = new StorageClient([
            'keyFilePath' => $credentials,
            'projectId' => (string) config('services.vertex.project_id'),
        ]);

        $bucket = $storage->bucket($bucketName);
        if (! $bucket->exists()) {
            throw new RuntimeException('GCS bucket not found or no access: '.$bucketName);
        }

        $bytes = filesize($localPath) ?: 0;
        $object = $bucket->upload(
            fopen($localPath, 'r'),
            [
                'name' => $objectName,
                'metadata' => [
                    'contentType' => 'application/jsonl',
                ],
            ]
        );

        $gsUri = 'gs://'.$bucketName.'/'.$object->name();

        return [
            'gs_uri' => $gsUri,
            'object' => $object->name(),
            'bytes' => (int) $bytes,
        ];
    }

    /**
     * @return array{name: string, raw: array<string, mixed>}
     */
    public function createTuningJob(string $trainingDatasetUri, ?string $displayName = null): array
    {
        $project = (string) config('services.vertex.project_id');
        $location = (string) config('services.vertex.location');
        $baseModel = (string) config('services.vertex.base_model');
        $displayName ??= (string) config('services.vertex.tuned_display_name', 'travel-route-model-v1');
        $epochs = max(1, (int) config('services.vertex.epoch_count', 4));

        $token = $this->accessToken();
        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/tuningJobs',
            $location,
            rawurlencode($project),
            rawurlencode($location),
        );

        $body = [
            'baseModel' => $baseModel,
            'tunedModelDisplayName' => $displayName,
            'supervisedTuningSpec' => [
                'trainingDatasetUri' => $trainingDatasetUri,
                'hyperParameters' => [
                    'epochCount' => $epochs,
                ],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])
            ->connectTimeout(15)
            ->timeout(120)
            ->acceptJson()
            ->asJson()
            ->post($url, $body);

        $json = $response->json();
        if (! is_array($json)) {
            $json = ['raw_body' => mb_substr($response->body(), 0, 1000)];
        }

        if (! $response->successful()) {
            $msg = $json['error']['message'] ?? mb_substr($response->body(), 0, 500);
            throw new RuntimeException('Vertex tuningJobs HTTP '.$response->status().': '.$msg);
        }

        $name = (string) ($json['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Vertex tuningJobs: empty job name in response');
        }

        return ['name' => $name, 'raw' => $json];
    }

    public function credentialsPath(): string
    {
        $path = (string) config('services.vertex.credentials');
        if ($path === '') {
            $path = storage_path('app/json/google-credentials.json');
        }
        // Relative paths from .env → absolute (Google libs require a real file path).
        if ($path !== '' && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) && ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (! is_file($path)) {
            $fallback = storage_path('app/json/google-credentials.json');
            if (is_file($fallback)) {
                $path = $fallback;
            }
        }
        if (! is_file($path)) {
            throw new RuntimeException(
                'Нет файла учетных данных: '.$path
                ."\nПоложи service account JSON в storage/app/json/google-credentials.json"
            );
        }

        return $path;
    }

    /**
     * Set GOOGLE_APPLICATION_CREDENTIALS for this PHP process (ADC / Google libs).
     */
    public function bootstrapApplicationCredentials(): string
    {
        $path = $this->credentialsPath();
        putenv('GOOGLE_APPLICATION_CREDENTIALS='.$path);
        $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $path;
        $_SERVER['GOOGLE_APPLICATION_CREDENTIALS'] = $path;

        return $path;
    }

    public function accessToken(): string
    {
        $credentialsPath = $this->bootstrapApplicationCredentials();
        $rawJson = file_get_contents($credentialsPath);
        $data = is_string($rawJson) ? json_decode($rawJson, true) : null;

        // gcloud ADC / "authorized_user" (refresh_token) — not service_account assertion.
        if (is_array($data) && ($data['type'] ?? '') === 'authorized_user') {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $data['client_id'] ?? '',
                'client_secret' => $data['client_secret'] ?? '',
                'refresh_token' => $data['refresh_token'] ?? '',
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Google OAuth refresh failed: '.mb_substr($response->body(), 0, 500));
            }

            $access = (string) ($response->json('access_token') ?? '');
            if ($access !== '') {
                return $access;
            }

            throw new RuntimeException('Google OAuth refresh: empty access_token');
        }

        // Fallback: service_account JWT assertion.
        $client = new GoogleClient;
        $client->setAuthConfig($credentialsPath);
        $client->addScope('https://www.googleapis.com/auth/cloud-platform');
        $client->addScope('https://www.googleapis.com/auth/devstorage.read_write');

        $token = $client->fetchAccessTokenWithAssertion();
        if (! is_array($token)) {
            throw new RuntimeException('Google Client: fetchAccessTokenWithAssertion returned non-array');
        }
        if (isset($token['error'])) {
            $err = (string) ($token['error_description'] ?? $token['error']);
            throw new RuntimeException('Google Client auth error: '.$err);
        }

        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            $stored = $client->getAccessToken();
            $access = is_array($stored) ? (string) ($stored['access_token'] ?? '') : '';
        }
        if ($access === '') {
            throw new RuntimeException(
                'Google Client: empty access_token. Проверь JSON (authorized_user или service_account) и права IAM.'
            );
        }

        return $access;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productToExample(Product $product): ?array
    {
        $meta = $product->ruMeta() ?? $product->heMeta();
        if ($meta === null) {
            $raw = is_array($product->meta_ru) ? $product->meta_ru : (is_array($product->meta) ? $product->meta : null);
            if (! is_array($raw)) {
                return null;
            }
            $meta = $raw;
        }

        $days = $this->compactDaysFromMeta($meta);
        if ($days === []) {
            return null;
        }

        $modelCode = trim((string) ($product->model ?? ''));
        $user = 'Сформируй детальный туристический маршрут на основе вводных данных.'
            ."\nproduct_id=".$product->id
            .($modelCode !== '' ? (' model='.$modelCode) : '')
            ."\nВерни JSON: {\"days\":[{\"day\":1,\"summary\":\"...\",\"places\":[\"...\"],\"body\":\"...\"}, ...]}";

        $target = json_encode([
            'product_id' => $product->id,
            'model' => $modelCode !== '' ? $modelCode : null,
            'days' => $days,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($target === false) {
            return null;
        }

        return [
            'systemInstruction' => [
                'role' => 'system',
                'parts' => [['text' => self::SYSTEM]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $user]],
                ],
                [
                    'role' => 'model',
                    'parts' => [['text' => $target]],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array{day: int, summary: string, places: list<string>, body: string}>
     */
    private function compactDaysFromMeta(array $meta): array
    {
        $blocks = is_array($meta['blocks'] ?? null) ? $meta['blocks'] : [];
        $days = [];
        $hebrewWeekdays = 'ראשון|שני|שלישי|רביעי|חמישי|שישי|שבת';
        $current = null;

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') === 'image') {
                continue;
            }
            $text = trim((string) ($block['text'] ?? $block['text_ru'] ?? $block['text_he'] ?? ''));
            if ($text === '' || str_contains($text, '#_Toc')) {
                continue;
            }
            $lines = preg_split('/\R/u', $text) ?: [];
            $first = trim((string) ($lines[0] ?? ''));
            $isHeader = $first !== ''
                && mb_strlen($first) <= 160
                && (
                    preg_match('/^(?:יום)\s+('.$hebrewWeekdays.')\b/u', $first)
                    || preg_match('/^(?:יום|день|day)\s*[:\-]?\s*\d{1,2}\b/iu', $first)
                    || preg_match('/^\d{1,2}[.\-\/]\d{1,2}(?:[.\-\/]\d{2,4})?\b/u', $first)
                );

            if ($isHeader) {
                if ($current !== null) {
                    $days[] = $current;
                }
                $body = trim(mb_substr($text, mb_strlen($first)));
                $current = [
                    'day' => count($days) + 1,
                    'summary' => mb_substr($first, 0, 120),
                    'places' => $this->guessPlaces($first.' '.$body),
                    'body' => mb_substr($body !== '' ? $body : $first, 0, 500),
                ];

                continue;
            }

            if ($current !== null) {
                $extra = mb_substr($text, 0, 400);
                $current['body'] = mb_substr(trim($current['body']."\n".$extra), 0, 800);
                if ($current['places'] === []) {
                    $current['places'] = $this->guessPlaces($current['summary'].' '.$extra);
                }
            }
        }

        if ($current !== null) {
            $days[] = $current;
        }

        // Fallback: one blob if no day headers
        if ($days === [] && $blocks !== []) {
            $blob = '';
            foreach (array_slice($blocks, 0, 20) as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $t = trim((string) ($block['text'] ?? $block['text_ru'] ?? ''));
                if ($t === '' || ($block['type'] ?? '') === 'image') {
                    continue;
                }
                $blob .= $t."\n";
                if (mb_strlen($blob) > 1500) {
                    break;
                }
            }
            $blob = trim($blob);
            if ($blob !== '') {
                $days[] = [
                    'day' => 1,
                    'summary' => mb_substr($blob, 0, 80),
                    'places' => $this->guessPlaces($blob),
                    'body' => mb_substr($blob, 0, 800),
                ];
            }
        }

        return array_slice($days, 0, 21);
    }

    /**
     * @return list<string>
     */
    private function guessPlaces(string $text): array
    {
        $map = [
            'Рива' => 'Рива-дель-Гарда',
            'Riva' => 'Рива-дель-Гарда',
            'Лимоне' => 'Лимоне-суль-Гарда',
            'Limone' => 'Лимоне-суль-Гарда',
            'Мальчезине' => 'Мальчезине',
            'Malcesine' => 'Мальчезине',
            'Сирмионе' => 'Сирмионе',
            'Sirmione' => 'Сирмионе',
            'Комо' => 'Комо',
            'Como' => 'Комо',
            'Белладжо' => 'Белладжо',
            'Bellagio' => 'Белладжо',
            'Варенна' => 'Варенна',
            'Varenna' => 'Варенна',
            'Ортизеи' => 'Ортизеи',
            'Ortisei' => 'Ортизеи',
            'Верона' => 'Верона',
            'Verona' => 'Верона',
            'Милан' => 'Милан',
            'Milan' => 'Милан',
            'Тенно' => 'Озеро Тенно',
            'Tenno' => 'Озеро Тенно',
            'Вароне' => 'Вароне',
            'Varone' => 'Вароне',
        ];
        $out = [];
        foreach ($map as $needle => $label) {
            if (mb_stripos($text, $needle) !== false && ! in_array($label, $out, true)) {
                $out[] = $label;
            }
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }
}
