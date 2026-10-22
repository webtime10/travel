<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeDescription;
use App\Models\AttributeGroup;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttributeController extends Controller
{
    public function index()
    {
        $pageTitle = 'Attributes';
        $defaultLanguage = Language::admin();
        $attributes = Attribute::with(['descriptions', 'group.descriptions'])->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.attributes.index', compact('pageTitle', 'attributes', 'defaultLanguage'));
    }

    public function create()
    {
        return view('admin.attributes.create', $this->formData());
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));
        DB::transaction(function () use ($request, $languages) {
            $item = Attribute::create([
                'attribute_group_id' => (int) $request->input('attribute_group_id'),
                'sort_order' => (int) $request->input('sort_order', 0),
            ]);
            $this->syncNames($item, $request, $languages);
        });

        return redirect()->route('admin.attributes.index')->with('success', 'Атрибут создан');
    }

    public function edit(Attribute $attribute)
    {
        $attribute->load('descriptions');

        return view('admin.attributes.edit', $this->formData() + ['attribute' => $attribute]);
    }

    public function update(Request $request, Attribute $attribute)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));
        DB::transaction(function () use ($request, $languages, $attribute) {
            $attribute->update([
                'attribute_group_id' => (int) $request->input('attribute_group_id'),
                'sort_order' => (int) $request->input('sort_order', 0),
            ]);
            $this->syncNames($attribute, $request, $languages);
        });

        return redirect()->route('admin.attributes.index')->with('success', 'Сохранено');
    }

    public function destroy(Attribute $attribute)
    {
        $attribute->delete();

        return redirect()->route('admin.attributes.index')->with('success', 'Удалено');
    }

    private function formData(): array
    {
        $defaultLanguage = Language::admin();

        return [
            'pageTitle' => 'Attributes',
            'languages' => Language::forAdminForms(),
            'groups' => AttributeGroup::with('descriptions')->orderBy('sort_order')->get()
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->nameFor($defaultLanguage)]),
        ];
    }

    private function rules($languages): array
    {
        $rules = [
            'attribute_group_id' => 'required|exists:attribute_groups,id',
            'sort_order' => 'nullable|integer|min:0',
        ];
        foreach ($languages as $language) {
            $rules['name_'.$language->code] = $language->is_default ? 'required|string|max:64' : 'nullable|string|max:64';
        }

        return $rules;
    }

    private function syncNames(Attribute $item, Request $request, $languages): void
    {
        foreach ($languages as $language) {
            $name = trim((string) $request->input('name_'.$language->code, ''));
            if ($name === '') {
                $item->descriptions()->where('language_id', $language->id)->delete();

                continue;
            }
            AttributeDescription::updateOrCreate(
                ['attribute_id' => $item->id, 'language_id' => $language->id],
                ['name' => $name]
            );
        }
    }
}
