@php $item = $category ?? null; @endphp
<div class="mb-3">
  <label class="form-label" for="helpCategoryName">Name <span class="text-danger">*</span></label>
  <input
    type="text"
    id="helpCategoryName"
    name="name"
    class="form-control"
    value="{{ old('name', $item->name ?? '') }}"
    required
    maxlength="255"
  >
</div>
<div class="mb-3">
  <label class="form-label" for="helpCategoryContent">Content</label>
  <textarea
    id="helpCategoryContent"
    name="content"
    class="form-control"
    rows="12"
  >{{ old('content', $item->content ?? '') }}</textarea>
</div>
<div class="mb-3">
  <label class="form-label" for="helpCategorySort">Sort order</label>
  <input
    type="number"
    id="helpCategorySort"
    name="sort_order"
    class="form-control"
    style="max-width:12rem"
    value="{{ old('sort_order', $item->sort_order ?? 0) }}"
    min="0"
  >
</div>
<div class="form-check mb-0">
  <input
    class="form-check-input"
    type="checkbox"
    name="status"
    value="1"
    id="helpCategoryStatus"
    {{ old('status', $item->status ?? true) ? 'checked' : '' }}
  >
  <label class="form-check-label" for="helpCategoryStatus">Active</label>
</div>
