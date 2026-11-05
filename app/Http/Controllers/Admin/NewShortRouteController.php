<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IndexNewShortRouteJob;
use App\Models\NewShortRoute;
use App\Models\NewShortRouteDay;
use App\Services\NewShortRouteDocImporter;
use App\Services\NewShortRouteOvernightGemini;
use App\Services\NewShortRouteRuTranslator;
use App\Services\NewShortRouteVectorPipelineStore;
use App\Services\RagDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewShortRouteController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $num = trim((string) $request->input('num', ''));
        $days = $request->input('days');
        $query = NewShortRoute::query()->orderByDesc('id');

        // Отдельный поиск по номеру: id или код документа (5512).
        if ($num !== '') {
            $query->where(function ($w) use ($num) {
                if (ctype_digit($num)) {
                    $w->where('id', (int) $num)
                        ->orWhere('doc_code', $num);
                } else {
                    $w->where('doc_code', 'like', '%'.$num.'%');
                }
            });
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%'.$q.'%')
                    ->orWhere('title_ru', 'like', '%'.$q.'%')
                    ->orWhere('source_filename', 'like', '%'.$q.'%')
                    ->orWhere('doc_code', 'like', '%'.$q.'%')
                    ->orWhere('search_blob', 'like', '%'.$q.'%')
                    ->orWhere('arrival_hub', 'like', '%'.$q.'%');
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q)
                        ->orWhere('doc_code', $q);
                }
            });
        }
        if ($days !== null && $days !== '' && is_numeric($days)) {
            $query->where('days_count', (int) $days);
        }

        $items = $query->paginate(30)->withQueryString();
        $adminLang = strtolower((string) (session('admin_lang', 'he') ?: 'he'));
        if (! in_array($adminLang, ['he', 'ru'], true)) {
            $adminLang = 'he';
        }

        $pendingRu = NewShortRoute::query()
            ->where(function ($w) {
                $w->whereNull('title_ru')
                    ->orWhere('title_ru', '')
                    ->orWhereNull('ru_units')
                    ->orWhere('ru_units', '[]');
            })
            ->count();

        $vectorIndexed = 0;
        $pendingVector = NewShortRoute::count();
        try {
            $ragDb = app(RagDatabaseService::class);
            $vectorIndexed = $ragDb->indexedNewShortRouteCount();
            $pendingVector = count($this->pendingVectorRouteIds($ragDb));
        } catch (\Throwable) {
        }

        return view('admin.new_short_routes.index', [
            'pageTitle' => 'New Short routes',
            'items' => $items,
            'q' => $q,
            'num' => $num,
            'days' => $days,
            'total' => NewShortRoute::count(),
            'adminLang' => $adminLang,
            'showRu' => $adminLang === 'ru',
            'pendingRu' => $pendingRu,
            'vectorIndexed' => $vectorIndexed,
            'pendingVector' => $pendingVector,
        ]);
    }

    public function show(NewShortRoute $new_short_route): View
    {
        $new_short_route->load(['days']);
        $unitsTotal = 0;
        $unitsDone = is_array($new_short_route->ru_units) ? count($new_short_route->ru_units) : 0;
        try {
            $unitsTotal = count(app(NewShortRouteRuTranslator::class)->units($new_short_route));
        } catch (\Throwable) {
        }

        $lang = strtolower(trim((string) request()->input('lang', session('admin_lang', 'he'))));
        if (! in_array($lang, ['he', 'ru'], true)) {
            $lang = 'he';
        }

        return view('admin.new_short_routes.show', [
            'pageTitle' => 'New Short #'.$new_short_route->id.' · '.strtoupper($lang),
            'item' => $new_short_route,
            'unitsTotal' => $unitsTotal,
            'unitsDone' => $unitsDone,
            'lang' => $lang,
        ]);
    }

    public function saveDays(Request $request, NewShortRoute $new_short_route): JsonResponse
    {
        $days = $request->input('days');
        if (! is_array($days)) {
            return response()->json(['ok' => false, 'message' => 'Нет days[]'], 422);
        }

        $lang = strtolower(trim((string) $request->input('lang', 'he')));
        $saveRu = $lang === 'ru';

        $new_short_route->load('days');
        $byId = $new_short_route->days->keyBy('id');
        foreach ($days as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            /** @var NewShortRouteDay|null $day */
            $day = $byId->get($id);
            if (! $day) {
                continue;
            }
            $day->is_overnight = ! empty($row['is_overnight']);
            $text = trim((string) ($row['overnight_text'] ?? ''));
            $note = trim((string) ($row['overnight_note'] ?? ''));
            if ($saveRu) {
                $day->overnight_text_ru = $text !== '' ? mb_substr($text, 0, 500) : null;
                if ($day->is_overnight && $day->overnight_text_ru) {
                    $day->end_point_ru = $day->overnight_text_ru;
                }
            } else {
                $day->overnight_text = $text !== '' ? mb_substr($text, 0, 500) : null;
                $day->overnight_note = $note !== '' ? mb_substr($note, 0, 2000) : null;
                if ($day->is_overnight && $day->overnight_text) {
                    $day->end_point = $day->overnight_text;
                }
                $day->overnight_location_id = null;
            }
            $day->save();
        }

        $arr = trim((string) $request->input('arrival_hub', ''));
        $dep = trim((string) $request->input('departure_hub', ''));
        if ($saveRu) {
            $new_short_route->arrival_hub_ru = $arr !== '' ? $arr : null;
            $new_short_route->departure_hub_ru = $dep !== '' ? $dep : null;
        } else {
            $new_short_route->arrival_hub = $arr !== '' ? $arr : null;
            $new_short_route->departure_hub = $dep !== '' ? $dep : null;
            $new_short_route->arrival_location_id = null;
            $new_short_route->departure_location_id = null;
        }
        $new_short_route->save();

        return response()->json([
            'ok' => true,
            'message' => $saveRu ? 'Русские ночёвки сохранены' : 'Ночёвки (HE) сохранены',
        ]);
    }

    public function geminiOvernights(NewShortRoute $new_short_route, NewShortRouteOvernightGemini $gemini): JsonResponse
    {
        @set_time_limit(600);
        try {
            $result = $gemini->suggestForRoute($new_short_route);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json($result + ['ok' => $result['ok']]);
    }

    public function geminiOvernightsBatch(Request $request, NewShortRouteOvernightGemini $gemini): JsonResponse
    {
        @set_time_limit(1200);
        $limit = max(1, min(20, (int) $request->input('limit', 5)));
        $routes = NewShortRoute::query()->orderBy('id')->limit($limit)->get();
        $ok = 0;
        $fail = 0;
        $messages = [];
        foreach ($routes as $route) {
            try {
                $r = $gemini->suggestForRoute($route);
                if ($r['ok']) {
                    $ok++;
                } else {
                    $fail++;
                }
                $messages[] = '#'.$route->id.': '.$r['message'];
            } catch (\Throwable $e) {
                $fail++;
                $messages[] = '#'.$route->id.': '.$e->getMessage();
            }
        }

        return response()->json([
            'ok' => $fail === 0,
            'message' => "Gemini ночёвки: ok={$ok}, fail={$fail}",
            'details' => $messages,
        ]);
    }

    public function destroy(NewShortRoute $new_short_route): RedirectResponse
    {
        $id = $new_short_route->id;
        $new_short_route->delete();

        return redirect()
            ->route('admin.new-short-routes.index')
            ->with('success', 'New Short route удалён: #'.$id);
    }

    public function scan(Request $request, NewShortRouteDocImporter $importer): JsonResponse
    {
        @set_time_limit(600);
        $limit = max(1, min(200, (int) $request->input('limit', 30)));
        $since2023 = $request->boolean('since_2023', true);
        $parseBody = $request->boolean('parse_body', true);

        try {
            $result = $importer->import($limit, $since2023, $parseBody);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Скан doc: просмотрено '.$result['scanned']
                .', записано '.$result['upserted']
                .', пропуск '.$result['skipped'],
            'result' => $result,
        ]);
    }

    public function rescanOne(NewShortRoute $new_short_route, NewShortRouteDocImporter $importer): JsonResponse
    {
        @set_time_limit(300);
        $file = $new_short_route->source_filename;
        if (! $file) {
            return response()->json(['ok' => false, 'message' => 'Нет source_filename'], 422);
        }
        $path = $importer->docDir().DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            return response()->json(['ok' => false, 'message' => 'Файл не найден: '.$file], 404);
        }
        try {
            $importer->importOne($path, true);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Перечитан docx']);
    }

    public function translateRu(Request $request, NewShortRoute $new_short_route, NewShortRouteRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $limit = max(1, min(40, (int) $request->input('limit', 20)));
        try {
            $result = $translator->translateBatch($new_short_route, $limit);
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

    /**
     * One (or few) New Short HE→RU per request — for visible progress when many Word routes.
     *
     * @return JsonResponse{ok,processed,remaining_routes,route_id,done,message?}
     */
    public function translateRuBatch(Request $request, NewShortRouteRuTranslator $translator): JsonResponse
    {
        @set_time_limit(900);
        $routesLimit = max(1, min(5, (int) $request->input('routes', 1)));
        $onlyPending = $request->boolean('only_pending', true);

        $pendingQuery = NewShortRoute::query()->orderBy('id');
        if ($onlyPending) {
            $pendingQuery->where(function ($w) {
                $w->whereNull('title_ru')
                    ->orWhere('title_ru', '')
                    ->orWhereNull('ru_units')
                    ->orWhere('ru_units', '[]');
            });
        }
        $remainingBefore = (clone $pendingQuery)->count();
        $routes = (clone $pendingQuery)->limit($routesLimit)->get();

        if ($routes->isEmpty()) {
            return response()->json([
                'ok' => true,
                'processed' => 0,
                'remaining_routes' => 0,
                'route_id' => null,
                'done' => true,
                'message' => 'Все New Short из Word уже с русским',
            ]);
        }

        $processed = 0;
        $lastId = null;
        $warnings = [];
        foreach ($routes as $route) {
            $lastId = $route->id;
            try {
                $guard = 0;
                while ($guard++ < 40) {
                    $last = $translator->translateBatch($route->fresh(['days']), 20);
                    if (! empty($last['done']) || (int) ($last['remaining'] ?? 0) === 0) {
                        break;
                    }
                    if ((int) ($last['translated'] ?? 0) === 0 && ! empty($last['warning'])) {
                        throw new \RuntimeException((string) $last['warning']);
                    }
                }
                $processed++;
            } catch (\Throwable $e) {
                $warnings[] = '#'.$route->id.': '.$e->getMessage();
            }
        }

        $remainingAfter = (clone $pendingQuery)->count();

        return response()->json([
            'ok' => $warnings === [],
            'processed' => $processed,
            'remaining_routes' => $remainingAfter,
            'remaining_before' => $remainingBefore,
            'route_id' => $lastId,
            'done' => $remainingAfter === 0,
            'message' => $warnings !== []
                ? implode('; ', $warnings)
                : ('Переведён #'.$lastId.' · осталось без RU: '.$remainingAfter),
        ], $warnings === [] ? 200 : 422);
    }

    public function vectorPipelineStatus(NewShortRouteVectorPipelineStore $pipeline, RagDatabaseService $ragDb): JsonResponse
    {
        $all = NewShortRoute::query()->count();
        $indexed = 0;
        try {
            $indexed = $ragDb->indexedNewShortRouteCount();
        } catch (\Throwable) {
        }

        return response()->json($pipeline->status() + [
            'route_total' => $all,
            'indexed' => $indexed,
            'need' => max(0, $all - $indexed),
        ]);
    }

    public function vectorPipelineStart(NewShortRouteVectorPipelineStore $pipeline, RagDatabaseService $ragDb): JsonResponse
    {
        if ($pipeline->running()) {
            return response()->json(['ok' => false, 'message' => 'RAG New Short уже считается'], 409);
        }

        $ragStatus = $ragDb->connectionStatus();
        if (! ($ragStatus['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'PostgreSQL RAG не подключен'], 503);
        }
        $ragDb->migrateSchema();

        $ids = $this->pendingVectorRouteIds($ragDb);
        if ($ids === []) {
            return response()->json(['ok' => true, 'started' => 0, 'message' => 'Все New Short уже в RAG']);
        }

        $already = 0;
        try {
            $already = $ragDb->indexedNewShortRouteCount();
        } catch (\Throwable) {
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            IndexNewShortRouteJob::dispatch($first);
        }

        $need = count($ids);

        return response()->json([
            'ok' => true,
            'started' => $need,
            'indexed' => $already,
            'message' => 'Продолжаем с недостающих: уже в RAG '.$already
                .', сейчас догоняем '.$need
                .'. Нужен queue:work / queue:work-forever',
        ]);
    }

    public function vectorPipelineCancel(NewShortRouteVectorPipelineStore $pipeline): JsonResponse
    {
        $pipeline->cancel();

        return response()->json(['ok' => true, 'message' => 'Остановлено']);
    }

    /**
     * @return list<int>
     */
    private function pendingVectorRouteIds(RagDatabaseService $ragDb): array
    {
        $ids = NewShortRoute::query()
            ->where(function ($w) {
                $w->whereNotNull('route_text')->where('route_text', '!=', '')
                    ->orWhere(function ($w2) {
                        $w2->whereNotNull('route_text_ru')->where('route_text_ru', '!=', '');
                    });
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($ids === []) {
            return [];
        }
        $indexed = array_fill_keys($ragDb->indexedNewShortRouteIds($ids), true);

        return array_values(array_filter($ids, fn (int $id) => ! isset($indexed[$id])));
    }
}
