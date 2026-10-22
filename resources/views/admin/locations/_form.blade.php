@php
  $item = $location ?? null;
  $selectedCats = array_map('intval', (array) old('category_ids', $item?->categories?->pluck('id')->all() ?? []));
  $selectedCount = count($selectedCats);
  $heMetaForm = $item?->heMeta();
  $ruUnitsTotal = $heMetaForm ? count(app(\App\Services\LocationRuTranslator::class)->units($heMetaForm)) : 0;
  $ruUnitsDone = (int) data_get($item?->ruMeta(), 'stats.translated', 0);
@endphp

@if($languages->isEmpty())
  <p class="text-muted">Add <a href="{{ route('admin.languages.index') }}">languages</a> first.</p>
@else
<ul class="nav nav-tabs mb-3" role="tablist">
  @foreach($languages as $index => $language)
    <li class="nav-item">
      <button type="button" class="nav-link @if($index === 0) active @endif" data-bs-toggle="tab" data-bs-target="#lpane-{{ $language->code }}" role="tab">{{ strtolower($language->code) }}</button>
    </li>
  @endforeach
</ul>
<div class="tab-content p-0 mb-4">
  @foreach($languages as $index => $language)
    @php
      $c = $language->code;
      $desc = $item?->descriptions?->firstWhere('language_id', $language->id);
      $isRu = strtolower($c) === 'ru';
      $meta = $isRu ? $item?->ruMeta() : $item?->heMeta();
    @endphp
    <div class="tab-pane fade @if($index === 0) show active @endif" id="lpane-{{ $c }}" role="tabpanel">
      <div class="mb-3">
        <label class="form-label">Name @if($language->is_default)<span class="text-danger">*</span>@endif</label>
        <input type="text" name="name_{{ $c }}" class="form-control" dir="auto" value="{{ old('name_'.$c, $desc->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
      </div>
      <div class="mb-3">
        <label class="form-label">Description</label>
        <textarea name="description_{{ $c }}" class="form-control" dir="auto" rows="10">{{ old('description_'.$c, $desc->description ?? '') }}</textarea>
      </div>

      @if($item)
        <hr class="my-3">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <h6 class="mb-0">
            JSON ({{ strtoupper($isRu ? 'ru' : 'he') }})
            @if($isRu && $ruUnitsTotal > 0)
              <span class="text-muted small fw-normal">· RU {{ $ruUnitsDone }}/{{ $ruUnitsTotal }}</span>
            @endif
          </h6>
          <div class="d-flex align-items-center gap-1">
            @if($meta !== null)
              <button type="button" class="btn btn-sm btn-outline-secondary location-json-copy" data-target="#ljson-{{ $c }}">Copy</button>
            @endif
            @if($isRu && $heMetaForm !== null)
              <button
                type="button"
                class="btn btn-icon btn-outline-primary location-translate-ru-btn"
                title="Перевести на русский"
                data-url="{{ route('admin.locations.translate-ru', $item->id) }}"
                data-csrf="{{ csrf_token() }}"
                data-already="{{ $ruUnitsDone }}"
                data-total="{{ $ruUnitsTotal }}"
              >
                <span class="d-inline-flex align-items-center justify-content-center" style="width:1.5rem;height:1.5rem;font-weight:700;font-size:.85rem;line-height:1;" aria-hidden="true">A文</span>
              </button>
            @endif
          </div>
        </div>
        @if($isRu)
          <div class="location-translate-ru-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
              <div class="fw-semibold location-translate-ru-label">Перевод на русский…</div>
              <div class="fw-bold location-translate-ru-pct">0%</div>
            </div>
            <div class="progress mb-2" style="height:1rem;">
              <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary location-translate-ru-bar" role="progressbar" style="width:0%"></div>
            </div>
            <div class="small location-translate-ru-status">Подготовка…</div>
          </div>
        @endif
        @if($meta === null)
          <p class="text-muted mb-0">{{ $isRu ? 'Русский JSON пока пустой — появится после перевода.' : 'JSON пока пустой — появится после импорта.' }}</p>
        @else
          <pre id="ljson-{{ $c }}" class="mb-0 small" style="max-height:28rem;overflow:auto;white-space:pre;direction:ltr;text-align:left;background:#f8f9fa;padding:0.75rem;border-radius:0.375rem;">{{ json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
        @endif
      @endif
    </div>
  @endforeach
</div>
@endif

<div class="mb-3" style="max-width:28rem">
  <label class="form-label">Manufacturer <span class="text-muted fw-normal">· страна</span></label>
  <select name="manufacturer_id" class="form-select">
    <option value="">—</option>
    @foreach(($manufacturers ?? []) as $man)
      <option value="{{ $man->id }}" {{ (string) old('manufacturer_id', $item->manufacturer_id ?? '') === (string) $man->id ? 'selected' : '' }}>{{ $man->nameFor($defaultLanguage) }}</option>
    @endforeach
  </select>
</div>
<div class="mb-3" style="max-width:28rem">
  <label class="form-label">Area <span class="text-muted fw-normal">· район тура</span></label>
  <select name="area_id" class="form-select">
    <option value="">—</option>
    @foreach(($areas ?? []) as $area)
      <option value="{{ $area->id }}" {{ (string) old('area_id', $item->area_id ?? '') === (string) $area->id ? 'selected' : '' }}>{{ $area->nameFor($defaultLanguage) }}</option>
    @endforeach
  </select>
  @if(empty($areas) || count($areas) === 0)
    <div class="form-text">Справочник пуст — <a href="{{ route('admin.areas.create') }}">создать район</a></div>
  @endif
</div>
<div class="mb-3" style="max-width:28rem">
  <label class="form-label">Trip role <span class="text-muted fw-normal">· роль в туре</span></label>
  <select name="trip_role" class="form-select">
    <option value="">—</option>
    @foreach(\App\Models\Location::TRIP_ROLES as $role => $label)
      <option value="{{ $role }}" {{ (string) old('trip_role', $item->trip_role ?? '') === $role ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
  </select>
</div>
<div class="mb-3">
  <label class="form-label">Categories <span class="text-muted fw-normal">· область</span></label>
  @if(empty($categories) || count($categories) === 0)
    <p class="text-muted mb-0">No categories yet. <a href="{{ route('admin.categories.create') }}">Add category</a></p>
  @else
    <div class="dropdown checkbox-select" data-checkbox-select>
      <button class="form-select text-start checkbox-select-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <span data-checkbox-select-label>
          @if($selectedCount === 0)
            Select categories
          @elseif($selectedCount === 1)
            1 category selected
          @else
            {{ $selectedCount }} categories selected
          @endif
        </span>
      </button>
      <div class="dropdown-menu w-100 py-2 checkbox-select-menu">
        @foreach($categories as $opt)
          <label class="dropdown-item d-flex align-items-center gap-2 mb-0" for="lcat-{{ $opt['id'] }}">
            <input
              class="form-check-input m-0"
              type="checkbox"
              name="category_ids[]"
              value="{{ $opt['id'] }}"
              id="lcat-{{ $opt['id'] }}"
              {{ in_array((int) $opt['id'], $selectedCats, true) ? 'checked' : '' }}
            >
            <span>{{ $opt['label'] }}</span>
          </label>
        @endforeach
      </div>
    </div>
  @endif
</div>
<div class="mb-3">
  <label class="form-label">Model</label>
  <input type="text" name="model" class="form-control" style="max-width:12rem" value="{{ old('model', $item->model ?? '') }}" maxlength="64" placeholder="id из Python">
</div>
<div class="row g-2 mb-3" style="max-width:26rem">
  <div class="col">
    <label class="form-label">Lat</label>
    <input type="text" name="lat" class="form-control" value="{{ old('lat', $item->lat ?? '') }}" placeholder="38.1157">
  </div>
  <div class="col">
    <label class="form-label">Lng</label>
    <input type="text" name="lng" class="form-control" value="{{ old('lng', $item->lng ?? '') }}" placeholder="13.3615">
  </div>
</div>
<div class="mb-3">
  <label class="form-label">Sort order</label>
  <input type="number" name="sort_order" class="form-control" style="max-width:12rem" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
</div>
<input type="hidden" name="status" value="0">
<div class="form-check mb-4">
  <input class="form-check-input" type="checkbox" name="status" value="1" id="lstatus" {{ old('status', $item->status ?? true) ? 'checked' : '' }}>
  <label class="form-check-label" for="lstatus">Status</label>
</div>

@php
  $heLangForm = $languages->firstWhere('code', 'he');
  $ruLangForm = $languages->firstWhere('code', 'ru');
  $attrRows = old('location_attributes');
  if (! is_array($attrRows)) {
    $attrRows = [];
    foreach (($item?->attributeValues ?? collect()) as $val) {
      $attrRows[$val->attribute_id]['attribute_id'] = $val->attribute_id;
      if ($heLangForm && (int) $val->language_id === (int) $heLangForm->id) {
        $attrRows[$val->attribute_id]['text_he'] = $val->text;
      }
      if ($ruLangForm && (int) $val->language_id === (int) $ruLangForm->id) {
        $attrRows[$val->attribute_id]['text_ru'] = $val->text;
      }
    }
    $attrRows = array_values($attrRows);
  }
@endphp
<div class="mb-0">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <label class="form-label mb-0">Attributes</label>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="addLocationAttr">+ атрибут</button>
  </div>
  <div id="locationAttrRows">
    @forelse($attrRows as $i => $row)
      @include('admin.locations._attribute_row', ['i' => $i, 'row' => $row, 'attributeGroups' => $attributeGroups ?? collect(), 'defaultLanguage' => $defaultLanguage])
    @empty
    @endforelse
  </div>
  <template id="locationAttrTpl">
    @include('admin.locations._attribute_row', ['i' => '__I__', 'row' => [], 'attributeGroups' => $attributeGroups ?? collect(), 'defaultLanguage' => $defaultLanguage])
  </template>
</div>
<script>
  (function () {
    const box = document.getElementById('locationAttrRows');
    const tpl = document.getElementById('locationAttrTpl');
    const add = document.getElementById('addLocationAttr');
    if (!box || !tpl || !add) return;
    add.addEventListener('click', function () {
      const i = box.querySelectorAll('[data-attr-row]').length;
      box.insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__I__', String(i)));
    });
    box.addEventListener('click', function (e) {
      const btn = e.target.closest('[data-attr-remove]');
      if (btn) btn.closest('[data-attr-row]')?.remove();
    });
  })();
</script>

<script>
  document.querySelectorAll('.location-json-copy').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      const pre = document.querySelector(btn.dataset.target);
      if (!pre) return;
      try {
        await navigator.clipboard.writeText(pre.textContent);
        const prev = btn.textContent;
        btn.textContent = 'OK';
        setTimeout(function () { btn.textContent = prev; }, 1200);
      } catch (e) {
        alert('Не удалось скопировать');
      }
    });
  });

  (function () {
    const btn = document.querySelector('.location-translate-ru-btn');
    if (!btn) return;
    const pane = btn.closest('.tab-pane') || document;
    const box = pane.querySelector('.location-translate-ru-progress');
    const label = pane.querySelector('.location-translate-ru-label');
    const pctEl = pane.querySelector('.location-translate-ru-pct');
    const bar = pane.querySelector('.location-translate-ru-bar');
    const status = pane.querySelector('.location-translate-ru-status');

    function setProgress(done, total, detail) {
      const pct = total > 0 ? Math.round((done / total) * 100) : 0;
      bar.style.width = pct + '%';
      pctEl.textContent = pct + '%';
      status.textContent = detail || (done + ' / ' + total);
    }

    async function runBatch() {
      const res = await fetch(btn.dataset.url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': btn.dataset.csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ limit: 12 }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.status === 422 && data.total != null) {
        data._soft = true;
        return data;
      }
      if (!res.ok || !data.ok) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      return data;
    }

    btn.addEventListener('click', async function () {
      if (btn.disabled) return;
      const already = Number(btn.dataset.already || 0);
      const total0 = Number(btn.dataset.total || 0);
      const msg = already > 0
        ? ('Продолжить с ' + already + ' / ' + total0 + '? Уже переведённое не трогаем.')
        : 'Перевести локацию на русский? Вкладка he не изменится.';
      if (!confirm(msg)) return;

      btn.disabled = true;
      box.style.display = 'block';
      bar.classList.remove('bg-danger');
      bar.classList.add('progress-bar-animated');
      label.textContent = 'Перевод на русский…';
      setProgress(already, total0, 'Запуск…');

      let softStreak = 0;
      try {
        for (let guard = 0; guard < 400; guard++) {
          const data = await runBatch();
          const total = Number(data.total || total0);
          const done = Number(data.translated_ru || 0);
          if (data._soft) {
            if (++softStreak > 5) throw new Error((data.message || 'Сбой') + ' — нажмите ещё раз, продолжит с ' + done);
            setProgress(done, total, (data.message || 'Сбой пакета') + ' · повторяю…');
            continue;
          }
          softStreak = 0;
          setProgress(done, total, 'Готово ' + done + ' из ' + total + (data.remaining ? (' · осталось ' + data.remaining) : ''));
          if (data.done) {
            label.textContent = 'Готово';
            try { sessionStorage.setItem('travel_open_ru_tab', '1'); } catch (e) {}
            window.location.reload();
            return;
          }
        }
      } catch (e) {
        label.textContent = 'Ошибка (можно продолжить)';
        status.textContent = e && e.message ? e.message : String(e);
        bar.classList.remove('progress-bar-animated');
        bar.classList.add('bg-danger');
        btn.disabled = false;
      }
    });

    try {
      if (sessionStorage.getItem('travel_open_ru_tab') === '1') {
        sessionStorage.removeItem('travel_open_ru_tab');
        const ruTab = document.querySelector('[data-bs-target="#lpane-ru"]');
        if (ruTab && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(ruTab).show();
        else if (ruTab) ruTab.click();
      }
    } catch (e) {}
  })();
</script>
