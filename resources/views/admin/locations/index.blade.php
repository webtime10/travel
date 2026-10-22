@extends('admin.layouts.layout')

@section('content')
@php
  $statuses = \App\Models\Location::FACTS_STATUSES;
  $filterOptions = [
    '' => 'Все',
    'review' => 'Требует проверки',
    'bad' => $statuses['bad']['label'],
    'warn' => $statuses['warn']['label'],
    'ok' => $statuses['ok']['label'],
    'error' => $statuses['error']['label'],
    'none' => 'Не разбиты',
  ];
  $countFor = function ($key) use ($statusCounts) {
    if ($key === '') return array_sum($statusCounts->all());
    if ($key === 'review') return (int) $statusCounts->get('bad', 0) + (int) $statusCounts->get('warn', 0) + (int) $statusCounts->get('error', 0);
    return (int) $statusCounts->get($key, 0);
  };
@endphp
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0 d-flex align-items-center gap-2">
    Locations
    <span class="badge bg-label-primary rounded-pill">{{ $locations->total() }}</span>
  </h4>
  <div class="page-toolbar d-flex flex-wrap gap-1">
    <button
      type="button"
      class="btn btn-outline-primary facts-pipeline-start-btn"
      title="Разбить все неразбитые локации на факты и проверить"
      data-url="{{ route('admin.locations.facts-pipeline.start') }}"
      data-cancel-url="{{ route('admin.locations.facts-pipeline.cancel') }}"
      data-status-url="{{ route('admin.locations.facts-pipeline.status') }}"
      data-csrf="{{ csrf_token() }}"
      data-pending="{{ $countFor('none') }}"
    >
      <i class="bx bx-git-branch me-1"></i> Разбить все
    </button>
    <button
      type="button"
      class="btn btn-outline-success vector-pipeline-start-btn"
      title="Положить места в вектор (только ещё не прогнанные)"
      data-url="{{ route('admin.locations.vector-pipeline.start') }}"
      data-cancel-url="{{ route('admin.locations.vector-pipeline.cancel') }}"
      data-status-url="{{ route('admin.locations.vector-pipeline.status') }}"
      data-csrf="{{ csrf_token() }}"
      data-pending="{{ max(0, $locations->total() - (int) $vectorIndexedCount) }}"
    >
      <i class="bx bx-data me-1"></i> Вектор
      <span class="badge bg-label-dark ms-1">{{ (int) $vectorIndexedCount }}</span>
    </button>
    <button type="submit" form="locationsBulkDeleteForm" class="btn btn-icon btn-outline-danger" title="Delete" onclick="return confirm('Delete selected?');"><i class="bx bx-trash bx-md"></i></button>
    <a href="{{ route('admin.locations.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>

<div class="facts-pipeline-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold facts-pipeline-label">Разбивка на факты</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold facts-pipeline-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary facts-pipeline-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary facts-pipeline-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted facts-pipeline-status">—</div>
  <div class="small text-danger facts-pipeline-error mt-1" style="display:none;"></div>
</div>

<div class="vector-pipeline-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold vector-pipeline-label">Вектор мест</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold vector-pipeline-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary vector-pipeline-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success vector-pipeline-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted vector-pipeline-status">—</div>
  <div class="small text-danger vector-pipeline-error mt-1" style="display:none;"></div>
</div>

<form id="locationsBulkDeleteForm" method="post" action="{{ route('admin.locations.bulk-delete') }}">@csrf</form>

