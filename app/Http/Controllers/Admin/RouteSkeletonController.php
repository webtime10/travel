<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RouteSkeleton;
use App\Services\RouteSkeletonRuTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RouteSkeletonController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $days = $request->input('days');
        $query = RouteSkeleton::query()->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%'.$q.'%')
                    ->orWhere('search_blob', 'like', '%'.$q.'%')
                    ->orWhere('arrival_hub', 'like', '%'.$q.'%')
                    ->orWhere('departure_hub', 'like', '%'.$q.'%');
                if (ctype_digit($q)) {
                    $w->orWhere('source_route_id', (int) $q)
                        ->orWhere('id', (int) $q);
                }
            });
        }
        if ($days !== null && $days !== '' && is_numeric($days)) {
            $query->where('days_count', (int) $days);
        }

        $items = $query->paginate(30)->withQueryString();

        return view('admin.route_skeletons.index', [
            'pageTitle' => 'Short routes',
            'items' => $items,
            'q' => $q,
            'days' => $days,
            'total' => RouteSkeleton::count(),
        ]);
    }

    public function show(RouteSkeleton $route_skeleton): View
    {
        $route_skeleton->load('days');
        $unitsTotal = 0;
        $unitsDone = is_array($route_skeleton->ru_units) ? count($route_skeleton->ru_units) : 0;
        try {
            $unitsTotal = count(app(RouteSkeletonRuTranslator::class)->units($route_skeleton));
        } catch (\Throwable) {
        }

        return view('admin.route_skeletons.show', [
            'pageTitle' => 'Short route #'.$route_skeleton->id,
            'item' => $route_skeleton,
            'unitsTotal' => $unitsTotal,
            'unitsDone' => $unitsDone,
            'lang' => request()->input('lang', $route_skeleton->hasRussian() ? 'ru' : 'he'),
        ]);
    }

    public function translateRu(Request $request, RouteSkeleton $route_skeleton, RouteSkeletonRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $limit = max(1, min(40, (int) $request->input('limit', 20)));

        try {
            $result = $translator->translateBatch($route_skeleton, $limit);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = [
            'ok' => empty($result['warning']),
            'translated' => $result['translated'],
            'remaining' => $result['remaining'],
            'total' => $result['total'],
            'already' => $result['already'],
            'done' => $result['done'],
            'translated_ru' => $result['total'] - $result['remaining'],
        ];
        if (! empty($result['warning'])) {
            $payload['message'] = $result['warning'];
        }

        return response()->json($payload, empty($result['warning']) ? 200 : 422);
    }
}
