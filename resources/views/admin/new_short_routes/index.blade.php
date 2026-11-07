@extends('admin.layouts.layout')
@section('content')
@php
  $pendingRu = (int) ($pendingRu ?? 0);
  $pendingVector = (int) ($pendingVector ?? 0);
  $vectorIndexed = (int) ($vectorIndexed ?? 0);
  $reverseReady = (int) ($reverseReady ?? 0);
  $reversePending = (int) ($reversePending ?? 0);
@endphp
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bx bx-file-blank text-info me-1"></i>
    New Short routes
    <span class="text-muted fw-normal small">· из папки doc (Word)</span>
    <span class="badge bg-label-info rounded-pill">{{ $total }}</span>
    @if($pendingRu > 0)
      <span class="badge bg-label-warning rounded-pill" title="Ещё без русского">без RU: {{ $pendingRu }}</span>
    @endif
    <span class="badge bg-label-success rounded-pill" title="Уже в PostgreSQL RAG">RAG: {{ $vectorIndexed }}</span>
    <span class="badge bg-label-danger rounded-pill" title="Обратный разбор готов">RE: {{ $reverseReady }}</span>
    @if($reversePending > 0)
      <span class="badge bg-label-secondary rounded-pill" title="Ещё без RE">без RE: {{ $reversePending }}</span>
    @endif
  </h4>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-info text-white nsr-scan-btn"
            title="Шаг 1: взять Word из doc/*.docx"
            data-url="{{ route('admin.new-short-routes.scan') }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-folder-open me-1"></i> 1. Скан Word
    </button>
    <button type="button" class="btn btn-warning nsr-gemini-batch-btn"
            title="Шаг 2: ночёвки по смыслу (иврит)"
            data-url="{{ route('admin.new-short-routes.gemini-overnights-batch') }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-bot me-1"></i> 2. Gemini ночёвки
    </button>
    <button type="button" class="btn btn-outline-primary nsr-translate-batch-btn"
            title="Шаг 3: перевод HE → RU (с прогрессом, когда много)"
            data-url="{{ route('admin.new-short-routes.translate-ru-batch') }}"
            data-csrf="{{ csrf_token() }}"
            data-pending="{{ $pendingRu }}">
      <i class="bx bx-globe me-1"></i> 3. Перевод на русский
    </button>
    <button type="button" class="btn btn-outline-success nsr-vector-start-btn"
            title="Шаг 4: только ещё не залитые дни → PostgreSQL RAG (продолжает с места остановки)"
            data-url="{{ route('admin.new-short-routes.vector-pipeline.start') }}"
            data-cancel-url="{{ route('admin.new-short-routes.vector-pipeline.cancel') }}"
            data-status-url="{{ route('admin.new-short-routes.vector-pipeline.status') }}"
            data-csrf="{{ csrf_token() }}"
            data-pending="{{ $pendingVector }}"
            data-indexed="{{ $vectorIndexed }}">
      <i class="bx bx-data me-1"></i> 4. В RAG
      <span class="badge bg-label-dark ms-1" title="ещё не в RAG">{{ $pendingVector }}</span>
    </button>
    <button type="button" class="btn btn-danger nsr-reverse-start-btn"
            title="5. Обратный разбор: короткий каркас + полный Word → JSON (Gemini, queue)"
            data-url="{{ route('admin.new-short-routes.reverse-pipeline.start') }}"
            data-cancel-url="{{ route('admin.new-short-routes.reverse-pipeline.cancel') }}"
            data-status-url="{{ route('admin.new-short-routes.reverse-pipeline.status', ['provider' => 'gemini']) }}"
            data-csrf="{{ csrf_token() }}"
            data-pending="{{ $reversePending }}">
      <i class="bx bx-search-alt me-1"></i> 5. RE Gemini
      <span class="badge bg-dark ms-1">{{ $reversePending }}</span>
    </button>
    <button type="button" class="btn btn-outline-danger nsr-reverse-clear-btn"
            title="Снести все RE (New Short + старые FT Short)"
            data-url="{{ route('admin.new-short-routes.reverse-principles.clear') }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-trash me-1"></i> Снести RE
    </button>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

