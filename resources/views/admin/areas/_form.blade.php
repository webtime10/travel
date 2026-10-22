@php $item = $area ?? null; @endphp
<ul class="nav nav-tabs mb-3">
  @foreach($languages as $index => $language)
    <li class="nav-item"><button type="button" class="nav-link @if($index===0) active @endif" data-bs-toggle="tab" data-bs-target="#apane-{{ $language->code }}">{{ $language->code }}</button></li>
  @endforeach
</ul>
<div class="tab-content mb-3">
  @foreach($languages as $index => $language)
    @php $d = $item?->descriptions?->firstWhere('language_id', $language->id); @endphp
    <div class="tab-pane fade @if($index===0) show active @endif" id="apane-{{ $language->code }}">
      <label class="form-label">Name</label>
      <input type="text" name="name_{{ $language->code }}" class="form-control" value="{{ old('name_'.$language->code, $d->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
    </div>
  @endforeach
</div>

{{-- slug задаётся в коде из названия при создании; в UI не показываем --}}
@if($item?->slug)
  <input type="hidden" name="slug" value="{{ old('slug', $item->slug) }}">
@endif

<div class="mb-3" style="max-width:28rem">
  <label class="form-label">База ночёвки</label>
  <select name="default_base_location_id" class="form-select">
    <option value="">—</option>
    @foreach(($baseOptions ?? []) as $opt)
      <option value="{{ $opt['id'] }}" {{ (string) old('default_base_location_id', $item->default_base_location_id ?? '') === (string) $opt['id'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
    @endforeach
  </select>
  <div class="form-text">Если lat/lng пустые — подставятся с базы</div>
</div>

<div class="row g-2 mb-3" style="max-width:28rem">
  <div class="col">
    <label class="form-label">Центр Lat</label>
    <input type="text" name="lat" class="form-control" value="{{ old('lat', $item->lat ?? '') }}" placeholder="45.885">
  </div>
  <div class="col">
    <label class="form-label">Центр Lng</label>
    <input type="text" name="lng" class="form-control" value="{{ old('lng', $item->lng ?? '') }}" placeholder="10.845">
  </div>
</div>

<div class="mb-3" style="max-width:12rem">
  <label class="form-label">Радиус (км)</label>
  <input type="number" name="radius_km" class="form-control" value="{{ old('radius_km', $item->radius_km ?? 25) }}" min="0.5" max="500" step="0.5">
  <div class="form-text">Охват для авто-привязки локаций</div>
</div>

<div class="row g-2 mb-3" style="max-width:20rem">
  <div class="col">
    <label class="form-label">Min days</label>
    <input type="number" name="min_days" class="form-control" value="{{ old('min_days', $item->min_days ?? 1) }}" min="1" max="14">
  </div>
  <div class="col">
    <label class="form-label">Max days</label>
    <input type="number" name="max_days" class="form-control" value="{{ old('max_days', $item->max_days ?? 4) }}" min="1" max="14">
  </div>
</div>

<div class="mb-3" style="max-width:12rem">
  <label class="form-label">Sort order</label>
  <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
</div>

<input type="hidden" name="status" value="0">
<div class="form-check mb-0">
  <input class="form-check-input" type="checkbox" name="status" value="1" id="astatus" {{ old('status', $item->status ?? true) ? 'checked' : '' }}>
  <label class="form-check-label" for="astatus">Status</label>
</div>
