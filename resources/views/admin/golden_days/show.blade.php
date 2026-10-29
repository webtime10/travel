@extends('admin.layouts.layout')

@section('content')
@php
  $showRu = ($lang ?? 'he') === 'ru' && $item->hasRussian();
@endphp
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0" dir="auto">{{ $showRu ? $item->displayTitle() : ($item->title ?: $item->displayTitle()) }}</h4>
  <div class="page-toolbar d-flex flex-wrap gap-1 align-items-center">
    <a href="#gd-edit" class="btn btn-primary" id="gdEditJumpBtn">
      <i class="bx bx-edit me-1"></i>Редактировать
    </a>
    <div class="btn-group btn-group-sm">
      <a href="{{ route('admin.golden-days.show', [$item, 'lang' => 'he']) }}"
         class="btn btn-outline-secondary {{ !$showRu ? 'active' : '' }}">HE</a>
      <a href="{{ route('admin.golden-days.show', [$item, 'lang' => 'ru']) }}"
         class="btn btn-outline-secondary {{ $showRu ? 'active' : '' }} {{ !$item->hasRussian() ? 'disabled' : '' }}">RU</a>
    </div>
    <button type="button"
            class="btn btn-outline-primary golden-translate-ru-btn"
            data-url="{{ route('admin.golden-days.translate-ru', $item) }}"
            data-reload="{{ route('admin.golden-days.show', [$item, 'lang' => 'ru']) }}">
      <i class="bx bx-globe me-1"></i> Перевод на русский
    </button>
    <form action="{{ route('admin.golden-days.toggle', $item) }}" method="post">
      @csrf
      <button type="submit" class="btn btn-outline-secondary">{{ $item->is_active ? 'Deactivate' : 'Activate' }}</button>
    </form>
    <a href="{{ route('admin.golden-days.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="golden-translate-ru-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
  <div class="d-flex justify-content-between mb-1">
    <div class="fw-semibold golden-translate-ru-label">Перевод на русский…</div>
    <div class="fw-bold golden-translate-ru-pct">0%</div>
  </div>
  <div class="progress mb-1" style="height:8px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary golden-translate-ru-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small golden-translate-ru-status">Подготовка…</div>
</div>

@if(($unitsTotal ?? 0) > 0)
  <div class="small text-muted mb-3">
    Перевод: {{ $unitsDone }} / {{ $unitsTotal }} фрагментов
    @if($unitsDone >= $unitsTotal && $unitsTotal > 0)
      <span class="badge bg-label-success">готово</span>
    @endif
  </div>
@endif

@php
  $metaKind = (string) data_get($item->meta, 'kind', '');
  $metaLoad = (string) data_get($item->meta, 'load', '');
