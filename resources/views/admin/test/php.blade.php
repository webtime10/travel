@extends('admin.layouts.layout')

@section('content')
@php
  $lang = $defaultLanguage ?? null;
  $hubJson = $hubLabels->map(fn ($h) => ['slug' => $h->slug, 'name' => $h->nameFor($lang)])->values();
  $otherJson = $otherLabels->map(fn ($h) => ['slug' => $h->slug, 'name' => $h->nameFor($lang), 'id' => $h->id])->values();
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Украина</h4>
    <p class="text-muted small mb-0">Только UA: хабы, метки и золотые дни. Без Италии. PHP-сборка слотов.</p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="{{ route('admin.test.index') }}" class="btn btn-sm btn-outline-secondary">← Test</a>
    <a href="{{ route('admin.test.php.map') }}" class="btn btn-sm btn-outline-primary">Собрать день · карта</a>
    <a href="{{ route('admin.test.php.gemini') }}" class="btn btn-sm btn-outline-primary">Собрать день · поиск</a>
    <span class="badge bg-label-primary">UA дней: {{ count($uaDays ?? []) }}</span>
  </div>
</div>

<div class="alert alert-info small mb-4">
  Авто: хаб + въезд/выезд + средний / насыщенный (1–6) + активный трек (0–6).
  Въезд всегда лёгкий (без галки «лёгкий»). Соседние дни рядом, без повтора мест.
  Зелёные метки — авто; жёлтые — только ручной фильтр.
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="mb-3">
      <label class="form-label" for="phpQuery">Запрос клиента</label>
      <textarea id="phpQuery" class="form-control" rows="2" placeholder="Киев, Львов, 10 дней, двое взрослых, двое детей"></textarea>
    </div>
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label">Регионы / хабы (порядок)</label>
        <div id="autoHubChecks" class="d-flex flex-wrap gap-2"></div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="phpDays">Дней</label>
        <input type="number" id="phpDays" class="form-control" min="1" max="14" value="" placeholder="—">
        <input type="hidden" id="autoDays" value="">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="autoKids">Детей</label>
        <input type="number" id="autoKids" class="form-control" min="0" max="10" value="0">
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label" for="autoHardDays">Насыщенных дней</label>
        <select id="autoHardDays" class="form-select">
          <option value="1" selected>1</option>
          <option value="2">2</option>
          <option value="3">3</option>
          <option value="4">4</option>
          <option value="5">5</option>
          <option value="6">6</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label" for="autoTrekDays">Активный трек (дней)</label>
        <select id="autoTrekDays" class="form-select">
          <option value="0" selected>0</option>
          <option value="1">1</option>
          <option value="2">2</option>
          <option value="3">3</option>
          <option value="4">4</option>
          <option value="5">5</option>
          <option value="6">6</option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-3">
        <label class="form-label" for="autoTransport">Перелёт / выезд · транспорт</label>
        <select id="autoTransport" class="form-select">
          <option value="">— не важно —</option>
          <option value="plane">Самолёт</option>
          <option value="bus">Автобус</option>
          <option value="car">Автомобиль</option>
        </select>
      </div>
      <div class="col-12 d-flex flex-wrap align-items-center gap-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="autoFamily">
          <label class="form-check-label" for="autoFamily">С детьми</label>
        </div>
      </div>
    </div>
    <div class="alert alert-secondary small mt-3 mb-3" id="autoPlanPreview">План: —</div>
    <div class="alert alert-warning small mt-0 mb-3 d-none" id="phpAssembleStatus" role="status"></div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-outline-secondary" id="autoParseBtn">
        Разобрать через Gemini
      </button>
      <button type="button" class="btn btn-success" id="phpAutoAll">
        <i class="bx bx-magic-wand me-1"></i>Подобрать все дни
      </button>
      <button type="button" class="btn btn-outline-primary" id="phpRebuildSlots">
        Разбить на слоты
      </button>
      <button type="button" class="btn btn-primary" id="autoRunBtn">
        Собрать по полям
      </button>
    </div>
  </div>
</div>

