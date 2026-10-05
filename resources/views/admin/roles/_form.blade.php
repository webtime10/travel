@php $item = $role ?? null; @endphp
<div class="mb-3">
  <label class="form-label">Name <span class="text-danger">*</span></label>
  <input type="text" name="name" class="form-control" value="{{ old('name', $item->name ?? '') }}" required>
</div>
<div class="mb-3">
  <label class="form-label">Slug <span class="text-danger">*</span></label>
  <input type="text" name="slug" class="form-control" value="{{ old('slug', $item->slug ?? '') }}" required pattern="[a-z0-9\-]+">
</div>
<div class="mb-0">
  <label class="form-label">Description</label>
  <textarea name="description" class="form-control" rows="3">{{ old('description', $item->description ?? '') }}</textarea>
</div>