<form method="get" action="{{ route('admin.locations.index') }}" class="card mb-3">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-lg-3">
        <label class="form-label mb-1" for="locationsSearch">Поиск по model или названию</label>
        <input type="text" class="form-control" id="locationsSearch" name="q" value="{{ $search }}" placeholder="135 или Palermo" autocomplete="off">
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label mb-1">Страна (производитель)</label>
        <select class="form-select" name="manufacturer_id" onchange="this.form.submit()">
          <option value="">Все страны</option>
          @foreach($manufacturers as $man)
            <option value="{{ $man->id }}" {{ (int) $manufacturerId === (int) $man->id ? 'selected' : '' }}>{{ $man->nameFor($defaultLanguage) }} ({{ $man->locations_count }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label mb-1">Категория (область)</label>
        <select class="form-select" name="category_id" onchange="this.form.submit()">
          <option value="">Все области</option>
          @foreach($regionCategories as $opt)
            <option value="{{ $opt['id'] }}" {{ (int) $categoryId === (int) $opt['id'] ? 'selected' : '' }}>{{ $opt['label'] }}@if(isset($opt['count'])) ({{ $opt['count'] }})@endif</option>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label mb-1">Атрибут</label>
        <select class="form-select" name="attribute_id" onchange="this.form.submit()">
          <option value="">Все атрибуты</option>
          @foreach($filterAttributes->groupBy('attribute_group_id') as $groupAttrs)
            <optgroup label="{{ $groupAttrs->first()->group?->nameFor($defaultLanguage) }}">
              @foreach($groupAttrs as $attr)
                <option value="{{ $attr->id }}" {{ (int) $attributeId === (int) $attr->id ? 'selected' : '' }}>{{ $attr->nameFor($defaultLanguage) }}</option>
              @endforeach
            </optgroup>
          @endforeach
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label class="form-label mb-1" for="locationsFacts">Проверка фактов</label>
        <select class="form-select" id="locationsFacts" name="facts" onchange="this.form.submit()">
          @foreach($filterOptions as $key => $label)
            <option value="{{ $key }}" {{ $factsStatus === $key ? 'selected' : '' }}>{{ $label }} ({{ $countFor($key) }})</option>
          @endforeach
        </select>
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary">Найти</button>
        @if($search !== '' || $factsStatus !== '' || $manufacturerId || $categoryId || $attributeId)
          <a href="{{ route('admin.locations.index') }}" class="btn btn-outline-secondary">Сброс</a>
        @endif
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive text-nowrap">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width:40px"><input type="checkbox" class="form-check-input" id="selectAll"></th>
          <th>Model</th>
          <th style="max-width:280px">Name</th>
          <th>Страна</th>
          <th>Область</th>
          <th>Lat / Lng</th>
          <th>Факты</th>
          <th>Проверка</th>
          <th>Вектор</th>
          <th>Sort</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="table-border-bottom-0">
        @forelse($locations as $item)
          @php $d = $item->descriptions->firstWhere('language_id', $defaultLanguage?->id) ?? $item->descriptions->first(); $st = $statuses[$item->facts_status] ?? null; @endphp
          <tr>
            <td><input type="checkbox" name="selected[]" value="{{ $item->id }}" class="form-check-input row-check" form="locationsBulkDeleteForm"></td>
            <td>{{ $item->model ?? '—' }}</td>
            <td class="text-truncate" style="max-width:280px" dir="auto" title="{{ $d->name ?? '' }}">{{ $d->name ?? '—' }}</td>
            <td class="small">{{ $item->manufacturer?->nameFor($defaultLanguage) ?? '—' }}</td>
            <td class="small">{{ $item->categories->map(fn ($c) => optional($c->descriptions->firstWhere('language_id', $defaultLanguage?->id) ?? $c->descriptions->first())->name)->filter()->implode(', ') ?: '—' }}</td>
            <td class="small text-muted">
              @if($item->lat !== null && $item->lng !== null)
                {{ $item->lat }}, {{ $item->lng }}
              @else
                —
              @endif
            </td>
            <td>
              @if($item->facts_count)
                <span class="badge bg-label-success">{{ $item->facts_count }}</span>
              @else
                <span class="text-muted">—</span>
              @endif
            </td>
            <td>
              @if($st)
                <a href="{{ route('admin.locations.edit', $item->id) }}#facts" class="badge {{ $st['class'] }}">{{ $st['label'] }}@if($item->facts_coverage !== null) · {{ $item->facts_coverage }}%@endif</a>
              @elseif($item->facts_split_at)
                <span class="text-muted small">не проверено</span>
              @else
                <span class="text-muted">—</span>
              @endif
            </td>
            <td>
              @if(!empty($vectorIndexed[$item->id]))
                <span class="badge bg-label-success">да</span>
              @else
                <span class="text-muted">—</span>
              @endif
            </td>
            <td>{{ $item->sort_order }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.locations.edit', $item->id) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.locations.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Delete"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="11" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($locations->total() > 0)
  <div class="card-footer">
    <div class="row align-items-center g-2">
      <div class="col-12 col-md-auto text-center text-md-start">
        <span class="text-muted small">
          {{ $locations->firstItem() }}–{{ $locations->lastItem() }} / {{ $locations->total() }}
          <span class="ms-1">(по 50)</span>
        </span>
      </div>
      <div class="col-12 col-md">
        @if($locations->hasPages())
          {{ $locations->withQueryString()->onEachSide(2)->links() }}
        @endif
      </div>
    </div>
  </div>
  @endif
</div>
@endsection

@section('scripts')
<script>
  $('#selectAll').on('change', function () { $('.row-check').prop('checked', this.checked); });

  (function () {
    const btn = document.querySelector('.facts-pipeline-start-btn');
    const box = document.querySelector('.facts-pipeline-progress');
    const pctEl = document.querySelector('.facts-pipeline-pct');
    const bar = document.querySelector('.facts-pipeline-bar');
    const statusEl = document.querySelector('.facts-pipeline-status');
    const errEl = document.querySelector('.facts-pipeline-error');
    const cancelBtn = document.querySelector('.facts-pipeline-cancel-btn');
    if (!btn) return;
    let timer = null;

    async function post(url) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': btn.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok && res.status !== 409) throw new Error(data.message || ('HTTP ' + res.status));
      return data;
    }

    function render(s) {
      const total = Number(s.total || 0);
      const done = Number(s.done || 0) + Number(s.failed || 0);
      const pct = total > 0 ? Math.round(done / total * 100) : 0;
      bar.style.width = pct + '%';
      pctEl.textContent = pct + '%';
      statusEl.textContent = 'Локаций: ' + done + ' / ' + total
        + ' · OK ' + (s.ok || 0) + ' · посмотреть ' + (s.warn || 0) + ' · проверить ' + (s.bad || 0)
        + (s.failed ? (' · ошибок ' + s.failed) : '')
        + (s.current ? (' · сейчас: ' + s.current) : '');
      if (s.last_error) { errEl.style.display = 'block'; errEl.textContent = s.last_error; } else { errEl.style.display = 'none'; }
      if (s.state === 'running') {
        box.style.display = 'block';
        btn.disabled = true;
        bar.classList.add('progress-bar-animated');
      } else {
        btn.disabled = false;
        bar.classList.remove('progress-bar-animated');
        if (timer && (s.state === 'done' || s.state === 'cancelled')) {
          clearInterval(timer);
          timer = null;
          statusEl.textContent = (s.state === 'done' ? 'Готово. ' : 'Остановлено. ') + statusEl.textContent;
        }
      }
    }

    async function poll() {
      const res = await fetch(btn.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
      const s = await res.json().catch(() => ({}));
      render(s);
      return s;
    }

    function startPolling() {
      if (timer) clearInterval(timer);
      poll();
      timer = setInterval(poll, 4000);
    }

    btn.addEventListener('click', async function () {
      const pending = Number(btn.dataset.pending || 0);
      if (!confirm('Разбить и проверить ' + pending + ' неразбитых локаций? Нужен запущенный worker: php artisan queue:work')) return;
      try {
        const data = await post(btn.dataset.url);
        if (!data.started) { alert(data.message || 'Нечего разбивать'); return; }
        startPolling();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    cancelBtn.addEventListener('click', async function () {
      if (!confirm('Остановить разбивку?')) return;
      try { await post(btn.dataset.cancelUrl); await poll(); } catch (e) { alert(e.message || String(e)); }
    });

    poll().then(function (s) { if (s.state === 'running') startPolling(); });
  })();

  (function () {
    const btn = document.querySelector('.vector-pipeline-start-btn');
    const box = document.querySelector('.vector-pipeline-progress');
    const pctEl = document.querySelector('.vector-pipeline-pct');
    const bar = document.querySelector('.vector-pipeline-bar');
    const statusEl = document.querySelector('.vector-pipeline-status');
    const errEl = document.querySelector('.vector-pipeline-error');
    const cancelBtn = document.querySelector('.vector-pipeline-cancel-btn');
    if (!btn) return;
    let timer = null;

    async function post(url) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': btn.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok && res.status !== 409) throw new Error(data.message || ('HTTP ' + res.status));
      return data;
    }

    function render(s) {
      const total = Number(s.total || 0);
      const done = Number(s.done || 0) + Number(s.failed || 0);
      const pct = total > 0 ? Math.round(done / total * 100) : 0;
      bar.style.width = pct + '%';
      pctEl.textContent = pct + '%';
      statusEl.textContent = 'Локаций: ' + done + ' / ' + total
        + ' · в базе ' + (s.indexed || 0)
        + ' · кусков ' + (s.chunks || 0)
        + (s.failed ? (' · ошибок ' + s.failed) : '')
        + (s.current ? (' · сейчас: ' + s.current) : '');
      if (s.last_error) { errEl.style.display = 'block'; errEl.textContent = s.last_error; } else { errEl.style.display = 'none'; }
      if (s.state === 'running') {
        box.style.display = 'block';
        btn.disabled = true;
        bar.classList.add('progress-bar-animated');
      } else {
        btn.disabled = false;
        bar.classList.remove('progress-bar-animated');
        if (timer && (s.state === 'done' || s.state === 'cancelled')) {
          clearInterval(timer);
          timer = null;
          statusEl.textContent = (s.state === 'done' ? 'Готово. ' : 'Остановлено. ') + statusEl.textContent;
        }
      }
    }

    async function poll() {
      const res = await fetch(btn.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
      const s = await res.json().catch(() => ({}));
      render(s);
      return s;
    }

    function startPolling() {
      if (timer) clearInterval(timer);
      poll();
      timer = setInterval(poll, 4000);
    }

    btn.addEventListener('click', async function () {
      const pending = Number(btn.dataset.pending || 0);
      if (!confirm('Положить в вектор ' + pending + ' локаций без эмбеддинга? Нужен worker: php artisan queue:work-forever')) return;
      try {
        const data = await post(btn.dataset.url);
        if (!data.started) { alert(data.message || 'Нечего класть'); return; }
        startPolling();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    cancelBtn.addEventListener('click', async function () {
      if (!confirm('Остановить вектор?')) return;
      try { await post(btn.dataset.cancelUrl); await poll(); } catch (e) { alert(e.message || String(e)); }
    });

    poll().then(function (s) { if (s.state === 'running') startPolling(); });
  })();
</script>
@endsection
