@extends('admin.layouts.layout')
@section('content')
@php
  $showRu = ($lang ?? 'he') === 'ru' && $item->hasRussian();
  $routeCode = $item->source_route_id !== null && $item->source_route_id !== ''
    ? (string) $item->source_route_id
    : null;
@endphp
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="{{ route('admin.route-skeletons.index') }}" class="small text-muted d-inline-block mb-1">← к списку</a>
    <h4 class="mb-1 d-flex flex-wrap align-items-center gap-2">
      <span>{{ $item->displayTitle($showRu) ?: 'Маршрут #'.$item->id }}</span>
      @if($routeCode)
        <span class="badge bg-primary rounded-pill fs-6 px-3 py-2" title="Id тура в Python / цифры из названия папки">
          Код {{ $routeCode }}
        </span>
      @endif
    </h4>
    <div class="text-muted small">
      id={{ $item->id }}
      @if($routeCode) · <strong>python={{ $routeCode }}</strong> @endif
      · {{ $item->days_count }} дн.
      @if($item->start_date) · старт {{ $item->start_date->format('d.m.Y') }} @endif
      @if($item->is_template) · <span class="badge bg-label-info">template</span> @endif
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <div class="btn-group btn-group-sm">
      <a href="{{ route('admin.route-skeletons.show', [$item, 'lang' => 'he']) }}"
         class="btn btn-outline-secondary {{ !$showRu ? 'active' : '' }}">HE</a>
      <a href="{{ route('admin.route-skeletons.show', [$item, 'lang' => 'ru']) }}"
         class="btn btn-outline-secondary {{ $showRu ? 'active' : '' }} {{ !$item->hasRussian() ? 'disabled' : '' }}">RU</a>
    </div>
    <button type="button"
            class="btn btn-outline-primary skeleton-translate-ru-btn"
            data-url="{{ route('admin.route-skeletons.translate-ru', $item) }}"
            data-reload="{{ route('admin.route-skeletons.show', [$item, 'lang' => 'ru']) }}">
      <i class="bx bx-globe me-1"></i> Перевод на русский
    </button>
    <button type="button"
            class="btn btn-outline-warning skeleton-reverse-one-btn"
            data-url="{{ route('admin.route-skeletons.reverse', $item) }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-search-alt me-1"></i> Принцип (Gemini)
    </button>
    <form action="{{ route('admin.route-skeletons.destroy', $item) }}" method="post" class="d-inline"
          onsubmit="return confirm('Удалить этот short route #{{ $item->id }}? Дни каркаса тоже удалятся.');">
      @csrf
      @method('DELETE')
      <button type="submit" class="btn btn-outline-danger" title="Удалить">
        <i class="bx bx-trash me-1"></i> Удалить
      </button>
    </form>
  </div>
</div>

