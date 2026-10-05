<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function index()
    {
        $pageTitle = 'Languages';
        $languages = Language::orderBy('sort_order')->orderBy('is_default', 'desc')->orderBy('id')->paginate(20);

        return view('admin.languages.index', compact('languages', 'pageTitle'));
    }

    public function create()
    {
        return view('admin.languages.create', ['pageTitle' => 'Languages']);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:10|unique:languages,code',
            'name' => 'required|string|max:100',
            'locale' => 'required|string|max:255',
            'directory' => 'nullable|string|max:32',
            'sort_order' => 'nullable|integer|min:0',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->boolean('is_default')) {
            Language::where('is_default', true)->update(['is_default' => false]);
        }

        $isActive = $request->boolean('is_active');

        Language::create([
            'code' => $request->code,
            'name' => $request->name,
            'locale' => $request->locale,
            'directory' => $request->directory,
            'sort_order' => (int) $request->input('sort_order', 0),
            'status' => $isActive,
            'is_default' => $request->boolean('is_default'),
            'is_active' => $isActive,
        ]);

        return redirect()->route('admin.languages.index')->with('success', 'Язык создан');
    }

    public function edit(string $id)
    {
        $language = Language::findOrFail($id);

        return view('admin.languages.edit', [
            'pageTitle' => 'Languages',
            'language' => $language,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'code' => 'required|string|max:10|unique:languages,code,'.$id,
            'name' => 'required|string|max:100',
            'locale' => 'required|string|max:255',
            'directory' => 'nullable|string|max:32',
            'sort_order' => 'nullable|integer|min:0',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $language = Language::findOrFail($id);

        if ($request->boolean('is_default') && ! $language->is_default) {
            Language::where('is_default', true)->where('id', '!=', $id)->update(['is_default' => false]);
        }

        $isActive = $request->boolean('is_active');

        $language->update([
            'code' => $request->code,
            'name' => $request->name,
            'locale' => $request->locale,
            'directory' => $request->directory,
            'sort_order' => (int) $request->input('sort_order', 0),
            'status' => $isActive,
            'is_default' => $request->boolean('is_default'),
            'is_active' => $isActive,
        ]);

        return redirect()->route('admin.languages.index')->with('success', 'Язык обновлён');
    }

    public function destroy(string $id)
    {
        $language = Language::findOrFail($id);

        if ($language->is_default) {
            return redirect()->route('admin.languages.index')->with('error', 'Нельзя удалить язык по умолчанию');
        }

        if (Language::where('is_active', true)->count() <= 1 && $language->is_active) {
            return redirect()->route('admin.languages.index')->with('error', 'Нельзя удалить последний активный язык');
        }

        $language->delete();

        return redirect()->route('admin.languages.index')->with('success', 'Язык удалён');
    }
}
