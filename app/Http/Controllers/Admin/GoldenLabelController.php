<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoldenLabel;
use App\Models\GoldenLabelDescription;
use App\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GoldenLabelController extends Controller
{
    public function index(): View
    {
        $defaultLanguage = Language::admin();
        $labels = GoldenLabel::with(['descriptions.language'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.golden_labels.index', [
            'pageTitle' => 'Golden labels',
            'labels' => $labels,
            'defaultLanguage' => $defaultLanguage,
        ]);
    }

    public function create(): View
    {
        return view('admin.golden_labels.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages));

        DB::transaction(function () use ($request, $languages) {
            $defaultName = '';
            foreach ($languages as $language) {
                if ($language->is_default) {
                    $defaultName = trim((string) $request->input('name_'.$language->code, ''));
                    break;
                }
            }
            $slug = trim((string) $request->input('slug', ''));
            if ($slug === '') {
                $slug = GoldenLabel::makeSlug($defaultName !== '' ? $defaultName : 'label');
            }
            $base = $slug;
            $n = 1;
            while (GoldenLabel::query()->where('slug', $slug)->exists()) {
                $slug = mb_substr($base.'-'.$n, 0, 64);
                $n++;
            }

            $item = GoldenLabel::create([
                'slug' => $slug,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status', true),
            ]);
            $this->syncNames($item, $request, $languages);
        });

        return redirect()->route('admin.golden-labels.index')->with('success', 'Метка создана');
    }

    public function edit(GoldenLabel $golden_label): View
    {
        $golden_label->load('descriptions');

        return view('admin.golden_labels.edit', $this->formData() + ['label' => $golden_label]);
    }

    public function update(Request $request, GoldenLabel $golden_label): RedirectResponse
    {
        $languages = Language::forAdminForms();
        $request->validate($this->rules($languages, $golden_label->id));

        DB::transaction(function () use ($request, $languages, $golden_label) {
            $slug = trim((string) $request->input('slug', ''));
            if ($slug === '') {
                $slug = $golden_label->slug ?: GoldenLabel::makeSlug('label');
            }
            $golden_label->update([
                'slug' => mb_substr($slug, 0, 64),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status', true),
            ]);
            $this->syncNames($golden_label, $request, $languages);
        });

        return redirect()->route('admin.golden-labels.index')->with('success', 'Сохранено');
    }

    public function destroy(GoldenLabel $golden_label): RedirectResponse
    {
        $golden_label->delete();

        return redirect()->route('admin.golden-labels.index')->with('success', 'Удалено');
    }

    private function formData(): array
    {
        return [
            'pageTitle' => 'Golden labels',
            'languages' => Language::forAdminForms(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Language>  $languages
     * @return array<string, mixed>
     */
    private function rules($languages, ?int $ignoreId = null): array
    {
        $slugRule = 'nullable|string|max:64|unique:golden_labels,slug';
        if ($ignoreId) {
            $slugRule .= ','.$ignoreId;
        }
        $rules = [
            'slug' => $slugRule,
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];
        foreach ($languages as $language) {
            $rules['name_'.$language->code] = $language->is_default
                ? 'required|string|max:128'
                : 'nullable|string|max:128';
        }

        return $rules;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Language>  $languages
     */
    private function syncNames(GoldenLabel $item, Request $request, $languages): void
    {
        foreach ($languages as $language) {
            $name = trim((string) $request->input('name_'.$language->code, ''));
            if ($name === '') {
                GoldenLabelDescription::query()
                    ->where('golden_label_id', $item->id)
                    ->where('language_id', $language->id)
                    ->delete();

                continue;
            }
            GoldenLabelDescription::query()->updateOrCreate(
                [
                    'golden_label_id' => $item->id,
                    'language_id' => $language->id,
                ],
                ['name' => mb_substr($name, 0, 128)]
            );
        }
    }
}
