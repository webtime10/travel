@php $item = $language ?? null; @endphp
<div class="mb-3">
  <label class="form-label">Code <span class="text-danger">*</span></label>
  <input type="text" name="code" class="form-control" value="{{ old('code', $item->code ?? '') }}" required maxlength="10">
</div>
<div class="mb-3">
  <label class="form-label">Name <span class="text-danger">*</span></label>
  <input type="text" name="name" class="form-control" value="{{ old('name', $item->name ?? '') }}" required maxlength="100">
</div>
<div class="mb-3">
  <label class="form-label">Locale <span class="text-danger">*</span></label>
  <input type="text" name="locale" class="form-control" value="{{ old('locale', $item->locale ?? '') }}" required>
</div>
<div class="mb-3">
  <label class="form-label">Directory</label>
  <input type="text" name="directory" class="form-control" value="{{ old('directory', $item->directory ?? '') }}" maxlength="32">
</div>
<div class="mb-3">
  <label class="form-label">Sort order</label>
  <input type="number" name="sort_order" class="form-control" style="max-width:12rem" value="{{ old('sort_order', $item->sort_order ?? 0) }}" min="0">
</div>
<div class="form-check mb-2">
  <input class="form-check-input" type="checkbox" name="is_default" value="1" id="is_default" {{ old('is_default', $item->is_default ?? false) ? 'checked' : '' }}>
  <label class="form-check-label" for="is_default">Default</label>
</div>
<div class="form-check mb-0">
  <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" {{ old('is_active', $item->is_active ?? true) ? 'checked' : '' }}>
  <label class="form-check-label" for="is_active">Active</label>
</div>