@endphp
<div class="card mb-3" id="gd-edit">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Редактировать день</span>
    <span class="small text-muted">#{{ $item->id }} · stops {{ $item->stops_count }} · usage {{ $item->usage_count }}</span>
  </div>
  <div class="card-body">
    <form method="post" action="{{ route('admin.golden-days.update', $item) }}">
      @csrf @method('PUT')
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Название (RU)</label>
          <input type="text" name="title_ru" class="form-control" dir="auto" value="{{ old('title_ru', $item->title_ru) }}">
        </div>
        <div class="col-md-6">
          <label class="form-label">Название (HE)</label>
          <input type="text" name="title" class="form-control" dir="auto" value="{{ old('title', $item->title) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Регион</label>
          <select name="category_id" class="form-select">
            <option value="">—</option>
            @foreach(($regionOptions ?? []) as $reg)
              <option value="{{ $reg->id }}" @selected((int) old('category_id', $item->category_id) === (int) $reg->id)>
                {{ $reg->nameFor($defaultLanguage ?? null) }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Hub</label>
          <select name="hub_key" class="form-select">
            <option value="">—</option>
            @foreach(($hubOptions ?? []) as $hub)
              <option value="{{ $hub['key'] }}" @selected(old('hub_key', $item->hub_key) === $hub['key'])>{{ $hub['label'] }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Роль</label>
          <select name="kind" class="form-select">
            <option value="">—</option>
            @foreach(['arrival' => 'Въезд', 'departure' => 'Выезд', 'stay' => 'Stay', 'nature' => 'Nature', 'family' => 'Family', 'transfer' => 'Transfer', 'kosher' => 'Kosher'] as $k => $lab)
              <option value="{{ $k }}" @selected(old('kind', $metaKind) === $k)>{{ $lab }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Нагрузка</label>
          <select name="load" class="form-select">
            <option value="">—</option>
            @foreach(['soft' => 'Лёгкий', 'medium' => 'Средний', 'hard' => 'Насыщенный'] as $k => $lab)
              <option value="{{ $k }}" @selected(old('load', $metaLoad) === $k)>{{ $lab }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <div class="form-check mb-2">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="gdActive" @checked(old('is_active', $item->is_active))>
            <label class="form-check-label" for="gdActive">On</label>
          </div>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
        <button type="submit" class="btn btn-primary btn-sm">Сохранить день</button>
        <span class="small text-muted" dir="auto">{{ $item->overnightAxis($showRu) ?: '—' }}</span>
        @if($item->sourceSkeleton)
          <a class="small" href="{{ route('admin.route-skeletons.show', $item->source_skeleton_id) }}">skeleton #{{ $item->source_skeleton_id }}</a>
        @endif
      </div>
    </form>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Golden labels · {{ $labelsCountry ?? $item->countryCode() }}</span>
    <a href="{{ route('admin.golden-labels.index', ['country' => $labelsCountry ?? $item->countryCode()]) }}" class="small">Справочник меток</a>
  </div>
  <div class="card-body">
    <p class="small text-muted mb-3">Только метки {{ $labelsCountry ?? $item->countryCode() }} и общие (BOTH). Хабы другой страны скрыты.</p>
    @if(($allLabels ?? collect())->isEmpty())
      <div class="text-muted small">Меток ещё нет — <a href="{{ route('admin.golden-labels.create') }}">создайте</a>.</div>
    @else
      <form method="post" action="{{ route('admin.golden-days.sync-labels', $item) }}">
        @csrf
        @php
          $allowedIds = $allLabels->pluck('id')->all();
          $selected = array_values(array_intersect($item->labels->pluck('id')->all(), $allowedIds));
        @endphp
        <div class="row g-2">
          @foreach($allLabels as $lab)
            <div class="col-md-4 col-lg-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="label_ids[]" value="{{ $lab->id }}"
                       id="glab{{ $lab->id }}"
                       {{ in_array($lab->id, $selected, true) ? 'checked' : '' }}>
                <label class="form-check-label" for="glab{{ $lab->id }}">
                  {{ $lab->nameFor($defaultLanguage) }}
                  <span class="text-muted small">({{ $lab->slug }})</span>
                </label>
              </div>
            </div>
          @endforeach
        </div>
        <button type="submit" class="btn btn-primary btn-sm mt-3">Сохранить метки</button>
      </form>
    @endif
  </div>
</div>

<div class="card" id="gd-stops">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>Стопы · выбор локации</span>
    <span class="small text-muted">как товар в OpenCart: 2 буквы → список → клик</span>
  </div>
  <div class="card-body border-bottom">
    <div class="row g-2 align-items-end">
      <div class="col-md-7 col-lg-6">
        <label class="form-label mb-1" for="gdAddStopSearch">Локация</label>
        <div class="position-relative">
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bx bx-search"></i></span>
            <input
              type="text"
              id="gdAddStopSearch"
              class="form-control"
              placeholder="Начните вводить название (от 2 букв)…"
              autocomplete="off"
            >
          </div>
          <div
            id="gdAddStopResults"
            class="list-group position-absolute start-0 end-0 shadow border rounded mt-1"
            style="display:none;z-index:1050;max-height:260px;overflow:auto;background:#fff"
          ></div>
        </div>
        <div class="form-text">Выберите локацию из списка — она добавится в этот золотой день.</div>
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th style="width:2rem"></th>
          <th style="width:2.5rem">#</th>
          <th>Name</th>
          <th>Model</th>
          <th>Location</th>
          <th style="min-width:12rem">Атрибуты</th>
          <th style="width:3rem"></th>
        </tr>
      </thead>
      <tbody id="gdStopsBody">
        @foreach($item->stops as $stop)
          @php
            $name = $showRu && trim((string)($stop->name_ru ?? '')) !== ''
              ? $stop->name_ru
              : ($stop->name ?: '—');
            $loc = $stop->location;
            $langId = $defaultLanguage?->id;
            $attrBadges = [];
            if ($loc) {
              if ($loc->weight !== null) {
                $attrBadges[] = ['Вес '.$loc->weight, 'bg-label-primary'];
              }
              if ($loc->visit_hours !== null) {
                $h = rtrim(rtrim(number_format((float) $loc->visit_hours, 1, '.', ''), '0'), '.');
                $attrBadges[] = [$h.' ч', 'bg-label-secondary'];
              }
              $k = $loc->kosher_food ?? 'unknown';
              if ($k === 'yes') {
                $attrBadges[] = ['кошер', 'bg-label-success'];
              } elseif ($k === 'no') {
                $attrBadges[] = ['не кошер', 'bg-label-secondary'];
              }
              $placeType = trim((string) data_get($loc->meta, 'place_type', ''));
              if ($placeType !== '') {
                $attrBadges[] = [$placeType, 'bg-label-info'];
              }
              $activity = trim((string) data_get($loc->meta, 'activity', ''));
              if ($activity !== '') {
                $attrBadges[] = [$activity, 'bg-label-warning'];
              }
              foreach ($loc->attributeValues ?? [] as $av) {
                $txt = trim((string) ($av->text ?? ''));
                if ($txt === '' || ($langId && (int) $av->language_id !== (int) $langId)) {
                  continue;
                }
                $attrName = $av->attribute?->nameFor($defaultLanguage) ?: '';
                if (in_array($attrName, ['Вес', 'Время на месте', 'Кошер', 'Не кошер', 'Тип точки', 'Активность'], true)) {
                  continue;
                }
                if ($attrName !== '') {
                  $attrBadges[] = [$attrName.': '.$txt, 'bg-label-dark'];
                } else {
                  $attrBadges[] = [$txt, 'bg-label-dark'];
                }
              }
            }
          @endphp
          <tr data-stop-row data-stop-id="{{ $stop->id }}">
            <td class="align-middle text-muted" style="cursor:grab" data-stop-handle title="Перетащить"><i class="bx bx-menu"></i></td>
            <td class="align-middle stop-num">{{ $loop->iteration }}</td>
            <td class="align-middle stop-name" dir="auto">{{ $name }}</td>
            <td class="align-middle">{{ $stop->source_model ?: '—' }}</td>
            <td class="stop-location-cell align-middle" data-stop-id="{{ $stop->id }}" style="min-width:16rem;max-width:22rem">
              <div class="stop-location-view">
                @if($stop->location_id)
                  <a class="stop-loc-link" href="{{ route('admin.locations.edit', $stop->location_id) }}">#{{ $stop->location_id }}</a>
                  <span class="text-muted small ms-1 stop-loc-name" dir="auto">{{ $stop->location?->nameFor() }}</span>
                  <button type="button" class="btn btn-sm btn-link p-0 ms-1 stop-link-open">сменить</button>
                  <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1 stop-unlink">снять</button>
                @else
                  <span class="text-muted stop-loc-empty">not linked</span>
                  <button type="button" class="btn btn-sm btn-outline-primary ms-1 stop-link-open">Привязать</button>
                @endif
              </div>
              <div class="stop-location-search mt-1" style="display:none">
                <input type="text" class="form-control form-control-sm stop-search-input" placeholder="От 2 букв — название локации" autocomplete="off">
                <div class="list-group stop-search-results mt-1 shadow-sm" style="max-height:220px;overflow:auto"></div>
                <button type="button" class="btn btn-sm btn-link p-0 mt-1 stop-search-cancel">Отмена</button>
              </div>
            </td>
            <td class="small stop-attrs-cell align-middle">
              @if($attrBadges === [])
                <span class="text-muted">—</span>
              @else
                <div class="d-flex flex-wrap gap-1">
                  @foreach($attrBadges as [$label, $cls])
                    <span class="badge {{ $cls }}">{{ $label }}</span>
                  @endforeach
                </div>
              @endif
            </td>
            <td class="align-middle">
              <button type="button" class="btn btn-sm btn-outline-danger stop-delete" title="Удалить стоп"><i class="bx bx-trash"></i></button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(function () {
  const suggestUrl = @json(route('admin.locations.suggest'));
  const storeStopUrl = @json(route('admin.golden-days.stops.store', $item));
  const reorderUrl = @json(route('admin.golden-days.stops.reorder', $item));
  const destroyTpl = @json(route('admin.golden-days.stops.destroy', ['golden_day' => $item->id, 'stop' => 999999]));
  const linkTpl = @json(route('admin.golden-days.stops.link', ['golden_day' => $item->id, 'stop' => 999999]));
  const unlinkTpl = @json(route('admin.golden-days.stops.unlink', ['golden_day' => $item->id, 'stop' => 999999]));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());
  const body = document.getElementById('gdStopsBody');

  function linkUrl(stopId) { return linkTpl.replace('999999', String(stopId)); }
  function unlinkUrl(stopId) { return unlinkTpl.replace('999999', String(stopId)); }
  function destroyUrl(stopId) { return destroyTpl.replace('999999', String(stopId)); }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function renumber() {
    if (!body) return;
    body.querySelectorAll('[data-stop-row]').forEach(function (row, i) {
      const num = row.querySelector('.stop-num');
      if (num) num.textContent = String(i + 1);
    });
  }

  function setLinked(cell, data) {
    const view = cell.querySelector('.stop-location-view');
    const search = cell.querySelector('.stop-location-search');
    search.style.display = 'none';
    view.innerHTML =
      '<a class="stop-loc-link" href="' + esc(data.edit_url) + '">#' + data.location_id + '</a>'
      + '<span class="text-muted small ms-1 stop-loc-name" dir="auto">' + esc(data.location_name) + '</span>'
      + '<button type="button" class="btn btn-sm btn-link p-0 ms-1 stop-link-open">сменить</button>'
      + '<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1 stop-unlink">снять</button>';
    view.style.display = '';
    const row = cell.closest('[data-stop-row]');
    const nameCell = row?.querySelector('.stop-name');
    if (nameCell && (data.name_ru || data.name || data.location_name)) {
      nameCell.textContent = data.name_ru || data.name || data.location_name;
    }
  }

  function setUnlinked(cell) {
    const view = cell.querySelector('.stop-location-view');
    const search = cell.querySelector('.stop-location-search');
    search.style.display = 'none';
    view.innerHTML =
      '<span class="text-muted stop-loc-empty">not linked</span>'
      + '<button type="button" class="btn btn-sm btn-outline-primary ms-1 stop-link-open">Привязать</button>';
    view.style.display = '';
  }

  function bindCellSearch(cell) {
    if (cell.dataset.searchBound) return;
    cell.dataset.searchBound = '1';
    let timer = null;
    let seq = 0;
    const input = cell.querySelector('.stop-search-input');
    const results = cell.querySelector('.stop-search-results');
    if (!input || !results) return;
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
            return '<button type="button" class="list-group-item list-group-item-action py-2 small text-start" dir="auto" data-location-id="' + it.id + '">'
              + esc(label) + '</button>';
          }).join('');
        } catch (err) {
          if (my === seq) results.innerHTML = '<div class="list-group-item small text-danger">Ошибка поиска</div>';
        }
      }, 220);
    });
  }

  document.querySelectorAll('.stop-location-cell').forEach(bindCellSearch);

  if (body) {
    body.addEventListener('click', async function (e) {
      const cell = e.target.closest('.stop-location-cell');
      const row = e.target.closest('[data-stop-row]');
      const stopId = (cell || row)?.dataset.stopId;

      const delBtn = e.target.closest('.stop-delete');
      if (delBtn && row && stopId) {
        e.preventDefault();
        if (!confirm('Удалить стоп из дня?')) return;
        delBtn.disabled = true;
        try {
          const res = await fetch(destroyUrl(stopId), {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          });
          const data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || 'Ошибка');
          row.remove();
          renumber();
        } catch (err) {
          alert(err.message || String(err));
          delBtn.disabled = false;
        }
        return;
      }

      if (!cell || !stopId) return;
      const view = cell.querySelector('.stop-location-view');
      const searchBox = cell.querySelector('.stop-location-search');
      const input = cell.querySelector('.stop-search-input');
      const results = cell.querySelector('.stop-search-results');
      bindCellSearch(cell);

      if (e.target.closest('.stop-link-open')) {
        e.preventDefault();
        view.style.display = 'none';
        searchBox.style.display = '';
        input.value = '';
        results.innerHTML = '';
        input.focus();
        return;
      }
      if (e.target.closest('.stop-search-cancel')) {
        e.preventDefault();
        searchBox.style.display = 'none';
        view.style.display = '';
        return;
      }
      if (e.target.closest('.stop-unlink')) {
        e.preventDefault();
        if (!confirm('Снять привязку локации?')) return;
        try {
          const res = await fetch(unlinkUrl(stopId), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          });
          const data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || 'Ошибка');
          setUnlinked(cell);
        } catch (err) {
          alert(err.message || String(err));
        }
        return;
      }
      const pick = e.target.closest('[data-location-id]');
      if (pick) {
        e.preventDefault();
        const locationId = parseInt(pick.dataset.locationId, 10);
        try {
          const res = await fetch(linkUrl(stopId), {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ location_id: locationId }),
          });
          const data = await res.json();
          if (!res.ok || !data.ok) throw new Error(data.message || 'Ошибка');
          setLinked(cell, data);
        } catch (err) {
          alert(err.message || String(err));
        }
      }
    });

    if (window.Sortable) {
      Sortable.create(body, {
        handle: '[data-stop-handle]',
        animation: 150,
        onEnd: async function () {
          renumber();
          const ids = Array.from(body.querySelectorAll('[data-stop-row]')).map(function (r) {
            return parseInt(r.dataset.stopId, 10);
          });
          try {
            await fetch(reorderUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: JSON.stringify({ stop_ids: ids }),
            });
          } catch (e) {}
        },
      });
    }
  }

  // OpenCart-style: 2 letters → dropdown → add location to golden day
  (function () {
    const input = document.getElementById('gdAddStopSearch');
    const results = document.getElementById('gdAddStopResults');
    if (!input || !results) return;
    let timer = null;
    let seq = 0;

    function hide() {
      results.style.display = 'none';
      results.innerHTML = '';
    }

    function showList(html) {
      results.innerHTML = html;
      results.style.display = 'block';
    }

    input.addEventListener('input', function () {
      const q = input.value.trim();
      clearTimeout(timer);
      if (q.length < 2) { hide(); return; }
      const my = ++seq;
      timer = setTimeout(async function () {
        try {
          const res = await fetch(suggestUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
          const data = await res.json();
          if (my !== seq) return;
          const items = Array.isArray(data.items) ? data.items : [];
          if (!items.length) {
            showList('<div class="list-group-item small text-muted">Ничего не найдено</div>');
            return;
          }
          showList(items.map(function (it) {
            const label = (it.model ? ('#' + it.model + ' · ') : '') + it.name;
            return '<button type="button" class="list-group-item list-group-item-action py-2 small text-start" dir="auto" data-add-location="' + it.id + '">'
              + esc(label) + '</button>';
          }).join(''));
        } catch (e) {
          if (my === seq) showList('<div class="list-group-item small text-danger">Ошибка поиска</div>');
        }
      }, 220);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hide();
    });

    document.addEventListener('click', function (e) {
      if (!results.contains(e.target) && e.target !== input) hide();
    });

    results.addEventListener('click', async function (e) {
      const btn = e.target.closest('[data-add-location]');
      if (!btn) return;
      const locationId = parseInt(btn.getAttribute('data-add-location'), 10);
      btn.disabled = true;
      try {
        const res = await fetch(storeStopUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ location_id: locationId }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || 'Ошибка');
        window.location.href = window.location.pathname + window.location.search + '#gd-stops';
        window.location.reload();
      } catch (err) {
        alert(err.message || String(err));
        btn.disabled = false;
      }
    });

    function focusPicker() {
      const box = document.getElementById('gd-stops') || document.getElementById('gd-edit');
      if (box) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(function () { input.focus(); }, 200);
    }

    document.getElementById('gdEditJumpBtn')?.addEventListener('click', function (e) {
      e.preventDefault();
      focusPicker();
    });

    if (window.location.hash === '#gd-edit' || window.location.hash === '#gd-stops') {
      focusPicker();
    }
  })();
})();

