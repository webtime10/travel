<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        $pageTitle = 'Categories';
        $defaultLanguage = Language::getDefault();
        $categories = Category::with(['parent.descriptions', 'descriptions'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return view('admin.categories.index', compact('categories', 'pageTitle', 'defaultLanguage'));
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

    public function edit(Category $category)
    {
        $pageTitle = 'Categories';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $exclude = array_merge([$category->id], $category->descendantIdList());
        $parentOptions = Category::treeForParentSelect($defaultLanguage, $exclude);
        $category->load('descriptions');

        return view('admin.categories.edit', compact('pageTitle', 'category', 'languages', 'defaultLanguage', 'parentOptions'));
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
}