@php $rp = is_array($item->reverse_principle) ? $item->reverse_principle : null; @endphp
@if($rp && empty($rp['skipped']))
  <div class="card mb-3 border-warning">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>
        Обратный разбор · v{{ $rp['schema_version'] ?? 1 }}
        @if($routeCode)
          <span class="badge bg-primary ms-2" title="Id тура в Python / цифры из названия папки">Код {{ $routeCode }}</span>
        @endif
      </span>
      @if(!empty($rp['days']))
        <span class="badge bg-label-secondary">дней в разборе: {{ count($rp['days']) }}</span>
      @endif
    </div>
    <div class="card-body small">
      @php
        $abstract = $rp['abstract_user_prompt'] ?? $rp['synthetic_query'] ?? null;
        $macro = is_array($rp['macro_architecture'] ?? null) ? $rp['macro_architecture'] : [];
        $micro = is_array($rp['micro_clustering'] ?? null) ? $rp['micro_clustering'] : [];
      @endphp
      @if(!empty($abstract))
        <div class="mb-2 p-2 rounded bg-label-secondary" dir="auto"><strong>Абстрактный запрос клиента:</strong> {{ $abstract }}</div>
      @endif
      <div class="mb-2" dir="auto"><strong>Выбор регионов:</strong> {{ $macro['region_selection_logic'] ?? $rp['principle'] ?? '—' }}</div>
      <div class="mb-2" dir="auto"><strong>Базы и транзит:</strong> {{ $macro['routing_strategy'] ?? $rp['geo_logic'] ?? '—' }}</div>
      @php $ft = is_array($rp['fine_tune'] ?? null) ? $rp['fine_tune'] : []; @endphp
      @if(!empty($ft['question']) || !empty($ft['answer']))
        <hr class="my-2">
        <div class="fw-semibold mb-1">Пара для FunTunes</div>
        <div class="mb-2 p-2 rounded bg-label-info" dir="auto"><strong>Вопрос:</strong> {{ $ft['question'] ?? $abstract ?? '—' }}</div>
        <div class="mb-2 p-2 rounded border" dir="auto">
          <strong>Ответ (summary):</strong> {{ is_array($ft['answer'] ?? null) ? ($ft['answer']['summary'] ?? '—') : '—' }}
          @if(!empty($ft['answer']['days']))
            <div class="mt-2 small text-muted">
              @foreach($ft['answer']['days'] as $fd)
                <div>День {{ $fd['day_index'] ?? '?' }}@if(!empty($fd['title'])) — {{ $fd['title'] }}@endif
                  · id: {{ implode(', ', $fd['location_ids'] ?? []) }}</div>
              @endforeach
            </div>
          @endif
        </div>
      @endif
      @if($micro !== [])
        <hr class="my-2">
        <div class="fw-semibold mb-2">Микро-кластеризация (выборка дней)</div>
        @foreach($micro as $m)
          <div class="mb-3 p-2 border rounded">
            <div><strong>День {{ $m['day_number'] ?? '?' }}</strong> — {{ $m['day_description'] ?? '—' }}</div>
            <div class="mt-1" dir="auto"><strong>Логика:</strong> {{ $m['practical_logic'] ?? '—' }}</div>
            <div class="mt-1 text-muted" dir="auto"><strong>Логистика/ZTL:</strong> {{ $m['logistics_and_ztl'] ?? '—' }}</div>
          </div>
        @endforeach
      @elseif(!empty($rp['days']))
        <hr class="my-2">
        @foreach($rp['days'] as $d)
          @if(!empty($d['note']))
            <div class="mb-2">
              <strong>День {{ $d['day_index'] ?? '?' }}</strong>
              · stops {{ count($d['stops'] ?? []) }}
              <div class="text-muted" dir="auto">{{ $d['note'] }}</div>
            </div>
          @endif
        @endforeach
      @endif
    </div>
  </div>
@endif
<div class="small text-muted mb-2 skeleton-reverse-one-status" style="display:none;"></div>

<div class="skeleton-translate-ru-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
  <div class="d-flex justify-content-between mb-1">
    <div class="fw-semibold skeleton-translate-ru-label">Перевод на русский…</div>
    <div class="fw-bold skeleton-translate-ru-pct">0%</div>
  </div>
  <div class="progress mb-1" style="height:8px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary skeleton-translate-ru-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small skeleton-translate-ru-status">Подготовка…</div>
</div>

@if($unitsTotal > 0)
  <div class="small text-muted mb-3">
    Перевод: {{ $unitsDone }} / {{ $unitsTotal }} фрагментов
    @if($unitsDone >= $unitsTotal && $unitsTotal > 0)
      <span class="badge bg-label-success">готово</span>
    @endif
  </div>
@endif

