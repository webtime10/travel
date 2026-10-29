@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Украина · собрать день · карта</h4>
    <p class="text-muted small mb-0">Только UA-локации. Клик → радиус → выбрать точки → сохранить в слот.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="{{ route('admin.test.php') }}" class="btn btn-sm btn-outline-secondary">← Слоты UA</a>
    <a href="{{ route('admin.test.php.gemini') }}?day={{ (int) request('day', 1) }}" class="btn btn-sm btn-outline-primary">Поиск</a>
    <span class="badge bg-label-info">слот дня {{ (int) request('day', 1) }}</span>
    <span class="badge bg-label-primary">UA POI: {{ count($pois ?? []) }}</span>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span class="small fw-semibold">Карта Украины</span>
        <span class="small text-muted" id="mapCoords">кликните точку</span>
      </div>
      <div class="card-body p-0">
        <div id="dayMap" style="height:420px;width:100%"></div>
      </div>
      <div class="card-footer">
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label small mb-1">Радиус км</label>
            <input type="number" id="mapRadius" class="form-control form-control-sm" value="35" min="5" max="120">
          </div>
          <div class="col-md-8">
            <button type="button" class="btn btn-sm btn-primary" id="mapSearch">Найти локации в радиусе</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="mapClear">Сбросить</button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold small">Локации рядом <span class="text-muted" id="mapCount"></span></div>
      <div class="card-body" id="mapList">
        <p class="text-muted small mb-0">Кликните карту (Киев / Львов / Карпаты / Одесса), затем «Найти».</p>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary btn-sm" id="mapSave">Сохранить в день {{ (int) request('day', 1) }}</button>
        <a href="{{ route('admin.test.php') }}" class="btn btn-outline-secondary btn-sm">К слотам</a>
      </div>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
  const day = {{ (int) request('day', 1) }};
  const POIS = @json($pois ?? []);

  const map = L.map('dayMap').setView([49.0, 31.5], 6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  let clickMarker = null;
  let circle = null;
  let clickLatLng = null;
  const toastEl = document.createElement('div');
  toastEl.className = 'position-fixed bottom-0 end-0 p-3';
  toastEl.style.zIndex = 2000;
  toastEl.innerHTML = '<div class="toast align-items-center text-bg-dark border-0" id="mapToast"><div class="d-flex"><div class="toast-body" id="mapToastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
  document.body.appendChild(toastEl);
  let bsToast;

  function notify(msg) {
    document.getElementById('mapToastBody').textContent = msg;
    if (!bsToast) bsToast = new bootstrap.Toast(document.getElementById('mapToast'), { delay: 2500 });
    bsToast.show();
  }

  function haversine(a, b) {
    const R = 6371;
    const toRad = function (d) { return d * Math.PI / 180; };
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(x));
  }

  map.on('click', function (e) {
    clickLatLng = e.latlng;
    document.getElementById('mapCoords').textContent = e.latlng.lat.toFixed(4) + ', ' + e.latlng.lng.toFixed(4);
    if (clickMarker) map.removeLayer(clickMarker);
    clickMarker = L.marker(e.latlng).addTo(map);
    const r = parseFloat(document.getElementById('mapRadius').value) || 35;
    if (circle) map.removeLayer(circle);
    circle = L.circle(e.latlng, { radius: r * 1000, color: '#696cff', fillOpacity: 0.08 }).addTo(map);
  });

  document.getElementById('mapSearch').addEventListener('click', function () {
    if (!clickLatLng) {
      notify('Сначала кликните карту');
      return;
    }
    const r = parseFloat(document.getElementById('mapRadius').value) || 35;
    if (circle) map.removeLayer(circle);
    circle = L.circle(clickLatLng, { radius: r * 1000, color: '#696cff', fillOpacity: 0.08 }).addTo(map);

    const found = POIS
      .map(function (p) {
        return Object.assign({}, p, { km: haversine(clickLatLng, p) });
      })
      .filter(function (p) { return p.km <= r; })
      .sort(function (a, b) {
        if (a.km !== b.km) return a.km - b.km;
        return (b.weight || 0) - (a.weight || 0);
      });

    const box = document.getElementById('mapList');
    document.getElementById('mapCount').textContent = '(' + found.length + ')';
    if (!found.length) {
      box.innerHTML = '<p class="text-muted small mb-0">В радиусе пусто — кликните ближе к Киеву / Львову / Карпатам / Одессе.</p>';
      notify('0 локаций UA');
      return;
    }
    box.innerHTML = found.slice(0, 40).map(function (p) {
      return '<div class="form-check mb-2">'
        + '<input class="form-check-input map-poi" type="checkbox" value="' + p.id + '" id="poi' + p.id + '">'
        + '<label class="form-check-label" for="poi' + p.id + '">'
        + '<strong>#' + p.id + '</strong> ' + p.name
        + ' <span class="text-muted small">· ' + (p.kind || '') + ' · w' + (p.weight || 0) + ' · ' + p.km.toFixed(1) + ' км</span>'
        + '</label></div>';
    }).join('');
    notify('Найдено UA: ' + found.length);
  });

  document.getElementById('mapClear').addEventListener('click', function () {
    document.getElementById('mapList').innerHTML = '<p class="text-muted small mb-0">Кликните карту Украины…</p>';
    document.getElementById('mapCount').textContent = '';
    document.getElementById('mapCoords').textContent = 'кликните точку';
    if (clickMarker) { map.removeLayer(clickMarker); clickMarker = null; }
    if (circle) { map.removeLayer(circle); circle = null; }
    clickLatLng = null;
  });

  document.getElementById('mapSave').addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.map-poi:checked')).map(function (el) { return el.value; });
    if (!ids.length) {
      notify('Отметьте хотя бы одну локацию');
      return;
    }
    notify('Демо UA: день ' + day + ' ← локации ' + ids.join(', ') + ' (не пишем в БД)');
  });
})();
</script>
@endsection