<div id="phpSlots"></div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold">Маршрут по Украине</span>
            <ul class="nav nav-pills nav-pills-sm mb-0" id="phpVariantTabs" role="tablist">
              <li class="nav-item">
                <button type="button" class="nav-link active py-1 px-3" data-variant="1" id="phpVarTab1">1</button>
              </li>
              <li class="nav-item">
                <button type="button" class="nav-link py-1 px-3" data-variant="2" id="phpVarTab2" disabled title="Сначала создайте второй маршрут">2</button>
              </li>
              <li class="nav-item">
                <button type="button" class="nav-link py-1 px-3" data-variant="3" id="phpVarTab3" disabled title="Сначала создайте третий маршрут">3</button>
              </li>
            </ul>
            <button type="button" class="btn btn-sm btn-outline-primary" id="phpMakeVariant" title="Тот же въезд/выезд, другая середина">
              Сделать следующий вариант
            </button>
          </div>
          <span class="badge bg-label-secondary" id="phpPickedCount">0 / 0 выбрано</span>
        </div>
        <div class="small text-muted mt-1" id="phpVariantHint">Сейчас: вариант <b>1</b>. Сборка и правки идут только в активный таб.</div>
      </div>
      <div class="card-body p-0">
        <table class="table mb-0" id="phpSummaryTable">
          <thead>
            <tr>
              <th style="width:4rem">Слот</th>
              <th>В маршруте (1 день на слот)</th>
              <th>Локации (id)</th>
              <th style="width:7rem"></th>
            </tr>
          </thead>
          <tbody id="phpSummaryBody"></tbody>
        </table>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
        <button type="button" class="btn btn-primary" id="phpAssemble">Собрать маршрут</button>
        <button type="button" class="btn btn-success" id="phpSaveRoute">
          <i class="bx bx-save me-1"></i>Сохранить маршрут
        </button>
        <button type="button" class="btn btn-outline-secondary" id="phpToGemini">Отдать в Gemini (оформить)</button>
        <a href="{{ route('admin.saved-routes.index') }}" class="btn btn-link btn-sm ms-auto">Все маршруты</a>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-1">
        <span>Карта Украины · выборка</span>
        <span class="badge bg-label-warning d-none" id="phpMapWarn">есть отклонения</span>
      </div>
      <div class="card-body p-0">
        <div id="phpUaMap" style="height:420px;width:100%"></div>
      </div>
      <div class="card-footer small text-muted py-2" id="phpMapLegend">
        От въезда: блеклый зелёный (≤10 км) · жёлтый (≤20) · красный (дальше).
      </div>
    </div>
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const hubs = @json($hubJson);
  const labels = @json($otherJson);
  const uaDays = @json($uaDays ?? []);
  const uaDaysById = {};
  uaDays.forEach(function (d) { uaDaysById[d.id] = d; });
  const mapUrl = @json(route('admin.test.php.map'));
  const geminiUrl = @json(route('admin.test.php.gemini'));
  const parseUrl = @json(route('admin.test.php.parse'));
  const saveRouteUrl = @json(route('admin.saved-routes.store'));
  const csrf = @json(csrf_token());
  const dayEditBase = @json(url('/admin/golden-days'));
  const SLOT_COLORS = ['#696cff', '#71dd37', '#ffab00', '#03c3ec', '#ff3e1d', '#8592a3', '#233446', '#28c76f', '#ff9f43', '#7367f0', '#00cfe8', '#ea5455', '#4b4b4b', '#28dac6'];

  /** Варианты маршрута: у каждого свои привязки слот→golden day */
  const routeVariants = {
    1: { picks: {}, ready: true },
    2: { picks: {}, ready: false },
    3: { picks: {}, ready: false },
  };
  let activeVariant = 1;
  let picks = routeVariants[1].picks;
  let toast;
  let uaMap = null;
  let mapLayer = null;

  function setActiveVariant(v, opts) {
    opts = opts || {};
    v = parseInt(v, 10);
    if (!routeVariants[v]) return;
    activeVariant = v;
    picks = routeVariants[v].picks;
    document.querySelectorAll('#phpVariantTabs [data-variant]').forEach(function (btn) {
      const id = parseInt(btn.getAttribute('data-variant'), 10);
      btn.classList.toggle('active', id === v);
      btn.disabled = !routeVariants[id].ready && id !== v;
    });
    const hint = document.getElementById('phpVariantHint');
    if (hint) {
      hint.innerHTML = 'Сейчас: вариант <b>' + v + '</b>. Сборка и правки идут только в этот таб.';
    }
    if (!opts.skipSync) {
      syncUiFromActivePicks();
    }
  }

  function clearPicksObject(store) {
    Object.keys(store).forEach(function (k) { delete store[k]; });
  }

  function clonePicks(src) {
    const out = {};
    Object.keys(src).forEach(function (k) {
      out[k] = (src[k] || []).map(function (p) {
        return { id: p.id, title: p.title, stops: p.stops };
      });
    });
    return out;
  }

  function variantFilledCount(v) {
    const store = routeVariants[v] && routeVariants[v].picks;
    if (!store) return 0;
    return Object.keys(store).filter(function (k) {
      return Array.isArray(store[k]) && store[k].length > 0;
    }).length;
  }

  function syncUiFromActivePicks() {
    const n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    for (let d = 1; d <= n; d++) {
      updateSlotBadge(d);
      document.querySelectorAll('.php-result-row[data-day="' + d + '"]').forEach(function (row) {
        markRowInSelection(row, isInPicks(d, row.dataset.id));
      });
    }
    renderSummary(n);
    try {
      refreshUaMap();
    } catch (e) {}
  }

  function dayEditUrl(id) {
    const n = parseInt(id, 10);
    if (!n) return '#';
    return dayEditBase.replace(/\/$/, '') + '/' + n;
  }

  function haversineKm(a, b) {
    const R = 6371;
    const toRad = function (d) { return d * Math.PI / 180; };
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const x = Math.sin(dLat / 2) ** 2
      + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(x));
  }

  function ensureUaMap() {
    if (uaMap) return uaMap;
    const el = document.getElementById('phpUaMap');
    if (!el || typeof L === 'undefined') return null;
    uaMap = L.map('phpUaMap').setView([49.0, 31.5], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; OpenStreetMap',
    }).addTo(uaMap);
    mapLayer = L.layerGroup().addTo(uaMap);
    setTimeout(function () { uaMap.invalidateSize(); }, 100);
    return uaMap;
  }

  function pointsForPick(goldenId) {
    const src = uaDaysById[goldenId];
    return (src && Array.isArray(src.points)) ? src.points : [];
  }

  function centroidOfPoints(pts) {
    if (!pts || !pts.length) return null;
    let la = 0, ln = 0, n = 0;
    pts.forEach(function (pt) {
      if (pt.lat == null || pt.lng == null) return;
      la += pt.lat; ln += pt.lng; n++;
    });
    if (!n) return null;
    return { lat: la / n, lng: ln / n };
  }

  function dayCentroid(goldenId) {
    return centroidOfPoints(pointsForPick(goldenId));
  }

  /** Близко / средне / далеко — блеклые цвета */
  const DIST_NEAR_KM = 10;
  const DIST_MID_KM = 20;
  const DIST_COLORS = {
    near: { fg: '#5a8f6a', bg: '#e8f3eb' },
    mid: { fg: '#a68b3c', bg: '#f7f1de' },
    far: { fg: '#b06a6a', bg: '#f5e8e8' },
    none: { fg: '#8592a3', bg: 'transparent' },
  };

  /** Центры хабов: в каталоге часть дней с hub=kyiv/odesa имеет координаты Закарпатья */
  const HUB_CENTERS = {
    'hub-kyiv': { lat: 50.4501, lng: 30.5234 },
    'hub-odesa': { lat: 46.4825, lng: 30.7233 },
    'hub-lviv': { lat: 49.8397, lng: 24.0297 },
    'hub-uzhhorod': { lat: 48.6208, lng: 22.2879 },
    'hub-carpathians': { lat: 48.3, lng: 24.2 },
    'hub-mykolaiv': { lat: 46.975, lng: 31.9946 },
    'hub-kharkiv': { lat: 49.9935, lng: 36.2304 },
    'hub-uman': { lat: 48.748, lng: 30.222 },
  };
  const HUB_MAX_KM = 140;

  function kmFromHubCenter(dayObj, hubSlug) {
    const center = HUB_CENTERS[hubSlug];
    if (!center || !dayObj) return null;
    const c = dayCentroid(dayObj.id);
    if (!c) return null;
    return haversineKm(center, c);
  }

  /** День реально в регионе хаба (не только label hub_key) */
  function dayFitsHub(dayObj, hubSlug, maxKm) {
    if (!hubSlug) return true;
    if (!dayObj) return false;
    if (dayObj.hub && dayObj.hub !== hubSlug) return false;
    const limit = maxKm != null ? maxKm : HUB_MAX_KM;
    const km = kmFromHubCenter(dayObj, hubSlug);
    // без координат — не режем (старый каталог); с координатами — только рядом с центром
    if (km == null) return true;
    return km <= limit;
  }

  function distZone(km) {
    if (km == null || isNaN(km)) return 'none';
    if (km <= DIST_NEAR_KM) return 'near';
    if (km <= DIST_MID_KM) return 'mid';
    return 'far';
  }

  /**
   * Якорь для слота: центр выбранного дня в предыдущем слоте (цепочка).
   * Если предыдущих нет — день прилёта того же хаба.
   */
  function findChainAnchor(hub, beforeSlot) {
    for (let d = beforeSlot - 1; d >= 1; d--) {
      const list = getPicks(d);
      if (!list.length) continue;
      const c = dayCentroid(list[0].id);
      if (c) {
        return {
          point: c,
          fromId: list[0].id,
          source: 'prev-slot-' + d,
          shortLabel: 'от дня ' + d,
        };
      }
    }
    for (let i = 0; i < uaDays.length; i++) {
      const src = uaDays[i];
      if ((src.labels || []).indexOf('arrival') === -1) continue;
      if (hub && src.hub && src.hub !== hub) continue;
      const c = dayCentroid(src.id);
      if (c) {
        return {
          point: c,
          fromId: src.id,
          source: 'catalog-arrival',
          shortLabel: 'от въезда',
        };
      }
    }
    return null;
  }

  function findEntryAnchor(hub, beforeSlot) {
    return findChainAnchor(hub, beforeSlot);
  }

  function kmFromEntry(goldenId, entry) {
    if (!entry || !entry.point) return null;
    const c = dayCentroid(goldenId);
    if (!c) return null;
    return haversineKm(entry.point, c);
  }

  function distanceFromEntryBadge(item, entry) {
    if ((item.labels || []).indexOf('arrival') !== -1 && (!entry || entry.source === 'catalog-arrival')) {
      return '<span class="badge" style="background:#e8eef5;color:#6b7c93;font-weight:500">это въезд</span>';
    }
    if (!entry || !entry.point) {
      return '<span class="small" style="color:#8592a3">удалённость: —</span>';
    }
    const km = kmFromEntry(item.id, entry);
    if (km == null) {
      return '<span class="small" style="color:#8592a3">удалённость: нет координат</span>';
    }
    const zone = distZone(km);
    const col = DIST_COLORS[zone];
    const label = zone === 'near' ? 'близко' : (zone === 'mid' ? 'средне' : 'далеко');
    const prefix = entry.shortLabel || 'от въезда';
    return '<span class="badge" style="background:' + col.bg + ';color:' + col.fg + ';font-weight:500" '
      + 'title="До #' + entry.fromId + '">'
      + prefix + ' ~' + km.toFixed(0) + ' км · ' + label
      + '</span>';
  }

  function refreshUaMap() {
    const map = ensureUaMap();
    if (!map || !mapLayer) return;
    mapLayer.clearLayers();
    const bounds = [];
    let farCount = 0;
    const n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    const warnBits = [];
    const dayCentroids = [];

    for (let d = 1; d <= n; d++) {
      const list = getPicks(d);
      if (!list.length) continue;
      const slotColor = SLOT_COLORS[(d - 1) % SLOT_COLORS.length];
      const hubSel = document.querySelector('.php-slot[data-day="' + d + '"] .php-hub');
      const entry = findEntryAnchor(hubSel ? hubSel.value : '', d);

      list.forEach(function (p) {
        const pts = pointsForPick(p.id);
        const dayKm = kmFromEntry(p.id, entry);
        const zone = distZone(dayKm);
        const zoneCol = DIST_COLORS[zone] || DIST_COLORS.none;
        const fill = zone === 'none' ? slotColor : zoneCol.fg;
        const ring = zone === 'far' ? DIST_COLORS.far.fg : (zone === 'mid' ? DIST_COLORS.mid.fg : fill);
        const dayPts = [];

        pts.forEach(function (pt) {
          if (pt.lat == null || pt.lng == null) return;
          dayPts.push(pt);
          if (zone === 'far') {
            farCount++;
            warnBits.push('слот ' + d + ' #' + p.id + ': ~' + (dayKm != null ? dayKm.toFixed(0) : '?') + ' км от въезда');
          }
          const marker = L.circleMarker([pt.lat, pt.lng], {
            radius: zone === 'far' ? 9 : 7,
            color: ring,
            weight: zone === 'far' ? 2 : 1,
            fillColor: fill,
            fillOpacity: 0.75,
          });
          marker.bindPopup(
            '<strong>Слот ' + d + ' · #' + p.id + '</strong><br>'
            + esc(pt.name || ('#' + pt.location_id))
            + (dayKm != null
              ? ('<br><span style="color:' + zoneCol.fg + '">от въезда ~' + dayKm.toFixed(0) + ' км</span>')
              : '')
          );
          marker.addTo(mapLayer);
          bounds.push([pt.lat, pt.lng]);
        });

        // Короткая линия только внутри дня (кластер стопов).
        if (dayPts.length >= 2) {
          L.polyline(dayPts.map(function (pt) { return [pt.lat, pt.lng]; }), {
            color: fill,
            weight: 3,
            opacity: 0.7,
          }).addTo(mapLayer);
        }
        const c = centroidOfPoints(dayPts);
        if (c) dayCentroids.push({ day: d, c: c, color: slotColor });
      });
    }

    // Пунктир между днями маршрута — не путать с зигзагом стопов внутри дня.
    if (dayCentroids.length >= 2) {
      L.polyline(dayCentroids.map(function (x) { return [x.c.lat, x.c.lng]; }), {
        color: '#8592a3',
        weight: 2,
        opacity: 0.55,
        dashArray: '6 8',
      }).addTo(mapLayer);
    }

    const warnEl = document.getElementById('phpMapWarn');
    const legend = document.getElementById('phpMapLegend');
    if (farCount > 0) {
      warnEl.classList.remove('d-none');
      warnEl.textContent = 'далеко: ' + farCount;
      legend.innerHTML = '<span style="color:' + DIST_COLORS.far.fg + '">Далеко (&gt;' + DIST_MID_KM + ' км от въезда):</span> '
        + esc(warnBits.slice(0, 4).join('; '))
        + (warnBits.length > 4 ? '…' : '');
    } else {
      warnEl.classList.add('d-none');
      legend.innerHTML = bounds.length
        ? ('Точек: ' + bounds.length + ' · сплошная = стопы дня · пунктир = переход слот→слот'
          + '. <span style="color:' + DIST_COLORS.near.fg + '">близко ≤' + DIST_NEAR_KM + '</span> · '
          + '<span style="color:' + DIST_COLORS.mid.fg + '">средне ≤' + DIST_MID_KM + '</span> · '
          + '<span style="color:' + DIST_COLORS.far.fg + '">далеко</span>')
        : 'Точки из «В выборку». От въезда: блеклый зелёный / жёлтый / красный.';
    }

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 11 });
    } else {
      map.setView([49.0, 31.5], 6);
    }
    setTimeout(function () { map.invalidateSize(); }, 50);
  }

  function setAssembleStatus(msg, kind) {
    const st = document.getElementById('phpAssembleStatus');
    if (!st) return;
    const text = String(msg || '').trim();
    if (!text) {
      st.classList.add('d-none');
      st.textContent = '';
      return;
    }
    st.classList.remove('d-none', 'alert-warning', 'alert-success', 'alert-danger', 'alert-secondary');
    st.classList.add(kind === 'ok' ? 'alert-success' : (kind === 'err' ? 'alert-danger' : 'alert-warning'));
    st.textContent = text;
  }

  function showToast(msg) {
    const text = String(msg || '');
    setAssembleStatus(text, /ошибк|укажите|отметьте|введите|пусто|нужн/i.test(text) ? 'err' : 'ok');
    try {
      const el = document.getElementById('phpToast');
      const body = document.getElementById('phpToastBody');
      if (body) body.textContent = text;
      if (el && window.bootstrap && bootstrap.Toast) {
        if (!toast) toast = new bootstrap.Toast(el, { delay: 4200 });
        toast.show();
      }
    } catch (e) {}
    try { console.log('[UA]', text); } catch (e2) {}
  }

  /** Записать день в слот без toggle (для автосборки). */
  function setSlotPick(day, item) {
    const id = parseInt(item && item.id, 10);
    if (!id) return false;
    picks[day] = [{
      id: id,
      title: item.title || ('#' + id),
      stops: item.stops || '',
    }];
    const row = document.querySelector('.php-result-row[data-day="' + day + '"][data-id="' + id + '"]');
    document.querySelectorAll('.php-result-row[data-day="' + day + '"]').forEach(function (r) {
      markRowInSelection(r, parseInt(r.dataset.id, 10) === id);
    });
    if (row) markRowInSelection(row, true);
    updateSlotBadge(day);
    return true;
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

  /** Метки автоподбора (зелёный блок) vs только ручной фильтр (жёлтый) */
  const AUTO_LABEL_SLUGS = {
    arrival: true, departure: true, flight: true,
    medium: true, hard: true, 'active-trek': true,
    plane: true, bus: true, car: true,
  };
  const HIDDEN_LABEL_SLUGS = {
    soft: true, stay: true, 'no-kids': true, 'short-day': true, 'long-day': true,
  };

  function oneLabelCheck(day, l) {
    return '<div class="form-check form-check-inline m-0">'
      + '<input class="form-check-input php-label" type="checkbox" data-day="' + day + '" value="' + esc(l.slug) + '" id="d' + day + '_' + l.id + '">'
      + '<label class="form-check-label small" for="d' + day + '_' + l.id + '">' + esc(l.name) + '</label>'
      + '</div>';
  }

  function labelChecks(day) {
    const auto = [];
    const manual = [];
    labels.forEach(function (l) {
      if (HIDDEN_LABEL_SLUGS[l.slug]) return;
      if (AUTO_LABEL_SLUGS[l.slug]) auto.push(l);
      else manual.push(l);
    });
    let html = '<div class="border border-success rounded p-2 mb-2" style="background:#f3faf5">'
      + '<div class="small text-success fw-semibold mb-1">Автоподбор</div>'
      + '<div class="d-flex flex-wrap gap-2">'
      + auto.map(function (l) { return oneLabelCheck(day, l); }).join('')
      + '</div></div>';
    if (manual.length) {
      html += '<div class="border rounded p-2" style="border-color:#e8d9a8!important;background:#fbf8ef">'
        + '<div class="small fw-semibold mb-1" style="color:#9a7b2f">Ручной фильтр</div>'
        + '<div class="d-flex flex-wrap gap-2">'
        + manual.map(function (l) { return oneLabelCheck(day, l); }).join('')
        + '</div></div>';
    }
    return html;
  }

  function defaultHub(day, total) {
    const checked = typeof selectedAutoHubs === 'function' ? selectedAutoHubs() : [];
    if (checked.length) {
      const plan = distributeDays(total, checked);
      return plan[day - 1] || checked[0];
    }
    return '';
  }

  function selectedLabels(day) {
    return Array.from(document.querySelectorAll('.php-label[data-day="' + day + '"]:checked'))
      .map(function (el) { return el.value; });
  }

  function matchDays(hub, needLabels) {
    const seenStops = {};
    return uaDays.filter(function (d) {
      if (hub && !dayFitsHub(d, hub)) return false;
      if (needLabels.length && !needLabels.every(function (slug) {
        return (d.labels || []).indexOf(slug) !== -1;
      })) return false;
      // Один набор стопов — одна карточка (без дублей #10028/#10040)
      const fp = String(d.stops || '').split(/[,\s]+/).filter(Boolean).map(Number).sort(function (a, b) { return a - b; }).join(',');
      if (fp && seenStops[fp]) return false;
      if (fp) seenStops[fp] = true;
      return true;
    });
  }

  function renderSlots(n, autoFindFirst, keepPicks) {
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
        + '<label class="form-label small">База / хаб (UA)</label>'
        + '<select class="form-select form-select-sm php-hub" data-day="' + d + '">' + hubOptions(hub) + '</select>'
        + '</div><div class="col-md-8">'
        + '<label class="form-label small">Метки</label>'
        + labelChecks(d)
        + '</div></div>'
        + '<div class="d-flex flex-wrap gap-2 mt-3">'
        + '<button type="button" class="btn btn-sm btn-outline-primary php-find" data-day="' + d + '"><i class="bx bx-search me-1"></i>Найти золотые дни</button>'
        + '<a class="btn btn-sm btn-outline-secondary" href="' + mapUrl + '?day=' + d + '">Дня нет · карта</a>'
        + '<a class="btn btn-sm btn-outline-secondary" href="' + geminiUrl + '?day=' + d + '">Дня нет · поиск</a>'
        + '</div>'
        + '<div class="php-results mt-3" data-day="' + d + '" style="display:none"></div>'
        + '</div></div>';
    }
    box.innerHTML = html;
    if (!keepPicks) {
      clearPicksObject(picks);
    }
    renderSummary(n);
    if (autoFindFirst !== false) {
      const pull = Math.min(3, n);
      for (let d = 1; d <= pull; d++) {
        findDays(d, { silent: d > 1 });
      }
      const found = [];
      for (let d = 1; d <= pull; d++) if (picks[d]) found.push(d);
      if (found.length) {
        showToast('Подтянулись дни UA: ' + found.join(', '));
      }
    }
  }

  function setSlotStatus(day, text, cls) {
    const status = document.querySelector('.php-slot-status[data-day="' + day + '"]');
    if (!status) return;
    status.textContent = text;
    status.className = 'badge ' + (cls || 'bg-label-secondary') + ' php-slot-status';
  }

  function getPicks(day) {
    return Array.isArray(picks[day]) ? picks[day] : [];
  }

  function picksFor(day) {
    if (!Array.isArray(picks[day])) picks[day] = [];
    return picks[day];
  }

  function isInPicks(day, id) {
    id = parseInt(id, 10);
    return getPicks(day).some(function (p) { return p.id === id; });
  }

  function updateSlotBadge(day) {
    const list = getPicks(day);
    if (list.length) {
      setSlotStatus(day, 'в маршруте #' + list[0].id, 'bg-label-primary');
    } else {
      const left = document.querySelectorAll('.php-result-row[data-day="' + day + '"]').length;
      setSlotStatus(day, left ? ('подтянуто · ' + left) : 'пусто', left ? 'bg-label-success' : 'bg-label-secondary');
    }
  }

  function recountResultsHead(day) {
    const left = document.querySelectorAll('.php-result-row[data-day="' + day + '"]').length;
    const box = document.querySelector('.php-slot[data-day="' + day + '"] .php-results');
    if (!box) return;
    let head = box.querySelector('.php-results-head');
    if (!head) {
      head = box.querySelector('.small.text-success, .small.text-muted');
    }
    if (head) {
      head.className = 'small ' + (left ? 'text-success' : 'text-muted') + ' mb-2 php-results-head';
      head.textContent = left ? ('В списке ' + left + ' (UA)') : 'Список пуст — всё перенесено в маршрут или удалено';
    }
    if (left === 0) {
      const group = box.querySelector('.list-group');
      if (group && !group.children.length) {
        group.remove();
      }
      if (!box.querySelector('.php-results-empty')) {
        const empty = document.createElement('div');
        empty.className = 'alert alert-secondary mb-0 small php-results-empty';
        empty.textContent = 'Список пуст. «Найти золотые дни» — подтянуть снова.';
        box.appendChild(empty);
      }
    } else {
      const empty = box.querySelector('.php-results-empty');
      if (empty) empty.remove();
    }
  }

  function markRowInSelection(row, inPick) {
    if (!row) return;
    row.classList.toggle('border-primary', inPick);
    row.classList.toggle('bg-label-primary', inPick);
    const addBtn = row.querySelector('.php-add-pick');
    if (!addBtn) return;
    addBtn.disabled = false;
    addBtn.classList.toggle('btn-primary', inPick);
    addBtn.classList.toggle('btn-outline-success', !inPick);
    addBtn.innerHTML = inPick
      ? '<i class="bx bx-check me-1"></i>В выборке'
      : '<i class="bx bx-plus me-1"></i>В выборку';
    addBtn.title = inPick ? 'Убрать из маршрута' : 'Добавить в маршрут';
  }

  /** В слоте только 1 день. Повторный клик по «В выборке» снимает с маршрута. */
  function addToSelection(day, item, rowEl) {
    const id = parseInt(item.id, 10);
    if (!id) {
      showToast('Не удалось добавить: нет id');
      return;
    }
    const row = rowEl || document.querySelector('.php-result-row[data-day="' + day + '"][data-id="' + id + '"]');

    // Уже в выборке — toggle off → «+ В выборку»
    if (isInPicks(day, id)) {
      removeFromSelection(day, id);
      return;
    }

    // Снять предыдущий (если был другой)
    getPicks(day).forEach(function (prev) {
      const prevRow = document.querySelector('.php-result-row[data-day="' + day + '"][data-id="' + prev.id + '"]');
      markRowInSelection(prevRow, false);
    });

    picks[day] = [{
      id: id,
      title: item.title || ('#' + id),
      stops: item.stops || '',
    }];
    markRowInSelection(row, true);
    updateSlotBadge(day);
    renderSummary();
    showToast('Слот ' + day + ': выбран #' + id + ' (один на слот)');
  }

  /** Убрать один золотой день из маршрута (слот day). */
  function removeFromSelection(day, id) {
    const list = getPicks(day);
    const before = list.length;
    const next = list.filter(function (p) { return p.id !== parseInt(id, 10); });
    if (next.length === before) return false;
    if (next.length) picks[day] = next;
    else delete picks[day];
    const row = document.querySelector('.php-result-row[data-day="' + day + '"][data-id="' + id + '"]');
    markRowInSelection(row, false);
    updateSlotBadge(day);
    renderSummary();
    showToast('Из выборки убран #' + id + ' (слот ' + day + ')');
    return true;
  }

  /** Убрать строку только из списка кандидатов (без маршрута). */
  function removeFromList(day, id, rowEl) {
    if (isInPicks(day, id)) {
      removeFromSelection(day, id);
    }
    const row = rowEl || document.querySelector('.php-result-row[data-day="' + day + '"][data-id="' + id + '"]');
    if (row) row.remove();
    recountResultsHead(day);
    updateSlotBadge(day);
    showToast('Удалён #' + id + ' из списка');
  }

  function renderSummary(n) {
    n = n || parseInt(document.getElementById('phpDays').value, 10) || 0;
    const body = document.getElementById('phpSummaryBody');
    let html = '';
    let slotFilled = 0;
    let totalItems = 0;
    for (let d = 1; d <= n; d++) {
      const list = getPicks(d);
      if (list.length) {
        slotFilled++;
        totalItems += list.length;
        const hubSel = document.querySelector('.php-slot[data-day="' + d + '"] .php-hub');
        const hubVal = hubSel ? hubSel.value : '';
        const entrySum = findEntryAnchor(hubVal, d);
        list.forEach(function (p, idx) {
          const src = uaDaysById[p.id] || { id: p.id, labels: [] };
          html += '<tr data-summary-day="' + d + '" data-id="' + p.id + '">'
            + '<td>' + (idx === 0 ? d : '') + '</td>'
            + '<td><a href="' + dayEditUrl(p.id) + '" target="_blank" rel="noopener">#' + p.id + '</a> · ' + esc(p.title)
            + '<div class="mt-1">' + distanceFromEntryBadge(src, entrySum) + '</div></td>'
            + '<td class="small text-muted">' + esc(p.stops) + '</td>'
            + '<td class="text-nowrap">'
            + '<a class="btn btn-sm btn-icon btn-outline-primary" href="' + dayEditUrl(p.id) + '" target="_blank" rel="noopener" title="Редактировать">'
            + '<i class="bx bx-pencil"></i></a> '
            + '<button type="button" class="btn btn-sm btn-icon btn-outline-danger php-summary-del" data-day="' + d + '" data-id="' + p.id + '" title="Удалить из маршрута">'
            + '<i class="bx bx-trash"></i></button>'
            + '</td></tr>';
        });
      } else {
        html += '<tr data-summary-day="' + d + '"><td>' + d + '</td>'
          + '<td><span class="text-muted">— не выбран —</span></td>'
          + '<td></td><td></td></tr>';
      }
    }
    body.innerHTML = html;
    const cnt = document.getElementById('phpPickedCount');
    if (cnt) {
      cnt.textContent = slotFilled + ' / ' + n + ' слотов · ' + totalItems + ' дн.';
    }
    try { refreshUaMap(); } catch (e) {}
  }

  function findDays(day, opts) {
    opts = opts || {};
    const slot = document.querySelector('.php-slot[data-day="' + day + '"]');
    if (!slot) return;
    const hubSel = slot.querySelector('.php-hub');
    const hub = hubSel ? hubSel.value : '';
    const need = selectedLabels(day);
    const results = slot.querySelector('.php-results');
    const status = document.querySelector('.php-slot-status[data-day="' + day + '"]');
    if (!results || !status) return;
    let list = matchDays(hub, need);
    // Уже выбранный день всегда в списке, даже если метки слота шире/уже
    getPicks(day).forEach(function (p) {
      const id = parseInt(p.id, 10);
      if (!id || list.some(function (x) { return x.id === id; })) return;
      const obj = uaDaysById[id];
      if (obj) list = [obj].concat(list);
    });
    if (list.length === 0) {
      const hubOnly = need.length ? matchDays(hub, []).length : 0;
      const why = need.length
        ? ('Нет дней UA с метками «' + need.join(' + ') + '»'
          + (hub ? ' у хаба ' + hub : '')
          + (hubOnly ? ' (без меток у хаба было бы ' + hubOnly + ')' : '') + '. ')
        : 'Нет золотых дней UA для этой базы. ';
      results.style.display = 'block';
      results.innerHTML = '<div class="alert alert-warning mb-0 small">' + why
        + 'Снимите лишние метки или соберите: <a href="' + mapUrl + '?day=' + day + '">карта</a> · '
        + '<a href="' + geminiUrl + '?day=' + day + '">поиск</a></div>';
      status.textContent = 'нет дней';
      status.className = 'badge bg-label-warning php-slot-status';
      if (!opts.silent) showToast('День ' + day + ': нет совпадений по меткам');
      return;
    }
    const entry = findEntryAnchor(hub, day);
    const preferArrival = need.indexOf('arrival') !== -1 || need.indexOf('flight') !== -1;
    const preferDeparture = need.indexOf('departure') !== -1 || need.indexOf('flight') !== -1
      || need.indexOf('transfer') !== -1;
    const preferFlight = need.indexOf('flight') !== -1;
    const usedElsewhere = usedStopsFromPicks(day);
    const placesElsewhere = usedPlacesFromPicks(day);
    const sorted = list.slice().sort(function (a, b) {
      const ca = dayConflictsUsed(a, usedElsewhere, placesElsewhere) ? 1 : 0;
      const cb = dayConflictsUsed(b, usedElsewhere, placesElsewhere) ? 1 : 0;
      if (ca !== cb) return ca - cb;
      const oa = overlapStopIds(a, usedElsewhere).length + overlapPlaceKeys(a, placesElsewhere).length;
      const ob = overlapStopIds(b, usedElsewhere).length + overlapPlaceKeys(b, placesElsewhere).length;
      if (oa !== ob) return oa - ob;
      // Роль ворот / перелёта приоритетнее нагрузки (soft/medium/hard)
      if (preferFlight) {
        const af = (a.labels || []).indexOf('flight') !== -1 ? 1 : 0;
        const bf = (b.labels || []).indexOf('flight') !== -1 ? 1 : 0;
        if (af !== bf) return bf - af;
      }
      if (preferArrival) {
        const aa = isEntryDay(a) ? 1 : 0;
        const ba = isEntryDay(b) ? 1 : 0;
        if (aa !== ba) return ba - aa;
      }
      if (preferDeparture) {
        const ad = isLeaveDay(a) ? 1 : 0;
        const bd = isLeaveDay(b) ? 1 : 0;
        if (ad !== bd) return bd - ad;
      }
      if (!preferArrival && !preferDeparture) {
        if (isEntryDay(a)) return -1;
        if (isEntryDay(b)) return 1;
      }
      if (!entry || !entry.point) return 0;
      const ka = kmFromEntry(a.id, entry);
      const kb = kmFromEntry(b.id, entry);
      if (ka == null && kb == null) return 0;
      if (ka == null) return 1;
      if (kb == null) return -1;
      return ka - kb;
    });

    let html = '<div class="small text-success mb-2 php-results-head">Подтянулось '
      + sorted.length + ' (UA)'
      + (getPicks(day).length ? (', в выборке ' + getPicks(day).length) : '')
      + (entry
        ? (', якорь #' + entry.fromId + ' (' + (entry.shortLabel || 'цепочка') + ')'
          + ' · близко ≤' + DIST_NEAR_KM + ' / средне ≤' + DIST_MID_KM + ' / далеко')
        : ', якорь не найден — сначала выберите день в предыдущем слоте')
      + ':</div><div class="list-group">';
    sorted.slice(0, 12).forEach(function (item) {
      const editHref = dayEditUrl(item.id);
      const inPick = isInPicks(day, item.id);
      html += '<div class="list-group-item php-result-row' + (inPick ? ' border-primary bg-label-primary' : '') + '" data-day="' + day + '" data-id="' + item.id + '">'
        + '<div class="d-flex gap-2 align-items-start">'
        + '<div class="flex-grow-1 min-w-0">'
        + '<div class="fw-semibold">'
        + '<a href="' + editHref + '" target="_blank" rel="noopener">#' + item.id + '</a> · ' + esc(item.title)
        + '</div>'
        + '<div class="small text-muted">stops: ' + esc(item.stops) + ' · ' + esc(item.hub)
        + ' · ' + esc((item.labels || []).slice(0, 5).join(', ')) + '</div>'
        + '<div class="mt-1">' + distanceFromEntryBadge(item, entry) + '</div>'
        + '</div>'
        + '<div class="d-flex gap-1 flex-shrink-0">'
        + '<a class="btn btn-sm btn-icon btn-outline-primary" href="' + editHref + '" target="_blank" rel="noopener" title="Открыть карточку">'
        + '<i class="bx bx-pencil"></i></a>'
        + '<button type="button" class="btn btn-sm php-add-pick ' + (inPick ? 'btn-primary' : 'btn-outline-success') + '"'
        + ' data-day="' + day + '" data-id="' + item.id + '" data-title="' + esc(item.title) + '" data-stops="' + esc(item.stops) + '"'
        + ' title="' + (inPick ? 'Уже выбран (клик не снимает — используйте Удалить)' : 'Выбрать в слот (один день)') + '">'
        + (inPick
          ? '<i class="bx bx-check me-1"></i>В выборке'
          : '<i class="bx bx-plus me-1"></i>В выборку')
        + '</button>'
        + '<button type="button" class="btn btn-sm btn-outline-danger php-del-pick"'
        + ' data-day="' + day + '" data-id="' + item.id + '"'
        + ' title="Убрать из списка; если был в выборке — снять">'
        + '<i class="bx bx-trash me-1"></i>Удалить</button>'
        + '</div></div></div>';
    });
    html += '</div>';
    results.innerHTML = html;
    results.style.display = 'block';
    // Восстановить подсветку выбранного после перерисовки
    getPicks(day).forEach(function (p) {
      const r = results.querySelector('.php-result-row[data-id="' + p.id + '"]');
      markRowInSelection(r, true);
    });
    updateSlotBadge(day);
    renderSummary();
    if (!opts.silent) showToast('День ' + day + ': найдено ' + sorted.length);
  }

  document.getElementById('phpRebuildSlots').addEventListener('click', function () {
    const raw = parseInt(document.getElementById('phpDays').value, 10);
    if (!raw) {
      showToast('Укажите число дней');
      return;
    }
    const n = Math.max(1, Math.min(14, raw));
    syncDaysFields(n);
    renderSlots(n, true);
  });

  /** Словарь регионов из запроса → slug хаба */
  const HUB_ALIASES = [
    { re: /киев|київ|kyiv|kiev/i, slug: 'hub-kyiv' },
    { re: /львов|львів|lviv|lvov/i, slug: 'hub-lviv' },
    { re: /карпат|carpath/i, slug: 'hub-carpathians' },
    { re: /ужгород|uzhhorod|uzhgorod/i, slug: 'hub-uzhhorod' },
    { re: /одесс|odesa|odessa/i, slug: 'hub-odesa' },
    { re: /николаев|миколаїв|mykolaiv|nikolaev/i, slug: 'hub-mykolaiv' },
    { re: /харьков|харків|kharkiv/i, slug: 'hub-kharkiv' },
    { re: /умань|uman/i, slug: 'hub-uman' },
  ];

  /** Порядок хабов из запроса (не sort_order меток в DOM) */
  let autoHubOrder = [];
  /** Нагрузка из разбора: medium ≠ hard(насыщенный) ≠ soft */
  let lastHardDays = 1;

  function fillAutoHubChecks(selectedSlugs) {
    const box = document.getElementById('autoHubChecks');
    if (!box) return;
    const incoming = (selectedSlugs || []).filter(function (s) {
      return hubs.some(function (h) { return h.slug === s; });
    });
    // Пустой разбор не должен снимать уже выбранные регионы
    if (incoming.length) {
      autoHubOrder = [];
      incoming.forEach(function (s) {
        if (autoHubOrder.indexOf(s) === -1) autoHubOrder.push(s);
      });
    } else if (!autoHubOrder.length) {
      box.querySelectorAll('.auto-hub:checked').forEach(function (el) {
        if (autoHubOrder.indexOf(el.value) === -1) autoHubOrder.push(el.value);
      });
    }
    const sel = {};
    autoHubOrder.forEach(function (s) { sel[s] = true; });
    const rendered = [];
    const seen = {};
    autoHubOrder.forEach(function (slug) {
      const h = hubs.find(function (x) { return x.slug === slug; });
      if (h) {
        rendered.push(h);
        seen[slug] = true;
      }
    });
    hubs.forEach(function (h) {
      if (!seen[h.slug]) rendered.push(h);
    });
    box.innerHTML = rendered.map(function (h) {
      const on = !!sel[h.slug];
      return '<div class="form-check form-check-inline m-0">'
        + '<input class="form-check-input auto-hub" type="checkbox" value="' + esc(h.slug) + '" id="ah_' + esc(h.slug) + '"'
        + (on ? ' checked' : '') + '>'
        + '<label class="form-check-label" for="ah_' + esc(h.slug) + '">' + esc(h.name) + '</label></div>';
    }).join('');
  }

  /** Локальный fallback, если Gemini недоступен — без дефолтных хабов/noTrek */
  function parseClientQueryLocal(text) {
    const t = String(text || '');
    const hits = [];
    HUB_ALIASES.forEach(function (a) {
      const m = t.match(a.re);
      if (m && typeof m.index === 'number') {
        hits.push({ slug: a.slug, index: m.index });
      }
    });
    hits.sort(function (a, b) { return a.index - b.index; });
    const hubsFound = [];
    hits.forEach(function (h) {
      if (hubsFound.indexOf(h.slug) === -1) hubsFound.push(h.slug);
    });
    let days = null;
    const range = t.match(/(\d+)\s*[–\-—]\s*(\d+)\s*(?:дн|день|дня|дней)?/i);
    const single = t.match(/(\d+)\s*(?:дн|день|дня|дней)/i);
    if (range) {
      days = Math.max(parseInt(range[1], 10), parseInt(range[2], 10));
    } else if (single) {
      days = parseInt(single[1], 10);
    } else {
      const bare = t.match(/\b(\d{1,2})\b/);
      if (bare) {
        const n = parseInt(bare[1], 10);
        if (n >= 2 && n <= 14) days = n;
      }
    }
    let kids = 0;
    if (/двое\s*(?:дет|ребён|ребен)/i.test(t)) kids = 2;
    else if (/трое\s*(?:дет|ребён|ребен)/i.test(t)) kids = 3;
    else if (/четверо\s*(?:дет|ребён|ребен)/i.test(t)) kids = 4;
    else {
      const kidsM = t.match(/(\d+)\s*(?:дет|ребён|ребен|child)/i);
      if (kidsM && kidsM[1]) kids = parseInt(kidsM[1], 10) || 0;
    }
    const family = kids > 0
      || /семь[яи]|с\s*детьми|family|дети|ребён|ребен/i.test(t);
    let hardDays = 1;
    const hardM = t.match(/(\d+)\s*насыщен/i);
    if (hardM) hardDays = Math.min(6, Math.max(1, parseInt(hardM[1], 10) || 1));
    else if (/шесть\s*насыщен/i.test(t)) hardDays = 6;
    else if (/два\s*насыщен|двое\s*насыщен/i.test(t)) hardDays = 2;
    let trekDays = 0;
    const trekM = t.match(/(\d+)\s*(?:дн\w*\s+)?(?:активн\w*\s+)?трек/i);
    if (trekM) trekDays = Math.min(6, Math.max(0, parseInt(trekM[1], 10) || 0));
    else if (/активн\w*\s+трек|говерл/i.test(t)) trekDays = 1;
    let transport = null;
    if (/самолёт|самолет|авиа|перелёт|перелет|plane|flight/i.test(t)) transport = 'plane';
    else if (/автобус|bus/i.test(t)) transport = 'bus';
    else if (/автомобил|на\s*машине|на\s*авто|car\b/i.test(t)) transport = 'car';
    return {
      hubs: hubsFound,
      days: days,
      kids: kids,
      family: family,
      hardDays: hardDays,
      trekDays: trekDays,
      transport: transport,
    };
  }

  async function parseClientQueryGemini(text) {
    const res = await fetch(parseUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
      },
      body: JSON.stringify({ query: text }),
    });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) {
      throw new Error((data && data.error) || ('HTTP ' + res.status));
    }
    let hardDays = parseInt(data.hard_days, 10);
    if (!(hardDays >= 1 && hardDays <= 6)) hardDays = 1;
    let trekDays = parseInt(data.trek_days, 10);
    if (!(trekDays >= 0 && trekDays <= 6)) trekDays = 0;
    const transport = ['plane', 'bus', 'car'].indexOf(data.transport) !== -1 ? data.transport : null;
    return {
      hubs: Array.isArray(data.hubs) ? data.hubs : [],
      days: data.days != null ? data.days : null,
      kids: data.kids || 0,
      family: !!data.family,
      hardDays: hardDays,
      trekDays: trekDays,
      transport: transport,
    };
  }

  function staySlotsFromPlan(hubPlan) {
    const staySlots = [];
    const n = hubPlan.length;
    for (let d = 1; d <= n; d++) {
      const hub = hubPlan[d - 1];
      const prev = d > 1 ? hubPlan[d - 2] : null;
      const next = d < n ? hubPlan[d] : null;
      const wantArrival = d === 1 || (prev && prev !== hub);
      const wantDeparture = d === n || (next && next !== hub);
      if (!wantArrival && !wantDeparture) staySlots.push(d);
    }
    return staySlots;
  }

  /** Равномерно выбрать ровно cap уникальных stay-слотов */
  function pickSlotMap(hubPlan, count, maxCount) {
    const staySlots = staySlotsFromPlan(hubPlan);
    const map = {};
    const cap = Math.min(Math.max(0, count || 0), maxCount || 6, staySlots.length);
    if (cap <= 0 || !staySlots.length) return map;
    if (cap === 1) {
      map[staySlots[Math.floor(staySlots.length / 2)]] = true;
      return map;
    }
    if (cap >= staySlots.length) {
      staySlots.forEach(function (s) { map[s] = true; });
      return map;
    }
    // равномерно по индексам; при коллизии — добираем свободные
    for (let i = 0; i < cap; i++) {
      const idx = Math.round(i * (staySlots.length - 1) / (cap - 1));
      map[staySlots[idx]] = true;
    }
    if (Object.keys(map).length < cap) {
      for (let s = 0; s < staySlots.length && Object.keys(map).length < cap; s++) {
        map[staySlots[s]] = true;
      }
    }
    return map;
  }

  /** Насыщенные: по возможности по одному на хаб, потом добор */
  function pickHardSlotMap(hubPlan, hardCount) {
    const staySlots = staySlotsFromPlan(hubPlan);
    const want = Math.min(Math.max(0, hardCount || 0), 6, staySlots.length);
    if (want <= 0) return {};
    const byHub = {};
    staySlots.forEach(function (slot) {
      const hub = hubPlan[slot - 1] || '';
      if (!byHub[hub]) byHub[hub] = [];
      byHub[hub].push(slot);
    });
    const map = {};
    const hubsOrder = Object.keys(byHub);
    // раунд 1: по одному mid-слоту на хаб
    hubsOrder.forEach(function (hub) {
      if (Object.keys(map).length >= want) return;
      const arr = byHub[hub];
      const mid = arr[Math.floor(arr.length / 2)];
      map[mid] = true;
    });
    // раунд 2: добор равномерно из оставшихся stay
    if (Object.keys(map).length < want) {
      const rest = staySlots.filter(function (s) { return !map[s]; });
      const need = want - Object.keys(map).length;
      for (let i = 0; i < need && i < rest.length; i++) {
        const idx = need === 1
          ? Math.floor(rest.length / 2)
          : Math.round(i * (rest.length - 1) / Math.max(1, need - 1));
        map[rest[Math.max(0, Math.min(rest.length - 1, idx))]] = true;
      }
      // если всё ещё мало из‑за коллизий индексов
      for (let s = 0; s < rest.length && Object.keys(map).length < want; s++) {
        map[rest[s]] = true;
      }
    }
    return map;
  }

  function pickTrekSlotMap(hubPlan, trekCount) {
    return pickSlotMap(hubPlan, trekCount, 6);
  }

  function selectedAutoHubs() {
    const checked = {};
    document.querySelectorAll('.auto-hub:checked').forEach(function (el) {
      checked[el.value] = true;
    });
    const out = autoHubOrder.filter(function (s) { return !!checked[s]; });
    document.querySelectorAll('.auto-hub:checked').forEach(function (el) {
      if (out.indexOf(el.value) === -1) out.push(el.value);
    });
    return out;
  }

  function syncAutoHubOrderFromChecks() {
    const checked = Array.from(document.querySelectorAll('.auto-hub:checked')).map(function (el) {
      return el.value;
    });
    autoHubOrder = autoHubOrder.filter(function (s) { return checked.indexOf(s) !== -1; });
    checked.forEach(function (s) {
      if (autoHubOrder.indexOf(s) === -1) autoHubOrder.push(s);
    });
  }

  /** Равномерно: 10 дней, 2 хаба → 5+5; остаток — первым хабам */
  function distributeDays(total, hubSlugs) {
    const hubsList = hubSlugs.length ? hubSlugs.slice() : ['hub-kyiv'];
    const n = hubsList.length;
    const base = Math.floor(total / n);
    let rem = total % n;
    const plan = [];
    hubsList.forEach(function (slug) {
      const count = base + (rem > 0 ? 1 : 0);
      if (rem > 0) rem--;
      for (let i = 0; i < count; i++) plan.push(slug);
    });
    while (plan.length < total) plan.push(hubsList[hubsList.length - 1]);
    return plan.slice(0, total);
  }

  function stopFingerprint(dayObj) {
    return stopIds(dayObj).slice().sort(function (a, b) { return a - b; }).join(',');
  }

  function stopIds(dayObj) {
    const raw = dayObj && dayObj.stops != null ? dayObj.stops : '';
    return String(raw).split(/[,\s]+/).filter(Boolean).map(Number).filter(function (n) { return n > 0; });
  }

  function overlapStopIds(dayObj, usedStops) {
    if (!usedStops) return [];
    return stopIds(dayObj).filter(function (id) { return !!usedStops[id]; });
  }

  function overlapRatio(dayObj, usedStops) {
    const ids = stopIds(dayObj);
    if (!ids.length) return 0;
    return overlapStopIds(dayObj, usedStops).length / ids.length;
  }

  function registerStops(dayObj, usedStops) {
    stopIds(dayObj).forEach(function (id) { usedStops[id] = true; });
  }

  function normalizePlaceKey(raw) {
    let s = String(raw || '').toLowerCase().replace(/\s+/g, ' ').trim();
    s = s.replace(/^(озеро\s*\/\s*пляж|пляж|озеро|картинг|карусель|аттракцион\w*|детские\s+аттракцион\w*|синагог\w*|хаб)\s+/u, '');
    s = s.replace(/[«»""]/g, '').trim();
    if (s.length < 3) return '';
    if (/одесса|дунай|хаб/i.test(s) && s.length < 8) return '';
    return s;
  }

  /** Ключи мест из названия («Липова», «Зоряна», Дерибасовская…) — чтобы не брать 2 дня одну зону */
  function placeKeysFromDay(dayObj) {
    const title = String((dayObj && dayObj.title) || '');
    const keys = {};
    const re = /[«"]([^»"]{2,48})[»"]/g;
    let m;
    while ((m = re.exec(title))) {
      const k = normalizePlaceKey(m[1]);
      if (k) keys[k] = true;
    }
    if (/дерибасов/i.test(title)) keys['дерибасовская'] = true;
    if (/липов/i.test(title)) keys['липова'] = true;
    if (/зорян/i.test(title)) keys['зоряна'] = true;
    if (/аркади/i.test(title)) keys['аркадия'] = true;
    if (/ланжерон/i.test(title)) keys['ланжерон'] = true;
    if (/катакомб/i.test(title)) keys['катакомбы'] = true;
    if (/потемкин|потёмкин/i.test(title)) keys['потемкинская'] = true;
    return Object.keys(keys);
  }

  function overlapPlaceKeys(dayObj, usedPlaces) {
    if (!usedPlaces) return [];
    return placeKeysFromDay(dayObj).filter(function (k) { return !!usedPlaces[k]; });
  }

  function registerPlaces(dayObj, usedPlaces) {
    placeKeysFromDay(dayObj).forEach(function (k) { usedPlaces[k] = true; });
  }

  function dayConflictsUsed(dayObj, usedStops, usedPlaces) {
    return overlapStopIds(dayObj, usedStops).length > 0
      || overlapPlaceKeys(dayObj, usedPlaces).length > 0;
  }

  /** Стопы уже занятые другими слотами маршрута (кроме exceptDay). */
  function usedStopsFromPicks(exceptDay) {
    const used = {};
    Object.keys(picks).forEach(function (key) {
      const d = parseInt(key, 10);
      if (exceptDay && d === exceptDay) return;
      getPicks(d).forEach(function (p) {
        const obj = uaDaysById[p.id] || p;
        registerStops(obj, used);
      });
    });
    return used;
  }

  function usedPlacesFromPicks(exceptDay) {
    const used = {};
    Object.keys(picks).forEach(function (key) {
      const d = parseInt(key, 10);
      if (exceptDay && d === exceptDay) return;
      getPicks(d).forEach(function (p) {
        const obj = uaDaysById[p.id] || p;
        registerPlaces(obj, used);
      });
    });
    return used;
  }

  function hasLabel(dayObj, slug) {
    return (dayObj.labels || []).indexOf(slug) !== -1;
  }

  function isEntryDay(dayObj) {
    return hasLabel(dayObj, 'arrival') || hasLabel(dayObj, 'flight');
  }

  function isLeaveDay(dayObj) {
    return hasLabel(dayObj, 'departure') || hasLabel(dayObj, 'flight') || hasLabel(dayObj, 'transfer');
  }

  function arrivalDayBlob(dayObj) {
    const parts = [dayObj.title || '', dayObj.start_point || '', dayObj.end_point || ''];
    // В каталоге stops — строка id («12, 34»); имена точек — в points[]
    const pts = dayObj && Array.isArray(dayObj.points) ? dayObj.points : [];
    pts.forEach(function (p) {
      if (!p) return;
      parts.push(p.name || '');
      parts.push(p.name_ru || '');
    });
    if (typeof dayObj.stops === 'string') {
      parts.push(dayObj.stops);
    } else if (Array.isArray(dayObj.stops)) {
      dayObj.stops.forEach(function (s) {
        if (s && typeof s === 'object') {
          parts.push(s.name || '');
          parts.push(s.name_ru || '');
        } else if (s != null) {
          parts.push(String(s));
        }
      });
    }
    return parts.join(' ');
  }

  function scoreCandidate(dayObj, opts) {
    let score = 0;
    const labels = dayObj.labels || [];
    // Въезд / перелёт в хаб ≠ экскурсия; выезд ≠ перелёт (разные метки)
    if (opts.wantArrival) {
      if (hasLabel(dayObj, 'arrival')) score += 45;
      if (hasLabel(dayObj, 'flight')) score += 40;
      if (hasLabel(dayObj, 'departure') && !hasLabel(dayObj, 'arrival') && !hasLabel(dayObj, 'flight')) score -= 80;
      if (hasLabel(dayObj, 'stay') && !isEntryDay(dayObj)) score -= 25;
      // Центр хаба (Одесса: Babel Fish / Маразлиевская / Шевченко / синагоги) ≫ дальние outing
      const blob = arrivalDayBlob(dayObj);
      if (/(бабель|babel|маразлиев|дерибасов|синагог|парк\s*шевченк|шевченк.*одесс)/i.test(blob)) score += 55;
      if (/(устрич|вилков|заток|шабо|аккерман|белгород)/i.test(blob)) score -= 90;
    }
    if (opts.wantDeparture) {
      if (hasLabel(dayObj, 'departure')) score += 45;
      if (hasLabel(dayObj, 'flight')) score += 40;
      if (hasLabel(dayObj, 'transfer')) score += 28;
      if (hasLabel(dayObj, 'arrival') && !hasLabel(dayObj, 'departure') && !hasLabel(dayObj, 'flight')) score -= 80;
      if (hasLabel(dayObj, 'stay') && !isLeaveDay(dayObj)) score -= 25;
    }
    if (opts.wantTransfer && hasLabel(dayObj, 'transfer')) score += 25;
    if (opts.wantStay) {
      // Обычный день = не ворота; метка stay не обязательна
      if (hasLabel(dayObj, 'arrival') || hasLabel(dayObj, 'departure') || hasLabel(dayObj, 'flight')) score -= 50;
      else score += 8;
    }
    // Транспорт (если выбран в prefs)
    if (opts.transport) {
      if (hasLabel(dayObj, opts.transport)) score += 30;
      else score -= 8;
    }
    // Нагрузка: soft ≠ medium ≠ hard(насыщенный) — разные уровни, не взаимозамена
    if (opts.preferHard) {
      if (hasLabel(dayObj, 'hard')) score += 28;
      else if (hasLabel(dayObj, 'medium')) score -= 10;
      else if (hasLabel(dayObj, 'soft')) score -= 20;
    } else if (opts.preferMedium) {
      if (hasLabel(dayObj, 'medium')) score += 28;
      else if (hasLabel(dayObj, 'soft')) score += 6;
      else if (hasLabel(dayObj, 'hard')) score -= 35; // насыщенный ≠ средний
    } else if (opts.preferSoft) {
      if (hasLabel(dayObj, 'soft')) score += 28;
      else if (hasLabel(dayObj, 'medium')) score += 4;
      else if (hasLabel(dayObj, 'hard')) score -= 30;
    }
    // С детьми: большинство дней ок; штрафуем/исключаем «без детей», лёгкий бонус family
    if (opts.family) {
      if (hasLabel(dayObj, 'no-kids')) score -= 100;
      if (hasLabel(dayObj, 'family')) score += 12;
      if (hasLabel(dayObj, 'active-trek')) score -= 50;
    }
    if (opts.noTrek && hasLabel(dayObj, 'active-trek')) score -= 100;
    if (opts.noTrek && hasLabel(dayObj, 'hard') && hasLabel(dayObj, 'active')) score -= 10;
    // Любой повтор стопа (в т.ч. «Дерибасовская хаб») — жёсткий штраф
    if (opts.usedStops) {
      const ids = stopIds(dayObj);
      const reused = overlapStopIds(dayObj, opts.usedStops).length;
      const fresh = Math.max(0, ids.length - reused);
      score += fresh * 18;
      score -= reused * 120;
      if (reused > 0) score -= 80;
    }
    if (opts.anchorKm != null && !isNaN(opts.anchorKm)) {
      // Обычный день: гуляем рядом с вчерашним (~10–20 км), не в противоположную сторону
      if (opts.wantStay) {
        if (opts.anchorKm <= DIST_NEAR_KM) score += 36;
        else if (opts.anchorKm <= DIST_MID_KM) score += 24;
        else if (opts.anchorKm <= 35) score += 4;
        else score -= Math.min(55, Math.floor((opts.anchorKm - 35) / 3));
      } else if (opts.anchorKm <= DIST_NEAR_KM) {
        score += 30;
      } else if (opts.anchorKm <= DIST_MID_KM) {
        score += 18;
      } else if (opts.anchorKm <= 40) {
        score += 5;
      } else {
        score -= Math.min(40, Math.floor((opts.anchorKm - 40) / 5));
      }
    }
    // лёгкий бонус за разнообразие меток
    score += Math.min(6, labels.length);
    return score;
  }

  function pickBestForSlot(hub, slotDay, total, usedIds, usedFp, usedStops, usedPlaces, prefs, prevHub, nextHub) {
    const isFirst = slotDay === 1;
    const isLast = slotDay === total;
    const hubChanged = prevHub && prevHub !== hub;
    // Последний день текущего хаба → выезд/перелёт (не stay); следующий хаб получит въезд
    const leavingHub = !!(nextHub && nextHub !== hub);
    const wantArrival = isFirst || hubChanged;
    const wantDeparture = isLast || leavingHub;
    const wantTransfer = false;
    const wantStay = !wantArrival && !wantDeparture;
    // Нагрузка: въезд лёгкий; N слотов насыщенный; M — активный трек; остальные средний
    const preferHard = wantStay && !!(prefs.hardSlots && prefs.hardSlots[slotDay]);
    const preferTrek = wantStay && !!(prefs.trekSlots && prefs.trekSlots[slotDay]);
    const preferMedium = wantStay && !preferHard && !preferTrek;
    const preferSoft = (wantArrival && !wantDeparture) || (wantDeparture && isLast);

    let pool = uaDays.filter(function (d) {
      if (hub && !dayFitsHub(d, hub)) return false;
      if (usedIds[d.id]) return false;
      const fp = stopFingerprint(d);
      if (fp && usedFp[fp]) return false;
      if (prefs.family && hasLabel(d, 'no-kids')) return false;
      if (prefs.family && hasLabel(d, 'active-trek') && !preferTrek) return false;
      // Чистый выезд не на въезд; чистый въезд не на выезд (перелёт ок в обе стороны)
      if (wantArrival && !wantDeparture
        && hasLabel(d, 'departure') && !hasLabel(d, 'arrival') && !hasLabel(d, 'flight')) return false;
      if (wantDeparture && !wantArrival
        && hasLabel(d, 'arrival') && !hasLabel(d, 'departure') && !hasLabel(d, 'flight')) return false;
      return true;
    });

    if (!pool.length) return null;

    // Роль ворот / перелёта — жёсткий приоритет над soft/medium/hard и над «экскурсией»
    if (wantArrival && wantDeparture) {
      // Один день в хабе: перелёт или день с обеими ролями
      const gated = pool.filter(function (d) {
        return hasLabel(d, 'flight') || hasLabel(d, 'transfer')
          || (hasLabel(d, 'arrival') && hasLabel(d, 'departure'));
      });
      if (gated.length) pool = gated;
    } else if (wantArrival) {
      const gated = pool.filter(function (d) { return isEntryDay(d); });
      if (gated.length) pool = gated;
    } else if (wantDeparture) {
      const gated = pool.filter(function (d) { return isLeaveDay(d); });
      if (gated.length) pool = gated;
    }

    if (prefs.transport) {
      const byTransport = pool.filter(function (d) { return hasLabel(d, prefs.transport); });
      if (byTransport.length) pool = byTransport;
    }

    // Обычный день: без меток въезд/выезд/перелёт (stay на карточке не обязателен)
    if (wantStay) {
      const nonGate = pool.filter(function (d) {
        return !hasLabel(d, 'arrival') && !hasLabel(d, 'departure') && !hasLabel(d, 'flight');
      });
      if (nonGate.length) pool = nonGate;
    }

    const entry = findEntryAnchor(hub, slotDay);
    const stopsMap = usedStops || {};
    const placesMap = usedPlaces || {};

    // Предпочитаем дни без повтора стопов/зон; для stay не обрываем — берём с мин. overlap
    const zeroOverlap = pool.filter(function (d) {
      return !dayConflictsUsed(d, stopsMap, placesMap);
    });
    if (zeroOverlap.length) {
      pool = zeroOverlap;
    } else {
      pool = pool.slice().sort(function (a, b) {
        const ca = overlapStopIds(a, stopsMap).length + overlapPlaceKeys(a, placesMap).length;
        const cb = overlapStopIds(b, stopsMap).length + overlapPlaceKeys(b, placesMap).length;
        return ca - cb;
      });
    }

    // Нагрузка / трек — мягкое предпочтение
    if (wantStay) {
      let loadPool = null;
      if (preferTrek) {
        loadPool = pool.filter(function (d) { return hasLabel(d, 'active-trek'); });
        if (!loadPool.length) {
          loadPool = pool.filter(function (d) { return hasLabel(d, 'hard'); });
        }
      } else if (preferHard) {
        loadPool = pool.filter(function (d) {
          return hasLabel(d, 'hard') && !hasLabel(d, 'active-trek');
        });
        if (!loadPool.length) {
          loadPool = pool.filter(function (d) { return hasLabel(d, 'hard'); });
        }
        // мало hard у хаба в 140 км — расширяем до 220 км, только hard
        if (!loadPool.length && hub) {
          const wider = uaDays.filter(function (d) {
            if (usedIds[d.id]) return false;
            if (!hasLabel(d, 'hard')) return false;
            if (d.hub && d.hub !== hub) return false;
            if (!dayFitsHub(d, hub, 220)) return false;
            if (prefs.family && hasLabel(d, 'no-kids')) return false;
            if (hasLabel(d, 'arrival') || hasLabel(d, 'departure') || hasLabel(d, 'flight')) return false;
            return true;
          });
          if (wider.length) loadPool = wider;
        }
        if (!loadPool.length) {
          loadPool = pool.filter(function (d) { return hasLabel(d, 'medium'); });
        }
      } else if (preferMedium) {
        loadPool = pool.filter(function (d) {
          return hasLabel(d, 'medium') && !hasLabel(d, 'active-trek');
        });
        if (!loadPool.length) {
          loadPool = pool.filter(function (d) { return !hasLabel(d, 'hard') && !hasLabel(d, 'active-trek'); });
        }
      }
      if (loadPool && loadPool.length) pool = loadPool;
    } else if (preferSoft) {
      // Финальный выезд / въезд: только лёгкий, без hard-экскурсий (каяки и т.п.)
      const softGate = pool.filter(function (d) {
        return (hasLabel(d, 'soft') || hasLabel(d, 'short-day') || !hasLabel(d, 'hard'))
          && !hasLabel(d, 'active-trek');
      });
      if (softGate.length) pool = softGate;
    }

    // Обычный день: рядом с вчерашним (~20 км), среди дней БЕЗ повтора стопов
    if (wantStay && entry && entry.point) {
      let geo = pool.filter(function (d) {
        const km = kmFromEntry(d.id, entry);
        return km == null || km <= DIST_MID_KM;
      });
      if (!geo.length) {
        geo = pool.filter(function (d) {
          const km = kmFromEntry(d.id, entry);
          return km == null || km <= 35;
        });
      }
      if (geo.length) pool = geo;
    }

    let best = null;
    let bestScore = -1e9;
    pool.forEach(function (d) {
      const km = kmFromEntry(d.id, entry);
      const s = scoreCandidate(d, {
        wantArrival: wantArrival,
        wantDeparture: wantDeparture,
        wantTransfer: wantTransfer,
        wantStay: wantStay,
        preferMedium: preferMedium,
        preferHard: preferHard,
        preferSoft: preferSoft,
        family: prefs.family,
        transport: prefs.transport || null,
        usedStops: stopsMap,
        anchorKm: km,
      });
      if (preferTrek && hasLabel(d, 'active-trek')) {
        // лёгкий бонус уже через пул
      }
      if (s > bestScore) {
        bestScore = s;
        best = d;
      }
    });
    return best
      ? {
        day: best,
        roleLabels: roleLabelsForPicked(best, wantArrival, wantDeparture, wantStay, {
          preferHard: preferHard,
          preferMedium: preferMedium,
          preferTrek: preferTrek,
          transport: prefs.transport,
        }),
      }
      : null;
  }

  /** Метки слота после авто: что реально участвовало в выборе */
  function roleLabelsForPicked(dayObj, wantArrival, wantDeparture, wantStay, prefs) {
    const out = [];
    if (wantArrival && wantDeparture) {
      if (hasLabel(dayObj, 'flight')) out.push('flight');
      else if (hasLabel(dayObj, 'transfer')) out.push('transfer');
      else {
        if (hasLabel(dayObj, 'arrival')) out.push('arrival');
        else if (hasLabel(dayObj, 'departure')) out.push('departure');
        else out.push('flight');
      }
    } else if (wantArrival) {
      if (hasLabel(dayObj, 'flight') && !hasLabel(dayObj, 'arrival')) out.push('flight');
      else out.push(hasLabel(dayObj, 'arrival') ? 'arrival' : (hasLabel(dayObj, 'flight') ? 'flight' : 'arrival'));
    } else if (wantDeparture) {
      if (hasLabel(dayObj, 'flight') && !hasLabel(dayObj, 'departure')) out.push('flight');
      else if (hasLabel(dayObj, 'departure')) out.push('departure');
      else if (hasLabel(dayObj, 'transfer')) out.push('transfer');
      else out.push('departure');
    } else if (wantStay) {
      // Чекбокс нагрузки = план фильтра (2 насыщенных → 2× hard), даже если взяли fallback
      if (prefs.preferTrek) {
        out.push(hasLabel(dayObj, 'active-trek') ? 'active-trek' : 'hard');
      } else if (prefs.preferHard) {
        out.push('hard');
      } else if (prefs.preferMedium) {
        if (hasLabel(dayObj, 'hard')) out.push('hard');
        else out.push('medium');
      } else if (hasLabel(dayObj, 'active-trek')) {
        out.push('active-trek');
      } else if (hasLabel(dayObj, 'hard')) {
        out.push('hard');
      } else if (hasLabel(dayObj, 'medium')) {
        out.push('medium');
      } else if (hasLabel(dayObj, 'soft') || hasLabel(dayObj, 'short-day')) {
        out.push('soft');
      }
    }
    ['plane', 'bus', 'car'].forEach(function (t) {
      if (hasLabel(dayObj, t) && out.indexOf(t) === -1) out.push(t);
    });
    if (prefs.transport && out.indexOf(prefs.transport) === -1) out.push(prefs.transport);
    return out;
  }

  function setSlotLabels(day, slugs) {
    document.querySelectorAll('.php-label[data-day="' + day + '"]').forEach(function (el) {
      el.checked = slugs.indexOf(el.value) !== -1;
    });
  }

  function syncDaysFields(n) {
    if (n == null || n === '' || isNaN(n)) {
      document.getElementById('phpDays').value = '';
      document.getElementById('autoDays').value = '';
      return;
    }
    const v = Math.max(1, Math.min(14, parseInt(n, 10)));
    document.getElementById('phpDays').value = v;
    document.getElementById('autoDays').value = v;
  }

  function updateAutoPlanPreview() {
    const hubsSel = selectedAutoHubs();
    const rawN = parseInt(document.getElementById('phpDays').value, 10);
    if (!hubsSel.length || !rawN) {
      document.getElementById('autoPlanPreview').textContent = 'План: — (нужны регионы и число дней)';
      return;
    }
    const n = Math.max(1, Math.min(14, rawN));
    const plan = distributeDays(n, hubsSel);
    const counts = {};
    plan.forEach(function (h) { counts[h] = (counts[h] || 0) + 1; });
    const parts = hubsSel.map(function (h) {
      const name = (hubs.find(function (x) { return x.slug === h; }) || {}).name || h;
      return name + ' × ' + (counts[h] || 0);
    });
    const flags = [];
    if (document.getElementById('autoFamily').checked) flags.push('с детьми');
    flags.push('средние по умолчанию');
    const hardEl = document.getElementById('autoHardDays');
    const hardN = hardEl ? (parseInt(hardEl.value, 10) || 0) : 0;
    const hardPlanned = Object.keys(pickHardSlotMap(plan, hardN)).length;
    flags.push('насыщенных: ' + hardN + (hardPlanned !== hardN ? (' → слотов ' + hardPlanned) : ''));
    const trekEl = document.getElementById('autoTrekDays');
    const trekN = trekEl ? (parseInt(trekEl.value, 10) || 0) : 0;
    if (trekN > 0) flags.push('трек: ' + trekN);
    const trSel = document.getElementById('autoTransport');
    if (trSel && trSel.value === 'plane') flags.push('самолёт');
    else if (trSel && trSel.value === 'bus') flags.push('автобус');
    else if (trSel && trSel.value === 'car') flags.push('автомобиль');
    document.getElementById('autoPlanPreview').textContent =
      'План: ' + n + ' дн. → ' + (parts.length ? parts.join(' + ') : '—')
      + (flags.length ? ' · ' + flags.join(', ') : '');
  }

  /** Порядок хабов = порядок первого упоминания в тексте (Киев…Львов → kyiv, lviv) */
  function orderHubsByQuery(hubSlugs, text) {
    const t = String(text || '');
    const list = (hubSlugs || []).slice();
    if (!t || list.length < 2) return list;
    const scored = list.map(function (slug) {
      const alias = HUB_ALIASES.find(function (a) { return a.slug === slug; });
      let idx = 1e9;
      if (alias) {
        const m = t.match(alias.re);
        if (m && typeof m.index === 'number') idx = m.index;
      }
      return { slug: slug, index: idx };
    });
    scored.sort(function (a, b) { return a.index - b.index; });
    return scored.map(function (x) { return x.slug; });
  }

  function applyParsedToForm(parsed) {
    const q = (document.getElementById('phpQuery').value || '').trim();
    const hubsOrdered = orderHubsByQuery(parsed.hubs || [], q);
    fillAutoHubChecks(hubsOrdered);
    if (parsed.days != null) syncDaysFields(parsed.days);
    document.getElementById('autoKids').value = parsed.kids || 0;
    document.getElementById('autoFamily').checked = !!parsed.family;
    const hardEl = document.getElementById('autoHardDays');
    const parsedHard = Math.min(6, Math.max(1, parseInt(parsed.hardDays, 10) || 1));
    const qMentionsHard = /\d+\s*насыщен|насыщенн/i.test(q);
    // Не затирать ручной выбор «2», если в запросе нет «N насыщенных»
    if (qMentionsHard || !hardEl) {
      lastHardDays = parsedHard;
      if (hardEl) hardEl.value = String(parsedHard);
    } else {
      lastHardDays = Math.min(6, Math.max(1, parseInt(hardEl.value, 10) || parsedHard));
    }
    const trekEl = document.getElementById('autoTrekDays');
    if (trekEl) {
      const td = Math.min(6, Math.max(0, parseInt(parsed.trekDays, 10) || 0));
      trekEl.value = String(td);
    }
    const tr = document.getElementById('autoTransport');
    if (tr) tr.value = parsed.transport || '';
    updateAutoPlanPreview();
  }

  function applyPickToSlot(day, dayObj, labelSlugs) {
    if (!dayObj) return false;
    const labels = (labelSlugs || []).filter(function (s) { return !!AUTO_LABEL_SLUGS[s]; }).slice(0, 5);
    try { setSlotLabels(day, labels); } catch (e) {}
    // Сначала запись в маршрут — UI кандидатов не должен ломать сборку
    if (!setSlotPick(day, dayObj)) return false;
    try { findDays(day, { silent: true }); } catch (e2) {}
    return true;
  }

  function runAutoAssemble(fromFormChange) {
    const hubsSel = selectedAutoHubs();
    if (!hubsSel.length) {
      showToast('Выберите хотя бы один регион');
      return;
    }
    const rawN = parseInt(document.getElementById('phpDays').value, 10);
    if (!rawN) {
      showToast('Укажите число дней');
      return;
    }
    if (!uaDays.length) {
      showToast('В каталоге нет UA золотых дней');
      return;
    }
    const n = Math.max(1, Math.min(14, rawN));
    syncDaysFields(n);
    setAssembleStatus('Сборка…', 'warn');

    const transportEl = document.getElementById('autoTransport');
    const hardEl = document.getElementById('autoHardDays');
    const trekEl = document.getElementById('autoTrekDays');
    let hardCount = parseInt(hardEl && hardEl.value, 10);
    if (!(hardCount >= 0 && hardCount <= 6)) {
      hardCount = lastHardDays || 1;
    }
    hardCount = Math.min(6, Math.max(0, hardCount));
    const trekCount = Math.min(6, Math.max(0, parseInt(trekEl && trekEl.value, 10) || 0));
    if (hardEl && hardCount >= 1) hardEl.value = String(hardCount);
    if (trekEl) trekEl.value = String(trekCount);
    if (hardCount >= 1) lastHardDays = hardCount;

    const hubPlan = distributeDays(n, hubsSel);
    const hardSlots = pickHardSlotMap(hubPlan, hardCount);
    const trekSlots = pickTrekSlotMap(hubPlan, trekCount);
    const prefs = {
      family: document.getElementById('autoFamily').checked
        || (parseInt(document.getElementById('autoKids').value, 10) || 0) > 0,
      hardSlots: hardSlots,
      trekSlots: trekSlots,
      transport: transportEl && transportEl.value ? transportEl.value : null,
    };

    renderSlots(n, false);

    for (let d = 1; d <= n; d++) {
      const sel = document.querySelector('.php-slot[data-day="' + d + '"] .php-hub');
      if (sel) sel.value = hubPlan[d - 1] || '';
    }

    const usedIds = {};
    const usedFp = {};
    const usedStops = {};
    const usedPlaces = {};
    let ok = 0;
    const miss = [];
    let prevHub = null;

    for (let d = 1; d <= n; d++) {
      const hub = hubPlan[d - 1];
      const nextHub = d < n ? hubPlan[d] : null;
      const picked = pickBestForSlot(hub, d, n, usedIds, usedFp, usedStops, usedPlaces, prefs, prevHub, nextHub);
      if (!picked) {
        miss.push(d);
        const leaving = nextHub && nextHub !== hub;
        const entering = !prevHub || prevHub !== hub;
        function softRank(a, b) {
          const ca = overlapStopIds(a, usedStops).length + overlapPlaceKeys(a, usedPlaces).length;
          const cb = overlapStopIds(b, usedStops).length + overlapPlaceKeys(b, usedPlaces).length;
          if (ca !== cb) return ca - cb;
          const fa = stopFingerprint(a);
          const fb = stopFingerprint(b);
          const ra = fa && usedFp[fa] ? 1 : 0;
          const rb = fb && usedFp[fb] ? 1 : 0;
          return ra - rb;
        }
        // 1) stay без ворот, overlap допускается (мин. конфликт); только дни реально у хаба
        let softPool = uaDays.filter(function (x) {
          return dayFitsHub(x, hub) && !usedIds[x.id]
            && !(prefs.family && hasLabel(x, 'no-kids'))
            && !(prefs.family && hasLabel(x, 'active-trek'))
            && (entering || leaving || d === n
              || (!hasLabel(x, 'arrival') && !hasLabel(x, 'departure') && !hasLabel(x, 'flight')));
        }).sort(softRank);
        // 2) любой неиспользованный день хаба (с гео) — чтобы не оставлять дыру
        if (!softPool.length) {
          softPool = uaDays.filter(function (x) {
            return dayFitsHub(x, hub) && !usedIds[x.id]
              && !(prefs.family && hasLabel(x, 'no-kids'));
          }).sort(softRank);
        }
        const soft = softPool[0] || null;
        if (soft) {
          let fb = [];
          if (entering && leaving) fb = ['flight'];
          else if (entering) fb = ['arrival'];
          else if (leaving || d === n) fb = ['departure'];
          else if (prefs.trekSlots && prefs.trekSlots[d]) fb = ['active-trek', 'hard'];
          else if (prefs.hardSlots && prefs.hardSlots[d]) fb = ['hard'];
          else fb = ['medium'];
          if (applyPickToSlot(d, soft, fb)) {
            usedIds[soft.id] = true;
            const fp = stopFingerprint(soft);
            if (fp) usedFp[fp] = true;
            registerStops(soft, usedStops);
            registerPlaces(soft, usedPlaces);
            ok++;
            miss.pop();
          }
        }
        prevHub = hub;
        continue;
      }
      const showLabels = (picked.roleLabels || []).filter(function (s) {
        return !!AUTO_LABEL_SLUGS[s];
      }).slice(0, 5);
      if (applyPickToSlot(d, picked.day, showLabels)) {
        usedIds[picked.day.id] = true;
        const fp = stopFingerprint(picked.day);
        if (fp) usedFp[fp] = true;
        registerStops(picked.day, usedStops);
        registerPlaces(picked.day, usedPlaces);
        ok++;
      } else {
        miss.push(d);
      }
      prevHub = hub;
    }

    updateAutoPlanPreview();
    renderSummary(n);
    try { refreshUaMap(); } catch (e) {}
    routeVariants[activeVariant].ready = true;
    const tabBtn = document.getElementById('phpVarTab' + activeVariant);
    if (tabBtn) tabBtn.disabled = false;
    const hardWanted = Object.keys(hardSlots).length;
    let hardGot = 0;
    Object.keys(hardSlots).forEach(function (slot) {
      const list = getPicks(parseInt(slot, 10));
      if (!list.length) return;
      const src = uaDaysById[list[0].id];
      if (src && hasLabel(src, 'hard')) hardGot++;
    });
    let msg = (fromFormChange ? 'Пересборка: ' : 'Собрано: ') + ok + ' / ' + n + ' (вариант ' + activeVariant + ')';
    if (hardWanted) msg += '. Насыщенных слотов: ' + hardWanted + ', hard-дней: ' + hardGot;
    if (miss.length) msg += '. Пусто: ' + miss.join(', ');
    showToast(msg);
    setAssembleStatus(msg, ok ? 'ok' : 'err');
  }

  let autoRebuildTimer = null;
  function scheduleAutoRebuild() {
    clearTimeout(autoRebuildTimer);
    autoRebuildTimer = setTimeout(function () {
      updateAutoPlanPreview();
      const hubsSel = selectedAutoHubs();
      const n = parseInt(document.getElementById('phpDays').value, 10);
      if (hubsSel.length && n) runAutoAssemble(true);
    }, 450);
  }

  async function parseAndFill(thenAssemble) {
    const q = (document.getElementById('phpQuery').value || '').trim();
    if (!q) {
      showToast('Введите запрос клиента');
      return;
    }
    const btn = thenAssemble ? document.getElementById('phpAutoAll') : document.getElementById('autoParseBtn');
    const prev = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Gemini…';
    }
    try {
      let parsed;
      let fromLocal = false;
      try {
        const geminiPromise = parseClientQueryGemini(q);
        const timeoutPromise = new Promise(function (_, reject) {
          setTimeout(function () { reject(new Error('timeout')); }, 12000);
        });
        parsed = await Promise.race([geminiPromise, timeoutPromise]);
      } catch (err) {
        parsed = parseClientQueryLocal(q);
        fromLocal = true;
        if (err && err.message !== 'timeout') {
          showToast('Gemini недоступен, локальный разбор');
        }
      }
      if (!parsed || typeof parsed !== 'object') {
        parsed = parseClientQueryLocal(q);
        fromLocal = true;
      }
      // не затирать регионы пустым ответом
      if (!Array.isArray(parsed.hubs) || !parsed.hubs.length) {
        parsed.hubs = selectedAutoHubs();
      }
      applyParsedToForm(parsed);
      if (thenAssemble) {
        if (!selectedAutoHubs().length) {
          showToast('Отметьте хотя бы один регион');
          return;
        }
        if (!parseInt(document.getElementById('phpDays').value, 10)) {
          showToast('Укажите число дней');
          return;
        }
        runAutoAssemble(false);
      } else {
        showToast(fromLocal ? 'Запрос разобран (локально)' : 'Запрос разобран');
      }
    } catch (err) {
      showToast('Ошибка: ' + (err && err.message ? err.message : String(err)));
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = prev;
      }
    }
  }

  document.getElementById('autoParseBtn').addEventListener('click', function () {
    parseAndFill(false);
  });

  document.getElementById('phpAutoAll').addEventListener('click', function () {
    try {
      const hubsSel = selectedAutoHubs();
      const n = parseInt(document.getElementById('phpDays').value, 10);
      const q = (document.getElementById('phpQuery').value || '').trim();
      // Регионы + дни уже есть → сразу сборка (без ожидания Gemini)
      if (hubsSel.length && n) {
        runAutoAssemble(false);
        return;
      }
      if (!q) {
        showToast('Введите запрос или отметьте регионы и число дней');
        return;
      }
      parseAndFill(true);
    } catch (err) {
      showToast('Ошибка сборки: ' + (err && err.message ? err.message : String(err)));
      try { console.error(err); } catch (e) {}
    }
  });

  document.getElementById('autoRunBtn').addEventListener('click', function () {
    try {
      if (!selectedAutoHubs().length) {
        showToast('Отметьте хотя бы один регион');
        return;
      }
      if (!parseInt(document.getElementById('phpDays').value, 10)) {
        showToast('Укажите число дней');
        return;
      }
      runAutoAssemble(false);
    } catch (err) {
      showToast('Ошибка сборки: ' + (err && err.message ? err.message : String(err)));
      try { console.error(err); } catch (e) {}
    }
  });

  document.getElementById('phpDays').addEventListener('change', function () {
    syncDaysFields(this.value);
    scheduleAutoRebuild();
  });
  document.getElementById('phpDays').addEventListener('input', updateAutoPlanPreview);

  ['autoKids', 'autoFamily', 'autoHardDays', 'autoTrekDays', 'autoTransport'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', function () {
      if (id === 'autoKids') {
        const k = parseInt(el.value, 10) || 0;
        if (k > 0) document.getElementById('autoFamily').checked = true;
      }
      scheduleAutoRebuild();
    });
    if (el.type === 'number') {
      el.addEventListener('input', updateAutoPlanPreview);
    }
  });
  document.getElementById('autoHubChecks').addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('auto-hub')) {
      syncAutoHubOrderFromChecks();
      scheduleAutoRebuild();
    }
  });

  fillAutoHubChecks([]);
  updateAutoPlanPreview();

  document.getElementById('phpSlots').addEventListener('click', function (e) {
    const findBtn = e.target.closest('.php-find');
    if (findBtn) {
      findDays(parseInt(findBtn.dataset.day, 10));
      return;
    }
    const addBtn = e.target.closest('.php-add-pick');
    if (addBtn) {
      e.preventDefault();
      e.stopPropagation();
      const row = addBtn.closest('.php-result-row');
      addToSelection(parseInt(addBtn.dataset.day || (row && row.dataset.day), 10), {
        id: addBtn.dataset.id || (row && row.dataset.id),
        title: addBtn.getAttribute('data-title') || (row && row.querySelector('.fw-semibold') ? row.querySelector('.fw-semibold').textContent.trim() : ''),
        stops: addBtn.dataset.stops || '',
      }, row);
      return;
    }
    const delBtn = e.target.closest('.php-del-pick');
    if (delBtn) {
      e.preventDefault();
      removeFromList(
        parseInt(delBtn.dataset.day, 10),
        parseInt(delBtn.dataset.id, 10),
        delBtn.closest('.php-result-row')
      );
    }
  });

  document.getElementById('phpSummaryBody').addEventListener('click', function (e) {
    const delBtn = e.target.closest('.php-summary-del');
    if (delBtn) {
      const day = parseInt(delBtn.dataset.day, 10);
      const id = parseInt(delBtn.dataset.id, 10);
      if (!removeFromSelection(day, id)) {
        showToast('Слот ' + day + ': нет #' + id + ' в маршруте');
      }
    }
  });

  function collectRouteDays(n) {
    const days = [];
    const missing = [];
    for (let d = 1; d <= n; d++) {
      const list = getPicks(d);
      if (!list.length) {
        missing.push(d);
        continue;
      }
      const hubSel = document.querySelector('.php-slot[data-day="' + d + '"] .php-hub');
      const pick = list[0];
      const src = uaDaysById[pick.id] || {};
      days.push({
        day: d,
        hub: hubSel ? hubSel.value : null,
        golden_day_id: parseInt(pick.id, 10),
        title: pick.title || src.title || ('#' + pick.id),
        labels: Array.isArray(src.labels) ? src.labels : [],
      });
    }
    return { days: days, missing: missing };
  }

  /** Середина нового варианта: въезд/выезд как в base, остальные дни — другие id */
  function assembleMiddleForActive(n, basePicks) {
    const hubsSel = selectedAutoHubs();
    if (!hubsSel.length) {
      showToast('Выберите хотя бы один регион');
      return 0;
    }
    const transportEl = document.getElementById('autoTransport');
    const hardEl = document.getElementById('autoHardDays');
    const trekEl = document.getElementById('autoTrekDays');
    const hardCount = Math.min(6, Math.max(1, parseInt(hardEl && hardEl.value, 10) || lastHardDays || 1));
    const trekCount = Math.min(6, Math.max(0, parseInt(trekEl && trekEl.value, 10) || 0));
    const hubPlan = [];
    for (let d = 1; d <= n; d++) {
      const sel = document.querySelector('.php-slot[data-day="' + d + '"] .php-hub');
      hubPlan.push(sel && sel.value ? sel.value : (hubsSel[(d - 1) % hubsSel.length] || hubsSel[0]));
    }
    const prefs = {
      family: document.getElementById('autoFamily').checked
        || (parseInt(document.getElementById('autoKids').value, 10) || 0) > 0,
      hardSlots: pickHardSlotMap(hubPlan, hardCount),
      trekSlots: pickTrekSlotMap(hubPlan, trekCount),
      transport: transportEl && transportEl.value ? transportEl.value : null,
    };

    const usedIds = {};
    const usedFp = {};
    const usedStops = {};
    const usedPlaces = {};
    // Запрет повторов дней из базового варианта (кроме въезда/выезда, которые копируем)
    Object.keys(basePicks).forEach(function (key) {
      const d = parseInt(key, 10);
      (basePicks[key] || []).forEach(function (p) {
        const id = parseInt(p.id, 10);
        if (!id) return;
        if (d === 1 || d === n) return;
        usedIds[id] = true;
        const obj = uaDaysById[id];
        if (obj) {
          const fp = stopFingerprint(obj);
          if (fp) usedFp[fp] = true;
          registerStops(obj, usedStops);
          registerPlaces(obj, usedPlaces);
        }
      });
    });
    // уже стоят края в active picks
    [1, n].forEach(function (d) {
      getPicks(d).forEach(function (p) {
        const id = parseInt(p.id, 10);
        if (!id) return;
        usedIds[id] = true;
        const obj = uaDaysById[id];
        if (obj) {
          const fp = stopFingerprint(obj);
          if (fp) usedFp[fp] = true;
          registerStops(obj, usedStops);
          registerPlaces(obj, usedPlaces);
        }
      });
    });

    let ok = 0;
    let prevHub = hubPlan[0] || null;
    for (let d = 2; d <= n - 1; d++) {
      const hub = hubPlan[d - 1];
      const nextHub = d < n ? hubPlan[d] : null;
      const picked = pickBestForSlot(hub, d, n, usedIds, usedFp, usedStops, usedPlaces, prefs, prevHub, nextHub);
      const dayObj = picked && picked.day ? picked.day : null;
      if (!dayObj) {
        prevHub = hub;
        continue;
      }
      const showLabels = ((picked && picked.roleLabels) || []).filter(function (s) {
        return !!AUTO_LABEL_SLUGS[s];
      }).slice(0, 5);
      if (!applyPickToSlot(d, dayObj, showLabels)) {
        prevHub = hub;
        continue;
      }
      usedIds[dayObj.id] = true;
      const fp = stopFingerprint(dayObj);
      if (fp) usedFp[fp] = true;
      registerStops(dayObj, usedStops);
      registerPlaces(dayObj, usedPlaces);
      ok++;
      prevHub = hub;
    }
    return ok;
  }

  function makeNextVariant() {
    const n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    if (n < 3) {
      showToast('Нужно минимум 3 дня (въезд + середина + выезд)');
      return;
    }
    if (variantFilledCount(1) < n) {
      showToast('Сначала полностью соберите вариант 1');
      setActiveVariant(1);
      return;
    }
    let next = null;
    for (let v = 2; v <= 3; v++) {
      if (!routeVariants[v].ready) {
        next = v;
        break;
      }
    }
    if (!next) {
      next = activeVariant === 1 ? 2 : (activeVariant === 2 ? 3 : 2);
    }

    const basePicks = clonePicks(routeVariants[1].picks);
    clearPicksObject(routeVariants[next].picks);
    routeVariants[next].picks[1] = (basePicks[1] || []).map(function (p) {
      return { id: p.id, title: p.title, stops: p.stops };
    });
    routeVariants[next].picks[n] = (basePicks[n] || []).map(function (p) {
      return { id: p.id, title: p.title, stops: p.stops };
    });
    routeVariants[next].ready = true;

    const tab = document.getElementById('phpVarTab' + next);
    if (tab) tab.disabled = false;

    setActiveVariant(next, { skipSync: true });
    const midOk = assembleMiddleForActive(n, basePicks);
    syncUiFromActivePicks();
    showToast('Вариант ' + next + ': въезд/выезд как в 1, середина новая (' + midOk + ' дн.)');
  }

  function collectRoutePrefs() {
    return {
      kids: parseInt(document.getElementById('autoKids')?.value || '0', 10) || 0,
      family: !!document.getElementById('autoFamily')?.checked,
      hard_days: parseInt(document.getElementById('autoHardDays')?.value || '0', 10) || 0,
      trek_days: parseInt(document.getElementById('autoTrekDays')?.value || '0', 10) || 0,
      transport: document.getElementById('autoTransport')?.value || '',
      hubs: selectedAutoHubs(),
    };
  }

  document.getElementById('phpAssemble').addEventListener('click', function () {
    const n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    const packed = collectRouteDays(n);
    if (packed.missing.length) {
      showToast('Пустые слоты: ' + packed.missing.join(', '));
      return;
    }
    showToast('Маршрут собран: ' + n + ' слотов. Можно «Сохранить маршрут».');
  });

  document.getElementById('phpSaveRoute')?.addEventListener('click', async function () {
    const n = parseInt(document.getElementById('phpDays').value, 10) || 0;
    if (!n) {
      showToast('Укажите число дней');
      return;
    }
    const packed = collectRouteDays(n);
    if (packed.missing.length) {
      showToast('Сначала заполните слоты: ' + packed.missing.join(', '));
      return;
    }
    const q = (document.getElementById('phpQuery')?.value || '').trim();
    const suggested = (q ? ('Маршрут: ' + q.slice(0, 50)) : ('Маршрут ' + n + ' дн.'))
      + ' · вариант ' + activeVariant;
    const title = window.prompt('Название маршрута (вариант ' + activeVariant + ')', suggested);
    if (title === null) return;
    const name = String(title).trim();
    if (!name) {
      showToast('Нужно название');
      return;
    }
    const btn = document.getElementById('phpSaveRoute');
    const prev = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Сохраняю…';
    try {
      const res = await fetch(saveRouteUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          title: name,
          query_text: q || null,
          country_code: 'UA',
          prefs: Object.assign({}, collectRoutePrefs(), { variant: activeVariant }),
          days: packed.days,
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) {
        throw new Error(data.message || (data.errors ? JSON.stringify(data.errors) : ('Ошибка ' + res.status)));
      }
      showToast('Сохранено: «' + (data.title || name) + '» (вариант ' + activeVariant + ')');
      if (data.url && window.confirm('Открыть сохранённый маршрут?')) {
        window.location.href = data.url;
      }
    } catch (err) {
      showToast(err.message || String(err));
    } finally {
      btn.disabled = false;
      btn.innerHTML = prev;
    }
  });

  document.getElementById('phpVariantTabs')?.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-variant]');
    if (!btn || btn.disabled) return;
    const v = parseInt(btn.getAttribute('data-variant'), 10);
    if (v === activeVariant) return;
    if (!routeVariants[v].ready) {
      showToast('Сначала нажмите «Сделать следующий вариант»');
      return;
    }
    setActiveVariant(v);
    showToast('Активен вариант ' + v);
  });

  document.getElementById('phpMakeVariant')?.addEventListener('click', function () {
    makeNextVariant();
  });

  document.getElementById('phpToGemini').addEventListener('click', function () {
    showToast('Демо: черновик UA отправили бы в Gemini только на оформление');
  });

  setActiveVariant(1, { skipSync: true });
  ensureUaMap();
})();
</script>
@endsection