<div class="card mb-3">
  <div class="card-body py-3 small">
    <strong>Ось поездки:</strong>
    @if($showRu)
      {{ $item->arrival_hub_ru ?: $item->arrival_hub ?: '—' }}
      →
      {{ $item->departure_hub_ru ?: $item->departure_hub ?: '—' }}
    @else
      {{ $item->arrival_hub ?: '—' }} → {{ $item->departure_hub ?: '—' }}
    @endif
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width:4rem">День</th>
          <th>Программа</th>
          <th style="width:28%">Ночёвка (ось)</th>
        </tr>
      </thead>
      <tbody>
        @foreach($item->days as $day)
          @php
            $useRu = $showRu && $day->hasRussian();
            $placeList = $useRu && is_array($day->places_ru) && $day->places_ru !== []
              ? $day->places_ru
              : (is_array($day->places) ? $day->places : []);
            $names = [];
            foreach ($placeList as $p) {
              if (is_array($p) && trim((string)($p['name'] ?? '')) !== '') {
                $n = trim((string)$p['name']);
                $id = (int)($p['id'] ?? 0);
                $names[] = $id > 0 ? $n.' [id='.$id.']' : $n;
              }
            }
            $dayTitle = $useRu && trim((string)($day->title_ru ?? '')) !== ''
              ? $day->title_ru
              : ($day->title ?: '');
            $prog = $names !== [] ? implode(', ', $names) : ($dayTitle ?: '—');
            $axis = $useRu && trim((string)($day->start_point_ru ?? '')) !== ''
              ? trim((string)$day->start_point_ru)
              : trim((string)($day->start_point ?? ''));
            $end = $useRu && trim((string)($day->end_point_ru ?? '')) !== ''
              ? trim((string)$day->end_point_ru)
              : trim((string)($day->end_point ?? ''));
            if ($end !== '' && $end !== $axis) {
              $axis = $axis !== '' ? $axis.' → '.$end : $end;
            }
          @endphp
          <tr>
            <td class="fw-medium">{{ $day->day_index }}</td>
            <td>
              @if($dayTitle && $names !== [])
                <div class="small text-muted mb-1">{{ $dayTitle }}</div>
              @endif
              {{ $prog }}
            </td>
            <td class="small">{{ $axis !== '' ? $axis : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header py-2"><span class="small text-muted">Текст для промпта ({{ $showRu ? 'RU' : 'HE' }})</span></div>
  <div class="card-body">
    <pre class="mb-0 small" style="white-space:pre-wrap">{{ $item->toBriefText($showRu) }}</pre>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const btn = document.querySelector('.skeleton-translate-ru-btn');
  if (!btn) return;
  const box = document.querySelector('.skeleton-translate-ru-progress');
  const label = document.querySelector('.skeleton-translate-ru-label');
  const pctEl = document.querySelector('.skeleton-translate-ru-pct');
  const bar = document.querySelector('.skeleton-translate-ru-bar');
  const status = document.querySelector('.skeleton-translate-ru-status');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    || '{{ csrf_token() }}';

  function setProgress(done, total) {
    const pct = total > 0 ? Math.min(100, Math.round(100 * done / total)) : 0;
    pctEl.textContent = pct + '%';
    bar.style.width = pct + '%';
    status.textContent = done + ' / ' + total;
  }

  async function run() {
    btn.disabled = true;
    box.style.display = '';
    label.textContent = 'Перевод на русский…';
    let safety = 40;
    try {
      while (safety-- > 0) {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ limit: 20 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok && !data.total) {
          status.textContent = data.message || ('Ошибка ' + res.status);
          label.textContent = 'Ошибка';
          break;
        }
        const total = data.total || 0;
        const rem = data.remaining || 0;
        const done = total - rem;
        setProgress(done, total);
        if (data.message) status.textContent = data.message;
        if (data.done) {
          label.textContent = 'Готово';
          status.textContent = 'Перевод завершён — обновляю страницу…';
          window.location.href = btn.dataset.reload;
          return;
        }
        if ((data.translated || 0) === 0 && rem > 0) {
          label.textContent = 'Пауза';
          status.textContent = data.message || 'Нет прогресса — нажмите ещё раз.';
          break;
        }
      }
    } catch (e) {
      label.textContent = 'Ошибка';
      status.textContent = e.message || String(e);
    } finally {
      btn.disabled = false;
    }
  }

  btn.addEventListener('click', run);
})();

(function () {
  const btn = document.querySelector('.skeleton-reverse-one-btn');
  const status = document.querySelector('.skeleton-reverse-one-status');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    if (!confirm('Снять принцип этого short route через Gemini?')) return;
    btn.disabled = true;
    if (status) { status.style.display = 'block'; status.textContent = 'Gemini разбирает…'; }
    try {
      const res = await fetch(btn.dataset.url, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': btn.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      location.reload();
    } catch (e) {
      if (status) status.textContent = 'Ошибка: ' + (e.message || e);
      btn.disabled = false;
    }
  });
})();
</script>
@endsection
