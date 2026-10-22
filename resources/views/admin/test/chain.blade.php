@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Test 3 — Цепочка</h4>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="{{ route('admin.test.index') }}" class="btn btn-sm btn-outline-secondary">← Test</a>
    <a href="{{ route('admin.test.scenario') }}" class="btn btn-sm btn-outline-secondary">Тест 2</a>
    <span class="badge bg-label-success">В векторе: {{ (int) $indexed }}</span>
    <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
  </div>
</div>

<div class="alert alert-warning mb-4">
  <div class="fw-semibold mb-1">V1 → География → День → Покрытие → V2</div>
  <p class="mb-0 small">Пять вызовов Gemini. Жёсткие узкие промпты. Тест 2 не трогаем — сравниваем рядом.</p>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form id="chForm" class="row g-2 align-items-end mb-3">
      <div class="col-12 col-lg">
        <label class="form-label small mb-1" for="chInput">Запрос</label>
        <input type="text" id="chInput" class="form-control" autocomplete="off"
          placeholder="Гарда → Доломиты, 10 дней">
      </div>
      <div class="col-4 col-md-2 col-lg-1">
        <label class="form-label small mb-1" for="chDays">Дней</label>
        <input type="number" id="chDays" class="form-control" value="10" min="5" max="14">
      </div>
      <div class="col-4 col-md-2 col-lg-1">
        <label class="form-label small mb-1" for="chPoi">POI</label>
        <input type="number" id="chPoi" class="form-control" value="48" min="12" max="60">
      </div>
      <div class="col-4 col-md-2 col-lg-1">
        <label class="form-label small mb-1" for="chSamples">Образцы</label>
        <input type="number" id="chSamples" class="form-control" value="5" min="3" max="5">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary" id="chRun">Запустить цепочку</button>
        <button type="button" class="btn btn-outline-secondary" id="chClear">Clear</button>
      </div>
    </form>

    <div id="chProgress" class="alert alert-primary d-none mb-3 py-3">
      <div class="d-flex gap-3">
        <div class="spinner-border text-primary flex-shrink-0"></div>
        <div>
          <div class="fw-semibold">Цепочка Gemini…</div>
          <div class="small" id="chProgressDetail">V1 → geo → day → coverage → V2 (может занять несколько минут)</div>
        </div>
      </div>
    </div>
    <div id="chError" class="alert alert-danger mb-3 py-2" style="display:none;white-space:pre-wrap;"></div>
    <div id="chMeta" class="small text-muted mb-3" style="display:none;"></div>

    <div class="row g-3">
      <div class="col-12 col-xl-6">
        <h5 class="mb-2">ROUTE V1</h5>
        <div id="chRouteV1" class="border rounded p-3 bg-white mb-2" style="min-height:120px;max-height:420px;overflow:auto;"></div>
      </div>
      <div class="col-12 col-xl-6">
        <h5 class="mb-2">ROUTE V2</h5>
        <div id="chRouteV2" class="border rounded p-3 bg-white mb-2" style="min-height:120px;max-height:420px;overflow:auto;"></div>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-12 col-md-4">
        <div class="fw-semibold mb-1">Аудит: география</div>
        <pre id="chAuditGeo" class="border rounded p-2 bg-light small mb-0" style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
      </div>
      <div class="col-12 col-md-4">
        <div class="fw-semibold mb-1">Аудит: день / нагрузка</div>
        <pre id="chAuditDay" class="border rounded p-2 bg-light small mb-0" style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
      </div>
      <div class="col-12 col-md-4">
        <div class="fw-semibold mb-1">Аудит: покрытие</div>
        <pre id="chAuditCov" class="border rounded p-2 bg-light small mb-0" style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <div>
      <span class="fw-semibold">Промпты Test 3</span>
      <div class="small text-muted">@{{QUERY}} @{{DAYS}} @{{PLAN}} @{{POIS}} @{{SAMPLES}} @{{FINDINGS}}</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <span id="chPromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="chPromptReset">Сброс</button>
      <button type="button" class="btn btn-sm btn-primary" id="chPromptSave">Save</button>
    </div>
  </div>
  <div class="card-body">
    @php
      $blocks = [
        'architect' => '0. Architect (V1)',
        'geo' => '1. Geography',
        'day' => '2. Day reality',
        'coverage' => '3. Coverage',
        'rebuild' => '4. Arbiter / Rebuild (V2)',
      ];
    @endphp
    @foreach($blocks as $prefix => $label)
      <div class="mb-4 pb-3 border-bottom">
        <div class="fw-semibold mb-2">{{ $label }}</div>
        <label class="form-label small" for="ch_{{ $prefix }}_system">System</label>
        <textarea id="ch_{{ $prefix }}_system" class="form-control font-monospace mb-2" rows="8" spellcheck="false">{{ $prompts[$prefix.'_system'] }}</textarea>
        <label class="form-label small" for="ch_{{ $prefix }}_user">User</label>
        <textarea id="ch_{{ $prefix }}_user" class="form-control font-monospace" rows="5" spellcheck="false">{{ $prompts[$prefix.'_user'] }}</textarea>
      </div>
    @endforeach
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const form = document.getElementById('chForm');
  const input = document.getElementById('chInput');
  const days = document.getElementById('chDays');
  const poi = document.getElementById('chPoi');
  const samples = document.getElementById('chSamples');
  const runBtn = document.getElementById('chRun');
  const clearBtn = document.getElementById('chClear');
  const progress = document.getElementById('chProgress');
  const err = document.getElementById('chError');
  const meta = document.getElementById('chMeta');
  const v1El = document.getElementById('chRouteV1');
  const v2El = document.getElementById('chRouteV2');
  const geoEl = document.getElementById('chAuditGeo');
  const dayEl = document.getElementById('chAuditDay');
  const covEl = document.getElementById('chAuditCov');
  const promptStatus = document.getElementById('chPromptStatus');
  const runUrl = @json(route('admin.test.chain.run'));
  const promptUrl = @json(route('admin.test.prompt.save'));
  const csrf = @json(csrf_token());
  const defaults = @json($defaults);
  const promptKeys = ['architect','geo','day','coverage','rebuild'];

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function collectPrompts() {
    const out = {};
    promptKeys.forEach(function (p) {
      out[p + '_system'] = document.getElementById('ch_' + p + '_system').value;
      out[p + '_user'] = document.getElementById('ch_' + p + '_user').value;
    });
    return out;
  }

  function placeLine(p) {
    if (!p || typeof p !== 'object') return '';
    return escapeHtml(p.name || ('#' + p.id))
      + '<span class="text-muted">'
      + (p.id != null ? ' #' + p.id : '')
      + (p.hours != null ? ' · ~' + p.hours + ' ч' : '')
      + '</span>';
  }

  function renderRoute(plan, el) {
    if (!plan || !Array.isArray(plan.days)) {
      el.innerHTML = '<div class="text-muted small">нет плана</div>';
      return;
    }
    let html = '<div class="fw-semibold mb-2">' + escapeHtml(plan.title || 'Маршрут') + '</div>';
    plan.days.forEach(function (day, idx) {
      const n = idx + 1;
      html += '<div class="mb-2 pb-2' + (idx < plan.days.length - 1 ? ' border-bottom' : '') + '">'
        + '<div class="fw-semibold small">День ' + n + ' · ' + escapeHtml(String(day.date || ''))
        + (day.theme ? ' · ' + escapeHtml(day.theme) : '') + '</div>';
      if (day.summary) html += '<div class="small text-muted">' + escapeHtml(day.summary) + '</div>';
      if (day.overnight) html += '<div class="small">Ночёвка: ' + placeLine(day.overnight) + '</div>';
      const places = Array.isArray(day.places) ? day.places : [];
      if (places.length) {
        html += '<ul class="small mb-0 ps-3">' + places.map(function (p) {
          return '<li>' + placeLine(p) + '</li>';
        }).join('') + '</ul>';
      }
      html += '</div>';
    });
    el.innerHTML = html;
  }

  function showError(t) { err.style.display = 'block'; err.textContent = t; }
  function clearError() { err.style.display = 'none'; err.textContent = ''; }

  function reset() {
    clearError();
    meta.style.display = 'none';
    meta.textContent = '';
    v1El.innerHTML = '';
    v2El.innerHTML = '';
    geoEl.textContent = '';
    dayEl.textContent = '';
    covEl.textContent = '';
  }

  clearBtn.addEventListener('click', reset);

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();
    const message = (input.value || '').trim();
    if (!message) { showError('Введите запрос'); return; }
    reset();
    runBtn.disabled = true;
    progress.classList.remove('d-none');
    try {
      const res = await fetch(runUrl, {
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
          prompts: collectPrompts(),
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      renderRoute(data.plan_v1, v1El);
      renderRoute(data.plan_v2, v2El);
      geoEl.textContent = data.audits && data.audits.geo ? JSON.stringify(data.audits.geo, null, 2) : '';
      dayEl.textContent = data.audits && data.audits.day ? JSON.stringify(data.audits.day, null, 2) : '';
      covEl.textContent = data.audits && data.audits.coverage ? JSON.stringify(data.audits.coverage, null, 2) : '';

      const ms = data.steps_ms || {};
      const parts = Object.keys(ms).map(function (k) { return k + ' ' + ms[k] + 'мс'; });
      meta.style.display = 'block';
      meta.textContent = 'POI ' + ((data.pois || []).length)
        + ' · образцы ' + ((data.samples || []).length)
        + (parts.length ? ' · ' + parts.join(' · ') : '');

      if (!res.ok || data.ok === false) {
        showError(data.message || ('HTTP ' + res.status));
      }
    } catch (ex) {
      showError(String(ex && ex.message ? ex.message : ex));
    } finally {
      runBtn.disabled = false;
      progress.classList.add('d-none');
    }
  });

  document.getElementById('chPromptSave').addEventListener('click', async function () {
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
        body: JSON.stringify({ mode: 'chain', prompts: collectPrompts() }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      promptStatus.textContent = 'Сохранено';
    } catch (e2) {
      promptStatus.textContent = e2.message || String(e2);
    }
  });

  document.getElementById('chPromptReset').addEventListener('click', function () {
    promptKeys.forEach(function (p) {
      document.getElementById('ch_' + p + '_system').value = defaults[p + '_system'] || '';
      document.getElementById('ch_' + p + '_user').value = defaults[p + '_user'] || '';
    });
    promptStatus.textContent = 'Сброшено в поля (сохрани при необходимости)';
  });
})();
</script>
@endsection
