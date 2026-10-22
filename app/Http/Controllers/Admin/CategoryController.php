<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IndexCategoryProductJob;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use App\Services\CategoryVectorPipelineStore;
use App\Services\RagDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        $pageTitle = 'Categories';
        $defaultLanguage = Language::admin();
        $heLanguage = Language::query()->where('code', 'he')->where('is_active', true)->first();
        $categories = Category::with(['parent.descriptions', 'descriptions'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return view('admin.categories.index', compact(
            'categories',
            'pageTitle',
            'defaultLanguage',
            'heLanguage',
        ));
    }

    public function create()
    {
        $pageTitle = 'Categories';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $parentOptions = Category::treeForParentSelect($defaultLanguage, []);

        return view('admin.categories.create', compact('pageTitle', 'languages', 'defaultLanguage', 'parentOptions'));
    }

    public function store(Request $request)
    {
        $request->merge([
            'parent_id' => $request->filled('parent_id') ? (int) $request->parent_id : null,
        ]);

        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')->with('info', 'Сначала добавьте язык');
        }

        $rules = [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];

        foreach ($languages as $language) {
            $c = $language->code;
            $rules['name_'.$c] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['description_'.$c] = 'nullable|string';
        }

        $request->validate($rules);

        DB::transaction(function () use ($request, $languages) {
            $category = Category::create([
                'parent_id' => $request->input('parent_id'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $name = trim((string) $request->input('name_'.$language->code, ''));
                if ($name === '') {
                    continue;
                }
                CategoryDescription::create([
                    'category_id' => $category->id,
                    'language_id' => $language->id,
                    'name' => $name,
                    'description' => $request->input('description_'.$language->code),
                ]);
            }

            Category::rebuildPaths();
        });

        return redirect()->route('admin.categories.index')->with('success', 'Категория создана');
    }

    public function edit(Category $category, CategoryVectorPipelineStore $vectorPipeline, RagDatabaseService $ragDb)
    {
        $pageTitle = 'Categories';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $exclude = array_merge([$category->id], $category->descendantIdList());
        $parentOptions = Category::treeForParentSelect($defaultLanguage, $exclude);
        $category->load('descriptions');
        $productCount = $category->products()->count();
        $vectorStatus = $vectorPipeline->status($category->id);
        $pendingVectorCount = count($this->pendingVectorProductIds($category, $ragDb));
        $indexedVectorCount = max(0, $productCount - $pendingVectorCount);

        return view('admin.categories.edit', compact(
            'pageTitle',
            'category',
            'languages',
            'defaultLanguage',
            'parentOptions',
            'productCount',
            'vectorStatus',
            'pendingVectorCount',
            'indexedVectorCount',
        ));
    }

    public function update(Request $request, Category $category)
    {
        $request->merge([
            'parent_id' => $request->filled('parent_id') ? (int) $request->parent_id : null,
        ]);

        $languages = Language::forAdminForms();
        $exclude = array_merge([$category->id], $category->descendantIdList());

        $rules = [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id', function ($attr, $value, $fail) use ($exclude) {
                if ($value && in_array((int) $value, array_map('intval', $exclude), true)) {
                    $fail('Нельзя выбрать эту категорию родителем.');
                }
            }],
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];

        foreach ($languages as $language) {
            $c = $language->code;
            $rules['name_'.$c] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['description_'.$c] = 'nullable|string';
        }

        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $category) {
            $category->update([
                'parent_id' => $request->input('parent_id'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $name = trim((string) $request->input('name_'.$language->code, ''));
                $desc = $category->descriptions()->where('language_id', $language->id)->first();

                if ($name === '') {
                    if ($desc) {
                        $desc->delete();
                    }

                    continue;
                }

                $payload = [
                    'name' => $name,
                    'description' => $request->input('description_'.$language->code),
                ];

                if ($desc) {
                    $desc->update($payload);
                } else {
                    CategoryDescription::create(array_merge($payload, [
                        'category_id' => $category->id,
                        'language_id' => $language->id,
                    ]));
                }
            }

            Category::rebuildPaths();
        });

        return redirect()->route('admin.categories.index')->with('success', 'Категория обновлена');
    }

    public function destroy(Category $category)
    {
        $category->delete();
        Category::rebuildPaths();

        return redirect()->route('admin.categories.index')->with('success', 'Категория удалена');
    }

    public function bulkDelete(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('selected', [])));
        if ($ids) {
            Category::whereIn('id', $ids)->delete();
            Category::rebuildPaths();
        }

        return redirect()->route('admin.categories.index')->with('success', 'Выбранные категории удалены');
    }

    public function vectorStatus(Category $category, CategoryVectorPipelineStore $pipeline, RagDatabaseService $ragDb): JsonResponse
    {
        $status = $pipeline->status($category->id);
        $all = $category->products()->count();
        $pending = count($this->pendingVectorProductIds($category, $ragDb));

        return response()->json(array_merge($status, [
            'product_total' => $all,
            'indexed' => max(0, $all - $pending),
            'pending' => $pending,
        ]));
    }

    public function vectorStart(
        Category $category,
        CategoryVectorPipelineStore $pipeline,
        RagDatabaseService $ragDb,
    ): JsonResponse {
        $ids = $category->products()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('products.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return $this->launchVectorPipeline($category, $pipeline, $ragDb, $ids, false);
    }

    public function vectorResume(
        Category $category,
        CategoryVectorPipelineStore $pipeline,
        RagDatabaseService $ragDb,
    ): JsonResponse {
        $ids = $this->pendingVectorProductIds($category, $ragDb);

        return $this->launchVectorPipeline($category, $pipeline, $ragDb, $ids, true);
    }

    public function vectorCancel(Category $category, CategoryVectorPipelineStore $pipeline): JsonResponse
    {
        $pipeline->cancel($category->id);

        return response()->json(['ok' => true, 'message' => 'Cancelled']);
    }

    /**
     * Products in category not yet indexed in PostgreSQL RAG.
     *
     * @return list<int>
     */
    private function pendingVectorProductIds(Category $category, RagDatabaseService $ragDb): array
    {
        $ids = $category->products()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('products.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $status = $ragDb->connectionStatus();
        if (! ($status['ok'] ?? false) || ! ($status['schema_ready'] ?? false)) {
            return $ids;
        }

        try {
            $indexed = DB::connection(RagDatabaseService::CONNECTION)
                ->table('rag_documents')
                ->whereIn('product_id', $ids)
                ->where('chunk_count', '>', 0)
                ->whereNotNull('indexed_at')
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            return $ids;
        }

        $indexedMap = array_fill_keys($indexed, true);

        return array_values(array_filter($ids, fn (int $id) => ! isset($indexedMap[$id])));
    }

    /**
     * @param  list<int>  $ids
     */
    private function launchVectorPipeline(
        Category $category,
        CategoryVectorPipelineStore $pipeline,
        RagDatabaseService $ragDb,
        array $ids,
        bool $resume,
    ): JsonResponse {
        if ($pipeline->running($category->id)) {
            return response()->json(['ok' => false, 'message' => 'Vector indexing already running'], 409);
        }

        $ragStatus = $ragDb->connectionStatus();
        if (! ($ragStatus['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'PostgreSQL RAG not connected'], 503);
        }
        if (! ($ragStatus['schema_ready'] ?? false)) {
            $ragDb->migrateSchema();
        }

        if ($ids === []) {
            return response()->json([
                'ok' => true,
                'started' => 0,
                'message' => $resume
                    ? 'Nothing pending — all products in this category are already indexed'
                    : 'No products in this category',
            ]);
        }

        $pipeline->begin($category->id, $ids);
        $first = $pipeline->shiftQueue($category->id);
        if ($first !== null) {
            IndexCategoryProductJob::dispatch($category->id, $first);
        }

        $msg = $resume
            ? 'Resume from previous: '.count($ids).' remaining products. Worker: php artisan queue:work'
            : 'Full reindex started ('.count($ids).' products). Worker: php artisan queue:work';

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'resume' => $resume,
            'message' => $msg,
        ]);
    }
}
