@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Test — Полный тур (сырьё)</h4>
  <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
</div>

<ul class="nav nav-pills mb-4">
  <li class="nav-item">
    <a class="nav-link" href="{{ route('admin.test.index') }}">Агентный RAG</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" href="{{ route('admin.test.rag-vector') }}">Vector RAG</a>
  </li>
  <li class="nav-item">
    <a class="nav-link active" href="{{ route('admin.test.full-tour') }}">Полный тур</a>
  </li>
</ul>

<div class="card mb-4 border-success">
  <div class="card-header bg-label-success">
    <span class="fw-semibold">Полный тур — сырьё → перевод → сборка</span>
  </div>
  <div class="card-body">
    <ol class="mb-2">
      <li class="mb-2"><strong>Разобрать запрос</strong> — что будем искать в векторной базе (хаб → регионы/локации). Можно уточнить в чате</li>
      <li class="mb-2"><strong>Искать в базе</strong> — строки маршрута («когда→куда») по локациям плана</li>
      <li class="mb-2"><strong>Извлечь дни</strong> — строки → карточки + PHP-дедуп (одинаковые места без учёта дат)</li>
      <li class="mb-2"><strong>Релевантный список (RU)</strong> — Gemini: полный каталог посещений на русском (не маршрут)</li>
      <li class="mb-0"><strong>Собрать маршруты</strong> — отдельно, из этого списка (один или несколько вариантов)</li>
    </ol>
    <div class="small text-muted">Нужен <code>php artisan queue:work</code>. Состояние run хранится ~2 суток (file cache) и восстанавливается после F5 через localStorage.</div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form id="ftForm" class="d-flex gap-2 flex-wrap mb-3">
      <input type="text" id="ftInput" class="form-control" autocomplete="off" style="min-width:220px;flex:1;"
             placeholder="Опросник или уточнение плана (напр.: убери Комо, больше Доломиты)">
      <button type="submit" class="btn btn-primary" id="ftSend">Разобрать запрос</button>
      <button type="button" class="btn btn-success d-none" id="ftSearch" disabled>Искать в базе</button>
      <button type="button" class="btn btn-outline-secondary" id="ftClear">Clear</button>
    </form>

    <div class="card mb-3 border-primary d-none" id="ftPlanWrap">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span class="fw-semibold">0) План поиска в векторной базе</span>
        <span class="badge bg-label-primary" id="ftPlanBadge">ожидает подтверждения</span>
      </div>
      <div class="card-body">
        <pre id="ftPlanText" class="small mb-2 p-3 bg-light border rounded" style="white-space:pre-wrap;max-height:280px;overflow:auto;"></pre>
        <div class="small text-muted">Если план устраивает — «Искать в базе». Если нет — напишите уточнение в поле выше и снова отправьте.</div>
      </div>
    </div>

    <div id="ftProgress" class="alert alert-primary d-none mb-3 py-3" role="status">
      <div class="d-flex align-items-start gap-3">
        <div class="spinner-border text-primary flex-shrink-0 mt-1"></div>
        <div class="flex-grow-1">
          <div class="fw-semibold" id="ftProgressTitle">Ищем…</div>
          <div class="small mt-1" id="ftProgressDetail"></div>
          <div class="small text-muted mt-2" id="ftProgressTimer">0 с</div>
        </div>
      </div>
    </div>

    <div id="ftError" class="alert alert-danger mb-3 py-2" style="display:none;white-space:pre-wrap;"></div>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold">1) Найденные куски из базы</span>
        <button type="button" class="btn btn-sm btn-outline-primary d-none" id="ftTranslate" disabled>Перевести все</button>
        <button type="button" class="btn btn-sm btn-outline-success d-none" id="ftCurate" disabled>Извлечь дни</button>
      </div>
      <span class="badge bg-label-secondary" id="ftSourceCount">0 источников</span>
    </div>
    <div id="ftAccordionWrap" class="border rounded bg-light p-2" style="max-height:480px;overflow:auto;">
      <div id="ftAccordionEmpty" class="text-muted small p-2">После «Искать в базе» — все туры, где упоминаются локации плана.</div>
      <div class="accordion accordion-flush d-none" id="ftAccordion"></div>
    </div>

    <div class="card mt-3 border-info d-none" id="ftCuratedWrap">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span class="fw-semibold">2) Карточки дней из строк (сырой список)</span>
        <span class="badge bg-label-info" id="ftCuratedCount">0 дней</span>
      </div>
      <div class="card-body">
        <div class="alert alert-info py-2 small mb-3 d-none" id="ftBriefNote"></div>
        <div class="fw-semibold small mb-1 text-success">Список дней</div>
        <div id="ftCuratedList" class="border rounded bg-white p-2 mb-3" style="max-height:220px;overflow:auto;"></div>
        <label class="fw-semibold small mb-1 d-block" for="ftCuratedText">Текст дней (можно править)</label>
        <textarea id="ftCuratedText" class="form-control font-monospace small mb-3" rows="14" spellcheck="false"
                  placeholder="После «Извлечь дни» здесь будет полный список дневных блоков…"></textarea>
        <div id="ftDroppedWrap" class="d-none">
          <div class="fw-semibold small mb-1 text-muted">Мусор (если был)</div>
          <div id="ftDroppedList" class="border rounded bg-light p-2 mb-3 text-muted" style="max-height:120px;overflow:auto;"></div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2" id="ftCuratedActions">
          <button type="button" class="btn btn-outline-warning" id="ftClean" disabled>
            <span class="spinner-border spinner-border-sm d-none me-1" id="ftCleanSpinner" role="status" aria-hidden="true"></span>
            <span id="ftCleanLabel">Релевантный список (RU)</span>
          </button>
          <span class="small text-muted d-none" id="ftCleanHint">Готовим каталог…</span>
        </div>
        <div class="small text-muted mt-2">Сырой список (часто ещё на иврите). Дальше — полный релевантный каталог на русском, без сборки маршрута.</div>
      </div>
    </div>

    <div class="card mt-3 border-warning d-none" id="ftCleanedWrap">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span class="fw-semibold">3) Релевантный каталог посещений (RU)</span>
        <span class="badge bg-label-warning" id="ftCleanedCount">0 дней</span>
      </div>
      <div class="card-body">
        <div class="alert alert-warning py-2 small mb-3 d-none" id="ftCleanNote"></div>
        <div class="fw-semibold small mb-1 text-success">Список (все годные дни, не маршрут)</div>
        <div id="ftCleanedList" class="border rounded bg-white p-2 mb-3" style="max-height:220px;overflow:auto;"></div>
        <label class="fw-semibold small mb-1 d-block" for="ftCleanedText">Текст каталога (можно править)</label>
        <textarea id="ftCleanedText" class="form-control font-monospace small mb-3" rows="12" spellcheck="false"
                  placeholder="После «Релевантный список (RU)» здесь будет полный каталог посещений…"></textarea>
        <div class="d-flex flex-wrap gap-2" id="ftCleanedActions">
          <button type="button" class="btn btn-outline-primary" id="ftTranslatePool" disabled>Доперевод RU</button>
          <button type="button" class="btn btn-success" id="ftAssemble" disabled>Собрать маршрут из списка</button>
          <button type="button" class="btn btn-outline-success" id="ftAssemble2" disabled>Собрать 2 варианта</button>
        </div>
        <div class="small text-muted mt-2">Сборка из каталога: гео-блоки под любой brief (завтра рядом с сегодня, переезд между кустами). Не привязано к одному озеру.</div>
      </div>
    </div>

    <div class="mt-3 d-none" id="ftReplyWrap">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <span class="fw-semibold">4) Собранный маршрут</span>
        <span class="badge bg-label-success" id="ftReplyBadge">готово</span>
      </div>
      <div id="ftReply" class="small mb-0"></div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-lg-7 mb-4">
    <div class="card h-100">
      <div class="card-header"><span class="fw-semibold">Запрос</span></div>
      <div class="card-body">
        <div id="ftChatLog" class="border rounded p-3 bg-light" style="height:180px;overflow:auto;">
          <div class="text-muted small">Последний отправленный запрос появится здесь.</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5 mb-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Лог</span>
        <span class="badge bg-label-secondary" id="ftStepCount">0 шагов</span>
      </div>
      <div class="card-body p-0">
        <div id="ftSteps" class="p-3" style="height:180px;overflow:auto;">
          <div class="text-muted small">Поиск → загрузка meta → показ в аккордеоне.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="fw-semibold">System prompt (используется при «Собрать маршрут»)</span>
    <div class="d-flex align-items-center gap-2">
      <span id="ftPromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-icon btn-sm btn-primary" id="ftPromptSave" title="Save">
        <i class="bx bx-save"></i>
      </button>
    </div>
  </div>
  <div class="card-body">
    <textarea id="ftPromptInput" class="form-control font-monospace" rows="5" spellcheck="false">{{ $systemPrompt }}</textarea>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const log = document.getElementById('ftChatLog');
  const stepsEl = document.getElementById('ftSteps');
  const stepCount = document.getElementById('ftStepCount');
  const form = document.getElementById('ftForm');
  const input = document.getElementById('ftInput');
  const sendBtn = document.getElementById('ftSend');
  const searchBtn = document.getElementById('ftSearch');
  const clearBtn = document.getElementById('ftClear');
  const err = document.getElementById('ftError');
  const progressBox = document.getElementById('ftProgress');
  const progressTitle = document.getElementById('ftProgressTitle');
  const progressDetail = document.getElementById('ftProgressDetail');
  const progressTimer = document.getElementById('ftProgressTimer');
  const planWrap = document.getElementById('ftPlanWrap');
  const planText = document.getElementById('ftPlanText');
  const planBadge = document.getElementById('ftPlanBadge');
  const accordion = document.getElementById('ftAccordion');
  const accordionEmpty = document.getElementById('ftAccordionEmpty');
  const sourceCount = document.getElementById('ftSourceCount');
  const translateBtn = document.getElementById('ftTranslate');
  const curateBtn = document.getElementById('ftCurate');
  const cleanBtn = document.getElementById('ftClean');
  const translatePoolBtn = document.getElementById('ftTranslatePool');
  const assembleBtn = document.getElementById('ftAssemble');
  const assemble2Btn = document.getElementById('ftAssemble2');
  const curatedWrap = document.getElementById('ftCuratedWrap');
  const curatedList = document.getElementById('ftCuratedList');
  const curatedTextEl = document.getElementById('ftCuratedText');
  const droppedWrap = document.getElementById('ftDroppedWrap');
  const droppedList = document.getElementById('ftDroppedList');
  const curatedCount = document.getElementById('ftCuratedCount');
  const briefNoteEl = document.getElementById('ftBriefNote');
  const cleanedWrap = document.getElementById('ftCleanedWrap');
  const cleanedList = document.getElementById('ftCleanedList');
  const cleanedTextEl = document.getElementById('ftCleanedText');
  const cleanedCount = document.getElementById('ftCleanedCount');
  const cleanNoteEl = document.getElementById('ftCleanNote');
  const replyWrap = document.getElementById('ftReplyWrap');
  const replyEl = document.getElementById('ftReply');
  const replyBadge = document.getElementById('ftReplyBadge');
  const promptInput = document.getElementById('ftPromptInput');
  const promptSave = document.getElementById('ftPromptSave');
  const promptStatus = document.getElementById('ftPromptStatus');
  const planUrl = @json(route('admin.test.full-tour.plan'));
  const startUrl = @json(route('admin.test.full-tour.start'));
  const statusUrlTpl = @json(route('admin.test.full-tour.status', ['runId' => '__RUN__']));
  const refinePlanUrlTpl = @json(route('admin.test.full-tour.refine-plan', ['runId' => '__RUN__']));
  const translateUrlTpl = @json(route('admin.test.full-tour.translate', ['runId' => '__RUN__']));
  const curateUrlTpl = @json(route('admin.test.full-tour.curate', ['runId' => '__RUN__']));
  const cleanUrlTpl = @json(route('admin.test.full-tour.clean', ['runId' => '__RUN__']));
  const translatePoolUrlTpl = @json(route('admin.test.full-tour.translate-pool', ['runId' => '__RUN__']));
  const assembleUrlTpl = @json(route('admin.test.full-tour.assemble', ['runId' => '__RUN__']));
  const promptUrl = @json(route('admin.test.prompt.save'));
  const csrf = @json(csrf_token());
  const sendDefault = sendBtn.textContent;
  const searchDefault = searchBtn.textContent;
  const translateDefault = translateBtn.textContent;
  const curateDefault = curateBtn.textContent;
  const cleanDefault = (document.getElementById('ftCleanLabel') || cleanBtn).textContent.trim() || 'Релевантный список (RU)';
  const translatePoolDefault = translatePoolBtn.textContent;
  const assembleDefault = assembleBtn.textContent;
  const assemble2Default = assemble2Btn.textContent;
  const RUN_STORAGE_KEY = 'admin.fullTour.runId';
  let timerId = null;
  let timerStartedAt = 0;
  let pollCancelled = false;
  let currentRunId = null;
  let currentSources = [];
  let currentCurated = [];
  let currentDropped = [];
  let currentCleaned = [];
  let currentPlan = null;
  let busy = false;

  function statusUrl(runId) {
    return statusUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function refinePlanUrl(runId) {
    return refinePlanUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function translateUrl(runId) {
    return translateUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function curateUrl(runId) {
    return curateUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function cleanUrl(runId) {
    return cleanUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function translatePoolUrl(runId) {
    return translatePoolUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function assembleUrl(runId) {
    return assembleUrlTpl.replace('__RUN__', encodeURIComponent(runId));
  }
  function rememberRunId(runId) {
    currentRunId = runId || null;
    try {
      if (runId) {
        localStorage.setItem(RUN_STORAGE_KEY, runId);
      } else {
        localStorage.removeItem(RUN_STORAGE_KEY);
      }
    } catch (e) { /* ignore quota / private mode */ }
  }
  function loadRememberedRunId() {
    try {
      return localStorage.getItem(RUN_STORAGE_KEY) || null;
    } catch (e) {
      return null;
    }
  }
  function applyRunResult(result, opts) {
    opts = opts || {};
    renderSteps(result.steps || []);
    renderPlan(result.search_plan || null, result.mode === 'plan' ? 'ожидает подтверждения' : (
      result.search_plan ? 'утверждён / поиск' : ''
    ));
    renderSources(result.sources || result.tours || []);
    renderCurated(
      result.curated_days || [],
      result.dropped_days || [],
      result.brief_note || '',
      result.curated_text || null
    );
    renderCleaned(
      result.cleaned_days || [],
      result.clean_note || '',
      result.cleaned_text || null
    );
    const replyMeta = result.format_ms != null ? ('format ' + result.format_ms + 'ms') : (
      result.mode === 'assemble' ? 'готово' : ''
    );
    renderReply(result.replies || [], result.reply || '', replyMeta || 'готово');
    if (opts.bubble) {
      addBubble('model', opts.bubble);
    }
  }
  function stopPoll() { pollCancelled = true; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function clearError() { err.style.display = 'none'; err.textContent = ''; }
  function showError(text) { err.style.display = 'block'; err.textContent = text; }
  function sourcesNeedTranslate(sources) {
    if (!Array.isArray(sources) || !sources.length) return false;
    return sources.some(function (s) {
      return !s.preview_text_ru || !String(s.preview_text_ru).trim();
    });
  }
  function updateActionButtons() {
    const hasPlan = !!currentRunId && !!currentPlan;
    const hasSources = !!currentRunId && currentSources.length > 0;
    const hasCurated = !!currentRunId && currentCurated.length > 0;
    const hasCleaned = !!currentRunId && currentCleaned.length > 0;
    const needTranslate = hasSources && sourcesNeedTranslate(currentSources);
    searchBtn.classList.toggle('d-none', !hasPlan);
    searchBtn.disabled = !hasPlan || busy;
    translateBtn.classList.toggle('d-none', !hasSources);
    translateBtn.disabled = !needTranslate || busy;
    curateBtn.classList.toggle('d-none', !hasSources);
    curateBtn.disabled = !hasSources || busy;
    cleanBtn.disabled = !hasCurated || busy;
    translatePoolBtn.disabled = !(hasCleaned || hasCurated) || busy;
    assembleBtn.disabled = !(hasCleaned || hasCurated) || busy;
    assemble2Btn.disabled = !(hasCleaned || hasCurated) || busy;
  }
  function renderPlan(plan, badge) {
    currentPlan = plan && typeof plan === 'object' ? plan : null;
    if (!currentPlan) {
      planWrap.classList.add('d-none');
      planText.textContent = '';
      planBadge.textContent = '';
      updateActionButtons();
      return;
    }
    planWrap.classList.remove('d-none');
    planText.textContent = currentPlan.reply || (
      'Регионы: ' + (currentPlan.regions || []).join(', ')
      + '\nЛокации: ' + (currentPlan.locations || []).join(', ')
    );
    planBadge.textContent = badge || 'план';
    updateActionButtons();
  }
  function dayRowHtml(day, i, dropped) {
    const places = Array.isArray(day.places) ? day.places.join(', ') : '';
    const wrap = document.createElement('div');
    wrap.className = 'border-bottom py-2 small';
    const head = document.createElement('div');
    head.className = dropped ? '' : 'fw-semibold';
    head.textContent = (i + 1) + '. [' + (day.region || '?') + '] ' + (day.summary || places || '—');
    const meta = document.createElement('div');
    meta.className = 'text-muted';
    let geo = '';
    if (day.lat != null && day.lng != null) {
      geo = ' · ' + Number(day.lat).toFixed(3) + ', ' + Number(day.lng).toFixed(3);
      if (day.geo_span_km != null) {
        geo += ' · ±' + Number(day.geo_span_km).toFixed(0) + 'км';
        if (day.geo_ok === false) geo += ' ⚠';
      }
    }
    meta.textContent = (day.kind || 'program')
      + (day.model ? (' · model ' + day.model) : '')
      + (day.product_id ? (' · #' + day.product_id) : '')
      + (places ? (' · ' + places) : '')
      + geo
      + (dropped && day.drop_reason ? (' · почему: ' + day.drop_reason) : '');
    wrap.appendChild(head);
    wrap.appendChild(meta);
    return wrap;
  }
  function daysToTextarea(days) {
    if (!Array.isArray(days) || !days.length) return '';
    return days.map(function (day, i) {
      const places = Array.isArray(day.places) ? day.places.join(', ') : '';
      const lines = [
        (i + 1) + '. [' + (day.region || 'other') + '] ' + (day.summary || places || 'день'),
      ];
      if (places) {
        lines.push('Места: ' + places);
      }
      if (day.lat != null && day.lng != null) {
        let geo = 'Координаты: ' + day.lat + ', ' + day.lng;
        if (day.geo_span_km != null) {
          geo += ' · разброс ' + day.geo_span_km + ' км';
          if (day.geo_ok === false) geo += ' (далеко внутри дня)';
        }
        lines.push(geo);
      }
      if (Array.isArray(day.places_geo) && day.places_geo.length) {
        lines.push('Точки: ' + day.places_geo.map(function (pg) {
          return (pg.name || '?') + ' (' + pg.lat + ', ' + pg.lng + ')';
        }).join('; '));
      }
      const meta = [];
      if (day.kind) meta.push(day.kind);
      if (day.model) meta.push('model ' + day.model);
      if (day.product_id) meta.push('#' + day.product_id);
      if (meta.length) {
        lines.push('Meta: ' + meta.join(' · '));
      }
      if (day.body && String(day.body).trim()) {
        lines.push('Текст:');
        lines.push(String(day.body).trim());
      }
      return lines.join('\n');
    }).join('\n\n');
  }
  function renderCurated(days, dropped, note, savedText) {
    currentCurated = Array.isArray(days) ? days : [];
    currentDropped = Array.isArray(dropped) ? dropped : [];
    curatedList.innerHTML = '';
    droppedList.innerHTML = '';
    if (!currentCurated.length && !currentDropped.length) {
      curatedWrap.classList.add('d-none');
      curatedCount.textContent = '0 дней';
      briefNoteEl.classList.add('d-none');
      briefNoteEl.textContent = '';
      curatedTextEl.value = '';
      if (droppedWrap) droppedWrap.classList.add('d-none');
      updateActionButtons();
      return;
    }
    curatedWrap.classList.remove('d-none');
    curatedCount.textContent = currentCurated.length + ' дн.';
    if (note && String(note).trim()) {
      briefNoteEl.textContent = note;
      briefNoteEl.classList.remove('d-none');
    } else {
      briefNoteEl.classList.add('d-none');
      briefNoteEl.textContent = '';
    }
    if (!currentCurated.length) {
      curatedList.innerHTML = '<div class="text-muted small p-2">Дни не найдены.</div>';
    } else {
      currentCurated.forEach(function (day, i) { curatedList.appendChild(dayRowHtml(day, i, false)); });
    }
    if (currentDropped.length && droppedWrap) {
      droppedWrap.classList.remove('d-none');
      currentDropped.forEach(function (day, i) { droppedList.appendChild(dayRowHtml(day, i, true)); });
    } else if (droppedWrap) {
      droppedWrap.classList.add('d-none');
    }
    if (savedText && String(savedText).trim()) {
      curatedTextEl.value = savedText;
    } else {
      curatedTextEl.value = daysToTextarea(currentCurated);
    }
    updateActionButtons();
  }
  function groupDaysByRegion(days) {
    const order = { como: 0, garda: 1, dolomiti: 2, milan: 3, tuscany: 4, rome: 5, other: 6 };
    return (Array.isArray(days) ? days : []).slice().sort(function (a, b) {
      const ra = String((a && a.region) || 'other').toLowerCase();
      const rb = String((b && b.region) || 'other').toLowerCase();
      const oa = Object.prototype.hasOwnProperty.call(order, ra) ? order[ra] : order.other;
      const ob = Object.prototype.hasOwnProperty.call(order, rb) ? order[rb] : order.other;
      return oa - ob;
    });
  }
  function renderCleaned(days, note, savedText) {
    currentCleaned = groupDaysByRegion(days);
    cleanedList.innerHTML = '';
    if (!currentCleaned.length) {
      cleanedWrap.classList.add('d-none');
      cleanedCount.textContent = '0 дней';
      cleanNoteEl.classList.add('d-none');
      cleanNoteEl.textContent = '';
      cleanedTextEl.value = '';
      updateActionButtons();
      return;
    }
    cleanedWrap.classList.remove('d-none');
    cleanedCount.textContent = currentCleaned.length + ' дн.';
    if (note && String(note).trim()) {
      cleanNoteEl.textContent = note;
      cleanNoteEl.classList.remove('d-none');
    } else {
      cleanNoteEl.classList.add('d-none');
      cleanNoteEl.textContent = '';
    }
    currentCleaned.forEach(function (day, i) {
      cleanedList.appendChild(dayRowHtml(day, i, false));
    });
    // Always rebuild textarea from grouped cards (ignore old scatter order).
    cleanedTextEl.value = daysToTextarea(currentCleaned);
    updateActionButtons();
  }
  function renderReply(replies, fallbackText, meta) {
    replyEl.innerHTML = '';
    const list = Array.isArray(replies) ? replies.filter(function (r) { return r && r.text; }) : [];
    if (!list.length && fallbackText && String(fallbackText).trim()) {
      list.push({ label: 'Маршрут', text: fallbackText });
    }
    if (!list.length) {
      replyWrap.classList.add('d-none');
      return;
    }
    replyWrap.classList.remove('d-none');
    replyBadge.textContent = meta || (list.length > 1 ? (list.length + ' варианта') : 'готово');
    list.forEach(function (r) {
      const box = document.createElement('div');
      box.className = 'mb-3';
      const title = document.createElement('div');
      title.className = 'fw-semibold small mb-1';
      title.textContent = r.label || 'Маршрут';
      const pre = document.createElement('pre');
      pre.className = 'small mb-0 p-3 bg-white border rounded';
      pre.style.whiteSpace = 'pre-wrap';
      pre.style.maxHeight = '420px';
      pre.style.overflow = 'auto';
      pre.setAttribute('dir', 'auto');
      pre.textContent = r.text || '';
      box.appendChild(title);
      box.appendChild(pre);
      replyEl.appendChild(box);
    });
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
    if (timerId) { clearInterval(timerId); timerId = null; }
  }
  function setBusy(on, title, detail) {
    if (on) {
      progressBox.classList.remove('d-none');
      progressTitle.textContent = title || 'Ищем…';
      progressDetail.textContent = detail || '';
      startTimer();
      try {
        progressBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } catch (e) {}
    } else {
      stopTimer();
      progressBox.classList.add('d-none');
    }
  }

  function setCleanLoading(on) {
    const spin = document.getElementById('ftCleanSpinner');
    const label = document.getElementById('ftCleanLabel');
    const hint = document.getElementById('ftCleanHint');
    if (spin) {
      spin.classList.toggle('d-none', !on);
    }
    if (label) {
      label.textContent = on ? 'Каталог…' : cleanDefault;
    }
    if (hint) {
      hint.classList.toggle('d-none', !on);
    }
    cleanBtn.disabled = on || !currentCurated.length;
  }

  function addBubble(role, text) {
    const wrap = document.createElement('div');
    wrap.className = 'mb-2';
    const who = document.createElement('div');
    who.className = 'small fw-semibold ' + (role === 'user' ? 'text-primary' : 'text-success');
    who.textContent = role === 'user' ? 'You' : 'System';
    const body = document.createElement('div');
    body.className = 'p-2 rounded bg-white border small';
    body.style.whiteSpace = 'pre-wrap';
    body.setAttribute('dir', 'auto');
    body.textContent = text;
    wrap.appendChild(who);
    wrap.appendChild(body);
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
      const head = document.createElement('div');
      head.className = 'fw-semibold small';
      head.textContent = (i + 1) + '. ' + (s.title || s.type || '');
      const detail = document.createElement('div');
      detail.className = 'small';
      detail.style.whiteSpace = 'pre-wrap';
      detail.textContent = s.detail || '';
      box.appendChild(head);
      box.appendChild(detail);
      stepsEl.appendChild(box);
    });
    stepsEl.scrollTop = stepsEl.scrollHeight;
  }

  function appendAccordionItem(collapseId, headerText, metaText, bodyText, langLabel) {
    const item = document.createElement('div');
    item.className = 'accordion-item';
    item.innerHTML =
      '<h2 class="accordion-header">' +
        '<button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#' + collapseId + '">' +
          '<span class="badge bg-label-secondary me-2"></span>' +
          '<span class="me-2 title-bit"></span>' +
        '</button>' +
      '</h2>' +
      '<div id="' + collapseId + '" class="accordion-collapse collapse" data-bs-parent="#ftAccordion">' +
        '<div class="accordion-body p-2">' +
          '<div class="small text-muted mb-2 meta-bit"></div>' +
          '<pre class="small mb-0 p-2 bg-white border rounded" style="white-space:pre-wrap;max-height:320px;overflow:auto;" dir="auto"></pre>' +
        '</div>' +
      '</div>';
    item.querySelector('.badge').textContent = langLabel;
    item.querySelector('.title-bit').textContent = headerText;
    item.querySelector('.meta-bit').textContent = metaText;
    item.querySelector('pre').textContent = bodyText || '(пусто)';
    accordion.appendChild(item);
  }

  function renderSources(sources) {
    currentSources = Array.isArray(sources) ? sources : [];
    accordion.innerHTML = '';
    if (!currentSources.length) {
      accordion.classList.add('d-none');
      accordionEmpty.classList.remove('d-none');
      sourceCount.textContent = '0 источников';
      updateActionButtons();
      return;
    }
    accordionEmpty.classList.add('d-none');
    accordion.classList.remove('d-none');
    const withRu = currentSources.filter(function (s) {
      return s.preview_text_ru && String(s.preview_text_ru).trim();
    }).length;
    sourceCount.textContent = currentSources.length + ' источник(ов)'
      + (withRu ? (' · RU: ' + withRu) : '');

    currentSources.forEach(function (src, i) {
      const matched = Array.isArray(src.matched_locations) ? src.matched_locations.filter(Boolean) : [];
      const routeLines = Array.isArray(src.route_lines) ? src.route_lines.filter(Boolean) : [];
      const routeCount = src.route_line_count != null ? src.route_line_count : routeLines.length;
      const titleBits = [
        (i + 1) + '.',
        src.model ? ('model ' + src.model) : null,
        src.product_id ? ('#' + src.product_id) : null,
        routeCount ? (routeCount + ' строк') : null,
        matched.length ? ('лок. ' + matched.length) : null,
        src.score != null ? ('score ' + src.score) : null,
        src.meta_lang ? ('lang ' + src.meta_lang) : null,
      ].filter(Boolean);
      const subtitle = src.title || '';
      const meta = (subtitle ? subtitle + ' · ' : '')
        + (matched.length ? matched.slice(0, 6).join(', ') : '')
        + (src.match_note ? (' · ' + src.match_note) : '');
      let heBody = '';
      if (routeLines.length) {
        heBody = routeLines.join('\n');
      } else if (src.preview_text && String(src.preview_text).trim()) {
        heBody = src.preview_text;
      } else if (src.preview_deferred) {
        heBody = '(строки маршрута не найдены / текст отложен)';
      } else {
        heBody = '(пусто)';
      }
      appendAccordionItem('ftSourceHe' + i, titleBits.join(' · '), meta, heBody, 'HE');
      if (src.preview_text_ru && String(src.preview_text_ru).trim()) {
        appendAccordionItem(
          'ftSourceRu' + i,
          titleBits.join(' · ') + ' · перевод',
          meta,
          src.preview_text_ru,
          'RU'
        );
      }
    });
    updateActionButtons();
  }

  async function readJson(res) {
    const raw = await res.text();
    try { return raw ? JSON.parse(raw) : {}; }
    catch (e) {
      throw new Error('HTTP ' + res.status + ' (не JSON). ' + (raw || '').replace(/\s+/g, ' ').slice(0, 300));
    }
  }

  async function pollUntilDone(runId) {
    pollCancelled = false;
    for (let ticks = 0; ticks < 600; ticks++) {
      if (pollCancelled) throw new Error('Опрос остановлен');
      const res = await fetch(statusUrl(runId), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      if (Array.isArray(data.steps) && data.steps.length) renderSteps(data.steps);
      const status = data.status || '';
      if (status === 'queued') {
        setBusy(true, 'В очереди…', 'Нужен php artisan queue:work');
      } else if (status === 'running' || status === 'translating' || status === 'curating'
        || status === 'cleaning' || status === 'translating_pool') {
        const last = (data.steps || []).slice(-1)[0];
        let fallback = 'Поиск…';
        if (status === 'translating') fallback = 'Перевод сырья…';
        else if (status === 'curating') fallback = 'Извлечение дней…';
        else if (status === 'cleaning') fallback = 'Очистка пула…';
        else if (status === 'translating_pool') fallback = 'Перевод пула…';
        setBusy(true, last && last.title ? last.title : fallback, last && last.detail ? last.detail : '');
      } else if (status === 'done') {
        return data;
      } else if (status === 'failed') {
        throw new Error(data.error || 'Failed');
      }
      await sleep(1500);
    }
    throw new Error('Таймаут ожидания. Проверьте queue:work.');
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
        body: JSON.stringify({ prompt: promptInput.value, mode: 'full_tour' }),
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

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const message = (input.value || '').trim();
    if (!message || busy) return;
    clearError();
    stopPoll();
    addBubble('user', message);
    input.value = '';
    busy = true;
    sendBtn.disabled = true;
    sendBtn.textContent = 'Разбор…';
    updateActionButtons();

    const refining = !!currentRunId && !!currentPlan && currentSources.length === 0;
    setBusy(true, refining ? 'Уточняем план…' : 'Разбираем запрос…', 'Что будем искать в векторной базе');

    if (!refining) {
      rememberRunId(null);
      renderSources([]);
      renderCurated([], [], '', '');
      renderCleaned([], '', '');
      renderReply([], '', '');
      renderPlan(null);
    }

    try {
      const url = refining ? refinePlanUrl(currentRunId) : planUrl;
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ message: message }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok || !data.run_id) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      rememberRunId(data.run_id);
      renderSteps(data.steps || [{ type: 'final', title: 'План поиска', detail: '' }]);
      renderPlan(data.search_plan || null, 'ожидает подтверждения');
      addBubble('model', data.reply || (data.search_plan && data.search_plan.reply) || 'План готов');
      planWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      setBusy(false);
      busy = false;
      sendBtn.disabled = false;
      sendBtn.textContent = currentPlan ? 'Уточнить план' : sendDefault;
      updateActionButtons();
      input.focus();
    }
  });

  searchBtn.addEventListener('click', async function () {
    if (!currentRunId || !currentPlan || searchBtn.disabled || busy) return;
    clearError();
    stopPoll();
    busy = true;
    sendBtn.disabled = true;
    searchBtn.textContent = 'Ищем…';
    updateActionButtons();
    setBusy(true, 'Ставим поиск в очередь…', 'Вектор по утверждённому плану');

    try {
      const res = await fetch(startUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          run_id: currentRunId,
          prompt: promptInput.value,
          mode: 'preview',
        }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok || !data.run_id) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      rememberRunId(data.run_id);
      const result = await pollUntilDone(data.run_id);
      const sources = result.sources || result.tours || [];
      applyRunResult(result, {
        bubble: 'Найдено источников: ' + sources.length
          + (result.pool_days != null ? (' · пул ~' + result.pool_days + ' дн.') : '')
          + (result.search_ms != null ? (' · ' + result.search_ms + 'ms') : ''),
      });
      planBadge.textContent = 'поиск выполнен';
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      busy = false;
      sendBtn.disabled = false;
      sendBtn.textContent = currentPlan ? 'Уточнить план' : sendDefault;
      searchBtn.textContent = searchDefault;
      updateActionButtons();
    }
  });

  translateBtn.addEventListener('click', async function () {
    if (!currentRunId || translateBtn.disabled || busy) return;
    clearError();
    stopPoll();
    busy = true;
    sendBtn.disabled = true;
    translateBtn.textContent = 'Перевод…';
    updateActionButtons();
    setBusy(true, 'Ставим перевод в очередь…', 'HE→RU по кускам через Gemini');

    try {
      const res = await fetch(translateUrl(currentRunId), {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      const result = await pollUntilDone(currentRunId);
      const sources = result.sources || result.tours || [];
      applyRunResult(result, {
        bubble: 'Перевод готов: '
          + sources.filter(function (s) { return s.preview_text_ru; }).length
          + ' / ' + sources.length
          + (result.translate_ms != null ? (' · ' + result.translate_ms + 'ms') : ''),
      });
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      busy = false;
      sendBtn.disabled = false;
      translateBtn.textContent = translateDefault;
      updateActionButtons();
    }
  });

  curateBtn.addEventListener('click', async function () {
    if (!currentRunId || curateBtn.disabled || busy) return;
    clearError();
    stopPoll();
    busy = true;
    sendBtn.disabled = true;
    curateBtn.textContent = 'Отбор…';
    updateActionButtons();
    setBusy(true, 'Ставим извлечение в очередь…', 'Все дневные блоки маршрута (без фильтра опросника)');

    try {
      const res = await fetch(curateUrl(currentRunId), {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      const result = await pollUntilDone(currentRunId);
      const days = result.curated_days || [];
      applyRunResult(result, {
        bubble: 'Извлечено дней маршрута: ' + days.length
          + (result.curate_ms != null ? (' · ' + result.curate_ms + 'ms') : ''),
      });
      curatedWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      busy = false;
      sendBtn.disabled = false;
      curateBtn.textContent = curateDefault;
      updateActionButtons();
    }
  });

  cleanBtn.addEventListener('click', async function () {
    if (!currentRunId || cleanBtn.disabled || busy) return;
    if (!currentCurated.length && !(curatedTextEl.value || '').trim()) {
      showError('Сначала нажмите «Извлечь дни»');
      return;
    }
    clearError();
    stopPoll();
    busy = true;
    sendBtn.disabled = true;
    setCleanLoading(true);
    updateActionButtons();
    setBusy(true, 'Релевантный список (RU)…', 'PHP-дедуп → Gemini: полный каталог на русском (не маршрут). Можно уйти — run хранится ~2 суток.');

    try {
      const res = await fetch(cleanUrl(currentRunId), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          curated_text: curatedTextEl.value || '',
        }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      const result = await pollUntilDone(currentRunId);
      const days = result.cleaned_days || [];
      applyRunResult(result, {
        bubble: 'Релевантный каталог (RU): ' + days.length + ' дн.'
          + (result.clean_ms != null ? (' · ' + result.clean_ms + 'ms') : ''),
      });
      cleanedWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      setCleanLoading(false);
      busy = false;
      sendBtn.disabled = false;
      updateActionButtons();
    }
  });

  translatePoolBtn.addEventListener('click', async function () {
    if (!currentRunId || translatePoolBtn.disabled || busy) return;
    const hasPool = currentCleaned.length > 0
      || (cleanedTextEl.value || '').trim()
      || currentCurated.length > 0;
    if (!hasPool) {
      showError('Сначала «Релевантный список (RU)» (или «Извлечь дни»)');
      return;
    }
    clearError();
    stopPoll();
    busy = true;
    sendBtn.disabled = true;
    translatePoolBtn.textContent = 'Перевод…';
    updateActionButtons();
    setBusy(true, 'Ставим перевод пула…', 'HE→RU в карточках дней');

    try {
      const res = await fetch(translatePoolUrl(currentRunId), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          cleaned_text: cleanedTextEl.value || curatedTextEl.value || '',
        }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      const result = await pollUntilDone(currentRunId);
      applyRunResult(result, {
        bubble: 'Пул на русском · '
          + ((result.cleaned_days || []).length || currentCleaned.length) + ' дн.'
          + (result.pool_translate_ms != null ? (' · ' + result.pool_translate_ms + 'ms') : ''),
      });
      cleanedWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      busy = false;
      sendBtn.disabled = false;
      translatePoolBtn.textContent = translatePoolDefault;
      updateActionButtons();
    }
  });

  async function runAssemble(variants) {
    if (!currentRunId || busy) return;
    const hasPool = currentCleaned.length > 0
      || (cleanedTextEl.value || '').trim()
      || currentCurated.length > 0
      || (curatedTextEl.value || '').trim();
    if (!hasPool) {
      showError('Сначала «Извлечь дни», лучше ещё «Релевантный список (RU)»');
      return;
    }
    clearError();
    stopPoll();
    busy = true;
    sendBtn.disabled = true;
    assembleBtn.textContent = variants > 1 ? '2 варианта…' : 'Сборка…';
    assemble2Btn.textContent = variants > 1 ? '2 варианта…' : assemble2Default;
    updateActionButtons();
    setBusy(true, 'Ставим сборку в очередь…', variants > 1
      ? 'Два варианта из чистого пула'
      : 'Один маршрут из чистого пула');

    try {
      const res = await fetch(assembleUrl(currentRunId), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          prompt: promptInput.value,
          variants: variants,
          curated_text: curatedTextEl.value || '',
          cleaned_text: cleanedTextEl.value || '',
        }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      const result = await pollUntilDone(currentRunId);
      const nReplies = (result.replies || []).length || (result.reply ? 1 : 0);
      const poolLen = (result.cleaned_days || currentCleaned).length
        || (result.curated_days || currentCurated).length;
      applyRunResult(result, {
        bubble: nReplies
          ? ('Собрано вариантов: ' + nReplies + ' из пула / ' + poolLen + ' карточек')
          : 'Сборка завершилась без текста',
      });
      if (nReplies) {
        replyWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    } catch (ex) {
      showError(ex.message || String(ex));
    } finally {
      stopPoll();
      setBusy(false);
      busy = false;
      sendBtn.disabled = false;
      assembleBtn.textContent = assembleDefault;
      assemble2Btn.textContent = assemble2Default;
      updateActionButtons();
    }
  }

  assembleBtn.addEventListener('click', function () {
    if (assembleBtn.disabled) return;
    runAssemble(1);
  });
  assemble2Btn.addEventListener('click', function () {
    if (assemble2Btn.disabled) return;
    runAssemble(2);
  });

  clearBtn.addEventListener('click', function () {
    stopPoll();
    rememberRunId(null);
    log.innerHTML = '<div class="text-muted small">Cleared.</div>';
    renderSteps([]);
    renderPlan(null);
    renderSources([]);
    renderCurated([], [], '', '');
    renderCleaned([], '', '');
    renderReply([], '', '');
    clearError();
    sendBtn.textContent = sendDefault;
    updateActionButtons();
  });

  async function restoreLastRun() {
    const savedId = loadRememberedRunId();
    if (!savedId) return;

    try {
      const res = await fetch(statusUrl(savedId), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) {
        rememberRunId(null);
        return;
      }

      rememberRunId(savedId);
      const status = data.status || '';
      const inFlight = status === 'queued' || status === 'running'
        || status === 'translating' || status === 'curating'
        || status === 'cleaning' || status === 'translating_pool';

      if (data.message) {
        addBubble('user', data.message);
      }
      applyRunResult(data, {
        bubble: inFlight
          ? ('Восстановлен run · статус: ' + status)
          : ('Восстановлено после F5 · ' + (data.mode || 'preview')
            + (status === 'failed' ? ' · ошибка' : '')),
      });
      if (data.mode === 'plan' || (data.search_plan && !(data.sources || []).length)) {
        sendBtn.textContent = 'Уточнить план';
      }

      if (status === 'failed' && data.error) {
        showError(data.error);
      }

      if (inFlight) {
        busy = true;
        sendBtn.disabled = true;
        updateActionButtons();
        setBusy(true, 'Восстановление…', 'Продолжаем опрос статуса');
        try {
          const result = await pollUntilDone(savedId);
          applyRunResult(result, {
            bubble: 'Готово после восстановления · ' + (result.mode || status),
          });
        } catch (ex) {
          showError(ex.message || String(ex));
        } finally {
          stopPoll();
          setBusy(false);
          busy = false;
          sendBtn.disabled = false;
          updateActionButtons();
        }
      }
    } catch (ex) {
      rememberRunId(null);
    }
  }

  restoreLastRun();
})();
</script>
@endsection
