@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Новый golden day</h4>
  <a href="{{ route('admin.golden-days.index', ['country' => 'UA']) }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
</div>

@include('admin.partials.errors')

<form method="post" action="{{ route('admin.golden-days.store') }}" id="goldenDayCreateForm">
  @csrf
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Название (RU) <span class="text-danger">*</span></label>
          <input type="text" name="title_ru" class="form-control" required value="{{ old('title_ru') }}" dir="auto">
        </div>
        <div class="col-md-6">
          <label class="form-label">Название (HE)</label>
          <input type="text" name="title" class="form-control" value="{{ old('title') }}" dir="auto">
        </div>
        <div class="col-md-4">
          <label class="form-label">Регион</label>
          <select name="category_id" class="form-select">
            <option value="">—</option>
            @foreach(($regionOptions ?? []) as $reg)
              <option value="{{ $reg->id }}" @selected((int) old('category_id') === (int) $reg->id)>{{ $reg->nameFor($defaultLanguage ?? null) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Hub</label>
          <select name="hub_key" class="form-select">
            <option value="">—</option>
            @foreach(($hubOptions ?? []) as $hub)
              <option value="{{ $hub['key'] }}" @selected(old('hub_key') === $hub['key'])>{{ $hub['label'] }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Роль</label>
          <select name="kind" class="form-select">
            @foreach(['stay' => 'Stay', 'arrival' => 'Въезд', 'departure' => 'Выезд', 'nature' => 'Nature', 'family' => 'Family', 'transfer' => 'Transfer', 'kosher' => 'Kosher'] as $k => $lab)
              <option value="{{ $k }}" @selected(old('kind', 'stay') === $k)>{{ $lab }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Нагрузка</label>
          <select name="load" class="form-select">
            <option value="">—</option>
            @foreach(['soft' => 'Лёгкий', 'medium' => 'Средний', 'hard' => 'Насыщенный'] as $k => $lab)
              <option value="{{ $k }}" @selected(old('load') === $k)>{{ $lab }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Стопы — поиск локаций</div>
    <div class="card-body">
      <div class="mb-3" style="max-width:28rem">
        <label class="form-label">Найти локацию</label>
        <input type="text" id="createStopSearch" class="form-control" placeholder="От 2 букв — название" autocomplete="off">
        <div id="createStopResults" class="list-group mt-1 shadow-sm" style="max-height:220px;overflow:auto"></div>
      </div>
      <div id="createStopPicks" class="d-flex flex-column gap-2"></div>
      <p class="small text-muted mb-0 mt-2">Можно добавить несколько; порядок — сверху вниз. Потом сохраните день.</p>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Создать день</button>
</form>
@endsection

@section('scripts')
<script>
(function () {
  const suggestUrl = @json(route('admin.locations.suggest'));
  const input = document.getElementById('createStopSearch');
  const results = document.getElementById('createStopResults');
  const picks = document.getElementById('createStopPicks');
  if (!input || !results || !picks) return;
  let timer = null;
  let seq = 0;
  const chosen = new Set();

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function renderPick(id, name) {
    if (chosen.has(id)) return;
    chosen.add(id);
    const row = document.createElement('div');
    row.className = 'border rounded px-3 py-2 d-flex align-items-center gap-2';
    row.innerHTML =
      '<input type="hidden" name="location_ids[]" value="' + id + '">'
      + '<span class="me-auto" dir="auto">#' + id + ' · ' + esc(name) + '</span>'
      + '<button type="button" class="btn btn-sm btn-outline-danger" data-remove="' + id + '">Убрать</button>';
    picks.appendChild(row);
  }

  picks.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-remove]');
    if (!btn) return;
    const id = parseInt(btn.getAttribute('data-remove'), 10);
    chosen.delete(id);
    btn.closest('div')?.remove();
  });

  input.addEventListener('input', function () {
    const q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { results.innerHTML = ''; return; }
    const my = ++seq;
    timer = setTimeout(async function () {
      try {
        const res = await fetch(suggestUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (my !== seq) return;
        const items = Array.isArray(data.items) ? data.items : [];
        if (!items.length) {
          results.innerHTML = '<div class="list-group-item small text-muted">Ничего не найдено</div>';
          return;
        }
        results.innerHTML = items.map(function (it) {
          const label = (it.model ? ('#' + it.model + ' · ') : '') + it.name;
          return '<button type="button" class="list-group-item list-group-item-action py-2 small text-start" dir="auto" data-id="' + it.id + '" data-name="' + esc(it.name) + '">'
            + esc(label) + '</button>';
        }).join('');
      } catch (e) {
        if (my === seq) results.innerHTML = '<div class="list-group-item small text-danger">Ошибка поиска</div>';
      }
    }, 220);
  });

  results.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-id]');
    if (!btn) return;
    renderPick(parseInt(btn.dataset.id, 10), btn.dataset.name || '');
    results.innerHTML = '';
    input.value = '';
    input.focus();
  });
})();
</script>
@endsection
