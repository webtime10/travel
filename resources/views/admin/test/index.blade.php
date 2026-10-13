@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Test — Агентный RAG</h4>
  <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
</div>

<ul class="nav nav-pills mb-4">
  <li class="nav-item">
    <a class="nav-link active" href="{{ route('admin.test.index') }}">Агентный RAG</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" href="{{ route('admin.test.rag-vector') }}">Vector RAG</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" href="{{ route('admin.test.full-tour') }}">Полный тур</a>
  </li>
</ul>

<div class="card mb-4 border-primary">
  <div class="card-header bg-label-primary">
    <span class="fw-semibold">Как работает процесс (Multi-step Agent)</span>
  </div>
  <div class="card-body">
    <ol class="mb-3">
      <li class="mb-2"><strong>Запрос</strong> — вы отправляете опросник / задачу (даты, регион, семья…).</li>
      <li class="mb-2"><strong>Мысль агента</strong> — Gemini решает, вызвать tool <code>search_database</code> или уже ответить.</li>
      <li class="mb-2"><strong>Поиск в Laravel</strong> — <code>RagSearchService</code> ищет в PostgreSQL по векторам и возвращает CONTEXT + sources.</li>
      <li class="mb-2"><strong>Повтор</strong> — если данных мало (например, нужен другой день/город), Gemini вызывает поиск снова с другим query.</li>
      <li class="mb-0"><strong>Сборка</strong> — когда хватает кусков, Gemini пишет финальный маршрут.</li>
    </ol>
    <div class="small text-muted">
      Пример: «26–28 октября, Италия, 3 дня, семья» → поиск «Гарда дети» → потом «Венеция 1 день» → итоговый план.
      Запрос идёт в очередь (без 504): нужен <code>php artisan queue:work</code> или <code>queue:work-forever</code>.
    </div>
  </div>
</div>

