<?php

namespace App\Services\Itinerary;

use App\Services\GeminiKeyPool;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Demo / probe fine-tuning: synthetic examples + Gemini tunedModels API (legacy).
 * Note: Google deprecated Gemini API/AI Studio fine-tuning for current models;
 * this still prepares a dataset and attempts the legacy endpoint for experimentation.
 */
class TuneGeminiDemoService
{
    public function __construct(private GeminiKeyPool $keys) {}

    /**
     * @return list<array{input: string, output: string}>
     */
    public function demoExamples(int $limit = 15): array
    {
        $all = [
            [
                'input' => 'Собери краткий маршрут: 7 дней, прилёт Милан, природа, Гарда+Доломиты, без больших городов. Формат: ДД.ММ — места.',
                'output' => '{"days":[{"date":"01.07","kind":"arrival","places":["Милан MXP","Рива-дель-Гарда"],"text":"01.07 — прилёт Милан, переезд в Рива-дель-Гарда"},{"date":"02.07","kind":"program","places":["Озеро Тенно","Водопад Вароне","Рива-дель-Гарда"],"text":"02.07 — озеро Тенно, водопад Вароне, Рива"},{"date":"03.07","kind":"program","places":["Мальчезине","Монте-Бальдо","Лимоне-суль-Гарда"],"text":"03.07 — Мальчезине, Монте-Бальдо, паром в Лимоне"},{"date":"04.07","kind":"transfer","places":["Рива-дель-Гарда","Ортизеи"],"text":"04.07 — переезд Рива → Ортизеи"},{"date":"05.07","kind":"program","places":["Перевал Селла","Ортизеи"],"text":"05.07 — перевал Селла, Ортизеи"},{"date":"06.07","kind":"program","places":["Озеро Карецца","Ортизеи"],"text":"06.07 — озеро Карецца"},{"date":"07.07","kind":"departure","places":["Ортизеи","Милан MXP"],"text":"07.07 — выезд к Милану, вылет"}]}',
            ],
            [
                'input' => '5 дней, хаб Верона, только север Гарды (Рива/Лимоне/Мальчезине), семья. Формат: ДД.ММ — места.',
                'output' => '{"days":[{"date":"10.08","kind":"arrival","places":["Верона","Рива-дель-Гарда"],"text":"10.08 — прилёт/приезд, Рива-дель-Гарда"},{"date":"11.08","kind":"program","places":["Лимоне-суль-Гарда","Мальчезине"],"text":"11.08 — Лимоне, паром в Мальчезине"},{"date":"12.08","kind":"program","places":["Озеро Ледро","Рива-дель-Гарда"],"text":"12.08 — озеро Ледро, Рива"},{"date":"13.08","kind":"program","places":["Озеро Тенно","Водопад Вароне"],"text":"13.08 — Тенно и Вароне"},{"date":"14.08","kind":"departure","places":["Рива-дель-Гарда","Верона"],"text":"14.08 — выезд на Верону"}]}',
            ],
            [
                'input' => '4 дня Комо из Милана, без шопинга. Формат строк ДД.ММ.',
                'output' => '{"days":[{"date":"03.06","kind":"arrival","places":["Милан","Комо"],"text":"03.06 — Милан → Комо"},{"date":"04.06","kind":"program","places":["Варенна","Белладжо"],"text":"04.06 — Варенна, паром в Белладжо"},{"date":"05.06","kind":"program","places":["Орридо-ди-Нессо","Комо"],"text":"05.06 — Орридо-ди-Нессо, Комо"},{"date":"06.06","kind":"departure","places":["Комо","Милан"],"text":"06.06 — возврат в Милан"}]}',
            ],
            [
                'input' => 'Не смешивай север и юг Гарды в один день. 3 дня юг: Сирмионе, Боргетто, Пескьера.',
                'output' => '{"days":[{"date":"20.05","kind":"program","places":["Сирмионе"],"text":"20.05 — Сирмионе"},{"date":"21.05","kind":"program","places":["Боргетто","Пескьера-дель-Гарда"],"text":"21.05 — Боргетто, Пескьера"},{"date":"22.05","kind":"program","places":["Парк Сигурта","Пескьера-дель-Гарда"],"text":"22.05 — парк Сигурта"}],"rules":["один день = один кластер","без прыжка юг↔север"]}',
            ],
            [
                'input' => 'День transfer: Комо → Рива-дель-Гарда. Коротко.',
                'output' => '{"days":[{"date":"12.09","kind":"transfer","places":["Комо","Рива-дель-Гарда"],"text":"12.09 — переезд Комо → Рива-дель-Гарда"}]}',
            ],
            [
                'input' => '10 дней: Милан хаб, блоки como→garda_north→dolomiti, nature. Только структура дат и мест.',
                'output' => '{"legs":[{"region":"como","days":["01.07","02.07","03.07"]},{"region":"transfer","days":["04.07"]},{"region":"garda_north","days":["05.07","06.07","07.07"]},{"region":"transfer","days":["08.07"]},{"region":"dolomiti","days":["09.07"]},{"region":"departure","days":["10.07"]}],"sample":[{"date":"01.07","text":"01.07 — Милан → Комо"},{"date":"04.07","text":"04.07 — Комо → Рива (transfer)"},{"date":"08.07","text":"08.07 — Рива → Ортизеи"},{"date":"10.07","text":"10.07 — Ортизеи → Милан, вылет"}]}',
            ],
            [
                'input' => 'Запрети Гардаленд и аутлет если nature=true. Ответь JSON правилом.',
                'output' => '{"nature":true,"exclude":["Gardaland","Caneva","outlet","аутлет","аквапарк"],"prefer":["Тенно","Вароне","Ледро","Мальчезине","Селла"]}',
            ],
            [
                'input' => 'Собери 1 день только из карточек: Мальчезине, Монте-Бальдо, Лимоне. Не добавляй Сирмионе.',
                'output' => '{"date":"15.07","kind":"program","places":["Мальчезине","Монте-Бальдо","Лимоне-суль-Гарда"],"text":"15.07 — Мальчезине, Монте-Бальдо, Лимоне","rejected":["Сирмионе"]}',
            ],
            [
                'input' => 'Формат ответа только строки без воды: ДД.ММ — места.',
                'output' => "14.08 — Рива-дель-Гарда, озеро Тенно\n15.08 — Мальчезине, Лимоне-суль-Гарда\n16.08 — выезд к аэропорту",
            ],
            [
                'input' => 'Прилёт вечером 18:40 в Милан — первый день лёгкий, без гор.',
                'output' => '{"days":[{"date":"01.08","kind":"arrival","places":["Милан MXP","Комо"],"text":"01.08 — поздний прилёт, трансфер в Комо, лёгкая прогулка"}]}',
            ],
            [
                'input' => 'Доломиты 2 дня: Селла и Карецца, база Ортизеи.',
                'output' => '{"days":[{"date":"05.09","kind":"program","places":["Перевал Селла","Ортизеи"],"text":"05.09 — перевал Селла, Ортизеи"},{"date":"06.09","kind":"program","places":["Озеро Карецца","Ортизеи"],"text":"06.09 — озеро Карецца"}]}',
            ],
            [
                'input' => 'Юг Гарды без севера: Сирмионе и Боргетто на разные дни.',
                'output' => '{"days":[{"date":"02.05","kind":"program","places":["Сирмионе"],"text":"02.05 — Сирмионе"},{"date":"03.05","kind":"program","places":["Боргетто"],"text":"03.05 — Боргетто"}]}',
            ],
            [
                'input' => 'Не повторяй одни places два дня подряд. Было вчера: Тенно, Вароне. Что сегодня?',
                'output' => '{"date":"08.07","places":["Мальчезине","Лимоне-суль-Гарда"],"text":"08.07 — Мальчезине, Лимоне","avoid_repeat":["Тенно","Вароне"]}',
            ],
            [
                'input' => 'Вылет из Милана утром — вчера ночёвка ближе к хабу, не в дальних Доломитах.',
                'output' => '{"days":[{"date":"09.07","kind":"transfer","places":["Ортизеи","Комо"],"text":"09.07 — сдвиг к Комо/Милану"},{"date":"10.07","kind":"departure","places":["Милан MXP"],"text":"10.07 — вылет"}]}',
            ],
            [
                'input' => 'Семья с детьми: лёгкий день на Гарде без каньонинга и via ferrata.',
                'output' => '{"date":"11.08","kind":"program","places":["Лимоне-суль-Гарда","Рива-дель-Гарда"],"text":"11.08 — Лимоне, спокойная Рива","exclude_activities":["каньонинг","via ferrata","рафтинг"]}',
            ],
            [
                'input' => 'Собери день из одной карточки region=garda, places=[Рива-дель-Гарда, озеро Тенно].',
                'output' => '{"region":"garda","places":["Рива-дель-Гарда","озеро Тенно"],"text":"18.06 — Рива-дель-Гарда, озеро Тенно"}',
            ],
            [
                'input' => 'Кратко объясни гео-блок: нельзя в один день Комо и Сирмионе.',
                'output' => '{"ok":false,"reason":"разные кластеры (~100+ км)","fix":"отдельные дни или transfer","clusters":["como","garda_south"]}',
            ],
            [
                'input' => 'Сделай 6 дней только Гарда north: Рива как база.',
                'output' => '{"base":"Рива-дель-Гарда","days":[{"d":1,"text":"день 1 — Рива"},{"d":2,"text":"день 2 — Тенно, Вароне"},{"d":3,"text":"день 3 — Лимоне, Мальчезине"},{"d":4,"text":"день 4 — Ледро"},{"d":5,"text":"день 5 — Мольвено (опционально)"},{"d":6,"text":"день 6 — Рива, выезд"}]}',
            ],
            [
                'input' => 'Ответ JSON: hotel_night всегда в текущем кластере дня.',
                'output' => '{"rule":"hotel_night == day.cluster","examples":[{"day":"garda_north","hotel":"Рива-дель-Гарда"},{"day":"como","hotel":"Комо"}]}',
            ],
            [
                'input' => 'Пустой каталог нельзя — верни ошибку JSON.',
                'output' => '{"error":"empty_catalog","message":"Нет карточек для сборки"}',
            ],
        ];

        $limit = max(5, min(count($all), $limit));

        return array_slice($all, 0, $limit);
    }

