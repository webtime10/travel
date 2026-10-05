@php $item = $category ?? null; @endphp
@if($languages->isEmpty())
  <p class="text-muted">Add <a href="{{ route('admin.languages.index') }}">languages</a> first.</p>
@else
<ul class="nav nav-tabs mb-3" role="tablist">
  @foreach($languages as $index => $language)
    <li class="nav-item">
      <button type="button" class="nav-link @if($index === 0) active @endif" data-bs-toggle="tab" data-bs-target="#pane-{{ $language->code }}" role="tab">{{ strtolower($language->code) }}</button>
    </li>
  @endforeach
</ul>
<div class="tab-content p-0 mb-4">
  @foreach($languages as $index => $language)
    @php
      $c = $language->code;
      $desc = $item?->descriptions?->firstWhere('language_id', $language->id);
    @endphp
    <div class="tab-pane fade @if($index === 0) show active @endif" id="pane-{{ $c }}" role="tabpanel">
      <div class="mb-3">
        <label class="form-label">Name @if($language->is_default)<span class="text-danger">*</span>@endif</label>
        <input type="text" name="name_{{ $c }}" class="form-control" value="{{ old('name_'.$c, $desc->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
      </div>
      <div class="mb-0">
        <label class="form-label">Description</label>
        <textarea name="description_{{ $c }}" class="form-control" rows="4">{{ old('description_'.$c, $desc->description ?? '') }}</textarea>
      </div>
    </div>
  @endforeach
</div>
@endif

<div class="mb-3">
  <label class="form-label">Parent</label>
  <select name="parent_id" class="form-select">
    <option value="">— Root —</option>
    @foreach($parentOptions as $opt)
      <option value="{{ $opt['id'] }}" {{ (string) old('parent_id', $item->parent_id ?? '') === (string) $opt['id'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
    @endforeach
  </select>
</div>
<div class="mb-3">
  <label class="form-label">Sort order</label>
  <input type="number" name="sort_order" class="form-control" style="max-width:12rem" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
</div>
<input type="hidden" name="status" value="0">
<div class="form-check mb-0">
  <input class="form-check-input" type="checkbox" name="status" value="1" id="status" {{ old('status', $item->status ?? true) ? 'checked' : '' }}>
  <label class="form-check-label" for="status">Status</label>
</div>
