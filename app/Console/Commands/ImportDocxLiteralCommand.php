<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Services\DocxSequentialParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportDocxLiteralCommand extends Command
{
    protected $signature = 'travel:import-docx-literal {--limit=6}';

    protected $description = 'Import DOCX files 1:1 into product meta JSON blocks (document order)';

    public function handle(DocxSequentialParser $parser): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $docDir = base_path('doc');
        $files = glob($docDir.'/*.docx') ?: [];
        usort($files, fn ($a, $b) => strcasecmp(basename($a), basename($b)));

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
        $en = Language::query()->where('code', 'en')->first();

        $category = Category::query()->find(1);
        if (! $category) {
            $category = Category::query()->create([
                'parent_id' => null,
                'sort_order' => 0,
                'status' => true,
            ]);
        }
        foreach ([$he, $ru] as $lang) {
            DB::table('category_descriptions')->updateOrInsert(
                ['category_id' => $category->id, 'language_id' => $lang->id],
                [
                    'name' => $lang->code === 'he' ? 'מסלולים' : 'Маршруты',
                    'description' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
        try {
            Category::rebuildPaths();
        } catch (\Throwable) {
        }

        $n = 0;
        $imported = 0;
        foreach ($files as $file) {
            if (filesize($file) < 1000) {
                $this->warn('Skip empty: '.basename($file));
                continue;
            }
            $n++;
            if ($n > $limit) {
                break;
            }

            $this->info("Parsing {$n} doc: ".basename($file));
            $publicRel = 'uploads/products/'.$n;
            $destDir = public_path($publicRel);

            try {
                $parsed = $parser->parse($file, $destDir, $publicRel);
            } catch (\Throwable $e) {
                $this->error('Parse failed: '.$e->getMessage());
                continue;
            }

            $blocks = $parsed['blocks'];

            $existing = Product::query()->get()->first(
                fn (Product $p) => (int) data_get($p->meta, 'doc_index') === $n
            );

            // Keep previous RU translations when Hebrew text still matches
            if ($existing && is_array($existing->meta)) {
                $ruMap = [];
                foreach ($existing->meta['blocks'] ?? [] as $old) {
                    if (! is_array($old)) {
                        continue;
                    }
                    $heText = trim((string) ($old['text'] ?? ''));
                    $ruText = trim((string) ($old['text_ru'] ?? ''));
                    if ($heText !== '' && $ruText !== '') {
                        $ruMap[$heText] = $ruText;
                    }
                }
                if ($ruMap !== []) {
                    foreach ($blocks as $i => $b) {
                        if (! is_array($b)) {
                            continue;
                        }
                        $heText = trim((string) ($b['text'] ?? ''));
                        if ($heText !== '' && isset($ruMap[$heText])) {
                            $blocks[$i]['text_ru'] = $ruMap[$heText];
                        }
                    }
                }
            }

            $linkCount = count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'link'));
            $meta = [
                'format' => 'docx_sequential_v1',
                'doc_index' => $n,
                'source_file' => $parsed['source'],
                'blocks' => $blocks,
                'images' => $parsed['images'],
                'stats' => [
                    'blocks' => count($blocks),
                    'images' => count($parsed['images']),
                    'text_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') !== 'image')),
                    'image_blocks' => count(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'image')),
                    'link_blocks' => $linkCount,
                    'translated_ru' => count(array_filter($blocks, fn ($b) => is_array($b) && trim((string) ($b['text_ru'] ?? '')) !== '')),
                ],
            ];

            $productName = $n.' doc';
            $preview = '';
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') !== 'image' && ! empty($b['text'])) {
                    $preview = mb_substr((string) $b['text'], 0, 300);
                    break;
                }
            }

            if ($existing) {
                $product = $existing;
                $product->fill([
                    'sort_order' => $n,
                    'status' => true,
                    'meta' => $meta,
                ])->save();
            } else {
                $product = Product::query()->create([
                    'sort_order' => $n,
                    'status' => true,
                    'meta' => $meta,
                ]);
            }

            foreach ([$he, $ru, $en] as $lang) {
                if (! $lang) {
                    continue;
                }
                ProductDescription::query()->updateOrCreate(
                    ['product_id' => $product->id, 'language_id' => $lang->id],
                    [
                        'name' => $productName,
                        'description' => $preview !== '' ? $preview : $parsed['source'],
                    ]
                );
            }

            $product->categories()->sync([$category->id]);

            // keep raw json copy for inspection
            $extractDir = storage_path('app/docx_literal/'.$n);
            if (! is_dir($extractDir)) {
                mkdir($extractDir, 0777, true);
            }
            file_put_contents(
                $extractDir.'/blocks.json',
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            $this->info(sprintf(
                'Saved #%d "%s" blocks=%d images=%d links=%d',
                $product->id,
                $productName,
                $meta['stats']['blocks'],
                $meta['stats']['images'],
                $meta['stats']['link_blocks']
            ));
            $imported++;
        }

        $this->info("Done. Imported={$imported}");

        return self::SUCCESS;
    }
}