(function () {
  const btn = document.querySelector('.golden-translate-ru-btn');
  if (!btn) return;
  const box = document.querySelector('.golden-translate-ru-progress');
  const label = document.querySelector('.golden-translate-ru-label');
  const pctEl = document.querySelector('.golden-translate-ru-pct');
  const bar = document.querySelector('.golden-translate-ru-bar');
  const status = document.querySelector('.golden-translate-ru-status');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    || '{{ csrf_token() }}';

  function setProgress(done, total) {
    const pct = total > 0 ? Math.min(100, Math.round(100 * done / total)) : 0;
    pctEl.textContent = pct + '%';
    bar.style.width = pct + '%';
    status.textContent = done + ' / ' + total;
  }

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    box.style.display = '';
    label.textContent = 'Перевод на русский…';
    let safety = 40;
    try {
      while (safety-- > 0) {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ limit: 20 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok && !data.total) {
          status.textContent = data.message || ('Ошибка ' + res.status);
          label.textContent = 'Ошибка';
          break;
        }
        const total = data.total || 0;
        const rem = data.remaining || 0;
        setProgress(total - rem, total);
        if (data.message) status.textContent = data.message;
        if (data.done) {
          label.textContent = 'Готово';
          status.textContent = 'Перевод завершён — обновляю страницу…';
          location.href = btn.dataset.reload || location.href;
          return;
        }
        if ((data.translated || 0) === 0 && rem > 0) {
          label.textContent = 'Ошибка';
          status.textContent = data.message || 'Gemini не вернул перевод';
          break;
        }
      }
    } catch (e) {
      label.textContent = 'Ошибка';
      status.textContent = e.message || String(e);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
@endsection
