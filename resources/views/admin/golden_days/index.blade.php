@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0 d-flex align-items-center gap-2">
    Golden days
    <span class="badge bg-label-primary rounded-pill">{{ $days->total() }}</span>
    @if(($pendingRu ?? 0) > 0)
      <span class="badge bg-label-warning rounded-pill" title="Без русского title">RU pending {{ $pendingRu }}</span>
    @endif
  </h4>
  <div class="page-toolbar d-flex flex-wrap gap-1">
    <a href="{{ route('admin.golden-days.create') }}" class="btn btn-primary">
      <i class="bx bx-plus me-1"></i> Новый день
    </a>
    <form method="post" action="{{ route('admin.golden-days.sync-hub-labels') }}" class="d-inline"
          onsubmit="return confirm('Проставить метки hub-* / junk всем дням по колонке Hub?');">
      @csrf
      <button type="submit" class="btn btn-outline-secondary" title="hub_key → Golden labels">
        <i class="bx bx-purchase-tag me-1"></i> Метки из Hub
      </button>
    </form>
    <button
      type="button"
      class="btn btn-outline-warning gd-reverse-pipeline-start-btn"
      title="Gemini: почему так сложен день + метки (кошер/дети/ночёвка). Нужен queue:work"
      data-url="{{ route('admin.golden-days.reverse-pipeline.start') }}"
      data-cancel-url="{{ route('admin.golden-days.reverse-pipeline.cancel') }}"
      data-status-url="{{ route('admin.golden-days.reverse-pipeline.status') }}"
      data-csrf="{{ csrf_token() }}"
      data-country="{{ $country ?? 'UA' }}"
      data-pending="{{ (int) ($reversePending ?? 0) }}"
    >
      <i class="bx bx-search-alt me-1"></i> Обратный разбор
      <span class="badge bg-label-dark ms-1">{{ (int) ($reversePending ?? 0) }}</span>
    </button>
    <button type="button"
            class="btn btn-outline-primary golden-translate-all-btn"
            data-url="{{ route('admin.golden-days.translate-ru-batch') }}"
            @if(($pendingRu ?? 0) === 0) disabled @endif>
      <i class="bx bx-globe me-1"></i> Перевод всех на русский
    </button>
    <form method="post" action="{{ route('admin.golden-days.rebuild') }}" onsubmit="return confirm('Пересобрать golden days из Short routes (свежий импорт, дубли сольются)?');">
      @csrf
      <input type="hidden" name="fresh" value="1">
      <button type="submit" class="btn btn-outline-primary" title="Rebuild from route skeletons">
        <i class="bx bx-refresh me-1"></i> Rebuild from skeletons
      </button>
    </form>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="gd-reverse-pipeline-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold gd-reverse-pipeline-label">Обратный разбор golden days</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold gd-reverse-pipeline-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary gd-reverse-pipeline-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning gd-reverse-pipeline-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted gd-reverse-pipeline-status">—</div>
  <div class="small text-danger gd-reverse-pipeline-error mt-1" style="display:none;"></div>
</div>

<div class="golden-translate-all-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
  <div class="d-flex justify-content-between mb-1">
    <div class="fw-semibold golden-translate-all-label">Перевод golden days…</div>
    <div class="fw-bold golden-translate-all-pct">—</div>
  </div>
  <div class="progress mb-1" style="height:8px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary golden-translate-all-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small golden-translate-all-status">Подготовка…</div>
</div>

