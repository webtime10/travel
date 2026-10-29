@php $item = $label ?? null; @endphp
<div class="row g-3 mb-3">
  <div class="col-md-3 col-lg-2">
    <label class="form-label" for="glCountry">Страна</label>
    <select name="country_code" id="glCountry" class="form-select" required>
      @foreach(\App\Models\GoldenLabel::COUNTRIES as $code => $labelName)
        <option value="{{ $code }}" {{ old('country_code', $item->country_code ?? 'BOTH') === $code ? 'selected' : '' }}>
          {{ $labelName }}
        </option>
      @endforeach
    </select>
  </div>
  <div class="col-md-9 col-lg-10">
    <ul class="nav nav-tabs mb-2">
      @foreach($languages as $index => $language)
        <li class="nav-item">
          <button type="button" class="nav-link @if($index===0) active @endif" data-bs-toggle="tab" data-bs-target="#glpane-{{ $language->code }}">
            {{ strtoupper($language->code) }}
          </button>
        </li>
      @endforeach
    </ul>
    <div class="tab-content">
      @foreach($languages as $index => $language)
        @php $d = $item?->descriptions?->firstWhere('language_id', $language->id); @endphp
        <div class="tab-pane fade @if($index===0) show active @endif" id="glpane-{{ $language->code }}">
          <label class="form-label">Name ({{ $language->code }})</label>
          <input type="text" name="name_{{ $language->code }}" class="form-control"
                 value="{{ old('name_'.$language->code, $d->name ?? '') }}"
                 {{ $language->is_default ? 'required' : '' }}
                 placeholder="например: stay / переезд / тяжёлый трек">
        </div>
      @endforeach
    </div>
  </div>
</div>
<div class="row g-3">
  <div class="col-md-4">
    <label class="form-label">Slug</label>
    <input type="text" name="slug" class="form-control" value="{{ old('slug', $item->slug ?? '') }}" placeholder="auto from name">
  </div>
  <div class="col-md-4">
    <label class="form-label">Sort</label>
    <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
  </div>
  <div class="col-md-4 d-flex align-items-end">
    <div class="form-check mb-2">
      <input type="hidden" name="status" value="0">
      <input class="form-check-input" type="checkbox" name="status" value="1" id="glStatus"
             {{ old('status', $item->status ?? true) ? 'checked' : '' }}>
      <label class="form-check-label" for="glStatus">Active</label>
    </div>
  </div>
</div>
