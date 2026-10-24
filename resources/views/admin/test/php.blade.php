@extends('admin.layouts.layout')

@section('content')
@php
  $lang = $defaultLanguage ?? null;
  $hubJson = $hubLabels->map(fn ($h) => ['slug' => $h->slug, 'name' => $h->nameFor($lang)])->values();
  $otherJson = $otherLabels->map(fn ($h) => ['slug' => $h->slug, 'name' => $h->nameFor($lang), 'id' => $h->id])->values();
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">PHP</h4>
    <p class="text-muted small mb-0">Слоты + метки → варианты дней. Клики работают (демо-данные). Поиск в БД позже.</p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="{{ route('admin.test.index') }}" class="btn btn-sm btn-outline-secondary">← Test</a>
    <a href="{{ route('admin.test.php.map') }}" class="btn btn-sm btn-outline-primary">Собрать день · карта</a>
    <a href="{{ route('admin.test.php.gemini') }}" class="btn btn-sm btn-outline-primary">Собрать день · Gemini</a>
  </div>
</div>

<div class="alert alert-info small mb-4">
  Выбери число дней → «Разбить на слоты». В слоте: база + метки → «Найти». Если пусто — ссылки на сборку из локаций (карта / Gemini).
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-lg-7">
        <label class="form-label" for="phpQuery">Запрос клиента</label>
        <input type="text" id="phpQuery" class="form-control"
          value="Доломиты, 14–23 августа, базы и переезды">
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label" for="phpDays">Дней</label>
        <input type="number" id="phpDays" class="form-control" value="10" min="1" max="14">
      </div>
      <div class="col-6 col-md-3 col-lg-3">
        <button type="button" class="btn btn-primary w-100" id="phpRebuildSlots">
          Разбить на слоты
        </button>
      </div>
      <div class="col-12 col-lg-auto">
        <button type="button" class="btn btn-success w-100" id="phpAutoAll" title="Авто: найти и выбрать день в каждом слоте">
          <i class="bx bx-magic-wand me-1"></i>Подобрать все дни
        </button>
      </div>
    </div>
  </div>
</div>

<div id="phpSlots"></div>