<form method="get" class="card mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-3 col-lg-2">
        <label class="form-label mb-1">Страна</label>
        <select name="country" class="form-select" onchange="this.form.submit()">
          <option value="UA" {{ ($country ?? '') === 'UA' ? 'selected' : '' }}>Ukraine</option>
          <option value="IT" {{ ($country ?? '') === 'IT' ? 'selected' : '' }}>Italy</option>
          <option value="ALL" {{ ($country ?? '') === 'ALL' ? 'selected' : '' }}>Все</option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label mb-1">Search</label>
        <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Title / overnight…">
      </div>
      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label mb-1">Регион</label>
        <select name="category_id" class="form-select" onchange="this.form.submit()">
          <option value="">Все регионы</option>
          @foreach(($regionOptions ?? []) as $reg)
            <option value="{{ $reg->id }}" {{ (int) ($categoryId ?? 0) === (int) $reg->id ? 'selected' : '' }}>
              {{ $reg->nameFor($defaultLanguage ?? null) }}
            </option>
          @endforeach
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">Find</button>
        @if($q !== '' || !empty($categoryId))
          <a href="{{ route('admin.golden-days.index', ['country' => $country ?? 'UA']) }}" class="btn btn-outline-secondary">Reset</a>
        @endif
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Title</th>
          <th>Region</th>
          <th>Hub</th>
          <th>Stops</th>
          <th>Usage</th>
          <th style="min-width:220px">Overnight</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($days as $item)
          @php $names = $item->stopNames(true); @endphp
          <tr>
            <td class="text-nowrap">{{ $item->id }}</td>
            <td style="max-width:280px;white-space:normal;word-break:break-word" dir="auto">
              {{ $item->displayTitle() }}
              @if($item->hasRussian())
                <span class="badge bg-label-success ms-1" style="font-size:9px">RU</span>
              @endif
              @if($item->labels->isNotEmpty())
                <div class="mt-1">                  @foreach($item->labels as $lab)
                    <span class="badge bg-label-info me-1" style="font-size:10px">{{ $lab->nameFor($defaultLanguage ?? null) }}</span>
                  @endforeach
                </div>
              @endif
            </td>
            <td class="small">{{ $item->category?->nameFor($defaultLanguage ?? null) ?: '—' }}</td>
            <td class="small text-nowrap">{{ $item->hub_key ?: '—' }}@if($item->junk_reason)<div class="text-danger" style="font-size:10px">{{ $item->junk_reason }}</div>@endif</td>
            <td class="small" style="max-width:420px;white-space:normal;word-break:break-word">
              <span class="badge bg-label-secondary">{{ $item->stops_count }}</span>
              <span class="text-muted ms-1" dir="auto">
                {{ implode(' → ', array_slice($names, 0, 4)) }}{{ count($names) > 4 ? '…' : '' }}
              </span>
            </td>
            <td class="text-nowrap">{{ $item->usage_count }}</td>
            <td class="small text-muted" style="white-space:normal;word-break:break-word;min-width:220px" dir="auto">
              @php $axis = $item->overnightAxis(true); @endphp
              {{ $axis !== '' ? $axis : '—' }}
            </td>
            <td class="text-nowrap">
              @if($item->is_active)
                <span class="badge bg-label-success">yes</span>
              @else
                <span class="badge bg-label-secondary">no</span>
              @endif
            </td>
            <td class="text-nowrap">
              <a href="{{ route('admin.golden-days.show', $item) }}#gd-edit"
                 class="btn btn-sm btn-primary">
                <i class="bx bx-edit me-1"></i>Редактировать
              </a>
              <button type="button"
                      class="btn btn-icon btn-sm btn-outline-primary golden-translate-one-btn"
                      title="Перевод на русский"
                      data-url="{{ route('admin.golden-days.translate-ru', $item) }}">
                <i class="bx bx-globe"></i>
              </button>
              <form action="{{ route('admin.golden-days.toggle', $item) }}" method="post" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-icon btn-sm btn-outline-secondary" title="Toggle active"><i class="bx bx-power-off"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-muted py-4">No golden days yet. Import Short routes, then Rebuild.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($days->hasPages())
    <div class="card-footer">{{ $days->withQueryString()->links() }}</div>
  @endif
</div>
@endsection

