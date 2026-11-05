<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReverseEngineerRouteSkeletonJob;
use App\Models\AppSetting;
use App\Models\RouteSkeleton;
use App\Services\GeminiKeyPool;
use App\Services\ReverseEngineeringPipelineStore;
use App\Services\RouteSkeletonFineTuneExporter;
use App\Services\RouteSkeletonReverseEngineer;
use App\Services\RouteSkeletonRuTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $ftCounts = app(RouteSkeletonFineTuneExporter::class)->counts();
        $reverseStored = RouteSkeleton::query()->whereNotNull('reverse_principle')->count();

        return view('admin.route_skeletons.index', [
            'pageTitle' => 'Short routes',
            'items' => $items,
            'q' => $q,
            'days' => $days,
            'total' => RouteSkeleton::count(),
            'reversePending' => $this->reversePendingCount(),
            'reverseStored' => $reverseStored,
            'reversePrompt' => RouteSkeletonReverseEngineer::resolvePrompt(),
            'fineTuneReady' => $ftCounts['ready'],
            'fineTuneSkipped' => $ftCounts['skipped'],
        ]);
    }

    /**
     * Wipe reverse_principle only (routes + location tags untouched).
     */
    public function clearReversePrinciples(): JsonResponse
    {
        ReverseEngineeringPipelineStore::for('route_skeletons')->cancel();

        $cleared = RouteSkeleton::query()
            ->whereNotNull('reverse_principle')
            ->update(['reverse_principle' => null]);

        return response()->json([
            'ok' => true,
            'cleared' => (int) $cleared,
            'message' => 'Сняты старые логические разборы (RE): '.(int) $cleared.'. Маршруты и метки локаций не тронуты.',
        ]);
    }

    /**
     * Download Gemini Fine-Tuning JSONL: user=query, model=thoughts+[ROUTE].
     */
    public function exportFineTuneJsonl(RouteSkeletonFineTuneExporter $exporter): StreamedResponse|Response
    {
        $counts = $exporter->counts();
        if ($counts['ready'] < 1) {
            return response('Нет готовых RE-разборов для FineTunes (нужны question + дни с location_ids).', 422)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $filename = 'route_skeletons_finetune_'.now()->format('Ymd_His').'.jsonl';

        return response()->streamDownload(function () use ($exporter) {
            foreach ($exporter->jsonlLines() as $line) {
                echo $line;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $filename, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
        ]);
    }

    public function saveReversePrompt(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:100000',
        ]);

        $prompt = trim((string) $request->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'message' => 'Промпт пустой'], 422);
        }

        AppSetting::putValue(RouteSkeletonReverseEngineer::PROMPT_SETTING_KEY, $prompt);

        return response()->json(['ok' => true, 'message' => 'Промпт сохранён']);
    }

    public function resetReversePrompt(): JsonResponse
    {
        $fresh = RouteSkeletonReverseEngineer::defaultPrompt();
        AppSetting::putValue(RouteSkeletonReverseEngineer::PROMPT_SETTING_KEY, $fresh);

        return response()->json(['ok' => true, 'prompt' => $fresh, 'message' => 'Вернули промпт по умолчанию (стиль директора)']);
    }

    public function reversePipelineStatus(): JsonResponse
    {
        $pipeline = ReverseEngineeringPipelineStore::for('route_skeletons');

        return response()->json($pipeline->status() + [
            'not_tagged' => $this->reversePendingCount(),
        ]);
    }

    public function reversePipelineStart(Request $request, GeminiKeyPool $keys): JsonResponse
    {
        $pipeline = ReverseEngineeringPipelineStore::for('route_skeletons');
        if ($pipeline->running()) {
            return response()->json(['ok' => false, 'message' => 'Разбор short routes уже идёт'], 409);
        }

        // Re-queue old v1 results (principle-only) for full v2 pass.
        $this->resetIncompleteReversePrinciples();

        $ids = $this->reversePendingIds();
        $limit = $request->input('limit');
        if ($limit !== null && $limit !== '' && is_numeric($limit)) {
            $n = max(1, (int) $limit);
            $ids = array_slice($ids, 0, $n);
        }

        if ($ids === []) {
            return response()->json(['ok' => true, 'started' => 0, 'message' => 'Все short routes уже с разбором v2']);
        }

        // Free key first in .env list, then paid.
        $keys->resetToFirst();

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            ReverseEngineerRouteSkeletonJob::dispatch($first);
        }

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'message' => 'Разбор short routes v2 (вопрос+логика+дни): '.count($ids).' (нужен queue:work; ключи с первого в .env)',
        ]);
    }

    public function reversePipelineCancel(): JsonResponse
    {
        ReverseEngineeringPipelineStore::for('route_skeletons')->cancel();

        return response()->json(['ok' => true, 'message' => 'Остановлено']);
    }

    public function reverseOne(RouteSkeleton $route_skeleton, RouteSkeletonReverseEngineer $engineer): JsonResponse
    {
        @set_time_limit(600);
        try {
            $result = $engineer->extract($route_skeleton->load('days'));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result);
    }

    public function destroy(RouteSkeleton $route_skeleton): RedirectResponse
    {
        $id = $route_skeleton->id;
        $title = $route_skeleton->displayTitle(true) ?: ('#'.$id);
        $route_skeleton->delete(); // days cascade

        return redirect()
            ->route('admin.route-skeletons.index')
            ->with('success', 'Short route удалён: #'.$id.' · '.$title);
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

    private function reversePendingCount(): int
    {
        return count($this->reversePendingIds());
    }

    /**
     * @return list<int>
     */
    private function reversePendingIds(): array
    {
        $need = [];
        RouteSkeleton::query()
            ->orderBy('id')
            ->select(['id', 'reverse_principle'])
            ->chunkById(500, function ($chunk) use (&$need) {
                foreach ($chunk as $row) {
                    $rp = $row->reverse_principle;
                    $ver = is_array($rp) ? (int) ($rp['schema_version'] ?? 0) : 0;
                    if ($ver < RouteSkeletonReverseEngineer::SCHEMA_VERSION) {
                        $need[] = (int) $row->id;
                    }
                }
            });

        return $need;
    }

    private function resetIncompleteReversePrinciples(): void
    {
        RouteSkeleton::query()
            ->whereNotNull('reverse_principle')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) {
                foreach ($chunk as $row) {
                    $rp = $row->reverse_principle;
                    $ver = is_array($rp) ? (int) ($rp['schema_version'] ?? 0) : 0;
                    if ($ver < RouteSkeletonReverseEngineer::SCHEMA_VERSION) {
                        $row->reverse_principle = null;
                        $row->save();
                    }
                }
            });
    }
}
