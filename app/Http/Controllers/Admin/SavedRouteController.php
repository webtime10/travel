<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoldenDay;
use App\Models\SavedRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavedRouteController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $query = SavedRoute::query()->orderByDesc('id');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%'.$q.'%')
                    ->orWhere('query_text', 'like', '%'.$q.'%')
                    ->orWhere('notes', 'like', '%'.$q.'%');
            });
        }

        return view('admin.saved_routes.index', [
            'pageTitle' => 'Маршруты',
            'routes' => $query->paginate(40)->withQueryString(),
            'q' => $q,
        ]);
    }

    public function show(SavedRoute $saved_route): View
    {
        $payload = is_array($saved_route->payload) ? $saved_route->payload : [];
        $dayIds = collect($payload['days'] ?? [])
            ->pluck('golden_day_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $goldenDays = $dayIds === []
            ? collect()
            : GoldenDay::query()->with(['stops.location.descriptions', 'labels.descriptions'])->whereIn('id', $dayIds)->get()->keyBy('id');

        return view('admin.saved_routes.show', [
            'pageTitle' => $saved_route->title,
            'item' => $saved_route,
            'goldenDays' => $goldenDays,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'query_text' => ['nullable', 'string', 'max:5000'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'prefs' => ['nullable', 'array'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.day' => ['required', 'integer', 'min:1'],
            'days.*.hub' => ['nullable', 'string', 'max:64'],
            'days.*.golden_day_id' => ['required', 'integer', 'exists:golden_days,id'],
            'days.*.title' => ['nullable', 'string', 'max:500'],
            'days.*.labels' => ['nullable', 'array'],
        ]);

        $days = array_values($data['days']);
        usort($days, fn ($a, $b) => ((int) $a['day']) <=> ((int) $b['day']));

        $route = SavedRoute::query()->create([
            'title' => trim($data['title']),
            'country_code' => strtoupper(trim((string) ($data['country_code'] ?? 'UA'))) ?: 'UA',
            'days_count' => count($days),
            'query_text' => trim((string) ($data['query_text'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'prefs' => $data['prefs'] ?? null,
            'payload' => [
                'days' => array_map(function (array $row) {
                    return [
                        'day' => (int) $row['day'],
                        'hub' => $row['hub'] ?? null,
                        'golden_day_id' => (int) $row['golden_day_id'],
                        'title' => $row['title'] ?? null,
                        'labels' => array_values($row['labels'] ?? []),
                    ];
                }, $days),
            ],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $route->id,
                'title' => $route->title,
                'url' => route('admin.saved-routes.show', $route),
            ]);
        }

        return redirect()
            ->route('admin.saved-routes.show', $route)
            ->with('success', 'Маршрут сохранён');
    }

    public function update(Request $request, SavedRoute $saved_route): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $saved_route->forceFill([
            'title' => trim($data['title']),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        return redirect()
            ->route('admin.saved-routes.show', $saved_route)
            ->with('success', 'Название сохранено');
    }

    public function destroy(SavedRoute $saved_route): RedirectResponse
    {
        $saved_route->delete();

        return redirect()
            ->route('admin.saved-routes.index')
            ->with('success', 'Маршрут удалён');
    }
}
