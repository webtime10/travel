<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReverseEngineerFtShortRouteJob;
use App\Models\FtShortRoute;
use App\Models\NewShortRoute;
use App\Services\FtShortRouteNormalizer;
use App\Services\FtShortRouteReverseEngineer;
use App\Services\FtShortRouteRuTranslator;
use App\Services\GeminiKeyPool;
use App\Services\ReverseEngineeringPipelineStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FtShortRouteController extends Controller
{
    private function uiLang(): string
    {
        $code = strtolower((string) (session('admin_lang', 'he') ?: 'he'));

        return in_array($code, ['he', 'ru'], true) ? $code : 'he';
    }

    private function applyUiLocale(): string
    {
        $ui = $this->uiLang();
        app()->setLocale($ui);

        return $ui;
    }

    public function index(Request $request): View
    {
        $ui = $this->applyUiLocale();
        $q = trim((string) $request->get('q', ''));
        // Сначала уже прогнанные RE (есть reverse_principle), потом остальные; внутри — новые id сверху.
        $query = FtShortRoute::query()
            ->orderByRaw(
                "CASE
                    WHEN reverse_principle IS NOT NULL
                     AND reverse_principle != '[]'
                     AND reverse_principle NOT LIKE '%\"skipped\"%'
                    THEN 0 ELSE 1 END"
            )
            ->orderByDesc('id');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('doc_code', 'like', '%'.$q.'%')
                    ->orWhere('title', 'like', '%'.$q.'%')
                    ->orWhere('title_ru', 'like', '%'.$q.'%')
                    ->orWhere('route_text', 'like', '%'.$q.'%')
                    ->orWhere('route_text_ru', 'like', '%'.$q.'%');
            });
        }

        $nsrTotal = NewShortRoute::count();
        $ftTotal = FtShortRoute::count();
        $pending = max(0, $nsrTotal - $ftTotal);
        $pendingRu = FtShortRoute::query()
            ->where('days_count', '>', 0)
            ->where(function ($w) {
                $w->where(function ($q) {
                    $q->whereNull('title_ru')->orWhere('title_ru', '');
                })->orWhere(function ($q) {
                    $q->whereNull('route_text_ru')->orWhere('route_text_ru', '');
                })->orWhere(function ($q) {
                    $q->whereNotNull('reverse_principle')
                        ->where('reverse_principle', '!=', '[]')
                        ->where('reverse_principle', 'not like', '%"skipped"%')
                        ->where(function ($r) {
                            $r->whereNull('reverse_principle_ru')
                                ->orWhere('reverse_principle_ru', '')
                                ->orWhere('reverse_principle_ru', '[]');
                        });
                });
            })
            ->count();

        $reverseReady = FtShortRoute::query()
            ->whereNotNull('reverse_principle')
            ->where('reverse_principle', '!=', '[]')
            ->count();

        return view('admin.ft_short_routes.index', [
            'pageTitle' => __('ft_short.title'),
            'uiLang' => $ui,
            'items' => $query->paginate(40)->withQueryString(),
            'q' => $q,
            'total' => $ftTotal,
            'nsrTotal' => $nsrTotal,
            'pending' => $pending,
            'pendingRu' => $pendingRu,
            'reverseReady' => $reverseReady,
            'reversePrompt' => FtShortRouteReverseEngineer::resolvePromptRu(),
            'manualPrompt' => FtShortRouteReverseEngineer::resolveManualPromptRu(),
        ]);
    }

    public function show(Request $request, FtShortRoute $ft_short_route): View
    {
        $ui = $this->applyUiLocale();
        $lang = strtolower((string) $request->get('lang', 'he'));
        if (! in_array($lang, ['he', 'ru'], true)) {
            $lang = 'he';
        }
        $engine = strtolower((string) $request->get('engine', 'gemini'));
        if (! in_array($engine, ['gemini', 'claude'], true)) {
            $engine = 'gemini';
        }
        $ft_short_route->loadMissing('newShortRoute');
        // Старый RE на русском в поле HE → перенос в RU, HE нужно получить заново (только Gemini-слот).
        if ($engine === 'gemini' && $ft_short_route->repairMisplacedRussianReverse()) {
            $ft_short_route->refresh();
        }

        $titleSuffix = $engine === 'claude' ? ' · Claude' : '';

        return view('admin.ft_short_routes.show', [
            'pageTitle' => __('ft_short.title').' #'.$ft_short_route->id.$titleSuffix,
            'uiLang' => $ui,
            'item' => $ft_short_route,
            'lang' => $lang,
            'engine' => $engine,
            'manualPrompt' => FtShortRouteReverseEngineer::resolveManualPromptRu(),
        ]);
    }

    /** Отдельная страница: собирает PDF HE/RU и сразу скачивает файл. */
    public function pdf(FtShortRoute $ft_short_route, string $lang): View
    {
        $lang = strtolower($lang);
        if (! in_array($lang, ['he', 'ru'], true)) {
            $lang = 'he';
        }
        // Подписи PDF = язык файла (не язык меню админки).
        app()->setLocale($lang);

        $ft_short_route->loadMissing('newShortRoute');
        if ($ft_short_route->repairMisplacedRussianReverse()) {
            $ft_short_route->refresh();
        }

        $code = preg_replace('/\W+/', '', (string) ($ft_short_route->doc_code ?: $ft_short_route->id)) ?: (string) $ft_short_route->id;
        $fileName = 'ft-short-'.$ft_short_route->id.'-'.$code.'-'.strtoupper($lang).'.pdf';

        return view('admin.ft_short_routes.pdf', [
            'item' => $ft_short_route,
            'lang' => $lang,
            'fileName' => $fileName,
        ]);
    }

    public function destroy(FtShortRoute $ft_short_route): RedirectResponse
    {
        $id = $ft_short_route->id;
        $ft_short_route->delete();

        return redirect()
            ->route('admin.ft-short-routes.index')
            ->with('success', 'FT Short route удалён: #'.$id);
    }

    /** Очистить разбор (HE + RU) для Gemini или Claude. Маршрут не трогает. */
    public function clearReverse(Request $request, FtShortRoute $ft_short_route): JsonResponse
    {
        $this->applyUiLocale();
        $provider = strtolower(trim((string) $request->input('provider', 'gemini')));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }

        $ft_short_route->clearReversePrinciple($provider);

        return response()->json([
            'ok' => true,
            'provider' => $provider,
            'message' => __('ft_short.re_cleared'),
        ]);
    }

    public function buildBatch(Request $request, FtShortRouteNormalizer $normalizer): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->input('limit', 50)));
        $force = (bool) $request->boolean('force');

        $query = NewShortRoute::query()->orderBy('id');
        if (! $force) {
            $query->whereDoesntHave('ftShortRoute');
        }
        $routes = $query->limit($limit)->get();

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($routes as $nsr) {
            try {
                $res = $normalizer->syncFromNewShort($nsr);
                if ($res['created']) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = '#'.$nsr->id.': '.$e->getMessage();
                }
            }
        }

        $nsrTotal = NewShortRoute::count();
        $ftTotal = FtShortRoute::count();
        $pending = max(0, $nsrTotal - $ftTotal);

        return response()->json([
            'ok' => true,
            'processed' => $routes->count(),
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'pending' => $pending,
            'ft_total' => $ftTotal,
            'nsr_total' => $nsrTotal,
            'message' => sprintf(
                'FT HE: +%d новых, %d обновлено%s. Готово %d / New Short %d, осталось %d.',
                $created,
                $updated,
                $failed > 0 ? ', ошибок '.$failed : '',
                $ftTotal,
                $nsrTotal,
                $pending
            ),
        ]);
    }

    public function rebuildOne(FtShortRoute $ft_short_route, FtShortRouteNormalizer $normalizer): JsonResponse
    {
        $nsr = NewShortRoute::query()->find($ft_short_route->new_short_route_id);
        if (! $nsr) {
            return response()->json(['ok' => false, 'message' => 'New Short источник не найден'], 404);
        }
        $res = $normalizer->syncFromNewShort($nsr);

        return response()->json([
            'ok' => true,
            'message' => 'Пересобрано HE из New Short #'.$nsr->id,
            'days_count' => $res['ft']->days_count,
            'ft_id' => $res['ft']->id,
        ]);
    }

    public function translateRu(Request $request, FtShortRoute $ft_short_route, FtShortRouteRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $limit = max(1, min(40, (int) $request->input('limit', 20)));
        $engine = strtolower((string) $request->input('engine', 'gemini'));
        try {
            $result = $engine === 'claude'
                ? $translator->translateClaudeReverse($ft_short_route, $limit)
                : $translator->translateBatch($ft_short_route, $limit);
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
            'engine' => $engine === 'claude' ? 'claude' : 'gemini',
        ];
        if (! empty($result['warning'])) {
            $payload['message'] = $result['warning'];
        }

        return response()->json($payload, empty($result['warning']) ? 200 : 422);
    }

    public function translateRuBatch(Request $request, FtShortRouteRuTranslator $translator): JsonResponse
    {
        @set_time_limit(900);
        @ini_set('max_execution_time', '900');
        $routesLimit = max(1, min(5, (int) $request->input('routes', 1)));
        // Меньше юнитов за вызов — меньше шанс обрыва CGI/таймаута без записи в БД.
        $unitsLimit = max(8, min(40, (int) $request->input('units', 16)));

        try {
            $result = $translator->translateNextPending($routesLimit, $unitsLimit);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'processed' => 0,
                'translated_units' => 0,
                'message' => $e->getMessage(),
            ], 500);
        }

        $ok = (bool) ($result['ok'] ?? true);

        return response()->json(array_merge($result, ['ok' => $ok]), $ok ? 200 : 422);
    }

    /** Разбор: инструкция RU → reverse_principle на иврите. provider=gemini|claude */
    public function reverseOne(Request $request, FtShortRoute $ft_short_route, FtShortRouteReverseEngineer $engineer): JsonResponse
    {
        @set_time_limit(600);
        $provider = strtolower((string) $request->input('provider', 'gemini'));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }
        try {
            // Если в HE лежал русский — уводим в RU-слот, потом пишем новый HE.
            $ft_short_route->repairMisplacedRussianReverse();
            $ft_short_route->refresh();
            $result = $engineer->extract($ft_short_route->fresh(), $provider);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result);
    }

    public function reversePipelineStatus(Request $request): JsonResponse
    {
        $provider = strtolower((string) $request->query('provider', 'gemini'));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }
        $pipeline = ReverseEngineeringPipelineStore::for(ReverseEngineerFtShortRouteJob::pipelineName($provider));

        return response()->json($pipeline->status() + ['provider' => $provider]);
    }

    /**
     * Пакетный разбор через queue:work (паузы между маршрутами).
     * Body: { provider: gemini|claude, ids?: int[] }
     */
    public function reversePipelineStart(Request $request, GeminiKeyPool $keys): JsonResponse
    {
        $this->applyUiLocale();
        $provider = strtolower((string) $request->input('provider', 'gemini'));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }

        $pipeline = ReverseEngineeringPipelineStore::for(ReverseEngineerFtShortRouteJob::pipelineName($provider));
        if ($pipeline->running()) {
            return response()->json([
                'ok' => false,
                'message' => __('ft_short.pipeline_already_running', ['provider' => $provider]),
            ], 409);
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));

        if ($ids === []) {
            return response()->json(['ok' => false, 'message' => __('ft_short.alert_select_first')], 422);
        }

        $existing = FtShortRoute::query()->whereIn('id', $ids)->pluck('id')->all();
        $ids = array_values(array_intersect($ids, array_map('intval', $existing)));
        if ($ids === []) {
            return response()->json(['ok' => false, 'message' => __('ft_short.alert_select_first')], 422);
        }

        if ($provider === 'gemini') {
            $keys->resetToFirst();
        }

        $pipeline->begin($ids);
        $first = $pipeline->shift();
        if ($first !== null) {
            ReverseEngineerFtShortRouteJob::dispatch($first, $provider);
        }

        $delay = max(8, (int) config('services.gemini.pipeline_delay_seconds', 8));
        if ($provider === 'claude') {
            $delay = max($delay, (int) config('services.claude.pipeline_delay_seconds', 12));
        }

        return response()->json([
            'ok' => true,
            'started' => count($ids),
            'provider' => $provider,
            'message' => __('ft_short.pipeline_started', [
                'n' => count($ids),
                'provider' => $provider,
                'delay' => $delay,
            ]),
        ]);
    }

    public function reversePipelineCancel(Request $request): JsonResponse
    {
        $this->applyUiLocale();
        $provider = strtolower((string) $request->input('provider', 'gemini'));
        if (! in_array($provider, ['gemini', 'claude'], true)) {
            $provider = 'gemini';
        }
        ReverseEngineeringPipelineStore::for(ReverseEngineerFtShortRouteJob::pipelineName($provider))->cancel();

        return response()->json([
            'ok' => true,
            'provider' => $provider,
            'message' => __('ft_short.pipeline_stopped'),
        ]);
    }

    public function saveReversePrompt(Request $request): JsonResponse
    {
        $this->applyUiLocale();
        try {
            $request->validate([
                'prompt' => 'required|string|max:200000',
            ]);
        } catch (ValidationException $e) {
            $msg = $e->validator->errors()->first('prompt') ?: __('ft_short.prompt_empty');

            return response()->json(['ok' => false, 'message' => $msg, 'errors' => $e->errors()], 422);
        }
        $prompt = trim((string) $request->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'message' => __('ft_short.prompt_empty')], 422);
        }
        // Как прислали — без resolve/апгрейда, иначе Save «откатывается».
        $saved = FtShortRouteReverseEngineer::savePromptForUi('ru', $prompt);

        return response()->json([
            'ok' => true,
            'message' => __('ft_short.prompt_saved'),
            'lang' => 'ru',
            'prompt' => $saved,
            'chars' => mb_strlen($saved),
        ]);
    }

    public function resetReversePrompt(Request $request): JsonResponse
    {
        $this->applyUiLocale();
        $fresh = FtShortRouteReverseEngineer::resetPromptForUi('ru');

        return response()->json([
            'ok' => true,
            'prompt' => $fresh,
            'lang' => 'ru',
            'message' => __('ft_short.prompt_reset_ok'),
        ]);
    }

    public function saveManualPrompt(Request $request): JsonResponse
    {
        $this->applyUiLocale();
        try {
            $request->validate([
                'prompt' => 'required|string|max:200000',
            ]);
        } catch (ValidationException $e) {
            $msg = $e->validator->errors()->first('prompt') ?: __('ft_short.prompt_empty');

            return response()->json(['ok' => false, 'message' => $msg, 'errors' => $e->errors()], 422);
        }
        $prompt = trim((string) $request->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'message' => __('ft_short.prompt_empty')], 422);
        }
        $saved = FtShortRouteReverseEngineer::saveManualPromptRu($prompt);

        return response()->json([
            'ok' => true,
            'message' => __('ft_short.manual_prompt_saved'),
            'prompt' => $saved,
            'chars' => mb_strlen($saved),
        ]);
    }

    public function resetManualPrompt(Request $request): JsonResponse
    {
        $this->applyUiLocale();
        $fresh = FtShortRouteReverseEngineer::resetManualPromptRu();

        return response()->json([
            'ok' => true,
            'prompt' => $fresh,
            'message' => __('ft_short.manual_prompt_reset_ok'),
        ]);
    }

    /** Ручная сборка: JSON из чата Gemini → reverse_principle (те же поля). */
    public function importManualJson(Request $request, FtShortRoute $ft_short_route, FtShortRouteReverseEngineer $engineer): JsonResponse
    {
        $this->applyUiLocale();
        try {
            $request->validate([
                'json' => 'required|string|max:500000',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->validator->errors()->first('json') ?: __('ft_short.manual_json_empty'),
            ], 422);
        }

        $raw = trim((string) $request->input('json'));
        if ($raw === '') {
            return response()->json(['ok' => false, 'message' => __('ft_short.manual_json_empty')], 422);
        }

        try {
            $result = $engineer->importManualJson($ft_short_route->fresh(), $raw);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => __('ft_short.manual_json_saved'),
            'provider' => $result['provider'],
            'has_re' => true,
        ]);
    }
}
