<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttributeGroup;
use App\Models\AttributeGroupDescription;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttributeGroupController extends Controller
{
    public function index()
    {
        $pageTitle = 'Attribute Groups';
        $defaultLanguage = Language::admin();
        $groups = AttributeGroup::with(['descriptions', 'attributes.descriptions'])->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.attribute_groups.index', compact('pageTitle', 'groups', 'defaultLanguage'));
    }

    public function create()
    {
        return view('admin.attribute_groups.create', [
            'pageTitle' => 'Attribute Groups',
            'languages' => Language::forAdminForms(),
        ]);
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));
        DB::transaction(function () use ($request, $languages) {
            $item = AttributeGroup::create(['sort_order' => (int) $request->input('sort_order', 0)]);
            $this->syncNames($item, $request, $languages);
        });

        return redirect()->route('admin.attribute-groups.index')->with('success', 'Группа создана');
    }

    public function edit(AttributeGroup $attribute_group)
    {
        $attribute_group->load('descriptions');

        return view('admin.attribute_groups.edit', [
            'pageTitle' => 'Attribute Groups',
            'group' => $attribute_group,
            'languages' => Language::forAdminForms(),
        ]);
    }

    public function update(Request $request, AttributeGroup $attribute_group)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));
        DB::transaction(function () use ($request, $languages, $attribute_group) {
            $attribute_group->update(['sort_order' => (int) $request->input('sort_order', 0)]);
            $this->syncNames($attribute_group, $request, $languages);
        });

        return redirect()->route('admin.attribute-groups.index')->with('success', 'Сохранено');
    }

    public function destroy(AttributeGroup $attribute_group)
    {
        $attribute_group->delete();

        return redirect()->route('admin.attribute-groups.index')->with('success', 'Удалено');
    }

    private function rules($languages): array
    {
        $rules = ['sort_order' => 'nullable|integer|min:0'];
        foreach ($languages as $language) {
            $rules['name_'.$language->code] = $language->is_default ? 'required|string|max:64' : 'nullable|string|max:64';
        }

        return $rules;
    }

    private function syncNames(AttributeGroup $item, Request $request, $languages): void
    {
        foreach ($languages as $language) {
            $name = trim((string) $request->input('name_'.$language->code, ''));
            if ($name === '') {
                $item->descriptions()->where('language_id', $language->id)->delete();

                continue;
            }
            AttributeGroupDescription::updateOrCreate(
                ['attribute_group_id' => $item->id, 'language_id' => $language->id],
                ['name' => $name]
            );
        }
    }
}
