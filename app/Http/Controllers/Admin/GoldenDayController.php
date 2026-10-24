<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoldenDay;
use App\Models\GoldenLabel;
use App\Models\Language;
use App\Services\GoldenDayBuilder;
use App\Services\GoldenDayLabelSync;
use App\Services\GoldenDayRuTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GoldenDayController extends Controller
{
    public function index(Request $request, GoldenDayRuTranslator $translator): View
    {
        $q = trim((string) $request->input('q', ''));
        $query = GoldenDay::query()
            ->with(['stops.location.descriptions', 'area.descriptions', 'labels.descriptions'])
            ->withCount('stops')
            ->orderByDesc('usage_count')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%'.$q.'%')
                    ->orWhere('title_ru', 'like', '%'.$q.'%')
                    ->orWhere('start_point', 'like', '%'.$q.'%')
                    ->orWhere('end_point', 'like', '%'.$q.'%')
                    ->orWhere('start_point_ru', 'like', '%'.$q.'%')
                    ->orWhere('end_point_ru', 'like', '%'.$q.'%');
            });
        }

        $days = $query->paginate(50)->withQueryString();

        return view('admin.golden_days.index', [
            'pageTitle' => 'Golden days',
            'days' => $days,
            'q' => $q,
            'pendingRu' => $translator->countPendingDays(),
            'defaultLanguage' => Language::admin(),
        ]);
    }

    public function show(GoldenDay $golden_day, GoldenDayRuTranslator $translator, GoldenDayLabelSync $labelSync): View
    {
        $labelSync->attachFromHub($golden_day);
        $golden_day->load(['stops.location.descriptions', 'area.descriptions', 'sourceSkeleton', 'labels.descriptions']);
        $units = $translator->units($golden_day);
        $done = is_array($golden_day->ru_units) ? $golden_day->ru_units : [];
        $unitsDone = count(array_intersect_key($done, $units));
        $defaultLanguage = Language::admin();
        $allLabels = GoldenLabel::query()
            ->with('descriptions')
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.golden_days.show', [
            'pageTitle' => 'Golden day #'.$golden_day->id,
            'item' => $golden_day,
            'unitsTotal' => count($units),
            'unitsDone' => $unitsDone,
            'lang' => request()->input('lang', $golden_day->hasRussian() ? 'ru' : 'he'),
            'allLabels' => $allLabels,
            'defaultLanguage' => $defaultLanguage,
        ]);
    }

    public function syncLabels(Request $request, GoldenDay $golden_day, GoldenDayLabelSync $labelSync): RedirectResponse
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('label_ids', []))));
        $valid = GoldenLabel::query()
            ->where('status', true)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
        $golden_day->labels()->sync($valid);
        // keep hub_* from hub_key even if unchecked by mistake
        $labelSync->attachFromHub($golden_day);

        return redirect()
            ->back()
            ->with('success', 'Метки сохранены');
    }

    public function syncHubLabels(GoldenDayLabelSync $labelSync): RedirectResponse
    {
        @set_time_limit(600);
        $result = $labelSync->syncAllFromHubKeys();

        return redirect()
            ->route('admin.golden-days.index')
            ->with('success', "Метки хабов: дней {$result['days']}, привязок {$result['attached']}");
    }

    public function rebuild(Request $request, GoldenDayBuilder $builder): RedirectResponse
    {
        $fresh = $request->boolean('fresh', true);
        $result = $builder->buildFromSkeletons([
            'fresh' => $fresh,
            'min_stops' => 1,
        ]);

        return redirect()
            ->route('admin.golden-days.index')
            ->with('success', "Golden days: created {$result['created']}, reused {$result['reused']}, stops {$result['stops']} (linked {$result['linked']})");
    }

    public function toggle(GoldenDay $golden_day): RedirectResponse
    {
        $golden_day->is_active = ! $golden_day->is_active;
        $golden_day->save();

        return redirect()
            ->back()
            ->with('success', $golden_day->is_active ? 'Day activated' : 'Day deactivated');
    }

    public function translateRu(Request $request, GoldenDay $golden_day, GoldenDayRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $limit = max(1, min(40, (int) $request->input('limit', 20)));

        try {
            $result = $translator->translateBatch($golden_day, $limit);
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

    public function translateRuBatch(Request $request, GoldenDayRuTranslator $translator): JsonResponse
    {
        @set_time_limit(600);
        $daysLimit = max(1, min(3, (int) $request->input('days', 1)));

        try {
            $result = $translator->translateNextPendingDays($daysLimit);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $payload = [
            'ok' => empty($result['warning']),
            'processed' => $result['processed'],
            'remaining' => $result['remaining_days'],
            'remaining_days' => $result['remaining_days'],
            'day_id' => $result['day_id'],
            'done' => $result['done'],
            'total' => $result['remaining_days'] + $result['processed'],
        ];
        if (! empty($result['warning'])) {
            $payload['message'] = $result['warning'];
        }

        return response()->json($payload, empty($result['warning']) ? 200 : 422);
    }
}
