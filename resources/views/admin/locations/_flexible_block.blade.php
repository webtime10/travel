@php
  $type = $type ?? 'text';
  $index = $index ?? 0;
  $data = is_array($data ?? null) ? $data : [];
  $layouts = $layouts ?? \App\Models\Location::FLEXIBLE_LAYOUTS;
  $label = $layouts[$type] ?? $type;
  $collapseId = 'flex-body-'.$index.'-'.preg_replace('/\W+/', '', (string) $type);
  $prefix = 'flexible_blocks['.$index.']';
@endphp
<div class="border rounded bg-label-secondary bg-opacity-10" data-flexible-block data-type="{{ $type }}">
  <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
    <button type="button" class="btn btn-sm btn-icon btn-text-secondary" data-flexible-handle title="Перетащить" style="cursor:grab">
      <i class="bx bx-menu"></i>
    </button>
    <span class="badge bg-label-primary" data-flexible-index>{{ is_numeric($index) ? ((int) $index + 1) : '' }}</span>
    <strong class="me-auto">{{ $label }}</strong>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}">
      <i class="bx bx-chevron-down"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-danger" data-flexible-remove title="Удалить">
      <i class="bx bx-trash"></i>
    </button>
  </div>
  <div class="collapse show" id="{{ $collapseId }}">
    <div class="p-3">
      <input type="hidden" name="{{ $prefix }}[type]" value="{{ $type }}">
      @if($type === 'text')
        <label class="form-label">Текст</label>
        <textarea name="{{ $prefix }}[data][content]" class="form-control" rows="4" dir="auto">{{ $data['content'] ?? '' }}</textarea>
      @elseif($type === 'link')
        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label">Подпись</label>
            <input type="text" name="{{ $prefix }}[data][label]" class="form-control" dir="auto" value="{{ $data['label'] ?? '' }}">
          </div>
          <div class="col-md-7">
            <label class="form-label">URL</label>
            <input type="url" name="{{ $prefix }}[data][url]" class="form-control" dir="ltr" placeholder="https://" value="{{ $data['url'] ?? '' }}">
          </div>
        </div>
      @elseif($type === 'photo')
        <div class="mb-2">
          <label class="form-label">URL фотографии</label>
          <input type="url" name="{{ $prefix }}[data][url]" class="form-control" dir="ltr" placeholder="https://" value="{{ $data['url'] ?? '' }}">
        </div>
        <div>
          <label class="form-label">Подпись</label>
          <input type="text" name="{{ $prefix }}[data][caption]" class="form-control" dir="auto" value="{{ $data['caption'] ?? '' }}">
        </div>
      @elseif($type === 'ad')
        <div class="mb-2">
          <label class="form-label">Заголовок</label>
          <input type="text" name="{{ $prefix }}[data][title]" class="form-control" dir="auto" value="{{ $data['title'] ?? '' }}">
        </div>
        <div class="mb-2">
          <label class="form-label">Текст рекламы</label>
          <textarea name="{{ $prefix }}[data][text]" class="form-control" rows="3" dir="auto">{{ $data['text'] ?? '' }}</textarea>
        </div>
        <div>
          <label class="form-label">Ссылка (опционально)</label>
          <input type="url" name="{{ $prefix }}[data][url]" class="form-control" dir="ltr" placeholder="https://" value="{{ $data['url'] ?? '' }}">
        </div>
      @elseif($type === 'map_screenshot')
        <div class="mb-2">
          <label class="form-label">URL скриншота карты</label>
          <input type="url" name="{{ $prefix }}[data][url]" class="form-control" dir="ltr" placeholder="https://" value="{{ $data['url'] ?? '' }}">
        </div>
        <div>
          <label class="form-label">Подпись</label>
          <input type="text" name="{{ $prefix }}[data][caption]" class="form-control" dir="auto" value="{{ $data['caption'] ?? '' }}">
        </div>
      @endif
    </div>
  </div>
</div>
