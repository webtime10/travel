@extends('admin.layouts.layout')
@section('content')
@php
  $showRu = ($lang ?? 'he') === 'ru' && $item->hasRussian();
@endphp
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="{{ route('admin.route-skeletons.index') }}" class="small text-muted d-inline-block mb-1">← к списку</a>
    <h4 class="mb-1">{{ $item->displayTitle($showRu) ?: 'Маршрут #'.$item->id }}</h4>
    <div class="text-muted small">
      id={{ $item->id }}
      · python={{ $item->source_route_id }}
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
  </div>
</div>

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
</script>
@endsection
