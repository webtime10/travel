@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Собрать день · карта</h4>
    <p class="text-muted small mb-0">Клик по карте → радиус → список локаций рядом → выбрать → сохранить в слот. Демо.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="{{ route('admin.test.php') }}" class="btn btn-sm btn-outline-secondary">← PHP слоты</a>
    <a href="{{ route('admin.test.php.gemini') }}?day={{ (int) request('day', 1) }}" class="btn btn-sm btn-outline-primary">Gemini-поиск</a>
    <span class="badge bg-label-info">слот дня {{ (int) request('day', 1) }}</span>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span class="small fw-semibold">Карта (Северная Италия)</span>
        <span class="small text-muted" id="mapCoords">кликните точку</span>
      </div>
      <div class="card-body p-0">
        <div id="dayMap" style="height:420px;width:100%"></div>
      </div>
      <div class="card-footer">
        <div class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label small mb-1">Радиус км</label>
            <input type="number" id="mapRadius" class="form-control form-control-sm" value="28" min="5" max="80">
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
        <p class="text-muted small mb-0">Кликните карту (Гарда / Доломиты), затем «Найти».</p>
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
  const POIS = [
    { id: 50, name: 'Рива-дель-Гарда', lat: 45.885, lng: 10.845, kind: 'база' },
    { id: 25, name: 'Strada del Ponale', lat: 45.875, lng: 10.855, kind: 'тропа' },
    { id: 65, name: 'Тенно', lat: 45.92, lng: 10.83, kind: 'озеро' },
    { id: 199, name: 'Varone', lat: 45.86, lng: 10.86, kind: 'водопад' },
    { id: 27, name: 'Ledro', lat: 45.88, lng: 10.75, kind: 'озеро' },
    { id: 32, name: 'Limone', lat: 45.81, lng: 10.79, kind: 'набережная' },
    { id: 158, name: 'Ортизей', lat: 46.576, lng: 11.672, kind: 'база' },
    { id: 362, name: 'Bulla / Mont Seuc', lat: 46.56, lng: 11.68, kind: 'хайк' },
    { id: 187, name: 'Marmolada day', lat: 46.43, lng: 11.86, kind: 'день' },
    { id: 123, name: 'Brunico', lat: 46.796, lng: 11.938, kind: 'база' },
    { id: 116, name: 'Braies', lat: 46.694, lng: 12.085, kind: 'озеро' },
    { id: 117, name: 'Misurina', lat: 46.584, lng: 12.254, kind: 'база' },
  ];

  const map = L.map('dayMap').setView([46.2, 11.2], 8);
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
    const r = parseFloat(document.getElementById('mapRadius').value) || 28;
    if (circle) map.removeLayer(circle);
    circle = L.circle(e.latlng, { radius: r * 1000, color: '#696cff', fillOpacity: 0.08 }).addTo(map);
  });

  document.getElementById('mapSearch').addEventListener('click', function () {
    if (!clickLatLng) {
      notify('Сначала кликните карту');
      return;
    }
    const r = parseFloat(document.getElementById('mapRadius').value) || 28;
    if (circle) map.removeLayer(circle);
    circle = L.circle(clickLatLng, { radius: r * 1000, color: '#696cff', fillOpacity: 0.08 }).addTo(map);

    const found = POIS
      .map(function (p) {
        return Object.assign({}, p, { km: haversine(clickLatLng, p) });
      })
      .filter(function (p) { return p.km <= r; })
      .sort(function (a, b) { return a.km - b.km; });

    const box = document.getElementById('mapList');
    document.getElementById('mapCount').textContent = '(' + found.length + ')';
    if (!found.length) {
      box.innerHTML = '<p class="text-muted small mb-0">В радиусе пусто — кликните ближе к Гарде/Доломитам.</p>';
      notify('0 локаций');
      return;
    }
    box.innerHTML = found.map(function (p) {
      return '<div class="form-check mb-2">'
        + '<input class="form-check-input map-poi" type="checkbox" value="' + p.id + '" id="poi' + p.id + '">'
        + '<label class="form-check-label" for="poi' + p.id + '">'
        + '<strong>#' + p.id + '</strong> ' + p.name
        + ' <span class="text-muted small">· ' + p.kind + ' · ' + p.km.toFixed(1) + ' км</span>'
        + '</label></div>';
    }).join('');
    notify('Найдено ' + found.length);
  });

  document.getElementById('mapClear').addEventListener('click', function () {
    document.getElementById('mapList').innerHTML = '<p class="text-muted small mb-0">Кликните карту…</p>';
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
    notify('Демо: день ' + day + ' ← локации ' + ids.join(', ') + ' (не пишем в БД)');
  });
})();
</script>
@endsection
