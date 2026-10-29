@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Украина · собрать день · поиск</h4>
    <p class="text-muted small mb-0">Только UA-локации. Текст области → фильтр по имени/региону → галочки → в слот.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="{{ route('admin.test.php') }}" class="btn btn-sm btn-outline-secondary">← Слоты UA</a>
    <a href="{{ route('admin.test.php.map') }}?day={{ (int) request('day', 1) }}" class="btn btn-sm btn-outline-primary">Карта</a>
    <span class="badge bg-label-info">слот дня {{ (int) request('day', 1) }}</span>
    <span class="badge bg-label-primary">UA sample: {{ count($sampleLocations ?? []) }}</span>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-lg-8">
        <label class="form-label" for="gemArea">Область / запрос (Украина)</label>
        <input type="text" id="gemArea" class="form-control"
          placeholder="например: Львов, Карпаты, семья"
          value="Львов, прогулка">
      </div>
      <div class="col-lg-4">
        <button type="button" class="btn btn-primary w-100" id="gemSearch">
          <i class="bx bx-search me-1"></i> Найти локации UA
        </button>
      </div>
    </div>
    <div class="form-text">Демо без API: фильтр по названиям/регионам из выборки Украины (вес сверху).</div>
  </div>
</div>

<div class="card">
  <div class="card-header py-2 d-flex justify-content-between">
    <span class="fw-semibold small">Результаты UA</span>
    <span class="small text-muted" id="gemCount">0</span>
  </div>
  <div class="card-body" id="gemList">
    <p class="text-muted small mb-0">Введите область (Киев, Львов, Карпаты, Одесса…) и нажмите «Найти».</p>
  </div>
  <div class="card-footer d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-primary btn-sm" id="gemSave">Сохранить в день {{ (int) request('day', 1) }}</button>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="gemSelect5">Отметить первые 5</button>
    <a href="{{ route('admin.test.php') }}" class="btn btn-outline-secondary btn-sm">К слотам</a>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const day = {{ (int) request('day', 1) }};
  const ALL = @json($sampleLocations ?? []);

  function notify(msg) {
    alert(msg);
  }

  function filterUa(q) {
    q = (q || '').toLowerCase().trim();
    if (!q) return ALL.slice(0, 15);
    const tokens = q.split(/[\s,;]+/).filter(Boolean);
    let list = ALL.filter(function (p) {
      const hay = ((p.name || '') + ' ' + (p.region || '')).toLowerCase();
      return tokens.some(function (t) { return hay.indexOf(t) !== -1; });
    });
    if (!list.length) list = ALL.slice();
    return list
      .sort(function (a, b) { return (b.weight || 0) - (a.weight || 0); })
      .slice(0, 15);
  }

  document.getElementById('gemSearch').addEventListener('click', function () {
    const q = document.getElementById('gemArea').value.trim();
    if (!q) {
      notify('Введите область');
      return;
    }
    const list = filterUa(q);
    document.getElementById('gemCount').textContent = list.length + ' шт.';
    document.getElementById('gemList').innerHTML = list.map(function (p, i) {
      return '<div class="form-check mb-2">'
        + '<input class="form-check-input gem-poi" type="checkbox" value="' + p.id + '" id="g' + p.id + '"' + (i < 3 ? ' checked' : '') + '>'
        + '<label class="form-check-label" for="g' + p.id + '"><strong>#' + p.id + '</strong> ' + p.name
        + ' <span class="text-muted small">· ' + (p.region || 'UA') + ' · w' + (p.weight || 0) + '</span></label></div>';
    }).join('');
  });

  document.getElementById('gemSelect5').addEventListener('click', function () {
    document.querySelectorAll('.gem-poi').forEach(function (el, i) {
      el.checked = i < 5;
    });
  });

  document.getElementById('gemSave').addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.gem-poi:checked')).map(function (el) { return el.value; });
    if (!ids.length) {
      notify('Отметьте локации');
      return;
    }
    notify('Демо UA: день ' + day + ' ← ' + ids.join(', ') + '\n(в БД пока не пишем)');
  });
})();
</script>
@endsection
