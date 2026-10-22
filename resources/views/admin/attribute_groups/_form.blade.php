@php $item = $group ?? null; @endphp
<ul class="nav nav-tabs mb-3">
  @foreach($languages as $index => $language)
    <li class="nav-item"><button type="button" class="nav-link @if($index===0) active @endif" data-bs-toggle="tab" data-bs-target="#agpane-{{ $language->code }}">{{ $language->code }}</button></li>
  @endforeach
</ul>
<div class="tab-content mb-3">
  @foreach($languages as $index => $language)
    @php $d = $item?->descriptions?->firstWhere('language_id', $language->id); @endphp
    <div class="tab-pane fade @if($index===0) show active @endif" id="agpane-{{ $language->code }}">
      <label class="form-label">Name</label>
      <input type="text" name="name_{{ $language->code }}" class="form-control" value="{{ old('name_'.$language->code, $d->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
    </div>
  @endforeach
</div>
<div class="mb-0" style="max-width:12rem">
  <label class="form-label">Sort order</label>
  <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
</div>
