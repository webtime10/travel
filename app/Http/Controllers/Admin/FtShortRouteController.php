<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FtShortRoute;
use App\Models\NewShortRoute;
use App\Models\AppSetting;
use App\Services\FtShortRouteNormalizer;
use App\Services\FtShortRouteReverseEngineer;
use App\Services\FtShortRouteRuTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $query = FtShortRoute::query()->orderByDesc('id');
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
            'reversePrompt' => FtShortRouteReverseEngineer::resolvePromptForUi($ui),
        ]);
    }

    public function show(Request $request, FtShortRoute $ft_short_route): View
    {
        $ui = $this->applyUiLocale();
        $lang = strtolower((string) $request->get('lang', 'he'));
        if (! in_array($lang, ['he', 'ru'], true)) {
            $lang = 'he';
        }
        $ft_short_route->loadMissing('newShortRoute');
        // Старый RE на русском в поле HE → перенос в RU, HE нужно получить заново.
        if ($ft_short_route->repairMisplacedRussianReverse()) {
            $ft_short_route->refresh();
        }

        return view('admin.ft_short_routes.show', [
            'pageTitle' => __('ft_short.title').' #'.$ft_short_route->id,
            'uiLang' => $ui,
            'item' => $ft_short_route,
            'lang' => $lang,
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
        try {
            $result = $translator->translateBatch($ft_short_route, $limit);
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

    /** Сразу в Gemini → reverse_principle на карточке (только иврит). */
    public function reverseOne(FtShortRoute $ft_short_route, FtShortRouteReverseEngineer $engineer): JsonResponse
    {
        @set_time_limit(600);
        try {
            // Если в HE лежал русский — уводим в RU-слот, потом пишем новый HE.
            $ft_short_route->repairMisplacedRussianReverse();
            $ft_short_route->refresh();
            $result = $engineer->extract($ft_short_route->fresh());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true] + $result);
    }

    public function saveReversePrompt(Request $request): JsonResponse
    {
        $ui = $this->applyUiLocale();
        $lang = strtolower((string) $request->input('lang', $ui));
        if (! in_array($lang, ['he', 'ru'], true)) {
            $lang = $ui;
        }
        $request->validate([
            'prompt' => 'required|string|max:100000',
        ]);
        $prompt = trim((string) $request->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'message' => __('ft_short.prompt_empty')], 422);
        }
        FtShortRouteReverseEngineer::savePromptForUi($lang, $prompt);

        return response()->json(['ok' => true, 'message' => __('ft_short.prompt_saved'), 'lang' => $lang]);
    }

    public function resetReversePrompt(Request $request): JsonResponse
    {
        $ui = $this->applyUiLocale();
        $lang = strtolower((string) $request->input('lang', $ui));
        if (! in_array($lang, ['he', 'ru'], true)) {
            $lang = $ui;
        }
        $fresh = FtShortRouteReverseEngineer::resetPromptForUi($lang);

        return response()->json([
            'ok' => true,
            'prompt' => $fresh,
            'lang' => $lang,
            'message' => __('ft_short.prompt_reset_ok'),
        ]);
    }
}
