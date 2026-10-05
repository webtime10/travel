<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportDocxRoutesCommand extends Command
{
    protected $signature = 'travel:import-docx {--limit=6 : Max docs to import} {--only= : Comma-separated doc indexes, e.g. 3,4,6}';

    protected $description = 'Import DOCX extracts into products (1 doc = 1 product) via Gemini he+ru';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $onlyOpt = trim((string) $this->option('only'));
        $only = $onlyOpt !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $onlyOpt))))
            : range(1, $limit);
        $extractRoot = storage_path('app/docx_extract');

        $he = Language::query()->updateOrCreate(
            ['code' => 'he'],
            [
                'name' => 'עברית',
                'locale' => 'he-IL',
                'directory' => 'he-he',
                'sort_order' => 0,
                'status' => true,
                'is_default' => false,
                'is_active' => true,
            ]
        );

        $ru = Language::query()->firstOrCreate(
            ['code' => 'ru'],
            [
                'name' => 'Русский',
                'locale' => 'ru-RU',
                'directory' => 'ru-ru',
                'sort_order' => 1,
                'status' => true,
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $category = Category::query()->find(1);
        if (! $category) {
            $category = Category::query()->create([
                'parent_id' => null,
                'sort_order' => 0,
                'status' => true,
            ]);
            // Ensure id=1 if empty table renumbered — recreate descriptions after
        }

        foreach ([$he, $ru] as $lang) {
            DB::table('category_descriptions')->updateOrInsert(
                [
                    'category_id' => $category->id,
                    'language_id' => $lang->id,
                ],
                [
                    'name' => $lang->code === 'he' ? 'מסלולים' : 'Маршруты',
                    'description' => $lang->code === 'he' ? 'מסלולי טיול מהמסמכים' : 'Маршруты из документов',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        try {
            Category::rebuildPaths();
        } catch (\Throwable) {
            // ignore
        }

        $apiKey = trim((string) config('services.gemini.key', ''));
        $model = trim((string) config('services.gemini.model', 'gemini-3.6-flash'));
        $timeout = max(60, (int) config('services.gemini.chat_timeout', 900));

        if ($apiKey === '') {
            $this->error('GEMINI_API_KEY is empty');

            return self::FAILURE;
        }

        $imported = 0;
        foreach ($only as $n) {
            if ($n < 1) {
                continue;
            }
            $dir = $extractRoot.DIRECTORY_SEPARATOR.$n;
            $textFile = $dir.DIRECTORY_SEPARATOR.'text.txt';
            if (! is_file($textFile)) {
                $this->warn("Skip {$n}: no extract");
                continue;
            }

            $this->info("Processing {$n} doc...");
            $sourceName = is_file($dir.'/source_name.txt')
                ? trim((string) file_get_contents($dir.'/source_name.txt'))
                : "doc {$n}";
            $text = (string) file_get_contents($textFile);
            $images = json_decode((string) @file_get_contents($dir.'/images.json'), true) ?: [];

            $publicRel = 'uploads/products/'.$n;
            $publicDir = public_path($publicRel);
            if (! is_dir($publicDir)) {
                mkdir($publicDir, 0777, true);
            }

            $publicImages = [];
            foreach ($images as $img) {
                $src = $dir.'/media/'.$img;
                if (! is_file($src)) {
                    continue;
                }
                $destName = $img;
                copy($src, $publicDir.'/'.$destName);
                $publicImages[] = $publicRel.'/'.$destName;
            }

            $material = mb_substr($text, 0, 70000);
            $instruction = <<<'PROMPT'
You are a travel itinerary parser and translator.
Input is Hebrew travel route document text (possibly messy from DOCX).
Return ONLY valid compact JSON (no markdown) with this shape:
{
  "title_he": "string",
  "title_ru": "string",
  "summary_he": "string max 400 chars",
  "summary_ru": "string max 400 chars",
  "cities": ["..."],
  "days": [
    {
      "day": 1,
      "badge_he": "יום 1",
      "badge_ru": "День 1",
      "subtitle_he": "short",
      "subtitle_ru": "short",
      "timeline": [
        { "time": "09:00 or empty", "text_he": "short", "text_ru": "short" }
      ]
    }
  ]
}
Hard limits to keep JSON small:
- max 14 days
- max 6 timeline items per day
- each text_he/text_ru max 220 characters (summarize, keep meaning)
- Russian must translate the Hebrew content
- Do not invent hotels/prices absent from source
PROMPT;

            $jsonText = $this->geminiChat($apiKey, $model, $material, $instruction, $timeout);
            if ($jsonText === null) {
                $this->error("Gemini failed for doc {$n}");
                continue;
            }

            $jsonText = trim($jsonText);
            $jsonText = preg_replace('/^```(?:json)?\s*/i', '', $jsonText) ?? $jsonText;
            $jsonText = preg_replace('/\s*```$/', '', $jsonText) ?? $jsonText;
            file_put_contents($dir.'/gemini_raw.txt', $jsonText);
            $parsed = json_decode($jsonText, true);
            if (! is_array($parsed)) {
                // one compact retry with smaller input
                $this->warn("Invalid JSON for doc {$n}, retry compact...");
                $retryMaterial = mb_substr($text, 0, 35000);
                $jsonText = $this->geminiChat($apiKey, $model, $retryMaterial, $instruction."\nReturn a SMALLER JSON. Prefer fewer timeline rows.", $timeout);
                if ($jsonText) {
                    $jsonText = trim($jsonText);
                    $jsonText = preg_replace('/^```(?:json)?\s*/i', '', $jsonText) ?? $jsonText;
                    $jsonText = preg_replace('/\s*```$/', '', $jsonText) ?? $jsonText;
                    file_put_contents($dir.'/gemini_raw_retry.txt', $jsonText);
                    $parsed = json_decode($jsonText, true);
                }
            }
            if (! is_array($parsed)) {
                $this->error("Invalid JSON for doc {$n}");
                continue;
            }

            $days = $parsed['days'] ?? [];
            if (is_array($days) && $days !== [] && $publicImages !== []) {
                $dayCount = count($days);
                $chunk = max(1, (int) ceil(count($publicImages) / $dayCount));
                $offset = 0;
                foreach ($days as $i => $day) {
                    $slice = array_slice($publicImages, $offset, $chunk);
                    $days[$i]['photos'] = array_values($slice);
                    $offset += $chunk;
                }
                $parsed['days'] = $days;
            }

            $parsed['source_file'] = $sourceName;
            $parsed['photos'] = $publicImages;
            $parsed['doc_index'] = $n;

            $productName = $n.' doc';

            $existing = Product::query()
                ->get()
                ->first(fn (Product $p) => (int) data_get($p->meta, 'doc_index') === $n);

            if ($existing) {
                $product = $existing;
                $product->meta = $parsed;
                $product->sort_order = $n;
                $product->status = true;
                $product->save();
            } else {
                $product = Product::query()->create([
                    'sort_order' => $n,
                    'status' => true,
                    'meta' => $parsed,
                ]);
            }

            ProductDescription::query()->updateOrCreate(
                ['product_id' => $product->id, 'language_id' => $he->id],
                [
                    'name' => $productName,
                    'description' => (string) ($parsed['summary_he'] ?? $parsed['title_he'] ?? $sourceName),
                ]
            );

            ProductDescription::query()->updateOrCreate(
                ['product_id' => $product->id, 'language_id' => $ru->id],
                [
                    'name' => $productName,
                    'description' => (string) ($parsed['summary_ru'] ?? $parsed['title_ru'] ?? $sourceName),
                ]
            );

            // also keep EN name if language exists
            $en = Language::query()->where('code', 'en')->first();
            if ($en) {
                ProductDescription::query()->updateOrCreate(
                    ['product_id' => $product->id, 'language_id' => $en->id],
                    [
                        'name' => $productName,
                        'description' => (string) ($parsed['title_ru'] ?? $parsed['title_he'] ?? $sourceName),
                    ]
                );
            }

            $product->categories()->syncWithoutDetaching([$category->id]);

            file_put_contents($dir.'/structured.json', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info("Saved product #{$product->id} as \"{$productName}\" (images=".count($publicImages).')');
            $imported++;
        }

        $this->info("Done. Imported={$imported}");

        return self::SUCCESS;
    }

    private function geminiChat(string $apiKey, string $model, string $material, string $instruction, int $timeout): ?string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent?key='.$apiKey;

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $instruction."\n\n--- SOURCE TEXT ---\n".$material]],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 65536,
                'responseMimeType' => 'application/json',
            ],
        ];

        if (str_starts_with($model, 'gemini-3')) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            if (! $response->successful()) {
                $this->error('Gemini HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));

                return null;
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            return is_string($text) ? $text : null;
        } catch (\Throwable $e) {
            $this->error('Gemini exception: '.$e->getMessage());

            return null;
        }
    }
}