{{-- Процесс из Word: видно шаги --}}
<div class="card mb-3 border-info">
  <div class="card-body py-3">
    <div class="fw-semibold mb-2"><i class="bx bx-git-branch me-1"></i> Процесс New Short (из Word)</div>
    <ol class="small mb-2 ps-3">
      <li><strong>Скан Word</strong> — из <code>doc/*.docx</code>: сверху маршрут как есть, снизу весь текст Word.</li>
      <li><strong>Карточка</strong> — два слоя без угаданных ночёвок/хабов (1:1 из Word).</li>
      <li><strong>Перевод на русский</strong> — HE → RU пачкой; прогресс ниже, когда много.</li>
      <li><strong>В RAG</strong> — дни в PostgreSQL (эмбеддинги, иврит), без локаций. Повторный запуск <strong>не сначала</strong>: уже залитое пропускает, догоняет только «ещё». Free: пауза ~20с, при 429 ~90с. Нужен <code>queue:work</code>.</li>
      <li><strong>RE Gemini</strong> — в модель уходит <strong>краткий каркас + полный текст Word</strong> → JSON обратной инженерии. Paid Gemini. Нужен <code>queue:work</code>.</li>
    </ol>
    <div class="small text-muted">
      Старые Short routes / FT Short RE — не рабочий контур. <code>he / ru</code> слева — язык списка.
      Без RU: <strong>{{ $pendingRu }}</strong> · RAG: <strong>{{ $vectorIndexed }}</strong> · RE готово: <strong>{{ $reverseReady }}</strong> · без RE: <strong>{{ $reversePending }}</strong>.
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Промпт обратного разбора (русский → ответ на иврите)</div>
      <div class="small text-muted">Общий промпт для New Short RE. «Сохранить» пишет в БД и не затирается при обновлении страницы.</div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span id="nsrReversePromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="nsrReversePromptReset">Сброс</button>
      <button type="button" class="btn btn-sm btn-primary" id="nsrReversePromptSave">
        <i class="bx bx-save me-1"></i> Сохранить
      </button>
    </div>
  </div>
  <div class="card-body pt-0">
    <textarea id="nsrReversePromptInput" class="form-control font-monospace" rows="16" spellcheck="false" dir="ltr">{{ $reversePrompt ?? '' }}</textarea>
  </div>
</div>

<div class="nsr-reverse-progress border rounded p-3 mb-3 bg-label-danger" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold nsr-reverse-label">RE New Short (каркас + Word → JSON)</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold nsr-reverse-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary nsr-reverse-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-danger nsr-reverse-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted nsr-reverse-status">—</div>
  <div class="small text-danger nsr-reverse-error mt-1" style="display:none;"></div>
</div>

<div class="nsr-vector-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold nsr-vector-label">RAG New Short (дни → Postgres)</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold nsr-vector-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary nsr-vector-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success nsr-vector-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted nsr-vector-status">—</div>
  <div class="small text-danger nsr-vector-error mt-1" style="display:none;"></div>
</div>

<div class="nsr-translate-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="fw-semibold nsr-translate-label">Перевод New Short (из Word) → RU</div>
    <div class="fw-bold nsr-translate-pct">0%</div>
  </div>
  <div class="progress mb-1" style="height:10px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary nsr-translate-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small nsr-translate-status">Подготовка…</div>
</div>

<div class="nsr-overnight-progress border rounded p-3 mb-3 bg-label-warning" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="fw-semibold nsr-overnight-label">Gemini ночёвки</div>
    <div class="fw-bold nsr-overnight-pct">…</div>
  </div>
  <div class="small nsr-overnight-status">Ждём ответ Gemini…</div>
</div>

<form method="get" action="{{ route('admin.new-short-routes.index') }}" class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div style="width:9rem">
      <label class="form-label small mb-1">Номер</label>
      <input type="text" name="num" value="{{ $num ?? '' }}" class="form-control" placeholder="id / код" inputmode="numeric">
    </div>
    <div class="flex-grow-1" style="min-width:12rem">
      <label class="form-label small mb-1">Поиск</label>
      <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="название, файл…">
    </div>
    <div style="width:7rem">
      <label class="form-label small mb-1">Дней</label>
      <input type="number" name="days" value="{{ $days }}" class="form-control" min="1" placeholder="10">
    </div>
    <button type="submit" class="btn btn-primary">Найти</button>
    @if(($num ?? '') !== '' || $q !== '' || ($days !== null && $days !== ''))
      <a href="{{ route('admin.new-short-routes.index') }}" class="btn btn-outline-secondary">Сброс</a>
    @endif
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width:2.5rem" class="text-center">
            <input type="checkbox" class="form-check-input nsr-select-all" title="Выбрать страницу">
          </th>
          <th>#</th>
          <th>Код</th>
          <th>Название</th>
          <th>Дней</th>
          <th>Прилёт → выезд</th>
          <th>RE</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $item)
          @php
            $useRu = !empty($showRu);
            $title = $useRu
              ? (trim((string)($item->title_ru ?? '')) !== '' ? $item->title_ru : ($item->title ?: '—'))
              : ($item->title ?: '—');
            $arrival = $useRu
              ? (trim((string)($item->arrival_hub_ru ?? '')) !== '' ? $item->arrival_hub_ru : ($item->arrival_hub ?: '—'))
              : ($item->arrival_hub ?: '—');
            $depart = $useRu
              ? (trim((string)($item->departure_hub_ru ?? '')) !== '' ? $item->departure_hub_ru : ($item->departure_hub ?: '—'))
              : ($item->departure_hub ?: '—');
            $showUrl = route('admin.new-short-routes.show', [$item, 'lang' => $useRu ? 'ru' : 'he']);
            $hasRu = $item->hasRussian();
            $hasRe = $item->hasReversePrinciple();
          @endphp
          <tr>
            <td class="text-center">
              <input type="checkbox" class="form-check-input nsr-row-check" value="{{ $item->id }}">
            </td>
            <td title="id={{ $item->id }}">{{ $item->id }}</td>
            <td>
              @if($item->doc_code)
                <span class="badge bg-info text-white" title="4 цифры из имени файла">{{ $item->doc_code }}</span>
              @else
                —
              @endif
            </td>
            <td>
              <a href="{{ $showUrl }}" class="fw-medium text-heading" dir="auto">
                <i class="bx bx-file me-1 text-info"></i>{{ $title }}
              </a>
              <span class="badge {{ $useRu ? 'bg-label-success' : 'bg-label-secondary' }}">{{ $useRu ? 'RU' : 'HE' }}</span>
              @if(!$hasRu)
                <span class="badge bg-label-warning" title="Нет перевода">нет RU</span>
              @endif
            </td>
            <td>{{ $item->days_count }}</td>
            <td class="small" dir="auto">
              {{ $arrival }}
              →
              {{ $depart }}
            </td>
            <td>
              @if($hasRe)
                <span class="badge bg-danger">RE</span>
              @else
                <span class="text-muted small">—</span>
              @endif
            </td>
            <td class="text-nowrap">
              <a href="{{ $showUrl }}" class="btn btn-icon btn-sm btn-outline-info" title="Открыть">
                <i class="bx bx-show"></i>
              </a>
              <form action="{{ route('admin.new-short-routes.destroy', $item) }}" method="post" class="d-inline"
                    onsubmit="return confirm('Удалить New Short #{{ $item->id }}?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Удалить">
                  <i class="bx bx-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-muted py-4">Пусто — нажмите «1. Скан Word»</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($items->hasPages())
    <div class="card-footer">{{ $items->links() }}</div>
  @endif
</div>
@endsection

@section('scripts')
<script>
(function () {
  const csrf = '{{ csrf_token() }}';

  // --- 3. Перевод RU с прогрессом (по 1 маршруту из Word) ---
  const trBatch = document.querySelector('.nsr-translate-batch-btn');
  if (trBatch) {
    const box = document.querySelector('.nsr-translate-progress');
    const label = document.querySelector('.nsr-translate-label');
    const pctEl = document.querySelector('.nsr-translate-pct');
    const bar = document.querySelector('.nsr-translate-bar');
    const status = document.querySelector('.nsr-translate-status');

    trBatch.addEventListener('click', async function () {
      let startRemaining = parseInt(trBatch.dataset.pending || '0', 10) || 0;
      const raw = prompt('Сколько New Short перевести сейчас? (из Word, без RU)', String(Math.min(20, Math.max(1, startRemaining || 5))));
      if (raw === null) return;
      const maxRoutes = parseInt(String(raw).trim(), 10) || 5;
      if (!confirm('Перевод HE → RU до ' + maxRoutes + ' маршрутов.\nБудет виден прогресс (по одному из Word).')) return;

      trBatch.disabled = true;
      box.style.display = '';
      label.textContent = 'Перевод New Short (из Word) → RU';
      let translated = 0;
      let safety = Math.max(50, maxRoutes * 2);

      try {
        while (safety-- > 0 && translated < maxRoutes) {
          const res = await fetch(trBatch.dataset.url, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ routes: 1, only_pending: true }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok && data.remaining_routes === undefined) {
            throw new Error(data.message || ('HTTP ' + res.status));
          }

          const rem = data.remaining_routes ?? 0;
          if (startRemaining <= 0) {
            startRemaining = rem + (data.processed || 0);
          }
          translated += (data.processed || 0);
          const target = Math.min(maxRoutes, startRemaining || maxRoutes);
          const pct = target > 0 ? Math.min(100, Math.round(100 * translated / target)) : 100;
          pctEl.textContent = pct + '%';
          bar.style.width = pct + '%';
          status.textContent = 'готово ' + translated + ' / ' + target
            + (data.route_id ? (' · last #' + data.route_id) : '')
            + ' · осталось без RU: ' + rem;
          if (data.message) status.textContent += ' · ' + data.message;

          if (data.done || rem === 0 || (data.processed || 0) === 0) {
            label.textContent = 'Готово';
            status.textContent = 'Перевод завершён — обновляю список…';
            window.location.reload();
            return;
          }
        }
        label.textContent = 'Пауза / лимит';
        status.textContent = 'Сделано ' + translated + ' из запрошенных ' + maxRoutes + ' — обновляю…';
        window.location.reload();
      } catch (e) {
        label.textContent = 'Ошибка';
        status.textContent = e.message || String(e);
        trBatch.disabled = false;
      }
    });
  }

  // --- 2. Gemini ночёвки ---
  const gemBatch = document.querySelector('.nsr-gemini-batch-btn');
  if (gemBatch) {
    const box = document.querySelector('.nsr-overnight-progress');
    const label = document.querySelector('.nsr-overnight-label');
    const pctEl = document.querySelector('.nsr-overnight-pct');
    const status = document.querySelector('.nsr-overnight-status');
    gemBatch.addEventListener('click', async function () {
      const raw = prompt('Сколько New Short прогнать через Gemini (ночёвки)?', '5');
      if (raw === null) return;
      const limit = parseInt(String(raw).trim(), 10) || 5;
      if (!confirm('Шаг 2: ночёвки на ' + limit + ' маршрутах из Word.')) return;
      gemBatch.disabled = true;
      box.style.display = '';
      label.textContent = 'Gemini ночёвки…';
      pctEl.textContent = '…';
      status.textContent = 'Идёт разбор (может занять минуты)…';
      try {
        const res = await fetch(gemBatch.dataset.url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': gemBatch.dataset.csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ limit: limit }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        pctEl.textContent = '100%';
        status.textContent = (data.message || 'OK') + (data.details ? '\n' + data.details.join('\n') : '');
        window.location.reload();
      } catch (e) {
        label.textContent = 'Ошибка';
        status.textContent = e.message || String(e);
        gemBatch.disabled = false;
      }
    });
  }

  // --- 4. RAG vector pipeline ---
  (function () {
    const btn = document.querySelector('.nsr-vector-start-btn');
    const box = document.querySelector('.nsr-vector-progress');
    const pctEl = document.querySelector('.nsr-vector-pct');
    const bar = document.querySelector('.nsr-vector-bar');
    const statusEl = document.querySelector('.nsr-vector-status');
    const errEl = document.querySelector('.nsr-vector-error');
    const cancelBtn = document.querySelector('.nsr-vector-cancel-btn');
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
      statusEl.textContent = 'Маршрутов: ' + done + ' / ' + total
        + ' · в RAG ' + (s.indexed || 0)
        + ' · кусков (дней) ' + (s.chunks || 0)
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
      const indexed = Number(btn.dataset.indexed || 0);
      if (!confirm(
        'Продолжить RAG с места остановки?\n'
        + 'Уже в RAG: ' + indexed + '\n'
        + 'Осталось догнать: ' + pending + '\n'
        + '(уже залитое не пересчитываем)\n'
        + 'Free-ключ: паузы ~20с, при 429 ~90с.\n'
        + 'Нужен worker: php artisan queue:work'
      )) return;
      try {
        const data = await post(btn.dataset.url);
        if (!data.started) { alert(data.message || 'Нечего класть'); return; }
        startPolling();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    cancelBtn.addEventListener('click', async function () {
      if (!confirm('Остановить RAG?')) return;
      try { await post(btn.dataset.cancelUrl); await poll(); } catch (e) { alert(e.message || String(e)); }
    });

    poll().then(function (s) { if (s.state === 'running') startPolling(); });
  })();

  // --- 1. Скан Word ---
  const btn = document.querySelector('.nsr-scan-btn');
  if (btn) btn.addEventListener('click', async function () {
    const raw = prompt('Сколько docx просканировать? (с 2023+, новее первыми)', '30');
    if (raw === null) return;
    const limit = parseInt(String(raw).trim(), 10);
    if (!Number.isFinite(limit) || limit < 1) { alert('Нужно число ≥ 1'); return; }
    if (!confirm('Шаг 1: скан ' + limit + ' файлов из doc/ (Word).')) return;
    btn.disabled = true;
    try {
      const res = await fetch(btn.dataset.url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': btn.dataset.csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ limit: limit, since_2023: true, parse_body: true }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
      alert(data.message || 'OK');
      window.location.reload();
    } catch (e) {
      alert(e.message || String(e));
      btn.disabled = false;
    }
  });

  // --- Выбор строк ---
  const selectAll = document.querySelector('.nsr-select-all');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.nsr-row-check').forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  }

  function selectedIds() {
    return Array.from(document.querySelectorAll('.nsr-row-check:checked'))
      .map(function (cb) { return parseInt(cb.value, 10); })
      .filter(function (id) { return id > 0; });
  }

  // --- 5. RE Gemini (каркас + Word) ---
  (function () {
    const startBtn = document.querySelector('.nsr-reverse-start-btn');
    const clearBtn = document.querySelector('.nsr-reverse-clear-btn');
    const box = document.querySelector('.nsr-reverse-progress');
    const pctEl = document.querySelector('.nsr-reverse-pct');
    const bar = document.querySelector('.nsr-reverse-bar');
    const statusEl = document.querySelector('.nsr-reverse-status');
    const errEl = document.querySelector('.nsr-reverse-error');
    const cancelBtn = document.querySelector('.nsr-reverse-cancel-btn');
    if (!startBtn) return;
    let timer = null;

    async function postJson(url, body) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': startBtn.dataset.csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body || {}),
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
      statusEl.textContent = 'Готово ' + done + ' / ' + total
        + (s.failed ? (' · ошибок ' + s.failed) : '')
        + (s.current ? (' · сейчас: ' + s.current) : '')
        + (s.reverse_ready != null ? (' · RE в БД: ' + s.reverse_ready) : '');
      if (s.last_error) { errEl.style.display = 'block'; errEl.textContent = s.last_error; }
      else { errEl.style.display = 'none'; }
      if (s.state === 'running') {
        box.style.display = 'block';
        startBtn.disabled = true;
        bar.classList.add('progress-bar-animated');
      } else {
        startBtn.disabled = false;
        bar.classList.remove('progress-bar-animated');
        if (timer && (s.state === 'done' || s.state === 'cancelled' || s.state === 'paused')) {
          clearInterval(timer);
          timer = null;
          statusEl.textContent = (s.state === 'done' ? 'Готово. ' : (s.state === 'paused' ? 'Пауза. ' : 'Остановлено. ')) + statusEl.textContent;
        }
      }
    }

    async function poll() {
      const res = await fetch(startBtn.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
      const s = await res.json().catch(() => ({}));
      render(s);
      return s;
    }

    function startPolling() {
      if (timer) clearInterval(timer);
      box.style.display = 'block';
      poll();
      timer = setInterval(poll, 5000);
    }

    startBtn.addEventListener('click', async function () {
      const ids = selectedIds();
      const pending = Number(startBtn.dataset.pending || 0);
      let msg;
      let body;
      if (ids.length > 0) {
        msg = 'RE Gemini для выбранных ' + ids.length + ' New Short?\nВ модель: короткий каркас + полный Word.\nНужен queue:work и paid Gemini.';
        body = { provider: 'gemini', ids: ids, only_pending: false };
      } else {
        msg = 'RE Gemini для всех без RE (' + pending + ' шт.)?\nВ модель: короткий каркас + полный Word.\nНужен queue:work и paid Gemini.';
        body = { provider: 'gemini', only_pending: true };
      }
      if (!confirm(msg)) return;
      try {
        const data = await postJson(startBtn.dataset.url, body);
        if (!data.started) { alert(data.message || 'Нечего разбирать'); return; }
        startPolling();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    if (cancelBtn) {
      cancelBtn.addEventListener('click', async function () {
        if (!confirm('Остановить RE New Short?')) return;
        try {
          await postJson(startBtn.dataset.cancelUrl, { provider: 'gemini' });
          await poll();
        } catch (e) {
          alert(e.message || String(e));
        }
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', async function () {
        if (!confirm('Снести ВСЕ reverse_principle у New Short и старые RE у FT Short?\nЭто необратимо.')) return;
        clearBtn.disabled = true;
        try {
          const data = await postJson(clearBtn.dataset.url, { also_ft: true });
          alert(data.message || 'OK');
          window.location.reload();
        } catch (e) {
          alert(e.message || String(e));
          clearBtn.disabled = false;
        }
      });
    }

    poll().then(function (s) { if (s.state === 'running') startPolling(); });
  })();

  // --- Промпт RE (общий с FT Short, хранится в AppSetting) ---
  (function () {
    const promptInput = document.getElementById('nsrReversePromptInput');
    const promptStatus = document.getElementById('nsrReversePromptStatus');
    const promptSave = document.getElementById('nsrReversePromptSave');
    const promptReset = document.getElementById('nsrReversePromptReset');
    if (!promptSave || !promptInput) return;
    const promptSaveUrl = @json(route('admin.ft-short-routes.reverse-prompt.save'));
    const promptResetUrl = @json(route('admin.ft-short-routes.reverse-prompt.reset'));

    promptSave.addEventListener('click', async function () {
      promptSave.disabled = true;
      if (promptStatus) promptStatus.textContent = 'Сохраняю…';
      try {
        const res = await fetch(promptSaveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ prompt: promptInput.value, lang: 'ru', _token: csrf }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          const ve = data.errors && data.errors.prompt ? data.errors.prompt[0] : null;
          throw new Error(ve || data.message || ('HTTP ' + res.status + (res.status === 419 ? ' (CSRF — обнови страницу)' : '')));
        }
        if (typeof data.prompt === 'string') promptInput.value = data.prompt;
        if (promptStatus) {
          promptStatus.textContent = (data.message || 'Промпт сохранён')
            + (data.chars ? (' · ' + data.chars + ' симв.') : '');
        }
      } catch (e) {
        if (promptStatus) promptStatus.textContent = e.message || String(e);
      } finally {
        promptSave.disabled = false;
      }
    });

    if (promptReset) {
      promptReset.addEventListener('click', async function () {
        if (!confirm('Сбросить промпт к заводскому?')) return;
        promptReset.disabled = true;
        try {
          const res = await fetch(promptResetUrl + '?lang=ru', {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ lang: 'ru' }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
          if (data.prompt) promptInput.value = data.prompt;
          if (promptStatus) promptStatus.textContent = data.message || 'Сброшено';
        } catch (e) {
          if (promptStatus) promptStatus.textContent = e.message || String(e);
        } finally {
          promptReset.disabled = false;
        }
      });
    }
  })();
})();
</script>
@endsection
