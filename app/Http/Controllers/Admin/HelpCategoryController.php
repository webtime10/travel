<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HelpCategoryController extends Controller
{
    public function index()
    {
        $pageTitle = 'Help';
        $categories = HelpCategory::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(50);

        return view('admin.help-categories.index', compact('categories', 'pageTitle'));
    }

    public function create()
    {
        return view('admin.help-categories.create', [
            'pageTitle' => 'Help',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        HelpCategory::create([
            'name' => trim($data['name']),
            'content' => $data['content'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'status' => $request->boolean('status', true),
        ]);

        return redirect()
            ->route('admin.help-categories.index')
            ->with('success', 'Help created');
    }

    public function edit(HelpCategory $help_category)
    {
        return view('admin.help-categories.edit', [
            'pageTitle' => 'Help',
            'category' => $help_category,
        ]);
    }

    public function update(Request $request, HelpCategory $help_category)
    {
        $data = $this->validated($request);

        $help_category->update([
            'name' => trim($data['name']),
            'content' => $data['content'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'status' => $request->boolean('status'),
        ]);

        return redirect()
            ->route('admin.help-categories.index')
            ->with('success', 'Help updated');
    }

    public function destroy(HelpCategory $help_category)
    {
        $help_category->delete();

        return redirect()
            ->route('admin.help-categories.index')
            ->with('success', 'Help deleted');
    }

    public function bulkDelete(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('selected', [])));
        if ($ids !== []) {
            HelpCategory::query()->whereIn('id', $ids)->delete();
        }

        return redirect()
            ->route('admin.help-categories.index')
            ->with('success', 'Selected help items deleted');
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        $file = $request->file('file');
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('image', $name, 'public');

        return response()->json([
            'ok' => true,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * @return array{name: string, content?: string|null, sort_order?: int|null}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ]);
    }
}