<div class="card mb-4">
  <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center">
    <span>Маршрут (черновик)</span>
    <span class="badge bg-label-secondary" id="phpPickedCount">0 / 0 выбрано</span>
  </div>
  <div class="card-body p-0">
    <table class="table mb-0" id="phpSummaryTable">
      <thead>
        <tr>
          <th style="width:4rem">День</th>
          <th>Выбранный день</th>
          <th>Локации (id)</th>
        </tr>
      </thead>
      <tbody id="phpSummaryBody"></tbody>
    </table>
  </div>
  <div class="card-footer d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-primary" id="phpAssemble">Собрать маршрут</button>
    <button type="button" class="btn btn-outline-secondary" id="phpToGemini">Отдать в Gemini (оформить)</button>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="phpToast" class="toast align-items-center text-bg-dark border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="phpToastBody">—</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const hubs = @json($hubJson);
  const labels = @json($otherJson);
  const mapUrl = @json(route('admin.test.php.map'));
  const geminiUrl = @json(route('admin.test.php.gemini'));

  const DEMO = {
    'hub-ortisei': [
      { id: 92, title: '17.9 — Ортизеи, Санта-Магдалена', stops: '158, 362, 156', hub: 'ortisei' },
      { id: 201, title: 'Stay Ortisei · Seceda', stops: '158, 329', hub: 'ortisei' },
      { id: 88, title: 'Val Gardena loop', stops: '158, 187, 362', hub: 'ortisei' },
    ],
    'hub-brunico': [
      { id: 120, title: 'Brunico + Kronplatz', stops: '123, 168', hub: 'brunico' },
      { id: 121, title: 'Braies day from Brunico', stops: '123, 116', hub: 'brunico' },
    ],
    'hub-garda': [
      { id: 50, title: 'Riva · Ponale · Tenno', stops: '50, 25, 65', hub: 'garda' },
      { id: 51, title: 'Limone · Malcesine', stops: '32, 394, 395', hub: 'garda' },
      { id: 52, title: 'Varone · Ledro', stops: '199, 27', hub: 'garda' },
    ],
    'hub-milan': [
      { id: 10, title: 'Arrival MXP', stops: '1', hub: 'milan' },
    ],
    'hub-misurina': [
      { id: 300, title: 'Misurina · Tre Cime view', stops: '117, 400', hub: 'misurina' },
      { id: 301, title: 'Cadini day', stops: '117, 401', hub: 'misurina' },
    ],
  };

  const picks = {};
  let toast;

  function showToast(msg) {
    const el = document.getElementById('phpToast');
    document.getElementById('phpToastBody').textContent = msg;
    if (!toast) toast = new bootstrap.Toast(el, { delay: 2800 });
    toast.show();
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function hubOptions(selected) {
    let html = '<option value="">— выберите —</option>';
    hubs.forEach(function (h) {
      html += '<option value="' + esc(h.slug) + '"' + (h.slug === selected ? ' selected' : '') + '>' + esc(h.name) + '</option>';
    });
    return html;
  }

  function labelChecks(day) {
    return labels.map(function (l) {
      return '<div class="form-check form-check-inline m-0">'
        + '<input class="form-check-input php-label" type="checkbox" data-day="' + day + '" value="' + esc(l.slug) + '" id="d' + day + '_' + l.id + '">'
        + '<label class="form-check-label small" for="d' + day + '_' + l.id + '">' + esc(l.name) + '</label>'
        + '</div>';
    }).join('');
  }

  function defaultHub(day, total) {
    if (day === 1) return 'hub-milan';
    if (day <= Math.ceil(total * 0.4)) return 'hub-ortisei';
    if (day <= Math.ceil(total * 0.7)) return 'hub-brunico';
    if (day < total) return 'hub-misurina';
    return 'hub-garda';
  }

  function renderSlots(n, autoFindFirst) {
    const box = document.getElementById('phpSlots');
    let html = '';
    for (let d = 1; d <= n; d++) {
      const hub = defaultHub(d, n);
      html += '<div class="card mb-3 php-slot" data-day="' + d + '">'
        + '<div class="card-header d-flex justify-content-between align-items-center py-2">'
        + '<span class="fw-semibold">День ' + d + '</span>'
        + '<span class="badge bg-label-secondary php-slot-status" data-day="' + d + '">пусто</span>'
        + '</div><div class="card-body">'
        + '<div class="row g-3"><div class="col-md-4">'
        + '<label class="form-label small">База / хаб</label>'
        + '<select class="form-select form-select-sm php-hub" data-day="' + d + '">' + hubOptions(hub) + '</select>'
        + '</div><div class="col-md-8">'
        + '<label class="form-label small">Метки</label>'
        + '<div class="d-flex flex-wrap gap-2">' + labelChecks(d) + '</div>'
        + '</div></div>'
        + '<div class="d-flex flex-wrap gap-2 mt-3">'
        + '<button type="button" class="btn btn-sm btn-outline-primary php-find" data-day="' + d + '"><i class="bx bx-search me-1"></i>Найти золотые дни</button>'
        + '<a class="btn btn-sm btn-outline-secondary" href="' + mapUrl + '?day=' + d + '">Дня нет · карта</a>'
        + '<a class="btn btn-sm btn-outline-secondary" href="' + geminiUrl + '?day=' + d + '">Дня нет · Gemini</a>'
        + '</div>'
        + '<div class="php-results mt-3" data-day="' + d + '" style="display:none"></div>'
        + '</div></div>';
    }
    box.innerHTML = html;
    Object.keys(picks).forEach(function (k) { delete picks[k]; });
    renderSummary(n);
    // Сразу подтянуть дни для первых слотов (демо: видно, что «нашлось»)
    if (autoFindFirst !== false) {
      const pull = Math.min(3, n);
      for (let d = 1; d <= pull; d++) {
        findDays(d, { silent: d > 1 });
      }
      const found = [];
      for (let d = 1; d <= pull; d++) if (picks[d]) found.push(d);
      if (found.length) {
        showToast('Подтянулись дни: ' + found.join(', ') + ' (остальные — «Найти» или собрать)');
      }
    }
  }

  function renderSummary(n) {
    n = n || parseInt(document.getElementById('phpDays').value, 10) || 0;
    const body = document.getElementById('phpSummaryBody');
    let html = '';
    let picked = 0;
    for (let d = 1; d <= n; d++) {
      const p = picks[d];
      if (p) picked++;
      html += '<tr><td>' + d + '</td><td>'
        + (p ? ('#' + p.id + ' · ' + esc(p.title)) : '<span class="text-muted">— не выбран —</span>')
        + '</td><td class="small text-muted">' + (p ? esc(p.stops) : '') + '</td></tr>';
    }
    body.innerHTML = html;
    document.getElementById('phpPickedCount').textContent = picked + ' / ' + n + ' выбрано';
  }

  function findDays(day, opts) {
    opts = opts || {};
    const slot = document.querySelector('.php-slot[data-day="' + day + '"]');
    const hub = slot.querySelector('.php-hub').value;
    const results = slot.querySelector('.php-results');
    const status = document.querySelector('.php-slot-status[data-day="' + day + '"]');
    let list = DEMO[hub] || [];
    if (list.length === 0) {
      results.style.display = 'block';
      results.innerHTML = '<div class="alert alert-warning mb-0 small">Нет демо-вариантов для этой базы. '
        + 'Собери день: <a href="' + mapUrl + '?day=' + day + '">карта</a> · '
        + '<a href="' + geminiUrl + '?day=' + day + '">Gemini</a></div>';
      status.textContent = 'нет дней';
      status.className = 'badge bg-label-warning php-slot-status';
      if (!opts.silent) showToast('День ' + day + ': шаблонов нет — собери из локаций');
      return;
    }
    let html = '<div class="small text-success mb-2">Подтянулось ' + list.length + ' варианта:</div><div class="list-group">';
    list.forEach(function (item, idx) {
      html += '<label class="list-group-item list-group-item-action">'
        + '<div class="d-flex gap-2"><input class="form-check-input mt-1 php-pick" type="radio" name="pick_' + day + '" '
        + 'data-day="' + day + '" data-id="' + item.id + '" data-title="' + esc(item.title) + '" data-stops="' + esc(item.stops) + '"'
        + (idx === 0 ? ' checked' : '') + '>'
        + '<div><div class="fw-semibold">#' + item.id + ' · ' + esc(item.title) + '</div>'
        + '<div class="small text-muted">stops: ' + esc(item.stops) + ' · hub: ' + esc(item.hub) + '</div></div></div></label>';
    });
    html += '</div>';
    results.innerHTML = html;
    results.style.display = 'block';
    status.textContent = 'подтянуто · ' + list.length;
    status.className = 'badge bg-label-success php-slot-status';
    picks[day] = list[0];
    renderSummary();
    if (!opts.silent) showToast('День ' + day + ': найдено ' + list.length);
  }

  document.getElementById('phpRebuildSlots').addEventListener('click', function () {
    const n = Math.max(1, Math.min(14, parseInt(document.getElementById('phpDays').value, 10) || 10));
    document.getElementById('phpDays').value = n;
    renderSlots(n, true);
  });

  document.getElementById('phpAutoAll').addEventListener('click', function () {
    let n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    if (!document.querySelector('.php-slot')) {
      n = Math.max(1, Math.min(14, n || 10));
      document.getElementById('phpDays').value = n;
      renderSlots(n, false);
    } else {
      n = document.querySelectorAll('.php-slot').length;
    }
    let ok = 0;
    let miss = [];
    for (let d = 1; d <= n; d++) {
      findDays(d, { silent: true });
      if (picks[d]) ok++;
      else miss.push(d);
    }
    let msg = 'Подобрано автоматически: ' + ok + ' / ' + n;
    if (miss.length) msg += '. Без шаблона: ' + miss.join(', ') + ' — карта/Gemini';
    showToast(msg);
  });

  document.getElementById('phpSlots').addEventListener('click', function (e) {
    const findBtn = e.target.closest('.php-find');
    if (findBtn) {
      findDays(parseInt(findBtn.dataset.day, 10));
      return;
    }
  });

  document.getElementById('phpSlots').addEventListener('change', function (e) {
    if (e.target.classList.contains('php-pick')) {
      const day = parseInt(e.target.dataset.day, 10);
      picks[day] = {
        id: parseInt(e.target.dataset.id, 10),
        title: e.target.dataset.title,
        stops: e.target.dataset.stops,
      };
      const status = document.querySelector('.php-slot-status[data-day="' + day + '"]');
      if (status) {
        status.textContent = 'выбран #' + picks[day].id;
        status.className = 'badge bg-label-primary php-slot-status';
      }
      renderSummary();
    }
  });

  document.getElementById('phpAssemble').addEventListener('click', function () {
    const n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    const missing = [];
    for (let d = 1; d <= n; d++) if (!picks[d]) missing.push(d);
    if (missing.length) {
      showToast('Не выбраны дни: ' + missing.join(', '));
      return;
    }
    showToast('Маршрут собран (демо): ' + n + ' дней');
  });

  document.getElementById('phpToGemini').addEventListener('click', function () {
    showToast('Демо: отправили бы черновик в Gemini для оформления');
  });

  renderSlots(parseInt(document.getElementById('phpDays').value, 10) || 10);
})();
</script>
@endsection
