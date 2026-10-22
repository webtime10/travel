<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\ManufacturerDescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManufacturerController extends Controller
{
    public function index()
    {
        $pageTitle = 'Manufacturers';
        $defaultLanguage = Language::admin();
        $manufacturers = Manufacturer::with('descriptions')->withCount('locations')->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.manufacturers.index', compact('pageTitle', 'manufacturers', 'defaultLanguage'));
    }

    public function create()
    {
        return view('admin.manufacturers.create', [
            'pageTitle' => 'Manufacturers',
            'languages' => Language::forAdminForms(),
        ]);
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));
        DB::transaction(function () use ($request, $languages) {
            $item = Manufacturer::create([
                'code' => strtoupper(trim((string) $request->input('code'))) ?: null,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);
            $this->syncNames($item, $request, $languages);
        });

        return redirect()->route('admin.manufacturers.index')->with('success', 'Производитель создан');
    }

    public function edit(Manufacturer $manufacturer)
    {
        $manufacturer->load('descriptions');

        return view('admin.manufacturers.edit', [
            'pageTitle' => 'Manufacturers',
            'manufacturer' => $manufacturer,
            'languages' => Language::forAdminForms(),
        ]);
    }

    public function update(Request $request, Manufacturer $manufacturer)
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages, $manufacturer->id));
        DB::transaction(function () use ($request, $languages, $manufacturer) {
            $manufacturer->update([
                'code' => strtoupper(trim((string) $request->input('code'))) ?: null,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);
            $this->syncNames($manufacturer, $request, $languages);
        });

        return redirect()->route('admin.manufacturers.index')->with('success', 'Сохранено');
    }

    public function destroy(Manufacturer $manufacturer)
    {
        $manufacturer->delete();

        return redirect()->route('admin.manufacturers.index')->with('success', 'Удалено');
    }

    private function rules($languages, ?int $ignore = null): array
    {
        $rules = [
            'code' => ['nullable', 'string', 'max:8', $ignore ? 'unique:manufacturers,code,'.$ignore : 'unique:manufacturers,code'],
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];
        foreach ($languages as $language) {
            $rules['name_'.$language->code] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
        }

        return $rules;
    }

    private function syncNames(Manufacturer $item, Request $request, $languages): void
    {
        foreach ($languages as $language) {
            $name = trim((string) $request->input('name_'.$language->code, ''));
            if ($name === '') {
                $item->descriptions()->where('language_id', $language->id)->delete();

                continue;
            }
            ManufacturerDescription::updateOrCreate(
                ['manufacturer_id' => $item->id, 'language_id' => $language->id],
                ['name' => $name]
            );
        }
    }
}
