@extends('admin.layouts.layout')

@section('content')
@php
  $showRu = ($lang ?? 'he') === 'ru' && $item->hasRussian();
@endphp
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0" dir="auto">{{ $showRu ? $item->displayTitle() : ($item->title ?: $item->displayTitle()) }}</h4>
  <div class="page-toolbar d-flex flex-wrap gap-1 align-items-center">
    <div class="btn-group btn-group-sm">
      <a href="{{ route('admin.golden-days.show', [$item, 'lang' => 'he']) }}"
         class="btn btn-outline-secondary {{ !$showRu ? 'active' : '' }}">HE</a>
      <a href="{{ route('admin.golden-days.show', [$item, 'lang' => 'ru']) }}"
         class="btn btn-outline-secondary {{ $showRu ? 'active' : '' }} {{ !$item->hasRussian() ? 'disabled' : '' }}">RU</a>
    </div>
    <button type="button"
            class="btn btn-outline-primary golden-translate-ru-btn"
            data-url="{{ route('admin.golden-days.translate-ru', $item) }}"
            data-reload="{{ route('admin.golden-days.show', [$item, 'lang' => 'ru']) }}">
      <i class="bx bx-globe me-1"></i> Перевод на русский
    </button>
    <form action="{{ route('admin.golden-days.toggle', $item) }}" method="post">
      @csrf
      <button type="submit" class="btn btn-outline-secondary">{{ $item->is_active ? 'Deactivate' : 'Activate' }}</button>
    </form>
    <a href="{{ route('admin.golden-days.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="golden-translate-ru-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
  <div class="d-flex justify-content-between mb-1">
    <div class="fw-semibold golden-translate-ru-label">Перевод на русский…</div>
    <div class="fw-bold golden-translate-ru-pct">0%</div>
  </div>
  <div class="progress mb-1" style="height:8px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary golden-translate-ru-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small golden-translate-ru-status">Подготовка…</div>
</div>

@if(($unitsTotal ?? 0) > 0)
  <div class="small text-muted mb-3">
    Перевод: {{ $unitsDone }} / {{ $unitsTotal }} фрагментов
    @if($unitsDone >= $unitsTotal && $unitsTotal > 0)
      <span class="badge bg-label-success">готово</span>
    @endif
  </div>
@endif

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-3 small">
      <div class="col-md-3"><span class="text-muted">ID</span><div class="fw-semibold">{{ $item->id }}</div></div>
      <div class="col-md-3"><span class="text-muted">Stops</span><div class="fw-semibold">{{ $item->stops_count }}</div></div>
      <div class="col-md-3"><span class="text-muted">Usage in skeletons</span><div class="fw-semibold">{{ $item->usage_count }}</div></div>
      <div class="col-md-3"><span class="text-muted">Active</span><div class="fw-semibold">{{ $item->is_active ? 'yes' : 'no' }}</div></div>
      <div class="col-md-6"><span class="text-muted">Start / end</span>
        <div dir="auto">{{ $item->overnightAxis($showRu) ?: '—' }}</div>
      </div>
      <div class="col-md-6"><span class="text-muted">Source skeleton</span>
        <div>
          @if($item->sourceSkeleton)
            <a href="{{ route('admin.route-skeletons.show', $item->source_skeleton_id) }}">#{{ $item->source_skeleton_id }} {{ $item->sourceSkeleton->title }}</a>
            · day index {{ $item->source_day_index }}
          @else
            —
          @endif
        </div>
      </div>
    </div>
    <p class="mb-0 mt-3 small text-muted" dir="auto">{{ $item->toBriefText($showRu) }}</p>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Golden labels</span>
    <a href="{{ route('admin.golden-labels.index') }}" class="small">Справочник меток</a>
  </div>
  <div class="card-body">
    @if(($allLabels ?? collect())->isEmpty())
      <div class="text-muted small">Меток ещё нет — <a href="{{ route('admin.golden-labels.create') }}">создайте</a>.</div>
    @else
      <form method="post" action="{{ route('admin.golden-days.sync-labels', $item) }}">
        @csrf
        @php $selected = $item->labels->pluck('id')->all(); @endphp
        <div class="row g-2">
          @foreach($allLabels as $lab)
            <div class="col-md-4 col-lg-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="label_ids[]" value="{{ $lab->id }}"
                       id="glab{{ $lab->id }}"
                       {{ in_array($lab->id, $selected, true) ? 'checked' : '' }}>
                <label class="form-check-label" for="glab{{ $lab->id }}">
                  {{ $lab->nameFor($defaultLanguage) }}
                  <span class="text-muted small">({{ $lab->slug }})</span>
                </label>
              </div>
            </div>
          @endforeach
        </div>
        <button type="submit" class="btn btn-primary btn-sm mt-3">Сохранить метки</button>
      </form>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-header">Stops (block)</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Python id</th>
          <th>Location</th>
        </tr>
      </thead>
      <tbody>
        @foreach($item->stops as $stop)
          @php
            $name = $showRu && trim((string)($stop->name_ru ?? '')) !== ''
              ? $stop->name_ru
              : ($stop->name ?: '—');
          @endphp
          <tr>
            <td>{{ $stop->sort_order + 1 }}</td>
            <td dir="auto">{{ $name }}</td>
            <td>{{ $stop->source_model ?: '—' }}</td>
            <td>
              @if($stop->location_id)
                <a href="{{ route('admin.locations.edit', $stop->location_id) }}">#{{ $stop->location_id }}</a>
                <span class="text-muted small ms-1" dir="auto">{{ $stop->location?->nameFor() }}</span>
              @else
                <span class="text-muted">not linked</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const btn = document.querySelector('.golden-translate-ru-btn');
  if (!btn) return;
  const box = document.querySelector('.golden-translate-ru-progress');
  const label = document.querySelector('.golden-translate-ru-label');
  const pctEl = document.querySelector('.golden-translate-ru-pct');
  const bar = document.querySelector('.golden-translate-ru-bar');
  const status = document.querySelector('.golden-translate-ru-status');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    || '{{ csrf_token() }}';

  function setProgress(done, total) {
    const pct = total > 0 ? Math.min(100, Math.round(100 * done / total)) : 0;
    pctEl.textContent = pct + '%';
    bar.style.width = pct + '%';
    status.textContent = done + ' / ' + total;
  }

  btn.addEventListener('click', async function () {
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
        setProgress(total - rem, total);
        if (data.message) status.textContent = data.message;
        if (data.done) {
          label.textContent = 'Готово';
          status.textContent = 'Перевод завершён — обновляю страницу…';
          location.href = btn.dataset.reload || location.href;
          return;
        }
        if ((data.translated || 0) === 0 && rem > 0) {
          label.textContent = 'Ошибка';
          status.textContent = data.message || 'Gemini не вернул перевод';
          break;
        }
      }
    } catch (e) {
      label.textContent = 'Ошибка';
      status.textContent = e.message || String(e);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
@endsection