    /**
     * Write Gemini-style JSONL (one object per line) for inspection / Vertex later.
     *
     * @param  list<array{input: string, output: string}>  $examples
     */
    public function writeJsonl(string $path, array $examples): int
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: '.$dir);
        }

        $system = 'Ты — эксперт-архитектор по планированию туристических маршрутов. Отвечай строго в валидном JSON или в формате «ДД.ММ — места». Без воды. Без прыжков между далёкими кластерами в один день.';
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot write: '.$path);
        }

        $n = 0;
        foreach ($examples as $ex) {
            $row = [
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $ex['input']],
                    ['role' => 'model', 'content' => $ex['output']],
                ],
            ];
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                fclose($fh);
                throw new RuntimeException('JSON encode failed');
            }
            fwrite($fh, $line."\n");
            $n++;
        }
        fclose($fh);

        return $n;
    }

    /**
     * Attempt legacy Google AI tunedModels.create (may be unavailable for current keys/models).
     *
     * @param  list<array{input: string, output: string}>  $examples
     * @return array{ok: bool, tuned_model: string|null, operation: string|null, raw: array<string, mixed>, error: string|null}
     */
    public function createTunedModel(
        array $examples,
        string $displayName = 'travel-route-model-v1',
        string $baseModel = 'models/gemini-1.5-flash-001',
        int $epochCount = 4,
    ): array {
        $trainingExamples = [];
        foreach ($examples as $ex) {
            $trainingExamples[] = [
                'text_input' => $ex['input'],
                'output' => $ex['output'],
            ];
        }

        $body = [
            'display_name' => $displayName,
            'base_model' => $baseModel,
            'tuning_task' => [
                'hyperparameters' => [
                    'batch_size' => 4,
                    'learning_rate' => 0.001,
                    'epoch_count' => $epochCount,
                ],
                'training_data' => [
                    'examples' => [
                        'examples' => $trainingExamples,
                    ],
                ],
            ],
        ];

        return $this->keys->withKey(function (string $apiKey) use ($body, $displayName) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/tunedModels?key='.$apiKey
                .'&tunedModelId='.rawurlencode($this->slugId($displayName));

            $response = Http::connectTimeout(10)
                ->timeout(120)
                ->acceptJson()
                ->asJson()
                ->post($url, $body);

            $json = $response->json();
            if (! is_array($json)) {
                $json = ['raw_body' => mb_substr($response->body(), 0, 800)];
            }

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'tuned_model' => null,
                    'operation' => null,
                    'raw' => $json,
                    'error' => 'HTTP '.$response->status().': '.$this->errorMessage($json, $response->body()),
                ];
            }

            $name = (string) ($json['name'] ?? '');
            $tuned = null;
            if (str_starts_with($name, 'tunedModels/')) {
                $tuned = $name;
            } elseif (isset($json['metadata']['tunedModel'])) {
                $tuned = (string) $json['metadata']['tunedModel'];
            }

            return [
                'ok' => true,
                'tuned_model' => $tuned,
                'operation' => $name !== '' ? $name : null,
                'raw' => $json,
                'error' => null,
            ];
        });
    }

    /**
     * @return array{done: bool, state: string, tuned_model: string|null, raw: array<string, mixed>, error: string|null}
     */
    public function getOperation(string $operationName): array
    {
        return $this->keys->withKey(function (string $apiKey) use ($operationName) {
            $op = ltrim($operationName, '/');
            if (! str_contains($op, 'operations/')) {
                // Sometimes create returns tunedModels/x/operations/y
            }
            $url = 'https://generativelanguage.googleapis.com/v1beta/'.$op.'?key='.$apiKey;
            $response = Http::connectTimeout(10)->timeout(60)->acceptJson()->get($url);
            $json = $response->json();
            if (! is_array($json)) {
                $json = ['raw_body' => mb_substr($response->body(), 0, 800)];
            }
            if (! $response->successful()) {
                return [
                    'done' => true,
                    'state' => 'ERROR',
                    'tuned_model' => null,
                    'raw' => $json,
                    'error' => 'HTTP '.$response->status().': '.$this->errorMessage($json, $response->body()),
                ];
            }

            $done = (bool) ($json['done'] ?? false);
            $tuned = null;
            if (isset($json['response']['name']) && str_starts_with((string) $json['response']['name'], 'tunedModels/')) {
                $tuned = (string) $json['response']['name'];
            } elseif (isset($json['metadata']['tunedModel'])) {
                $tuned = (string) $json['metadata']['tunedModel'];
            }

            $state = $done ? 'DONE' : (string) ($json['metadata']['totalSteps'] ?? 'RUNNING');
            if (isset($json['error'])) {
                return [
                    'done' => true,
                    'state' => 'ERROR',
                    'tuned_model' => null,
                    'raw' => $json,
                    'error' => $this->errorMessage($json, ''),
                ];
            }

            return [
                'done' => $done,
                'state' => $state,
                'tuned_model' => $tuned,
                'raw' => $json,
                'error' => null,
            ];
        });
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function deleteFile(string $fileName): array
    {
        return $this->keys->withKey(function (string $apiKey) use ($fileName) {
            $name = str_starts_with($fileName, 'files/') ? $fileName : 'files/'.$fileName;
            $url = 'https://generativelanguage.googleapis.com/v1beta/'.$name.'?key='.$apiKey;
            $response = Http::connectTimeout(10)->timeout(60)->delete($url);
            if ($response->successful() || $response->status() === 404) {
                return ['ok' => true, 'error' => null];
            }

            return [
                'ok' => false,
                'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300),
            ];
        });
    }

    private function slugId(string $displayName): string
    {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $displayName) ?? 'travel-demo');
        $s = trim($s, '-');
        if ($s === '' || ! preg_match('/^[a-z]/', $s)) {
            $s = 't'.$s;
        }

        return mb_substr($s, 0, 40);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function errorMessage(array $json, string $fallbackBody): string
    {
        $msg = $json['error']['message'] ?? $json['error']['status'] ?? null;
        if (is_string($msg) && $msg !== '') {
            return $msg;
        }
        $body = trim($fallbackBody);

        return $body !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 400) : 'unknown error';
    }
}
