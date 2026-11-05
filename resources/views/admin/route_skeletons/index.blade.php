@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">
    Short routes
    <span class="text-muted fw-normal small">· краткие каркасы директора</span>
    <span class="badge bg-label-primary rounded-pill">{{ $total }}</span>
  </h4>
  <div class="d-flex flex-wrap gap-2">
    <button
      type="button"
      class="btn btn-outline-danger sr-reverse-clear-btn"
      title="Удалить только reverse_principle (логика/RE). Маршруты и метки локаций не трогает."
      data-url="{{ route('admin.route-skeletons.reverse-principles.clear') }}"
      data-csrf="{{ csrf_token() }}"
      data-stored="{{ (int) ($reverseStored ?? 0) }}"
      @if((int) ($reverseStored ?? 0) < 1) disabled @endif
    >
      <i class="bx bx-trash me-1"></i> Снести RE
      <span class="badge bg-label-dark ms-1">{{ (int) ($reverseStored ?? 0) }}</span>
    </button>
    <a
      href="{{ route('admin.route-skeletons.export.finetune-jsonl') }}"
      class="btn btn-outline-success {{ (int) ($fineTuneReady ?? 0) < 1 ? 'disabled' : '' }}"
      title="Скачать JSONL для Gemini FineTunes: User=запрос, Model=мысли+[ROUTE]. Без ручного парсинга."
      @if((int) ($fineTuneReady ?? 0) < 1) aria-disabled="true" tabindex="-1" @endif
    >
      <i class="bx bx-download me-1"></i> FineTunes JSONL
      <span class="badge bg-label-dark ms-1">{{ (int) ($fineTuneReady ?? 0) }}</span>
    </a>
    <button
      type="button"
      class="btn btn-outline-warning sr-reverse-pipeline-start-btn"
      title="Gemini: вопрос + логика + дни. Старт с первого ключа в .env (free→paid). Можно лимит. Нужен queue:work"
      data-url="{{ route('admin.route-skeletons.reverse-pipeline.start') }}"
      data-cancel-url="{{ route('admin.route-skeletons.reverse-pipeline.cancel') }}"
      data-status-url="{{ route('admin.route-skeletons.reverse-pipeline.status') }}"
      data-csrf="{{ csrf_token() }}"
      data-pending="{{ (int) ($reversePending ?? 0) }}"
    >
      <i class="bx bx-search-alt me-1"></i> Принцип маршрута
      <span class="badge bg-label-dark ms-1">{{ (int) ($reversePending ?? 0) }}</span>
    </button>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card mb-3 border-warning">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <div>
      <div class="fw-semibold">Промпт обратного разбора (документ директора · Q→A для FunTunes)</div>
      <div class="small text-muted">
        Разбор директора + FineTunes: question коротко (хабы + дни ± пожелание, уникально), answer = дни с id.
        Готовых к FineTunes: <strong>{{ (int) ($fineTuneReady ?? 0) }}</strong>@if((int) ($fineTuneSkipped ?? 0) > 0), без разбора/дней: {{ (int) $fineTuneSkipped }}@endif.
        «Снести RE» — только старые разборы в БД (не путать со «Сброс» промпта). «FineTunes JSONL» = User + мысли + [ROUTE].
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span id="srReversePromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="srReversePromptReset" title="Вернуть текст по умолчанию">Сброс</button>
      <button type="button" class="btn btn-sm btn-primary" id="srReversePromptSave">
        <i class="bx bx-save me-1"></i> Сохранить
      </button>
    </div>
  </div>
  <div class="card-body pt-0">
    <textarea
      id="srReversePromptInput"
      class="form-control font-monospace"
      rows="18"
      spellcheck="false"
    >{{ $reversePrompt }}</textarea>
  </div>
</div>

<div class="sr-reverse-pipeline-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold sr-reverse-pipeline-label">Обратный разбор short routes</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold sr-reverse-pipeline-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary sr-reverse-pipeline-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning sr-reverse-pipeline-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted sr-reverse-pipeline-status">—</div>
  <div class="small text-danger sr-reverse-pipeline-error mt-1" style="display:none;"></div>
</div>

