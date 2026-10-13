@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Test — Vector RAG</h4>
  <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
</div>

<ul class="nav nav-pills mb-4">
  <li class="nav-item">
    <a class="nav-link" href="{{ route('admin.test.index') }}">Агентный RAG</a>
  </li>
  <li class="nav-item">
    <a class="nav-link active" href="{{ route('admin.test.rag-vector') }}">Vector RAG</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" href="{{ route('admin.test.full-tour') }}">Полный тур</a>
  </li>
</ul>

<div class="alert alert-secondary mb-4">
  <div class="fw-semibold mb-1">Как работает Vector RAG</div>
  <ol class="mb-0 small ps-3">
    <li>Один запрос → один embed вопроса</li>
    <li>Один поиск по PostgreSQL (top чанки → CONTEXT)</li>
    <li>Один ответ Gemini по CONTEXT</li>
  </ol>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div id="testChatLog" class="border rounded p-3 mb-3 bg-light" style="height:420px;overflow:auto;">
      <div class="text-muted small">Напишите сообщение — сначала векторный поиск по базе, затем ответ Gemini по найденному CONTEXT.</div>
    </div>

    <div id="testChatProgress" class="alert alert-primary d-none mb-3 py-3" role="status" aria-live="polite">
      <div class="d-flex align-items-start gap-3">
        <div class="spinner-border text-primary flex-shrink-0 mt-1" role="presentation" id="testChatSpinner"></div>
        <div class="flex-grow-1">
          <div class="fw-semibold" id="testChatProgressTitle">Работаю…</div>
          <div class="small mt-1" id="testChatProgressDetail"></div>
          <div class="progress mt-3" style="height:8px;">
            <div id="testChatProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:35%"></div>
          </div>
          <div class="small text-muted mt-2" id="testChatProgressTimer">0 с</div>
        </div>
      </div>
    </div>

    <form id="testChatForm" class="d-flex gap-2 flex-wrap">
      <input type="text" id="testChatInput" class="form-control" autocomplete="off" style="min-width:240px;flex:1;">
      <button type="submit" class="btn btn-primary" id="testChatSend">Send</button>
      <button type="button" class="btn btn-outline-secondary" id="testChatClear">Clear</button>
    </form>
    <div id="testChatError" class="alert alert-danger mt-3 mb-0 py-2" style="display:none;white-space:pre-wrap;"></div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="fw-semibold">System prompt</span>
    <div class="d-flex align-items-center gap-2">
      <span id="testPromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-icon btn-sm btn-primary" id="testPromptSave" title="Save">
        <i class="bx bx-save"></i>
      </button>
    </div>
  </div>
  <div class="card-body">
    <textarea
      id="testPromptInput"
      class="form-control font-monospace"
      rows="8"
      spellcheck="false"
    >{{ $systemPrompt }}</textarea>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const log = document.getElementById('testChatLog');
  const form = document.getElementById('testChatForm');
  const input = document.getElementById('testChatInput');
  const sendBtn = document.getElementById('testChatSend');
  const clearBtn = document.getElementById('testChatClear');
  const err = document.getElementById('testChatError');
  const progressBox = document.getElementById('testChatProgress');
  const progressTitle = document.getElementById('testChatProgressTitle');
  const progressDetail = document.getElementById('testChatProgressDetail');
  const progressBar = document.getElementById('testChatProgressBar');
  const progressTimer = document.getElementById('testChatProgressTimer');
  const promptInput = document.getElementById('testPromptInput');
  const promptSave = document.getElementById('testPromptSave');
  const promptStatus = document.getElementById('testPromptStatus');
  const searchUrl = @json(route('admin.test.search'));
  const chatUrl = @json(route('admin.test.chat'));
  const promptUrl = @json(route('admin.test.prompt.save'));
  const csrf = @json(csrf_token());
  const CHAT_STORAGE_KEY = 'travel.admin.test.chat.v1';
  const CHAT_TTL_MS = 12 * 60 * 60 * 1000;
  /** @type {{role:string,text:string}[]} */
  let history = [];
  /** @type {{role:string,text:string,sources?:any[],meta?:object}[]} */
  let messages = [];
  /** @type {HTMLElement|null} */
  let liveBubble = null;
  let timerStartedAt = 0;
  let timerId = null;
  const sendBtnDefault = sendBtn.textContent;

  function saveChat() {
    try {
      localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify({
        savedAt: Date.now(),
        history: history,
        messages: messages,
      }));
    } catch (e) {
      // quota / private mode — ignore
    }
  }

  function clearSavedChat() {
    try {
      localStorage.removeItem(CHAT_STORAGE_KEY);
    } catch (e) {}
  }

  function loadSavedChat() {
    try {
      const raw = localStorage.getItem(CHAT_STORAGE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || typeof data !== 'object' || !data.savedAt) {
        clearSavedChat();
        return null;
      }
      if ((Date.now() - Number(data.savedAt)) > CHAT_TTL_MS) {
        clearSavedChat();
        return null;
      }
      return data;
    } catch (e) {
      clearSavedChat();
      return null;
    }
  }

  function hoursLeft(savedAt) {
    const left = CHAT_TTL_MS - (Date.now() - Number(savedAt || 0));
    return Math.max(0, Math.ceil(left / (60 * 60 * 1000)));
  }

  function clearError() {
    err.style.display = 'none';
    err.textContent = '';
  }

  function showError(text) {
    err.style.display = 'block';
    err.textContent = text;
  }

  function stopTimer() {
    if (timerId) {
      clearInterval(timerId);
      timerId = null;
    }
  }

  function startTimer() {
    stopTimer();
    timerStartedAt = Date.now();
    progressTimer.textContent = '0 с';
    timerId = setInterval(function () {
      const sec = Math.floor((Date.now() - timerStartedAt) / 1000);
      progressTimer.textContent = 'Идёт ' + sec + ' с…';
    }, 250);
  }

  function ensureLiveBubble() {
    if (liveBubble && liveBubble.isConnected) {
      return liveBubble;
    }
    liveBubble = document.createElement('div');
    liveBubble.className = 'mb-3';
    liveBubble.id = 'testChatLiveBubble';
    liveBubble.innerHTML =
      '<div class="small fw-semibold text-warning">Система</div>' +
      '<div class="p-2 rounded border border-warning bg-white d-flex align-items-center gap-2">' +
      '<div class="spinner-border spinner-border-sm text-warning" role="presentation"></div>' +
      '<div class="flex-grow-1">' +
      '<div class="fw-semibold" data-live-title>Работаю…</div>' +
      '<div class="small text-muted" data-live-detail></div>' +
      '</div></div>';
    log.appendChild(liveBubble);
    log.scrollTop = log.scrollHeight;
    return liveBubble;
  }

  function setProgress(step, title, detail, percent) {
    progressBox.classList.remove('d-none', 'alert-success', 'alert-danger');
    progressBox.classList.add('alert-primary');
    progressTitle.textContent = title;
    progressDetail.textContent = detail || '';
    progressBar.style.width = Math.max(5, Math.min(100, percent || 35)) + '%';
    progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');

    const bubble = ensureLiveBubble();
    const t = bubble.querySelector('[data-live-title]');
    const d = bubble.querySelector('[data-live-detail]');
    if (t) t.textContent = (step ? step + ' · ' : '') + title;
    if (d) d.textContent = detail || '';
    log.scrollTop = log.scrollHeight;
  }

  function finishProgress(ok, title, detail) {
    stopTimer();
    progressBox.classList.remove('d-none', 'alert-primary', 'alert-success', 'alert-danger');
    progressBox.classList.add(ok ? 'alert-success' : 'alert-danger');
    progressTitle.textContent = title;
    progressDetail.textContent = detail || '';
    progressBar.style.width = '100%';
    progressBar.classList.remove('progress-bar-animated');
    progressTimer.textContent = ok ? 'Готово' : 'Ошибка';

    if (liveBubble && liveBubble.isConnected) {
      liveBubble.remove();
      liveBubble = null;
    }

    if (ok) {
      setTimeout(function () {
        progressBox.classList.add('d-none');
      }, 2200);
    }
  }

  function hideProgress() {
    stopTimer();
    progressBox.classList.add('d-none');
    if (liveBubble && liveBubble.isConnected) {
      liveBubble.remove();
      liveBubble = null;
    }
  }

  async function readJson(res) {
    const raw = await res.text();
    let data = {};
    try {
      data = raw ? JSON.parse(raw) : {};
    } catch (e) {
      const snippet = (raw || '').replace(/\s+/g, ' ').slice(0, 400);
      throw new Error('HTTP ' + res.status + ' (не JSON). ' + (snippet || res.statusText || ''));
    }
    return data;
  }

  function formatFail(data, res, stepLabel) {
    const parts = [];
    if (stepLabel) parts.push('Шаг: ' + stepLabel);
    if (data.step) parts.push('backend step: ' + data.step);
    parts.push(data.message || ('HTTP ' + res.status));
    if (data.error_class) parts.push('тип: ' + data.error_class);
    if (data.error_file) parts.push('файл: ' + data.error_file);
    return parts.join('\n');
  }

  function addBubble(role, text, sources, meta) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    const who = document.createElement('div');
    who.className = 'small fw-semibold ' + (role === 'user' ? 'text-primary' : 'text-success');
    who.textContent = role === 'user' ? 'You' : 'Gemini';
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
          if (s.score != null) bits.push('score ' + s.score);
          return bits.join(' · ');
        }).join(' | ');
        wrap.appendChild(src);
      } else {
        const src = document.createElement('div');
        src.className = 'small text-muted mt-1';
        src.textContent = 'Sources: none (no matching indexed chunks)';
        wrap.appendChild(src);
      }
      if (meta) {
        const m = document.createElement('div');
        m.className = 'small text-muted';
        const bits = [];
        if (meta.model_filter) bits.push('filter model=' + meta.model_filter);
        if (meta.hits != null) bits.push('hits ' + meta.hits);
        if (meta.scanned != null) bits.push('scanned ' + meta.scanned);
        if (meta.embed_ms != null) bits.push('embed ' + meta.embed_ms + 'ms');
        if (meta.search_ms != null) bits.push('search ' + meta.search_ms + 'ms');
        if (meta.chat_ms != null) bits.push('chat ' + meta.chat_ms + 'ms');
        if (meta.context_chars != null) bits.push('context ' + meta.context_chars + ' chars');
        m.textContent = bits.join(' · ');
        wrap.appendChild(m);
      }
    }
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
  }

  promptSave.addEventListener('click', async function () {
    promptSave.disabled = true;
    promptStatus.textContent = 'Saving…';
    promptStatus.className = 'small text-muted';
    try {
      const res = await fetch(promptUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ prompt: promptInput.value, mode: 'vector' }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(formatFail(data, res, 'save prompt'));
      }
      promptStatus.textContent = 'Saved (' + (data.length || 0) + ' chars)';
      promptStatus.className = 'small text-success';
    } catch (ex) {
      promptStatus.textContent = ex.message || String(ex);
      promptStatus.className = 'small text-danger';
    } finally {
      promptSave.disabled = false;
    }
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const message = (input.value || '').trim();
    if (!message || sendBtn.disabled) return;
    clearError();
    hideProgress();
    addBubble('user', message);
    input.value = '';
    sendBtn.disabled = true;
    sendBtn.textContent = 'Ждите…';
    startTimer();

    let searchData = null;
    try {
      setProgress('1/2', 'Векторный поиск', 'Gemini embed вопроса + поиск по PostgreSQL RAG', 30);
      const searchRes = await fetch(searchUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ message: message, limit: 12 }),
      });
      searchData = await readJson(searchRes);
      if (!searchRes.ok || !searchData.ok) {
        throw new Error(formatFail(searchData, searchRes, 'поиск'));
      }

      const filterInfo = searchData.model_filter
        ? 'filter model=' + searchData.model_filter + ' · '
        : '';
      setProgress(
        '2/2',
        'Gemini пишет ответ',
        filterInfo +
          'hits ' + (searchData.hits || 0) +
          ' · scanned ' + (searchData.scanned || 0) +
          ' · embed ' + (searchData.embed_ms || 0) + 'ms' +
          ' · search ' + (searchData.search_ms || 0) + 'ms' +
          ' · context ' + (searchData.context_chars || 0) + ' chars',
        70
      );

      const chatRes = await fetch(chatUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          message: message,
          history: history.slice(-12),
          prompt: promptInput.value,
          context: searchData.context || '',
          skip_search: true,
        }),
      });
      const chatData = await readJson(chatRes);
      if (!chatRes.ok || !chatData.ok) {
        throw new Error(formatFail(chatData, chatRes, 'генерация ответа'));
      }

      history.push({ role: 'user', text: message });
      history.push({ role: 'model', text: chatData.reply });
      messages.push({ role: 'user', text: message });
      const meta = {
        model_filter: searchData.model_filter,
        hits: searchData.hits,
        scanned: searchData.scanned,
        embed_ms: searchData.embed_ms,
        search_ms: searchData.search_ms,
        chat_ms: chatData.chat_ms,
        context_chars: searchData.context_chars,
      };
      messages.push({
        role: 'model',
        text: chatData.reply,
        sources: searchData.sources || [],
        meta: meta,
      });
      addBubble('model', chatData.reply, searchData.sources || [], meta);
      saveChat();
      finishProgress(
        true,
        'Готово',
        'chat ' + (chatData.chat_ms || 0) + 'ms · hits ' + (searchData.hits || 0)
      );
    } catch (ex) {
      finishProgress(false, 'Ошибка', ex.message || String(ex));
      showError(ex.message || String(ex));
    } finally {
      sendBtn.disabled = false;
      sendBtn.textContent = sendBtnDefault;
      input.focus();
    }
  });

  clearBtn.addEventListener('click', function () {
    history = [];
    messages = [];
    clearSavedChat();
    log.innerHTML = '<div class="text-muted small">Cleared.</div>';
    clearError();
    hideProgress();
  });

  // Restore chat for up to 12 hours (same browser).
  (function restoreChat() {
    const saved = loadSavedChat();
    if (!saved) return;
    const restoredMessages = Array.isArray(saved.messages) ? saved.messages : [];
    const restoredHistory = Array.isArray(saved.history) ? saved.history : [];
    if (restoredMessages.length === 0 && restoredHistory.length === 0) return;

    history = restoredHistory.filter(function (t) {
      return t && (t.role === 'user' || t.role === 'model') && typeof t.text === 'string';
    });
    messages = restoredMessages.filter(function (t) {
      return t && (t.role === 'user' || t.role === 'model') && typeof t.text === 'string';
    });

    log.innerHTML = '';
    const note = document.createElement('div');
    note.className = 'text-muted small mb-2';
    note.textContent = 'Восстановлен чат (~' + hoursLeft(saved.savedAt) + ' ч до очистки). Clear — сбросить.';
    log.appendChild(note);

    if (messages.length) {
      messages.forEach(function (m) {
        addBubble(m.role, m.text, m.sources || null, m.meta || null);
      });
    } else {
      history.forEach(function (m) {
        addBubble(m.role, m.text, null, null);
      });
      messages = history.map(function (m) {
        return { role: m.role, text: m.text };
      });
    }
    saveChat();
  })();
})();
</script>
@endsection
