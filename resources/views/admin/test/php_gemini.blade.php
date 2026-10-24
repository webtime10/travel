@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Собрать день · Gemini / вектор</h4>
    <p class="text-muted small mb-0">Строка области → ~15 локаций (демо). Потом галочки и «Сохранить в день».</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="{{ route('admin.test.php') }}" class="btn btn-sm btn-outline-secondary">← PHP слоты</a>
    <a href="{{ route('admin.test.php.map') }}?day={{ (int) request('day', 1) }}" class="btn btn-sm btn-outline-primary">Карта</a>
    <span class="badge bg-label-info">слот дня {{ (int) request('day', 1) }}</span>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-lg-8">
        <label class="form-label" for="gemArea">Область / запрос</label>
        <input type="text" id="gemArea" class="form-control"
          placeholder="например: Рива Гарда, развлечения, переезд"
          value="Рива Гарда, развлечения">
      </div>
      <div class="col-lg-4">
        <button type="button" class="btn btn-primary w-100" id="gemSearch">
          <i class="bx bx-search me-1"></i> Найти локации
        </button>
      </div>
    </div>
    <div class="form-text">Демо: не бьёт API. Подставляет типичный набор под «Гарда» / «Ортизей» / «Брунико».</div>
  </div>
</div>

<div class="card">
  <div class="card-header py-2 d-flex justify-content-between">
    <span class="fw-semibold small">Результаты</span>
    <span class="small text-muted" id="gemCount">0</span>
  </div>
  <div class="card-body" id="gemList">
    <p class="text-muted small mb-0">Введите область и нажмите «Найти».</p>
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
  const PACKS = {
    garda: [
      { id: 50, name: 'Рива-дель-Гарда', tip: 'база / набережная' },
      { id: 25, name: 'Strada del Ponale', tip: 'тропа' },
      { id: 65, name: 'Тенно', tip: 'озеро' },
      { id: 199, name: 'Cascate di Varone', tip: 'водопад' },
      { id: 27, name: 'Ledro', tip: 'озеро' },
      { id: 32, name: 'Limone sul Garda', tip: 'городок' },
      { id: 394, name: 'Terrazza del Brivido', tip: 'смотровая' },
      { id: 395, name: 'Катер Malcesine–Limone', tip: 'лодка' },
      { id: 369, name: 'Busatte–Tempesta', tip: 'тропа' },
      { id: 71, name: 'Molveno (рядом)', tip: 'озеро' },
      { id: 80, name: 'Torbole', tip: 'виндсёрф' },
      { id: 81, name: 'Arco', tip: 'замок' },
      { id: 82, name: 'Garda town', tip: 'юг озера' },
      { id: 83, name: 'Malcesine centro', tip: 'центр' },
      { id: 84, name: 'Monte Baldo cable', tip: 'канатка' },
    ],
    ortisei: [
      { id: 158, name: 'Ортизей', tip: 'база' },
      { id: 362, name: 'Bulla hiking', tip: 'хайк' },
      { id: 156, name: 'Zipline Monte Pana', tip: 'активность' },
      { id: 329, name: 'Selva trail', tip: 'хайк' },
      { id: 187, name: 'Marmolada day', tip: 'full day' },
      { id: 593, name: 'Carezza / Tires', tip: 'канатка' },
      { id: 200, name: 'Seceda ridge', tip: 'панорама' },
      { id: 201, name: 'Resciesa', tip: 'прогулка' },
      { id: 202, name: 'Alpe di Siusi', tip: 'плато' },
      { id: 203, name: 'Chiesa', tip: 'деревня' },
      { id: 204, name: 'Santa Maddalena', tip: 'вид' },
      { id: 205, name: 'Col Raiser', tip: 'канатка' },
      { id: 206, name: 'Ortisei center walk', tip: 'город' },
      { id: 207, name: 'St. Ulrich museum', tip: 'музей' },
      { id: 208, name: 'Val Gardena train', tip: 'поезд' },
    ],
    brunico: [
      { id: 123, name: 'Brunico', tip: 'база' },
      { id: 116, name: 'Braies', tip: 'озеро' },
      { id: 168, name: 'Cascate Di Riva', tip: 'водопад' },
      { id: 210, name: 'Kronplatz', tip: 'панорама' },
      { id: 211, name: 'Plan de Corones', tip: 'вершина' },
      { id: 212, name: 'Castel Tures', tip: 'замок' },
      { id: 213, name: 'Dobbiaco', tip: 'переезд' },
      { id: 214, name: 'Antholz lake', tip: 'озеро' },
      { id: 215, name: 'San Candido', tip: 'городок' },
      { id: 216, name: 'Giro delle Malghe', tip: 'хайк' },
      { id: 217, name: 'Brunico castle', tip: 'замок' },
      { id: 218, name: 'Tauferer Ahrntal', tip: 'долина' },
      { id: 219, name: 'Speikboden', tip: 'канатка' },
      { id: 220, name: 'Rein in Taufers', tip: 'водопад' },
      { id: 221, name: 'Shopping Brunico', tip: 'город' },
    ],
  };

  function pickPack(q) {
    q = (q || '').toLowerCase();
    if (q.includes('ортиз') || q.includes('ortisei') || q.includes('gardena') || q.includes('доломит')) return PACKS.ortisei;
    if (q.includes('бруник') || q.includes('brunico') || q.includes('braies') || q.includes('браес')) return PACKS.brunico;
    return PACKS.garda;
  }

  function notify(msg) {
    alert(msg);
  }

  document.getElementById('gemSearch').addEventListener('click', function () {
    const q = document.getElementById('gemArea').value.trim();
    if (!q) {
      notify('Введите область');
      return;
    }
    const list = pickPack(q);
    document.getElementById('gemCount').textContent = list.length + ' шт.';
    document.getElementById('gemList').innerHTML = list.map(function (p, i) {
      return '<div class="form-check mb-2">'
        + '<input class="form-check-input gem-poi" type="checkbox" value="' + p.id + '" id="g' + p.id + '"' + (i < 3 ? ' checked' : '') + '>'
        + '<label class="form-check-label" for="g' + p.id + '"><strong>#' + p.id + '</strong> ' + p.name
        + ' <span class="text-muted small">· ' + p.tip + '</span></label></div>';
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
    notify('Демо: день ' + day + ' ← ' + ids.join(', ') + '\n(в БД пока не пишем)');
  });
})();
</script>
@endsection