<form method="get" action="{{ route('admin.route-skeletons.index') }}" class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div class="flex-grow-1" style="min-width:12rem">
      <label class="form-label small mb-1">Поиск</label>
      <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="название, хаб, id, место…">
    </div>
    <div style="width:7rem">
      <label class="form-label small mb-1">Дней</label>
      <input type="number" name="days" value="{{ $days }}" class="form-control" min="1" placeholder="10">
    </div>
    <button type="submit" class="btn btn-primary">Найти</button>
    @if($q !== '' || $days !== null && $days !== '')
      <a href="{{ route('admin.route-skeletons.index') }}" class="btn btn-outline-secondary">Сброс</a>
    @endif
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Python id</th>
          <th>Название</th>
          <th>Дней</th>
          <th>Прилёт → выезд</th>
          <th>Старт</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>
              @if($item->source_route_id)
                <span class="badge bg-label-primary" title="Код маршрута (Python / папка)">{{ $item->source_route_id }}</span>
              @else
                —
              @endif
            </td>
            <td>
              <a href="{{ route('admin.route-skeletons.show', $item) }}" class="fw-medium text-heading">
                {{ $item->title_ru ?: ($item->title ?: '—') }}
              </a>
              @if($item->title_ru)
                <span class="badge bg-label-success">RU</span>
              @endif
              @if($item->is_template)
                <span class="badge bg-label-info">template</span>
              @endif
              @if(!empty($item->reverse_principle) && empty($item->reverse_principle['skipped']))
                <span class="badge bg-label-warning" title="Есть обратный разбор">RE</span>
              @endif
            </td>
            <td>{{ $item->days_count }}</td>
            <td class="small">
              {{ $item->arrival_hub ?: '—' }}
              →
              {{ $item->departure_hub ?: '—' }}
            </td>
            <td class="small text-nowrap">{{ $item->start_date?->format('d.m.Y') ?? '—' }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.route-skeletons.show', $item) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Открыть">
                <i class="bx bx-show"></i>
              </a>
              <form action="{{ route('admin.route-skeletons.destroy', $item) }}" method="post" class="d-inline"
                    onsubmit="return confirm('Удалить short route #{{ $item->id }}?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Удалить">
                  <i class="bx bx-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Нет данных — импорт: php artisan travel:import-route-skeletons</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($items->hasPages())
    <div class="card-footer">{{ $items->links() }}</div>
  @endif
</div>
<script>
(function () {
  const promptInput = document.getElementById('srReversePromptInput');
  const promptSave = document.getElementById('srReversePromptSave');
  const promptReset = document.getElementById('srReversePromptReset');
  const promptStatus = document.getElementById('srReversePromptStatus');
  const promptSaveUrl = @json(route('admin.route-skeletons.reverse-prompt.save'));
  const promptResetUrl = @json(route('admin.route-skeletons.reverse-prompt.reset'));
  const csrf = @json(csrf_token());
  async function savePrompt() {
    if (!promptInput) return;
    promptStatus.textContent = 'Сохраняю…';
    const res = await fetch(promptSaveUrl, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ prompt: promptInput.value }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
    promptStatus.textContent = data.message || 'Сохранено';
  }
  if (promptSave) {
    promptSave.addEventListener('click', async function () {
      try { await savePrompt(); } catch (e) { promptStatus.textContent = e.message || String(e); }
    });
  }
  if (promptReset) {
    promptReset.addEventListener('click', async function () {
      if (!confirm('Вернуть промпт по умолчанию (стиль директора)?')) return;
      promptStatus.textContent = 'Сбрасываю…';
      try {
        const res = await fetch(promptResetUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        if (promptInput && data.prompt) promptInput.value = data.prompt;
        promptStatus.textContent = data.message || 'Сброшено';
      } catch (e) { promptStatus.textContent = e.message || String(e); }
    });
  }

  const clearBtn = document.querySelector('.sr-reverse-clear-btn');
  if (clearBtn) {
    clearBtn.addEventListener('click', async function () {
      const n = Number(clearBtn.dataset.stored || 0);
      if (!confirm('Снести все старые RE-разборы (' + n + ')?\nТолько reverse_principle. Маршруты и метки локаций останутся.')) return;
      clearBtn.disabled = true;
      try {
        const res = await fetch(clearBtn.dataset.url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': clearBtn.dataset.csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        alert(data.message || 'Очищено');
        window.location.reload();
      } catch (e) {
        alert(e.message || String(e));
        clearBtn.disabled = false;
      }
    });
  }

  const btn = document.querySelector('.sr-reverse-pipeline-start-btn');
  const box = document.querySelector('.sr-reverse-pipeline-progress');
  const pctEl = document.querySelector('.sr-reverse-pipeline-pct');
  const bar = document.querySelector('.sr-reverse-pipeline-bar');
  const statusEl = document.querySelector('.sr-reverse-pipeline-status');
  const errEl = document.querySelector('.sr-reverse-pipeline-error');
  const cancelBtn = document.querySelector('.sr-reverse-pipeline-cancel-btn');
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
    statusEl.textContent = 'Маршрутов: ' + done + ' / ' + total
      + ' · OK ' + (s.ok || 0)
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
      if (timer && (s.state === 'done' || s.state === 'cancelled' || s.state === 'paused_quota')) {
        clearInterval(timer); timer = null;
        const prefix = s.state === 'done' ? 'Готово. '
          : (s.state === 'paused_quota' ? 'Пауза (квота ключей). ' : 'Остановлено. ');
        statusEl.textContent = prefix + statusEl.textContent;
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
    if (!confirm('Разобрать принцип до ' + n + ' short routes?\nНужен: php artisan queue:work\nКлючи: первый в .env (free), потом paid.')) return;
    const rawLimit = prompt('Лимит штук для этого прогона (пусто = все ' + n + '):', '');
    if (rawLimit === null) return;
    const body = {};
    const trimmed = String(rawLimit).trim();
    if (trimmed !== '') {
      const lim = parseInt(trimmed, 10);
      if (!Number.isFinite(lim) || lim < 1) { alert('Нужно целое число ≥ 1 или пусто'); return; }
      body.limit = lim;
    }
    try {
      const data = await post(btn.dataset.url, body);
      if (!data.started) { alert(data.message || 'Нечего разбирать'); return; }
      startPolling();
    } catch (e) { alert(e.message || String(e)); }
  });
  cancelBtn.addEventListener('click', async function () {
    if (!confirm('Остановить?')) return;
    try { await post(btn.dataset.cancelUrl); await poll(); } catch (e) { alert(e.message || String(e)); }
  });
  poll().then(function (s) { if (s.state === 'running') startPolling(); });
})();
</script>
@endsection
