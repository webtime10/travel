@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Gemini сценарий</h4>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="{{ route('admin.test.index') }}" class="btn btn-sm btn-outline-secondary">← Test</a>
    <a href="{{ route('admin.test.chain') }}" class="btn btn-sm btn-outline-secondary">Gemini — 3 проверки</a>
    <a href="{{ route('admin.test.locations') }}" class="btn btn-sm btn-outline-secondary">Поиск мест (PHP)</a>
    <span class="badge bg-label-success">В векторе: {{ (int) $indexed }}</span>
    <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
  </div>
</div>

<div class="alert alert-info mb-4">
  <div class="fw-semibold mb-1">Два шага Gemini + PHP-геофильтр</div>
  <ol class="mb-0 small ps-3">
    <li>Сборка: POI + историческая логика участков + <strong>TRANSFER candidates</strong> (PHP по lat/lng, bad отсекается)</li>
    <li>Аудит: перегруз/недогруз + nearby (авто после сборки)</li>
  </ol>
</div>

<div class="card">
  <div class="card-body">
    <form id="scForm" class="row g-2 align-items-end mb-3">
      <div class="col-12 col-lg">
        <label class="form-label small mb-1" for="scInput">Запрос</label>
        <input type="text" id="scInput" class="form-control" autocomplete="off"
          placeholder="Гарда → Доломиты, 10 дней, озёра и треки">
      </div>
      <div class="col-6 col-md-2 col-lg-1">
        <label class="form-label small mb-1" for="scDays">Дней</label>
        <input type="number" id="scDays" class="form-control" value="10" min="5" max="14">
      </div>
      <div class="col-6 col-md-2 col-lg-1">
        <label class="form-label small mb-1" for="scPoi">POI</label>
        <input type="number" id="scPoi" class="form-control" value="48" min="12" max="60">
      </div>
      <div class="col-6 col-md-2 col-lg-1">
        <label class="form-label small mb-1" for="scSamples">Образцы</label>
        <input type="number" id="scSamples" class="form-control" value="5" min="3" max="5">
      </div>
      <div class="col-6 col-md-auto">
        <button type="submit" class="btn btn-primary" id="scRun">Собрать + аудит</button>
        <button type="button" class="btn btn-outline-primary" id="scAuditOnly" disabled>Только аудит</button>
        <button type="button" class="btn btn-outline-secondary" id="scClear">Clear</button>
      </div>
    </form>

    <div id="scProgress" class="alert alert-primary d-none mb-3 py-3" role="status">
      <div class="d-flex align-items-start gap-3">
        <div class="spinner-border text-primary flex-shrink-0 mt-1"></div>
        <div>
          <div class="fw-semibold" id="scProgressTitle">Gemini пишет сценарий…</div>
          <div class="small" id="scProgressDetail">POI + исторические паттерны → маршрут</div>
        </div>
      </div>
    </div>

    <div id="scError" class="alert alert-danger mb-3 py-2" style="display:none;white-space:pre-wrap;"></div>
    <div id="scMeta" class="small text-muted mb-3" style="display:none;"></div>

    <div id="scRouteWrap" class="mb-4" style="display:none;">
      <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
        <h5 class="mb-0" id="scRouteTitle">Маршрут</h5>
        <span class="small text-muted" id="scRouteSub"></span>
      </div>
      <div id="scRoute" class="border rounded p-3 bg-white" style="max-height:720px;overflow:auto;"></div>
    </div>

    <div id="scAuditWrap" class="mb-4" style="display:none;">
      <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
        <h5 class="mb-0">Аудит</h5>
        <span class="small text-muted" id="scAuditSub"></span>
      </div>
      <div id="scAudit" class="border rounded p-3 bg-white" style="max-height:720px;overflow:auto;"></div>
    </div>

    <div id="scTransferWrap" class="mb-4" style="display:none;">
      <div class="fw-semibold mb-2">Геофильтр + кластеры + load (PHP)</div>
      <pre id="scTransferGeo" class="border rounded p-3 bg-white small mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap;"></pre>
    </div>

    <details class="mb-3" id="scDiagWrap" style="display:none;">
      <summary class="small fw-semibold">Диагностика запуска (до scrub)</summary>
      <div class="small text-muted mb-1 mt-2">stay_clusters_prompt → @{{TRANSFERS}}</div>
      <pre id="scStayClustersPrompt" class="border rounded p-3 bg-light small mb-2" style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
      <div class="small text-muted mb-1">transfers_block (полный блок @{{TRANSFERS}})</div>
      <pre id="scTransfersBlock" class="border rounded p-3 bg-light small mb-2" style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
      <div class="small text-muted mb-1">gemini_plan_before_scrub (JSON после parse, до scrub)</div>
      <pre id="scPlanBeforeScrub" class="border rounded p-3 bg-light small mb-2" style="max-height:360px;overflow:auto;white-space:pre-wrap;"></pre>
      <div class="small text-muted mb-1">gemini_raw_before_scrub</div>
      <pre id="scRawBeforeScrub" class="border rounded p-3 bg-light small mb-0" style="max-height:240px;overflow:auto;white-space:pre-wrap;"></pre>
    </details>

    <div class="row g-3">
      <div class="col-12 col-xl-6">
        <div class="fw-semibold mb-2">Образцы директора</div>
        <pre id="scSamplesOut" class="border rounded p-3 bg-light small mb-0" style="min-height:100px;max-height:240px;overflow:auto;white-space:pre-wrap;"></pre>
      </div>
      <div class="col-12 col-xl-6">
        <div class="fw-semibold mb-2">POI каталога</div>
        <pre id="scPois" class="border rounded p-3 bg-light small mb-0" style="min-height:100px;max-height:240px;overflow:auto;white-space:pre-wrap;"></pre>
      </div>
    </div>

    <details class="mt-3">
      <summary class="small text-muted">JSON плана</summary>
      <pre id="scPlan" class="border rounded p-3 bg-light small mt-2 mb-0" style="max-height:360px;overflow:auto;white-space:pre-wrap;"></pre>
    </details>
    <details class="mt-2">
      <summary class="small text-muted">JSON аудита</summary>
      <pre id="scAuditJson" class="border rounded p-3 bg-light small mt-2 mb-0" style="max-height:360px;overflow:auto;white-space:pre-wrap;"></pre>
    </details>
    <details class="mt-2">
      <summary class="small text-muted">raw_text сборки</summary>
      <pre id="scRaw" class="border rounded p-3 bg-light small mt-2 mb-0" style="max-height:240px;overflow:auto;white-space:pre-wrap;"></pre>
    </details>
    <details class="mt-2">
      <summary class="small text-muted">raw_text аудита</summary>
      <pre id="scAuditRaw" class="border rounded p-3 bg-light small mt-2 mb-0" style="max-height:240px;overflow:auto;white-space:pre-wrap;"></pre>
    </details>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <div>
      <span class="fw-semibold">Промпты сборки</span>
      <div class="small text-muted">Плейсхолдеры: @{{QUERY}}, @{{DAYS}}, @{{SAMPLES}}, @{{POIS}}, @{{TRANSFERS}}</div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span id="scPromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="scPromptReset" title="Сбросить к умолчанию">Сброс</button>
      <button type="button" class="btn btn-icon btn-sm btn-primary" id="scPromptSave" title="Save">
        <i class="bx bx-save"></i>
      </button>
    </div>
  </div>
  <div class="card-body">
    <label class="form-label fw-semibold" for="scSystemPrompt">System prompt (сборка)</label>
    <textarea
      id="scSystemPrompt"
      class="form-control font-monospace mb-4"
      rows="14"
      spellcheck="false"
    >{{ $systemPrompt }}</textarea>

    <label class="form-label fw-semibold" for="scUserPrompt">User prompt (сборка)</label>
    <textarea
      id="scUserPrompt"
      class="form-control font-monospace"
      rows="12"
      spellcheck="false"
    >{{ $userPrompt }}</textarea>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <div>
      <span class="fw-semibold">Промпты аудита</span>
      <div class="small text-muted">Плейсхолдеры: @{{QUERY}}, @{{PLAN}}, @{{SAMPLES}}, @{{POIS}}, @{{DAYS}}</div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="scAuditPromptReset">Сброс аудита</button>
    </div>
  </div>
  <div class="card-body">
    <label class="form-label fw-semibold" for="scAuditSystemPrompt">System prompt (аудит)</label>
    <textarea
      id="scAuditSystemPrompt"
      class="form-control font-monospace mb-4"
      rows="18"
      spellcheck="false"
    >{{ $auditSystemPrompt }}</textarea>

    <label class="form-label fw-semibold" for="scAuditUserPrompt">User prompt (аудит)</label>
    <textarea
      id="scAuditUserPrompt"
      class="form-control font-monospace"
      rows="12"
      spellcheck="false"
    >{{ $auditUserPrompt }}</textarea>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const form = document.getElementById('scForm');
  const input = document.getElementById('scInput');
  const days = document.getElementById('scDays');
  const poi = document.getElementById('scPoi');
  const samples = document.getElementById('scSamples');
  const runBtn = document.getElementById('scRun');
  const auditOnlyBtn = document.getElementById('scAuditOnly');
  const clearBtn = document.getElementById('scClear');
  const progress = document.getElementById('scProgress');
  const progressTitle = document.getElementById('scProgressTitle');
  const progressDetail = document.getElementById('scProgressDetail');
  const err = document.getElementById('scError');
  const meta = document.getElementById('scMeta');
  const routeWrap = document.getElementById('scRouteWrap');
  const routeEl = document.getElementById('scRoute');
  const routeTitle = document.getElementById('scRouteTitle');
  const routeSub = document.getElementById('scRouteSub');
  const auditWrap = document.getElementById('scAuditWrap');
  const auditEl = document.getElementById('scAudit');
  const auditSub = document.getElementById('scAuditSub');
  const planEl = document.getElementById('scPlan');
  const auditJsonEl = document.getElementById('scAuditJson');
  const samplesEl = document.getElementById('scSamplesOut');
  const poisEl = document.getElementById('scPois');
  const transferWrap = document.getElementById('scTransferWrap');
  const transferEl = document.getElementById('scTransferGeo');
  const diagWrap = document.getElementById('scDiagWrap');
  const stayClustersPromptEl = document.getElementById('scStayClustersPrompt');
  const transfersBlockEl = document.getElementById('scTransfersBlock');
  const planBeforeScrubEl = document.getElementById('scPlanBeforeScrub');
  const rawBeforeScrubEl = document.getElementById('scRawBeforeScrub');
  const rawEl = document.getElementById('scRaw');
  const auditRawEl = document.getElementById('scAuditRaw');
  const systemPromptEl = document.getElementById('scSystemPrompt');
  const userPromptEl = document.getElementById('scUserPrompt');
  const auditSystemPromptEl = document.getElementById('scAuditSystemPrompt');
  const auditUserPromptEl = document.getElementById('scAuditUserPrompt');
  const promptSave = document.getElementById('scPromptSave');
  const promptReset = document.getElementById('scPromptReset');
  const auditPromptReset = document.getElementById('scAuditPromptReset');
  const promptStatus = document.getElementById('scPromptStatus');
  const planUrl = @json(route('admin.test.scenario.plan'));
  const auditUrl = @json(route('admin.test.scenario.audit'));
  const promptUrl = @json(route('admin.test.prompt.save'));
  const csrf = @json(csrf_token());
  const defaultSystemPrompt = @json($defaultSystemPrompt);
  const defaultUserPrompt = @json($defaultUserPrompt);
  const defaultAuditSystemPrompt = @json($defaultAuditSystemPrompt);
  const defaultAuditUserPrompt = @json($defaultAuditUserPrompt);

  let lastQuery = '';
  let lastPlan = null;
  let lastPois = [];
  let lastSamples = [];

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showError(text) {
    err.style.display = 'block';
    err.textContent = text;
  }
  function clearError() {
    err.style.display = 'none';
    err.textContent = '';
  }
  function setBusy(busy, title, detail) {
    runBtn.disabled = busy;
    auditOnlyBtn.disabled = busy || !lastPlan;
    progress.classList.toggle('d-none', !busy);
    if (title) progressTitle.textContent = title;
    if (detail) progressDetail.textContent = detail;
  }

  function formatSamples(list) {
    if (!list || !list.length) return '(нет похожих скелетов)';
    return list.map(function (s, i) {
      return '=== ' + (i + 1) + '. ' + (s.title || '') + ' (#' + (s.source_route_id || s.id || '') + ', '
        + (s.days_count || '?') + ' дн.) ===\n' + (s.brief || '');
    }).join('\n\n');
  }

  function formatPois(list) {
    if (!list || !list.length) return '(пусто)';
    return list.map(function (p) {
      return '#' + p.id + '  ' + (p.name || '')
        + (p.is_base ? '  [base]' : '')
        + (p.role ? '  ' + p.role : '')
        + (p.lat != null ? '  ' + p.lat + ',' + p.lng : '');
    }).join('\n');
  }

  function placeLine(p) {
    if (!p || typeof p !== 'object') return '';
    const name = p.name || ('#' + (p.id || '?'));
    const id = p.id != null ? ' #' + p.id : '';
    const hours = p.hours != null && p.hours !== '' ? ' · ~' + p.hours + ' ч' : '';
    const role = p.role ? ' · ' + p.role : '';
    const note = p.note ? ' — ' + p.note : '';
    return escapeHtml(name) + '<span class="text-muted">' + escapeHtml(id + hours + role) + '</span>'
      + (note ? '<span class="text-muted">' + escapeHtml(note) + '</span>' : '');
  }

  function renderTimeBudget(tb) {
    if (!tb || typeof tb !== 'object' || !Array.isArray(tb.lines) || !tb.lines.length) return '';
    const feelMap = { thin: 'warning', heavy: 'danger', light: 'info', ok: 'success' };
    const feelCls = feelMap[tb.feel] || 'secondary';
    const feelLabel = ({ thin: 'день тонкий', heavy: 'день плотный', light: 'лёгкий', ok: 'норм' })[tb.feel] || '';
    let rows = tb.lines.map(function (ln) {
      return '<div class="d-flex justify-content-between gap-2">'
        + '<span>' + escapeHtml(ln.label || '') + '</span>'
        + '<span class="text-nowrap">~' + escapeHtml(String(ln.hours)) + ' ч</span>'
        + '</div>';
    }).join('');
    return '<div class="border rounded p-2 mt-2 bg-light small">'
      + '<div class="d-flex justify-content-between align-items-center mb-1">'
      + '<span class="fw-semibold">На что уходит день</span>'
      + (feelLabel ? '<span class="badge bg-label-' + feelCls + '">' + escapeHtml(feelLabel) + '</span>' : '')
      + '</div>'
      + rows
      + '<div class="d-flex justify-content-between gap-2 mt-1 pt-1 border-top fw-semibold">'
      + '<span>Итого с дорогой / обедом / логистикой</span>'
      + '<span class="text-nowrap">~' + escapeHtml(String(tb.total_hours)) + ' ч</span>'
      + '</div>'
      + (tb.note ? '<div class="text-muted mt-1" style="font-size:0.75rem;">' + escapeHtml(tb.note) + '</div>' : '')
      + '</div>';
  }

  function loadBadge(load) {
    const map = {
      ok: 'success',
      light: 'info',
      heavy: 'warning',
      overload: 'danger',
      underload: 'primary',
    };
    const cls = map[load] || 'secondary';
    return '<span class="badge bg-label-' + cls + '">' + escapeHtml(load || '?') + '</span>';
  }

  function compactPlace(p) {
    if (!p || typeof p !== 'object') return '';
    const id = p.id != null ? p.id : '?';
    const name = (p.name && String(p.name).trim()) ? String(p.name).trim() : ('#' + id);
    return name + ' [id=' + id + ']';
  }

  function renderCompactDayLine(day, prevNightId) {
    const date = day.date || '—';
    const oid = day.overnight && day.overnight.id != null ? Number(day.overnight.id) : 0;
    const theme = String(day.theme || '').toLowerCase();
    const isTransfer = theme.indexOf('переезд') >= 0 || theme.indexOf('transfer') >= 0;
    const places = Array.isArray(day.places) ? day.places : [];
    const acts = [];
    places.forEach(function (p) {
      if (!p || typeof p !== 'object') return;
      const pid = p.id != null ? Number(p.id) : 0;
      const role = String(p.role || '');
      if (role === 'base' || (oid > 0 && pid === oid)) return;
      // hours=0 (Limone в пароме) — всё равно в списке
      const line = compactPlace(p);
      if (line) acts.push(line);
    });
    let mid = acts.length ? acts.join(', ') : '—';
    let transferBit = '';
    if (isTransfer && oid > 0 && prevNightId != null && oid !== prevNightId) {
      const oname = (day.overnight && day.overnight.name) ? String(day.overnight.name).trim() : ('#' + oid);
      transferBit = '. Переезд в ' + oname;
    }
    const night = day.overnight
      ? ('. Ночёвка: ' + compactPlace(day.overnight))
      : '';
    return String(date) + ' — ' + mid + transferBit + night;
  }

  function renderRoute(plan) {
    if (!plan || typeof plan !== 'object') {
      routeWrap.style.display = 'none';
      routeEl.innerHTML = '';
      return;
    }
    const dayList = Array.isArray(plan.days) ? plan.days : [];
    routeTitle.textContent = plan.title || 'Маршрут';
    routeSub.textContent = dayList.length ? (dayList.length + ' дн.') : '';
    if (!dayList.length) {
      routeWrap.style.display = 'block';
      routeEl.innerHTML = '<div class="text-muted">В ответе нет массива days — смотри JSON / raw ниже.</div>';
      return;
    }

    let prevNight = null;
    let compactLines = [];
    dayList.forEach(function (day) {
      compactLines.push(escapeHtml(renderCompactDayLine(day, prevNight)));
      if (day && day.overnight && day.overnight.id != null) {
        prevNight = Number(day.overnight.id);
      }
    });

    let html = '<pre class="border rounded p-3 bg-light mb-3 small mb-0" style="white-space:pre-wrap;font-family:inherit;">'
      + compactLines.join('\n')
      + '</pre>';

    html += '<details class="mb-2"><summary class="small text-muted" style="cursor:pointer;">Подробнее (часы / тайминг)</summary>';
    prevNight = null;
    dayList.forEach(function (day, idx) {
      const n = idx + 1;
      const date = day.date || ('день ' + n);
      const theme = day.theme ? ' · ' + day.theme : '';
      const summary = day.summary ? '<div class="small text-muted mb-2">' + escapeHtml(day.summary) + '</div>' : '';
      const overnight = day.overnight && (day.overnight.name || day.overnight.id)
        ? '<div class="small mb-2"><span class="fw-semibold">Ночёвка:</span> '
          + placeLine(day.overnight) + '</div>'
        : '';
      const places = Array.isArray(day.places) ? day.places : [];
      let placesHtml = '';
      if (places.length) {
        placesHtml = '<ul class="mb-2 ps-3">' + places.map(function (p) {
          return '<li class="mb-1">' + placeLine(p) + '</li>';
        }).join('') + '</ul>';
      } else {
        placesHtml = '<div class="small text-muted mb-2">Мест в дне нет</div>';
      }
      const total = day.total_hours != null
        ? '<div class="small text-muted">POI только ~' + escapeHtml(String(day.total_hours)) + ' ч</div>'
        : '';
      const budget = renderTimeBudget(day.time_budget);

      html += '<div class="mb-3 pb-3' + (idx < dayList.length - 1 ? ' border-bottom' : '') + '">'
        + '<div class="fw-semibold mb-1">День ' + n + ' · ' + escapeHtml(String(date))
        + '<span class="text-muted fw-normal">' + escapeHtml(theme) + '</span></div>'
        + summary + overnight + placesHtml + total + budget
        + '</div>';
    });
    html += '</details>';

    if (Array.isArray(plan.notes) && plan.notes.length) {
      html += '<div class="mt-2 pt-2 border-top"><div class="fw-semibold small mb-1">Заметки</div><ul class="small mb-0 ps-3">'
        + plan.notes.map(function (n) { return '<li>' + escapeHtml(n) + '</li>'; }).join('')
        + '</ul></div>';
    }

    routeEl.innerHTML = html;
    routeWrap.style.display = 'block';
  }

  function renderAudit(audit) {
    if (!audit || typeof audit !== 'object') {
      auditWrap.style.display = 'none';
      auditEl.innerHTML = '';
      return;
    }

    const score = audit.overall_score != null ? String(audit.overall_score) : '—';
    auditSub.textContent = 'оценка ' + score + '/10';

    let html = '';
    if (audit.overall_summary) {
      html += '<div class="mb-3"><div class="fw-semibold mb-1">Итог</div><div>'
        + escapeHtml(audit.overall_summary) + '</div></div>';
    }

    if (Array.isArray(audit.top_fixes) && audit.top_fixes.length) {
      html += '<div class="mb-3"><div class="fw-semibold mb-1">Главные правки</div><ol class="mb-0 ps-3">'
        + audit.top_fixes.map(function (f) { return '<li class="mb-1">' + escapeHtml(f) + '</li>'; }).join('')
        + '</ol></div>';
    }

    if (Array.isArray(audit.do_not_add) && audit.do_not_add.length) {
      html += '<div class="mb-3"><div class="fw-semibold mb-1">Не добавлять</div><ul class="mb-0 ps-3">'
        + audit.do_not_add.map(function (f) { return '<li class="mb-1">' + escapeHtml(f) + '</li>'; }).join('')
        + '</ul></div>';
    }

    const dayList = Array.isArray(audit.days) ? audit.days : [];
    dayList.forEach(function (day, idx) {
      const n = day.day != null ? day.day : (idx + 1);
      const date = day.date || '';
      html += '<div class="mb-3 pb-3' + (idx < dayList.length - 1 ? ' border-bottom' : '') + '">'
        + '<div class="d-flex flex-wrap gap-2 align-items-center mb-1">'
        + '<span class="fw-semibold">День ' + escapeHtml(String(n))
        + (date ? ' · ' + escapeHtml(String(date)) : '') + '</span>'
        + loadBadge(day.load)
        + (day.real_day_hours_estimate
          ? '<span class="small text-muted">реальный день ~' + escapeHtml(String(day.real_day_hours_estimate)) + ' ч</span>'
          : '')
        + '</div>';
      if (day.verdict) {
        html += '<div class="mb-2">' + escapeHtml(day.verdict) + '</div>';
      }

      const issues = Array.isArray(day.issues) ? day.issues : [];
      if (issues.length) {
        html += '<div class="small fw-semibold mb-1">Проблемы</div><ul class="small ps-3 mb-2">'
          + issues.map(function (iss) {
            return '<li class="mb-1"><span class="text-uppercase">' + escapeHtml(iss.severity || '')
              + '</span> · ' + escapeHtml(iss.type || '') + ': '
              + escapeHtml(iss.problem || '')
              + (iss.why ? ' <span class="text-muted">(' + escapeHtml(iss.why) + ')</span>' : '')
              + '</li>';
          }).join('') + '</ul>';
      }

      const nearby = Array.isArray(day.nearby_unused) ? day.nearby_unused : [];
      if (nearby.length) {
        html += '<div class="small fw-semibold mb-1">Рядом не включено</div><ul class="small ps-3 mb-2">'
          + nearby.map(function (n) {
            return '<li class="mb-1">' + escapeHtml(n.name || '') + ' #' + escapeHtml(String(n.id || ''))
              + ' · ' + escapeHtml(n.suggestion || '')
              + (n.note ? ' — ' + escapeHtml(n.note) : '')
              + '</li>';
          }).join('') + '</ul>';
      }

      if (day.patch && day.patch.summary) {
        html += '<div class="small"><span class="fw-semibold">Правка ('
          + escapeHtml(day.patch.action || '') + '):</span> '
          + escapeHtml(day.patch.summary) + '</div>';
      }

      html += '</div>';
    });

    auditEl.innerHTML = html || '<div class="text-muted">Пустой аудит</div>';
    auditWrap.style.display = 'block';
  }

  function renderTransferGeo(tg) {
    if (!tg || !Array.isArray(tg.debug_lines) || !tg.debug_lines.length) {
      transferWrap.style.display = 'none';
      transferEl.textContent = '';
    } else {
      transferEl.textContent = tg.debug_lines.join('\n');
      transferWrap.style.display = 'block';
    }

    const hasDiag = tg && (
      tg.stay_clusters_prompt
      || tg.transfers_block
      || tg.gemini_raw_before_scrub
      || tg.gemini_plan_before_scrub
    );
    if (!hasDiag) {
      diagWrap.style.display = 'none';
      stayClustersPromptEl.textContent = '';
      transfersBlockEl.textContent = '';
      planBeforeScrubEl.textContent = '';
      rawBeforeScrubEl.textContent = '';
      return;
    }
    stayClustersPromptEl.textContent = tg.stay_clusters_prompt || '';
    transfersBlockEl.textContent = tg.transfers_block || '';
    planBeforeScrubEl.textContent = tg.gemini_plan_before_scrub
      ? JSON.stringify(tg.gemini_plan_before_scrub, null, 2)
      : '';
    rawBeforeScrubEl.textContent = tg.gemini_raw_before_scrub || '';
    diagWrap.style.display = 'block';
  }

  function resetOutputs() {
    routeWrap.style.display = 'none';
    routeEl.innerHTML = '';
    routeTitle.textContent = 'Маршрут';
    routeSub.textContent = '';
    auditWrap.style.display = 'none';
    auditEl.innerHTML = '';
    auditSub.textContent = '';
    transferWrap.style.display = 'none';
    transferEl.textContent = '';
    diagWrap.style.display = 'none';
    stayClustersPromptEl.textContent = '';
    transfersBlockEl.textContent = '';
    planBeforeScrubEl.textContent = '';
    rawBeforeScrubEl.textContent = '';
    planEl.textContent = '';
    auditJsonEl.textContent = '';
    samplesEl.textContent = '';
    poisEl.textContent = '';
    rawEl.textContent = '';
    auditRawEl.textContent = '';
    meta.style.display = 'none';
    meta.textContent = '';
    lastPlan = null;
    lastPois = [];
    lastSamples = [];
    lastQuery = '';
    auditOnlyBtn.disabled = true;
  }

  async function runAudit() {
    if (!lastPlan || !lastPois.length) {
      showError('Сначала соберите маршрут');
      return false;
    }
    setBusy(true, 'Gemini аудирует маршрут…', 'Перегруз / недогруз / nearby по каждому дню');
    try {
      const res = await fetch(auditUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          message: lastQuery,
          plan: lastPlan,
          pois: lastPois,
          samples: lastSamples,
          system_prompt: auditSystemPromptEl.value,
          user_prompt: auditUserPromptEl.value,
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      auditRawEl.textContent = data.raw_text || '';
      if (data.audit) {
        auditJsonEl.textContent = JSON.stringify(data.audit, null, 2);
        renderAudit(data.audit);
      } else {
        auditJsonEl.textContent = '';
        auditWrap.style.display = 'block';
        auditEl.innerHTML = '<div class="text-muted">Аудит не разобран. Смотри raw_text аудита.</div>';
      }
      meta.style.display = 'block';
      meta.textContent = (meta.textContent ? meta.textContent + ' · ' : '')
        + 'аудит ' + (data.gemini_ms || 0) + ' мс';
      if (!res.ok || data.ok === false) {
        showError(data.message || ('Аудит HTTP ' + res.status));
        return false;
      }
      return true;
    } catch (ex) {
      showError(String(ex && ex.message ? ex.message : ex));
      return false;
    } finally {
      setBusy(false);
    }
  }

  clearBtn.addEventListener('click', function () {
    clearError();
    resetOutputs();
  });

  auditOnlyBtn.addEventListener('click', async function () {
    clearError();
    await runAudit();
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();
    const message = (input.value || '').trim();
    if (!message) {
      showError('Введите запрос');
      return;
    }
    setBusy(true, 'Gemini пишет сценарий…', 'Поиск POI и скелетов, затем сборка…');
    resetOutputs();
    lastQuery = message;

    try {
      const res = await fetch(planUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          message: message,
          days: parseInt(days.value, 10) || 10,
          poi_limit: parseInt(poi.value, 10) || 48,
          sample_limit: parseInt(samples.value, 10) || 5,
          system_prompt: systemPromptEl.value,
          user_prompt: userPromptEl.value,
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      lastSamples = data.samples || [];
      lastPois = data.pois || [];
      samplesEl.textContent = formatSamples(lastSamples);
      poisEl.textContent = formatPois(lastPois);
      rawEl.textContent = data.raw_text || '';
      renderTransferGeo(data.transfer_geo || null);

      meta.style.display = 'block';
      meta.textContent = 'POI: ' + lastPois.length
        + ' · образцы: ' + lastSamples.length
        + ' · transfer legs: ' + (((data.transfer_geo || {}).legs || []).length)
        + ' · search ' + (data.search_ms || 0) + ' мс'
        + ' · gemini ' + (data.gemini_ms || 0) + ' мс';

      if (data.plan && Array.isArray(data.plan.days) && data.plan.days.length) {
        lastPlan = data.plan;
        planEl.textContent = JSON.stringify(data.plan, null, 2);
        renderRoute(data.plan);
        auditOnlyBtn.disabled = false;
      } else {
        lastPlan = null;
        planEl.textContent = data.plan ? JSON.stringify(data.plan, null, 2) : '';
        routeWrap.style.display = 'block';
        const fr = (data.transfer_geo && data.transfer_geo.finish_reason)
          ? String(data.transfer_geo.finish_reason)
          : '';
        routeEl.innerHTML = '<div class="text-danger">Маршрут не разобран.</div>'
          + '<div class="small text-muted mt-1">Открой «raw_text сборки» ниже'
          + (fr ? ' · finishReason=' + escapeHtml(fr) : '')
          + '. Геофильтр/образцы могут быть, даже если JSON плана битый.</div>';
      }

      if (!res.ok || data.ok === false) {
        showError(data.message || ('HTTP ' + res.status));
        setBusy(false);
        return;
      }

      setBusy(false);
      if (lastPlan) {
        await runAudit();
      }
    } catch (ex) {
      showError(String(ex && ex.message ? ex.message : ex));
      setBusy(false);
    }
  });

  promptSave.addEventListener('click', async function () {
    promptStatus.textContent = 'Сохраняю…';
    try {
      const res = await fetch(promptUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          mode: 'scenario',
          system_prompt: systemPromptEl.value,
          user_prompt: userPromptEl.value,
          audit_system_prompt: auditSystemPromptEl.value,
          audit_user_prompt: auditUserPromptEl.value,
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      promptStatus.textContent = 'Сохранено (сборка + аудит)';
    } catch (e2) {
      promptStatus.textContent = e2.message || String(e2);
    }
  });

  promptReset.addEventListener('click', function () {
    systemPromptEl.value = defaultSystemPrompt;
    userPromptEl.value = defaultUserPrompt;
    promptStatus.textContent = 'Сборка сброшена в поля (сохрани, если нужно)';
  });

  auditPromptReset.addEventListener('click', function () {
    auditSystemPromptEl.value = defaultAuditSystemPrompt;
    auditUserPromptEl.value = defaultAuditUserPrompt;
    promptStatus.textContent = 'Аудит сброшен в поля (сохрани, если нужно)';
  });
})();
</script>
@endsection