@section('scripts')
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    || '{{ csrf_token() }}';

  async function translateOne(url, onProgress) {
    let safety = 40;
    while (safety-- > 0) {
      const res = await fetch(url, {
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
      if (typeof onProgress === 'function') onProgress(data);
      if (!res.ok && !data.total) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      if (data.done) return data;
      if ((data.translated || 0) === 0 && (data.remaining || 0) > 0) {
        throw new Error(data.message || 'Gemini не вернул перевод');
      }
    }
    throw new Error('Слишком много итераций');
  }

  document.querySelectorAll('.golden-translate-one-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      try {
        await translateOne(btn.dataset.url);
        location.reload();
      } catch (e) {
        alert(e.message || String(e));
        btn.disabled = false;
      }
    });
  });

  (function () {
    const btn = document.querySelector('.gd-reverse-pipeline-start-btn');
    const box = document.querySelector('.gd-reverse-pipeline-progress');
    const pctEl = document.querySelector('.gd-reverse-pipeline-pct');
    const bar = document.querySelector('.gd-reverse-pipeline-bar');
    const statusEl = document.querySelector('.gd-reverse-pipeline-status');
    const errEl = document.querySelector('.gd-reverse-pipeline-error');
    const cancelBtn = document.querySelector('.gd-reverse-pipeline-cancel-btn');
    if (!btn) return;
    let timer = null;
    async function post(url, body) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': btn.dataset.csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body ? JSON.stringify(body) : undefined,
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
      statusEl.textContent = 'Дней: ' + done + ' / ' + total
        + ' · OK ' + (s.ok || 0)
        + (s.skipped ? (' · skip ' + s.skipped) : '')
        + (s.failed ? (' · ошибок ' + s.failed) : '')
        + (s.current ? (' · ' + s.current) : '');
      if (s.last_error) { errEl.style.display = 'block'; errEl.textContent = s.last_error; }
      else { errEl.style.display = 'none'; }
      if (s.state === 'running') {
        box.style.display = 'block';
        btn.disabled = true;
        bar.classList.add('progress-bar-animated');
      } else {
        btn.disabled = false;
        bar.classList.remove('progress-bar-animated');
        if (timer && (s.state === 'done' || s.state === 'cancelled')) {
          clearInterval(timer); timer = null;
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
      timer = setInterval(poll, 3000);
    }
    btn.addEventListener('click', async function () {
      const n = Number(btn.dataset.pending || 0);
      if (!confirm('Обратный разбор ' + n + ' golden days (фильтр страны на экране)? Нужен: php artisan queue:work. Не гоняйте параллельно тяжёлый RU.')) return;
      try {
        const data = await post(btn.dataset.url, { country: btn.dataset.country || 'ALL' });
        if (!data.started) { alert(data.message || 'Нечего разбирать'); return; }
        startPolling();
      } catch (e) { alert(e.message || String(e)); }
    });
    cancelBtn.addEventListener('click', async function () {
      if (!confirm('Остановить разбор?')) return;
      try { await post(btn.dataset.cancelUrl); await poll(); } catch (e) { alert(e.message || String(e)); }
    });
    poll().then(function (s) { if (s.state === 'running') startPolling(); });
  })();

  const allBtn = document.querySelector('.golden-translate-all-btn');
  if (!allBtn) return;
  const box = document.querySelector('.golden-translate-all-progress');
  const label = document.querySelector('.golden-translate-all-label');
  const pctEl = document.querySelector('.golden-translate-all-pct');
  const bar = document.querySelector('.golden-translate-all-bar');
  const status = document.querySelector('.golden-translate-all-status');
  let startRemaining = {{ (int) ($pendingRu ?? 0) }};

  allBtn.addEventListener('click', async function () {
    if (!confirm('Перевести все golden days без русского title? Это займёт время (тысячи запросов к Gemini).')) return;
    allBtn.disabled = true;
    box.style.display = '';
    label.textContent = 'Перевод golden days…';
    let safety = 20000;
    try {
      while (safety-- > 0) {
        const res = await fetch(allBtn.dataset.url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ days: 1 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok && data.remaining_days === undefined) {
          throw new Error(data.message || ('HTTP ' + res.status));
        }
        const rem = data.remaining_days ?? data.remaining ?? 0;
        if (startRemaining <= 0) startRemaining = rem + (data.processed || 0);
        const done = Math.max(0, startRemaining - rem);
        const pct = startRemaining > 0 ? Math.min(100, Math.round(100 * done / startRemaining)) : 0;
        pctEl.textContent = pct + '%';
        bar.style.width = pct + '%';
        status.textContent = 'готово дней ~' + done + ' · осталось ' + rem
          + (data.day_id ? (' · last #' + data.day_id) : '');
        if (data.message) status.textContent += ' · ' + data.message;
        if (data.done || rem === 0) {
          label.textContent = 'Готово';
          status.textContent = 'Все дни переведены — обновляю…';
          location.reload();
          return;
        }
        if ((data.processed || 0) === 0) {
          throw new Error(data.message || 'Нет прогресса');
        }
      }
      throw new Error('Слишком много итераций');
    } catch (e) {
      label.textContent = 'Ошибка';
      status.textContent = e.message || String(e);
      allBtn.disabled = false;
    }
  });
})();
</script>
@endsection
