@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Test — Поиск мест</h4>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="{{ route('admin.test.index') }}" class="btn btn-sm btn-outline-secondary">← Test</a>
    <a href="{{ route('admin.test.scenario') }}" class="btn btn-sm btn-outline-primary">Gemini сценарий →</a>
    <span class="badge bg-label-success">В векторе: {{ (int) $indexed }}</span>
    <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
  </div>
</div>

<div class="alert alert-secondary mb-4">
  <div class="fw-semibold mb-1">Два шага</div>
  <ol class="mb-0 small ps-3">
    <li>Send — места из каталога, дни раскладываются по координатам (район → переезд → район)</li>
    <li>Собрать дни — чат пишет текст по готовому плану, только из найденных id</li>
  </ol>
</div>

<div class="card">
  <div class="card-body">
    <div id="locChatLog" class="border rounded p-3 mb-3 bg-light" style="min-height:320px;max-height:640px;overflow:auto;">
      <div class="text-muted small">Напишите запрос — выложу все найденные места из каталога.</div>
    </div>

    <div id="locChatProgress" class="alert alert-primary d-none mb-3 py-3" role="status">
      <div class="d-flex align-items-start gap-3">
        <div class="spinner-border text-primary flex-shrink-0 mt-1"></div>
        <div>
          <div class="fw-semibold">Ищу места…</div>
          <div class="small" id="locChatProgressDetail">Эмбеддинг запроса, сверка с вектором</div>
        </div>
      </div>
    </div>

    <form id="locChatForm" class="d-flex gap-2 flex-wrap">
      <input type="text" id="locChatInput" class="form-control" autocomplete="off" style="min-width:240px;flex:1;" placeholder="Гарда, потом Доломиты, 14–23 августа">
      <button type="submit" class="btn btn-primary" id="locChatSend">Send</button>
      <button type="button" class="btn btn-success" id="locChatAssemble" disabled>Собрать дни</button>
      <button type="button" class="btn btn-outline-secondary" id="locChatClear">Clear</button>
    </form>
    <div id="locChatError" class="alert alert-danger mt-3 mb-0 py-2" style="display:none;white-space:pre-wrap;"></div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
    <span class="fw-semibold">Промпт сборки дней (русский)</span>
    <div class="d-flex align-items-center gap-2">
      <span id="locPromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-icon btn-sm btn-primary" id="locPromptSave" title="Save">
        <i class="bx bx-save"></i>
      </button>
    </div>
  </div>
  <div class="card-body">
    <textarea
      id="locPromptInput"
      class="form-control font-monospace"
      rows="12"
      spellcheck="false"
    >{{ $systemPrompt }}</textarea>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const log = document.getElementById('locChatLog');
  const form = document.getElementById('locChatForm');
  const input = document.getElementById('locChatInput');
  const sendBtn = document.getElementById('locChatSend');
  const assembleBtn = document.getElementById('locChatAssemble');
  const clearBtn = document.getElementById('locChatClear');
  const err = document.getElementById('locChatError');
  const progress = document.getElementById('locChatProgress');
  const progressDetail = document.getElementById('locChatProgressDetail');
  const promptInput = document.getElementById('locPromptInput');
  const promptSave = document.getElementById('locPromptSave');
  const promptStatus = document.getElementById('locPromptStatus');
  const searchUrl = @json(route('admin.test.locations.search'));
  const assembleUrl = @json(route('admin.test.locations.assemble'));
  const promptUrl = @json(route('admin.test.prompt.save'));
  const csrf = @json(csrf_token());
  let lastQuery = '';
  let lastLocations = [];
  let lastDayPlan = [];

  function showError(text) {
    err.style.display = 'block';
    err.textContent = text;
  }
  function clearError() {
    err.style.display = 'none';
    err.textContent = '';
  }

  function addUser(text) {
    const el = document.createElement('div');
    el.className = 'mb-3';
    el.innerHTML = '<div class="small fw-semibold text-primary">Вы</div><div class="p-2 rounded bg-white border">' + escapeHtml(text) + '</div>';
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
  }

  function addResults(data) {
    const el = document.createElement('div');
    el.className = 'mb-3';
    const filters = data.filters || {};
    const loc = data.locations || [];
    let meta = 'Найдено: ' + loc.length
      + ' · кандидатов SQL: ' + (data.candidates || 0)
      + ' · сканов: ' + (data.scanned || 0)
      + ' · embed ' + (data.embed_ms || 0) + ' мс'
      + ' · поиск ' + (data.search_ms || 0) + ' мс';
    if (filters.country) meta += ' · страна ' + filters.country;
    if (filters.regions && filters.regions.length) meta += ' · ' + filters.regions.join(', ');
    if (filters.calendar && filters.calendar.length) {
      meta += ' · календарь: ' + filters.calendar.join('; ');
    }
    if (filters.blocks && filters.blocks.length) {
      meta += ' · районы: ' + filters.blocks.map(function (b) {
        var span = (b.from && b.to) ? (' ' + b.from + '–' + b.to) : '';
        return (b.label || b.key) + span + ' ' + (b.count || 0);
      }).join(', ');
    }

    let html = '<div class="small fw-semibold text-success">Каталог</div>';
    html += '<div class="small text-muted mb-2">' + escapeHtml(meta) + '</div>';
    if (filters.dropped && filters.dropped.length) {
      html += '<div class="small text-muted mb-2">Отфильтровано: ' + escapeHtml(filters.dropped.slice(0, 12).join('; '))
        + (filters.dropped.length > 12 ? '…' : '') + '</div>';
    }
    if (filters.day_plan && filters.day_plan.length) {
      html += '<div class="p-2 rounded bg-white border mb-2 small" style="white-space:pre-wrap;">'
        + '<div class="fw-semibold mb-1">План дней по координатам</div>'
        + escapeHtml(filters.day_plan.join('\n'))
        + '</div>';
    }
    if (!loc.length) {
      html += '<div class="p-2 rounded bg-white border text-muted">Ничего не нашёл</div>';
    } else {
      html += '<div class="table-responsive"><table class="table table-sm table-bordered bg-white mb-0"><thead><tr>';
      html += '<th>#</th><th>Район</th><th>Роль</th><th>id</th><th>Название</th><th>Страна</th><th>Region</th><th>score</th><th></th></tr></thead><tbody>';
      loc.forEach(function (row, i) {
        var dates = (row.date_from && row.date_to) ? (row.date_from + '–' + row.date_to) : '';
        html += '<tr>';
        html += '<td>' + (i + 1) + '</td>';
        html += '<td>' + escapeHtml(row.block_label || row.block || '—');
        if (dates) html += '<div class="small text-muted">' + escapeHtml(dates) + '</div>';
        html += '</td>';
        html += '<td>' + (row.is_base ? '<span class="badge bg-label-warning">база</span>' : 'место') + '</td>';
        html += '<td>' + escapeHtml(String(row.id || '')) + '</td>';
        html += '<td dir="auto">' + escapeHtml(row.name || '—') + '<div class="small text-muted">' + escapeHtml(row.snippet || '') + '</div></td>';
        html += '<td>' + escapeHtml(row.country || '—') + '</td>';
        html += '<td>' + escapeHtml(row.region || '—') + '</td>';
        html += '<td>' + escapeHtml(String(row.score || '')) + '</td>';
        html += '<td><a href="' + escapeHtml(row.edit_url || '#') + '" target="_blank">карточка</a></td>';
        html += '</tr>';
      });
      html += '</tbody></table></div>';
    }
    el.innerHTML = html;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
  }

  function addPlan(text, ms, meta) {
    const el = document.createElement('div');
    el.className = 'mb-3';
    meta = meta || {};
    let v = '';
    if (meta.validator_report) {
      var ok = meta.validator_ok === true;
      v = '<div class="small ' + (ok ? 'text-success' : 'text-danger') + ' mb-1">'
        + (ok ? 'validator: ok' : 'validator: есть замечания (маршрут не менялся)')
        + '</div>';
      if (!ok || (meta.validator_findings || []).length) {
        v += '<pre class="p-2 rounded border bg-light small mb-2" style="white-space:pre-wrap;max-height:280px;overflow:auto;">'
          + escapeHtml(meta.validator_report) + '</pre>';
      }
    } else if (meta.validator_ok === true) {
      v = '<div class="small text-success mb-1">validator: ok</div>';
    }
    let sk = '';
    if ((meta.skeleton_ids || []).length) {
      sk = '<div class="small text-muted mb-1">образцы: '
        + meta.skeleton_ids.map(function (s) {
          return '#' + (s.source_route_id || s.id) + ' ' + escapeHtml(String(s.title || '').slice(0, 40));
        }).join(' · ')
        + '</div>';
    }
    if ((meta.refine_notes || []).length) {
      sk += '<div class="small text-info mb-1">правки: '
        + meta.refine_notes.map(function (n) { return escapeHtml(String(n)); }).join(' · ')
        + '</div>';
    }
    if (meta.plan_source) {
      sk += '<div class="small text-muted mb-1">источник плана: ' + escapeHtml(String(meta.plan_source)) + '</div>';
    }
    el.innerHTML = '<div class="small fw-semibold text-warning">Итоговый маршрут</div>'
      + '<div class="small text-muted mb-1">сборка ' + escapeHtml(String(ms || 0)) + ' мс'
      + (meta.plan_source ? ' · ' + escapeHtml(String(meta.plan_source)) : '')
      + '</div>'
      + sk
      + v
      + '<div class="p-2 mb-3 rounded bg-white border" style="color:#111;white-space:pre-wrap;font-weight:500;">'
      + escapeHtml(text || '') + '</div>'
      + renderHumanReport(meta)
      + renderDebugReport(meta);
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
  }

  function humanStat(label, value, fg, bg) {
    return '<div class="px-2 py-1 rounded small" style="background:' + bg + ';color:' + fg + ';">'
      + '<div style="font-weight:700;font-size:1rem;">' + escapeHtml(String(value != null ? value : 0)) + '</div>'
      + '<div>' + escapeHtml(label) + '</div></div>';
  }

  function humanRow(label, value, color) {
    return '<div class="small mb-1" style="color:' + color + ';">'
      + '<strong>' + escapeHtml(label) + ':</strong> '
      + escapeHtml(String(value)) + '</div>';
  }

  /** Цветное резюме: конструкция / база / аудит */
  function renderWorkResume(resume) {
    if (!resume || !(resume.items || []).length) return '';
    const colors = {
      construction: { border: '#93c5fd', bg: '#eff6ff', title: '#1e40af', body: '#1e3a8a' },
      catalog:      { border: '#5eead4', bg: '#f0fdfa', title: '#0f766e', body: '#115e59' },
      audit_add:    { border: '#86efac', bg: '#f0fdf4', title: '#166534', body: '#14532d' },
      recommend:    { border: '#fdba74', bg: '#fff7ed', title: '#9a3412', body: '#7c2d12' },
      kept:         { border: '#cbd5e1', bg: '#f8fafc', title: '#475569', body: '#334155' }
    };
    let html = '<div class="mb-3">';
    html += '<div class="small fw-semibold mb-2" style="color:#0f172a;">'
      + escapeHtml(String(resume.title || 'Резюме: откуда собран маршрут')) + '</div>';
    (resume.items || []).forEach(function (it) {
      const c = colors[it.kind] || colors.kept;
      html += '<div class="mb-2 p-2 rounded" style="background:' + c.bg
        + ';border-left:4px solid ' + c.border + ';">';
      html += '<div style="font-weight:700;color:' + c.title + ';margin-bottom:4px;">'
        + escapeHtml(String(it.n || '')) + '. '
        + escapeHtml(String(it.label || '')) + '</div>';
      if (it.text) {
        html += '<div class="small mb-1" style="color:' + c.body + ';">'
          + escapeHtml(String(it.text)) + '</div>';
      }
      (it.details || []).forEach(function (det) {
        html += '<div class="small" style="color:' + c.body + ';padding-left:8px;">— '
          + escapeHtml(String(det)) + '</div>';
      });
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  /** HUMAN / DIRECTOR — основной вывод: Изменено / Рекомендация / Проверено */
  function renderHumanReport(meta) {
    meta = meta || {};
    const pipe = meta.audit_pipeline || {};
    const hr = meta.human_report || pipe.human_report || null;
    if (!hr) {
      return '<div class="small text-muted mb-2">Отчёт аудита пока недоступен.</div>';
    }
    const s = hr.summary || {};
    let html = '<div class="mb-3 rounded border p-3" style="background:#f8fafc;border-color:#e2e8f0!important;">';
    html += '<div style="font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:6px;">'
      + escapeHtml(hr.title || 'Аудит маршрута') + '</div>';
    html += '<div class="small mb-2" style="color:#475569;">Период: <strong>'
      + escapeHtml(String(s.period || hr.period || '—')) + '</strong>'
      + ' · Дней проверено: <strong>' + escapeHtml(String(s.days_checked != null ? s.days_checked : '—')) + '</strong></div>';
    if (s.summary_line) {
      html += '<div class="mb-2" style="color:#0f172a;font-weight:600;">'
        + escapeHtml(String(s.summary_line)) + '</div>';
    } else {
      html += '<div class="d-flex flex-wrap gap-2 mb-2">';
      html += humanStat('Изменено', s.changed_auto, '#166534', '#dcfce7');
      html += humanStat('Требуют внимания', s.recommendations, '#9a3412', '#ffedd5');
      html += humanStat('Без изменений', s.ok != null ? s.ok : s.unchanged, '#374151', '#f3f4f6');
      html += '</div>';
    }
    if (hr.logic_note) {
      html += '<div class="small mb-3" style="color:#64748b;border-left:3px solid #cbd5e1;padding-left:8px;">'
        + escapeHtml(String(hr.logic_note)) + '</div>';
    }

    html += renderWorkResume(hr.work_resume || meta.work_resume);

    const days = hr.days || [];
    const changed = days.filter(function (d) { return d.kind === 'changed'; });
    const recs = days.filter(function (d) { return d.kind === 'recommendation' || d.kind === 'attention'; });
    const oks = days.filter(function (d) { return d.kind === 'ok'; });

    if (changed.length) {
      html += '<div class="small fw-semibold mb-2" style="color:#166534;">Изменено</div>';
      changed.forEach(function (d) {
        html += '<div class="mb-3 p-2 rounded bg-white" style="border-left:4px solid #86efac;">';
        html += '<div style="font-weight:700;color:#0f172a;margin-bottom:6px;">'
          + escapeHtml(String(d.date || '')) + '</div>';
        if (d.detected) html += humanRow('Обнаружено', d.detected, '#374151');
        if (d.proposed) html += humanRow('Предложено', d.proposed, '#1d4ed8');
        if (d.decision) html += humanRow('Решение', d.decision, '#15803d');
        if (d.why) html += humanRow('Причина', d.why, '#15803d');
        html += '</div>';
      });
    }

    if (recs.length) {
      html += '<div class="small fw-semibold mb-2" style="color:#9a3412;">Требуют внимания</div>';
      recs.forEach(function (d) {
        html += '<div class="mb-3 p-2 rounded bg-white" style="border-left:4px solid #fdba74;">';
        html += '<div style="font-weight:700;color:#0f172a;margin-bottom:6px;">'
          + escapeHtml(String(d.date || '')) + '</div>';
        html += '<div class="small mb-1" style="color:#57534e;">'
          + escapeHtml(String(d.route_status || d.decision || 'Маршрут: оставлен без изменений.'))
          + '</div>';
        if (d.recommendation) html += humanRow('Рекомендация', d.recommendation, '#9a3412');
        else if (d.problem) html += humanRow('Рекомендация', d.problem, '#9a3412');
        if ((d.alternatives || []).length) {
          html += '<div class="small mb-1" style="color:#1d4ed8;"><strong>Варианты:</strong> '
            + d.alternatives.map(function (a) { return escapeHtml(String(a)); }).join(', ')
            + '</div>';
        }
        if (d.why_not_auto || d.decision_why) {
          html += humanRow('Почему не изменено', d.why_not_auto || d.decision_why, '#78716c');
        }
        html += '</div>';
      });
    }

    if (oks.length) {
      html += '<div class="small fw-semibold mb-2" style="color:#64748b;">Без изменений</div>';
      html += '<div class="p-2 rounded bg-white mb-1" style="border-left:4px solid #e2e8f0;">';
      if (oks.length <= 2) {
        oks.forEach(function (d) {
          html += '<div class="mb-2">';
          html += '<div style="font-weight:600;color:#0f172a;">' + escapeHtml(String(d.date || '')) + '</div>';
          (d.checks || []).forEach(function (c) {
            html += '<div class="small" style="color:#15803d;">✓ ' + escapeHtml(String(c)) + '</div>';
          });
          html += '<div class="small" style="color:#64748b;">Изменений не требуется.</div></div>';
        });
      } else {
        html += '<div style="font-weight:600;color:#0f172a;margin-bottom:4px;">'
          + oks.map(function (d) { return escapeHtml(String(d.date || '')); }).join(', ')
          + '</div>';
        var checks = oks[0].checks || [
          'День соответствует временным ограничениям.',
          'Маршрут логичен по расположению объектов.'
        ];
        checks.forEach(function (c) {
          html += '<div class="small" style="color:#15803d;">✓ ' + escapeHtml(String(c)) + '</div>';
        });
        html += '<div class="small" style="color:#64748b;">Изменений не требуется.</div>';
      }
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  /** DEBUG — технический блок */
  function renderDebugReport(meta) {
    meta = meta || {};
    const pipe = meta.audit_pipeline || {};
    const days = meta.visual_report || pipe.visual_report || [];
    const resultText = meta.audit_result_text || pipe.audit_result_text || '';
    const trace = meta.audit_trace || pipe.audit_trace || [];

    let html = '<details class="mt-2"><summary class="small fw-semibold" style="cursor:pointer;color:#64748b;">'
      + 'DEBUG REPORT (для разработчика)</summary>';
    html += '<div class="p-2 mt-2 rounded border" style="background:#fafafa;">';
    if (resultText) {
      html += '<div class="small fw-semibold mb-1">AUDIT RESULT</div>'
        + '<pre class="p-2 mb-2 rounded border bg-white small" style="white-space:pre-wrap;max-height:140px;overflow:auto;">'
        + escapeHtml(resultText) + '</pre>';
    }
    days.forEach(function (d) {
      const st = String(d.status || 'PASS');
      html += '<div class="mb-2 p-2 bg-white border rounded small">';
      html += '<div><code>' + escapeHtml(String(d.date || '')) + '</code> · status=<code>'
        + escapeHtml(st) + '</code>';
      if (d.time_status) html += ' · TIME=<code>' + escapeHtml(String(d.time_status)) + '</code>';
      html += '</div>';
      if (d.flag) {
        html += '<div style="color:#b91c1c;">problem=<code>' + escapeHtml(String(d.flag.problem || ''))
          + '</code> · ' + escapeHtml(String(d.flag.reason || '')) + '</div>';
      }
      if (d.refiner) {
        html += '<div>refiner=<code>' + escapeHtml(String(d.refiner.action || ''))
          + '</code> / ' + escapeHtml(String(d.refiner.reason || '')) + '</div>';
      }
      if (d.fix) {
        html += '<div style="color:#15803d;">fix id=' + escapeHtml(String(d.fix.id || ''))
          + ' ' + escapeHtml(String(d.fix.name || '')) + '</div>';
      }
      if ((d.candidates || []).length) {
        html += '<div style="color:#1d4ed8;">candidates: '
          + d.candidates.map(function (c) {
            return '#' + (c.id || '?') + ' ' + escapeHtml(String(c.name || ''))
              + (c.hours != null ? ' ' + c.hours + 'h' : '')
              + (c.detour_km != null ? ' ' + c.detour_km + 'km' : '');
          }).join(' · ') + '</div>';
      }
      if ((d.rejected || []).length) {
        html += '<div style="color:#9ca3af;">rejected: '
          + d.rejected.slice(0, 5).map(function (r) {
            return escapeHtml(String(r.name || ('#' + r.id))) + '→' + escapeHtml(String(r.reason || ''));
          }).join(' · ') + '</div>';
      }
      html += '</div>';
    });
    if (trace.length) {
      html += '<details class="mt-1"><summary class="small" style="cursor:pointer;">audit_trace JSON</summary>'
        + '<pre class="p-2 mt-1 mb-0 rounded border bg-white small" style="white-space:pre-wrap;max-height:280px;overflow:auto;">'
        + escapeHtml(JSON.stringify(trace, null, 2)) + '</pre></details>';
    }
    html += '</div></details>';
    return html;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const text = (input.value || '').trim();
    if (!text) return;
    clearError();
    addUser(text);
    input.value = '';
    sendBtn.disabled = true;
    assembleBtn.disabled = true;
    progress.classList.remove('d-none');
    progressDetail.textContent = 'Эмбеддинг запроса, сверка с вектором';
    try {
      const res = await fetch(searchUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ message: text, limit: 36 }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      lastQuery = text;
      lastLocations = data.locations || [];
      lastDayPlan = data.day_plan || (data.filters && data.filters.day_plan) || [];
      addResults(data);
      assembleBtn.disabled = lastLocations.length === 0;
    } catch (e2) {
      showError(e2.message || String(e2));
    } finally {
      sendBtn.disabled = false;
      progress.classList.add('d-none');
    }
  });

  assembleBtn.addEventListener('click', async function () {
    if (!lastLocations.length || !lastQuery) {
      showError('Сначала нажмите Send — нужен список мест');
      return;
    }
    clearError();
    sendBtn.disabled = true;
    assembleBtn.disabled = true;
    progress.classList.remove('d-none');
    progressDetail.textContent = 'Чат собирает дни только из найденных id';
    try {
      const res = await fetch(assembleUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          message: lastQuery,
          prompt: promptInput.value,
          locations: lastLocations,
          day_plan: lastDayPlan,
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      lastDayPlan = data.day_plan || lastDayPlan;
      addPlan(data.reply || '', data.chat_ms, data);
    } catch (e2) {
      showError(e2.message || String(e2));
    } finally {
      sendBtn.disabled = false;
      assembleBtn.disabled = lastLocations.length === 0;
      progress.classList.add('d-none');
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
        body: JSON.stringify({ prompt: promptInput.value, mode: 'locations' }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
      promptStatus.textContent = 'Сохранено';
    } catch (e2) {
      promptStatus.textContent = e2.message || String(e2);
    }
  });

  clearBtn.addEventListener('click', function () {
    lastQuery = '';
    lastLocations = [];
    lastDayPlan = [];
    assembleBtn.disabled = true;
    log.innerHTML = '<div class="text-muted small">Напишите запрос — выложу все найденные места из каталога.</div>';
    clearError();
  });
})();
</script>
@endsection