<div class="row">
  <div class="col-lg-7 mb-4">
    <div class="card h-100">
      <div class="card-body">
        <div id="agentChatLog" class="border rounded p-3 mb-3 bg-light" style="height:380px;overflow:auto;">
          <div class="text-muted small">Напишите запрос — агент сам вызовет поиск столько раз, сколько нужно.</div>
        </div>

        <div id="agentProgress" class="alert alert-primary d-none mb-3 py-3" role="status">
          <div class="d-flex align-items-start gap-3">
            <div class="spinner-border text-primary flex-shrink-0 mt-1"></div>
            <div class="flex-grow-1">
              <div class="fw-semibold" id="agentProgressTitle">Агент работает…</div>
              <div class="small mt-1" id="agentProgressDetail"></div>
              <div class="small text-muted mt-2" id="agentProgressTimer">0 с</div>
            </div>
          </div>
        </div>

        <form id="agentForm" class="d-flex gap-2 flex-wrap">
          <input type="text" id="agentInput" class="form-control" autocomplete="off" style="min-width:220px;flex:1;"
                 placeholder="Напр.: 26–28 октября, Гарда, 3 дня, семья с детьми">
          <button type="submit" class="btn btn-primary" id="agentSend">Send</button>
          <button type="button" class="btn btn-outline-secondary" id="agentClear">Clear</button>
        </form>
        <div id="agentError" class="alert alert-danger mt-3 mb-0 py-2" style="display:none;white-space:pre-wrap;"></div>
      </div>
    </div>
  </div>

  <div class="col-lg-5 mb-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Лог процесса</span>
        <span class="badge bg-label-secondary" id="agentStepCount">0 шагов</span>
      </div>
      <div class="card-body p-0">
        <div id="agentSteps" class="p-3" style="height:420px;overflow:auto;">
          <div class="text-muted small">Здесь появятся итерации: мысль → tool call → результат поиска → финал.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="fw-semibold">System prompt (агент)</span>
    <div class="d-flex align-items-center gap-2">
      <span id="agentPromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-icon btn-sm btn-primary" id="agentPromptSave" title="Save">
        <i class="bx bx-save"></i>
      </button>
    </div>
  </div>
  <div class="card-body">
    <textarea id="agentPromptInput" class="form-control font-monospace" rows="7" spellcheck="false">{{ $systemPrompt }}</textarea>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const log = document.getElementById('agentChatLog');
  const stepsEl = document.getElementById('agentSteps');
  const stepCount = document.getElementById('agentStepCount');
  const form = document.getElementById('agentForm');
  const input = document.getElementById('agentInput');
  const sendBtn = document.getElementById('agentSend');
  const clearBtn = document.getElementById('agentClear');
  const err = document.getElementById('agentError');
  const progressBox = document.getElementById('agentProgress');
  const progressTitle = document.getElementById('agentProgressTitle');
  const progressDetail = document.getElementById('agentProgressDetail');
  const progressTimer = document.getElementById('agentProgressTimer');
  const promptInput = document.getElementById('agentPromptInput');
  const promptSave = document.getElementById('agentPromptSave');
  const promptStatus = document.getElementById('agentPromptStatus');
  const agentUrl = @json(route('admin.test.agent'));
  const agentStatusUrlTpl = @json(route('admin.test.agent.status', ['runId' => '__RUN__']));
  const promptUrl = @json(route('admin.test.prompt.save'));
  const csrf = @json(csrf_token());
  const sendDefault = sendBtn.textContent;
  let timerId = null;
  let timerStartedAt = 0;
  let pollCancelled = false;

  function agentStatusUrl(runId) {
    return agentStatusUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }

  function stopPoll() {
    pollCancelled = true;
  }

  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function clearError() {
    err.style.display = 'none';
    err.textContent = '';
  }

  function showError(text) {
    err.style.display = 'block';
    err.textContent = text;
  }

  function startTimer() {
    stopTimer();
    timerStartedAt = Date.now();
    progressTimer.textContent = '0 с';
    timerId = setInterval(function () {
      progressTimer.textContent = 'Идёт ' + Math.floor((Date.now() - timerStartedAt) / 1000) + ' с…';
    }, 250);
  }

  function stopTimer() {
    if (timerId) {
      clearInterval(timerId);
      timerId = null;
    }
  }

  function setBusy(on, title, detail) {
    if (on) {
      progressBox.classList.remove('d-none');
      progressTitle.textContent = title || 'Агент работает…';
      progressDetail.textContent = detail || '';
      startTimer();
    } else {
      stopTimer();
      progressBox.classList.add('d-none');
    }
  }

  function addBubble(role, text, sources, meta) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    const who = document.createElement('div');
    who.className = 'small fw-semibold ' + (role === 'user' ? 'text-primary' : 'text-success');
    who.textContent = role === 'user' ? 'You' : 'Agent';
    const body = document.createElement('div');
    body.className = 'p-2 rounded bg-white border';
    body.style.whiteSpace = 'pre-wrap';
    body.setAttribute('dir', 'auto');
    body.textContent = text;
    wrap.appendChild(who);
    wrap.appendChild(body);
    if (role === 'model') {
      if (Array.isArray(sources) && sources.length) {
        const src = document.createElement('div');
        src.className = 'small text-muted mt-1';
        src.textContent = 'Sources: ' + sources.map(function (s) {
          const bits = [];
          if (s.model) bits.push('model ' + s.model);
          bits.push('#' + s.product_id);
          if (s.title) bits.push(s.title);
          return bits.join(' · ');
        }).join(' | ');
        wrap.appendChild(src);
      }
      if (meta) {
        const m = document.createElement('div');
        m.className = 'small text-muted';
        m.textContent = [
          meta.tool_calls != null ? ('tool_calls ' + meta.tool_calls) : null,
          meta.total_ms != null ? ('total ' + meta.total_ms + 'ms') : null,
        ].filter(Boolean).join(' · ');
        wrap.appendChild(m);
      }
    }
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
  }

  function renderSteps(steps) {
    stepsEl.innerHTML = '';
    if (!Array.isArray(steps) || !steps.length) {
      stepsEl.innerHTML = '<div class="text-muted small">Нет шагов.</div>';
      stepCount.textContent = '0 шагов';
      return;
    }
    stepCount.textContent = steps.length + ' шагов';
    steps.forEach(function (s, i) {
      const box = document.createElement('div');
      box.className = 'border rounded p-2 mb-2 bg-light';
      const type = s.type || '';
      let badge = 'secondary';
      if (type === 'tool_call') badge = 'primary';
      else if (type === 'tool_result') badge = 'success';
      else if (type === 'tool_error') badge = 'danger';
      else if (type === 'final') badge = 'info';
      else if (type === 'think') badge = 'warning';
      const head = document.createElement('div');
      head.className = 'd-flex justify-content-between gap-2 mb-1';
      head.innerHTML =
        '<span class="fw-semibold small"></span>' +
        '<span class="badge bg-label-' + badge + '"></span>';
      head.querySelector('.fw-semibold').textContent = (i + 1) + '. ' + (s.title || type);
      head.querySelector('.badge').textContent = type;
      const detail = document.createElement('div');
      detail.className = 'small';
      detail.style.whiteSpace = 'pre-wrap';
      detail.textContent = s.detail || '';
      box.appendChild(head);
      box.appendChild(detail);
      if (Array.isArray(s.sources) && s.sources.length) {
        const src = document.createElement('div');
        src.className = 'small text-muted mt-1';
        src.textContent = 'models: ' + s.sources.map(function (x) { return x.model || ('#' + x.product_id); }).join(', ');
        box.appendChild(src);
      }
      stepsEl.appendChild(box);
    });
    stepsEl.scrollTop = stepsEl.scrollHeight;
  }

  async function readJson(res) {
    const raw = await res.text();
    try {
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      throw new Error('HTTP ' + res.status + ' (не JSON). ' + (raw || '').replace(/\s+/g, ' ').slice(0, 300));
    }
  }

  promptSave.addEventListener('click', async function () {
    promptSave.disabled = true;
    promptStatus.textContent = 'Saving…';
    try {
      const res = await fetch(promptUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ prompt: promptInput.value, mode: 'agent' }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      promptStatus.textContent = 'Saved (' + (data.length || 0) + ' chars)';
      promptStatus.className = 'small text-success';
    } catch (ex) {
      promptStatus.textContent = ex.message || String(ex);
      promptStatus.className = 'small text-danger';
    } finally {
      promptSave.disabled = false;
    }
  });

  async function pollUntilDone(runId) {
    pollCancelled = false;
    for (let ticks = 0; ticks < 600; ticks++) {
      if (pollCancelled) {
        throw new Error('Опрос остановлен');
      }
      const res = await fetch(agentStatusUrl(runId), {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      if (Array.isArray(data.steps) && data.steps.length) {
        renderSteps(data.steps);
      }
      const status = data.status || '';
      if (status === 'queued') {
        setBusy(true, 'В очереди…', 'Нужен php artisan queue:work (или queue:work-forever)');
      } else if (status === 'running') {
        const last = (data.steps || []).slice(-1)[0];
        setBusy(true, last && last.title ? last.title : 'Агент работает…', last && last.detail ? last.detail : '');
      } else if (status === 'done') {
        return data;
      } else if (status === 'failed') {
        throw new Error(data.error || 'Agent failed');
      }
      await sleep(1500);
    }
    throw new Error('Таймаут ожидания (15 мин). Проверьте queue:work.');
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const message = (input.value || '').trim();
    if (!message || sendBtn.disabled) return;
    clearError();
    stopPoll();
    addBubble('user', message);
    input.value = '';
    sendBtn.disabled = true;
    sendBtn.textContent = 'Ждите…';
    setBusy(true, 'Ставим в очередь…', 'HTTP сразу отвечает; длинный цикл идёт в worker');
    renderSteps([{ type: 'start', title: 'Отправка в очередь', detail: 'POST → job → poll статуса' }]);

    try {
      const res = await fetch(agentUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          message: message,
          prompt: promptInput.value,
        }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok || !data.run_id) {
        const parts = [data.message || ('HTTP ' + res.status)];
        if (data.step) parts.unshift('step: ' + data.step);
        if (data.error_file) parts.push(data.error_file);
        throw new Error(parts.join('\n'));
      }
      renderSteps([{
        type: 'start',
        title: 'В очереди',
        detail: 'run_id=' + data.run_id + '. Ждём worker…',
      }]);
      const result = await pollUntilDone(data.run_id);
      renderSteps(result.steps || []);
      addBubble('model', result.reply || '', result.sources || [], {
        tool_calls: result.tool_calls,
        total_ms: result.total_ms,
      });
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      sendBtn.disabled = false;
      sendBtn.textContent = sendDefault;
      input.focus();
    }
  });

  clearBtn.addEventListener('click', function () {
    stopPoll();
    log.innerHTML = '<div class="text-muted small">Cleared.</div>';
    renderSteps([]);
    stepsEl.innerHTML = '<div class="text-muted small">Лог очищен.</div>';
    clearError();
  });
})();
</script>
@endsection
