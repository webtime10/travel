@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0 d-flex align-items-center gap-2">
    Products
    <span class="badge bg-label-primary rounded-pill">{{ $products->total() }}</span>
  </h4>
  <div class="page-toolbar d-flex flex-wrap gap-1">
    <button
      type="button"
      class="btn btn-icon btn-outline-secondary products-import-docx-btn"
      title="Импорт новых DOCX (иврит)"
      data-url="{{ route('admin.products.pipeline.import.start') }}"
      data-cancel-url="{{ route('admin.products.pipeline.import.cancel') }}"
      data-csrf="{{ csrf_token() }}"
      data-pending="{{ $pendingDocx }}"
    >
      <i class="bx bxs-file-doc bx-md"></i>
    </button>
    <button
      type="button"
      class="btn btn-icon btn-outline-primary products-translate-all-btn"
      title="Перевести все продукты (HE → RU)"
      data-url="{{ route('admin.products.pipeline.translate.start') }}"
      data-cancel-url="{{ route('admin.products.pipeline.translate.cancel') }}"
      data-csrf="{{ csrf_token() }}"
      data-needs="{{ $needsTranslation }}"
    >
      <span class="d-inline-flex align-items-center justify-content-center" style="width:1.5rem;height:1.5rem;font-weight:700;font-size:.85rem;line-height:1;" aria-hidden="true">A文</span>
    </button>
    <button type="submit" form="productsBulkDeleteForm" class="btn btn-icon btn-outline-danger" title="Delete" onclick="return confirm('Delete selected?');"><i class="bx bx-trash bx-md"></i></button>
    <a href="{{ route('admin.products.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>

<div class="products-pipeline-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold products-pipeline-label">—</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold products-pipeline-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary products-pipeline-cancel-btn">Стоп</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary products-pipeline-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
  </div>
  <div class="small text-muted products-pipeline-status">—</div>
  <div class="small text-danger products-pipeline-error mt-1" style="display:none;"></div>
</div>

<form id="productsBulkDeleteForm" method="post" action="{{ route('admin.products.bulk-delete') }}">@csrf</form>

<form method="get" action="{{ route('admin.products.index') }}" class="card mb-3">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md">
        <label class="form-label mb-1" for="productsModelsFilter">Поиск по model</label>
        <input
          type="text"
          class="form-control"
          id="productsModelsFilter"
          name="models"
          value="{{ $modelsInput ?? '' }}"
          placeholder="33 или 33,4,18"
          autocomplete="off"
        >
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary">Найти</button>
        @if(!empty($modelsFilter))
          <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary">Сброс</a>
        @endif
      </div>
    </div>
    @if(!empty($modelsFilter))
      <div class="small text-muted mt-2">Фильтр: {{ implode(', ', $modelsFilter) }} · найдено {{ $products->total() }}</div>
    @endif
  </div>
</form>

<div class="card">
  <div class="table-responsive text-nowrap">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width:40px"><input type="checkbox" class="form-check-input" id="selectAll"></th>
          <th>Model</th>
          <th style="max-width:280px">Name</th>
          <th>Regions</th>
          <th>Sort</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="table-border-bottom-0">
        @forelse($products as $item)
          @php $d = $item->descriptions->first(); @endphp
          <tr>
            <td><input type="checkbox" name="selected[]" value="{{ $item->id }}" class="form-check-input row-check" form="productsBulkDeleteForm"></td>
            <td>{{ $item->model ?? '—' }}</td>
            <td class="text-truncate" style="max-width:280px" dir="auto" title="{{ $d->name ?? '' }}">{{ $d->name ?? '—' }}</td>
            <td>
              @foreach($item->categories as $cat)
                @php $cd = $defaultLanguage ? $cat->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                <span class="badge bg-label-primary">{{ $cd->name ?? '#'.$cat->id }}</span>
              @endforeach
            </td>
            <td>{{ $item->sort_order }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.products.edit', $item->id) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.products.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Delete"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($products->total() > 0)
  <div class="card-footer">
    <div class="row align-items-center g-2">
      <div class="col-12 col-md-auto text-center text-md-start">
        <span class="text-muted small">
          {{ $products->firstItem() }}–{{ $products->lastItem() }} / {{ $products->total() }}
          <span class="ms-1">(по 50)</span>
        </span>
      </div>
      <div class="col-12 col-md">
        @if($products->hasPages())
          {{ $products->withQueryString()->onEachSide(2)->links() }}
        @endif
      </div>
    </div>
  </div>
  @endif
</div>
@endsection

@section('scripts')
<script>
  $('#selectAll').on('change', function () { $('.row-check').prop('checked', this.checked); });

  (function () {
    const statusUrl = @json(route('admin.products.pipeline.status'));
    const csrf = @json(csrf_token());
    const importBtn = document.querySelector('.products-import-docx-btn');
    const translateBtn = document.querySelector('.products-translate-all-btn');
    const box = document.querySelector('.products-pipeline-progress');
    const label = document.querySelector('.products-pipeline-label');
    const pctEl = document.querySelector('.products-pipeline-pct');
    const bar = document.querySelector('.products-pipeline-bar');
    const statusEl = document.querySelector('.products-pipeline-status');
    const errEl = document.querySelector('.products-pipeline-error');
    const cancelBtn = document.querySelector('.products-pipeline-cancel-btn');
    if (!box || !importBtn || !translateBtn) return;

    let pollTimer = null;
    let activeMode = null;
    let cancelUrl = null;

    function setProgress(pct, detail, error) {
      const p = Math.max(0, Math.min(100, Number(pct) || 0));
      bar.style.width = p + '%';
      bar.setAttribute('aria-valuenow', String(p));
      pctEl.textContent = p + '%';
      statusEl.textContent = detail || '';
      if (error) {
        errEl.style.display = 'block';
        errEl.textContent = error;
      } else {
        errEl.style.display = 'none';
        errEl.textContent = '';
      }
    }

    function stopPoll() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    async function fetchStatus() {
      const res = await fetch(statusUrl, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      return res.json().catch(() => ({}));
    }

    function applyImport(s) {
      const total = Number(s.total || 0);
      const done = Number(s.done || 0) + Number(s.failed || 0);
      const pct = total > 0 ? Math.round((done / total) * 100) : 0;
      label.textContent = 'Импорт DOCX (иврит)';
      const cur = s.current ? (' · ' + s.current) : '';
      setProgress(pct, 'Файлов: ' + done + ' / ' + total + cur + (s.pending_files != null ? (' · в очереди doc: ' + s.pending_files) : ''), s.last_error || null);
      if (s.state === 'running') {
        importBtn.disabled = true;
        translateBtn.disabled = true;
        box.style.display = 'block';
        bar.classList.add('progress-bar-animated');
        activeMode = 'import';
        cancelUrl = importBtn.dataset.cancelUrl;
      } else if (activeMode === 'import' && (s.state === 'done' || s.state === 'cancelled')) {
        stopPoll();
        bar.classList.remove('progress-bar-animated');
        importBtn.disabled = false;
        translateBtn.disabled = false;
        if (s.state === 'done') {
          setProgress(100, 'Импорт завершён: ' + s.done + ' OK, ' + s.failed + ' ошибок');
        }
        activeMode = null;
      }
    }

    function applyTranslate(s) {
      const total = Number(s.total || 0);
      const done = Number(s.products_done || 0);
      const pct = total > 0 ? Math.round((done / total) * 100) : 0;
      label.textContent = 'Перевод продуктов (RU)';
      const model = s.current_model ? (' · model ' + s.current_model) : '';
      const blocks = s.blocks_total > 0
        ? (' · блоки ~' + s.blocks_done + '/' + s.blocks_total)
        : '';
      setProgress(pct, 'Продукты: ' + done + ' / ' + total + model + blocks, s.last_error || null);
      if (s.state === 'running') {
        importBtn.disabled = true;
        translateBtn.disabled = true;
        box.style.display = 'block';
        bar.classList.add('progress-bar-animated');
        activeMode = 'translate';
        cancelUrl = translateBtn.dataset.cancelUrl;
      } else if (activeMode === 'translate' && (s.state === 'done' || s.state === 'cancelled')) {
        stopPoll();
        bar.classList.remove('progress-bar-animated');
        importBtn.disabled = false;
        translateBtn.disabled = false;
        if (s.state === 'done') {
          setProgress(100, 'Перевод завершён: ' + done + ' продуктов');
        }
        activeMode = null;
      }
    }

    async function pollOnce() {
      const data = await fetchStatus();
      if (data.import) {
        if (data.import.state === 'running') applyImport(data.import);
        else if (activeMode === 'import') applyImport(data.import);
      }
      if (data.translate) {
        if (data.translate.state === 'running') applyTranslate(data.translate);
        else if (activeMode === 'translate') applyTranslate(data.translate);
      }
    }

    function startPoll() {
      stopPoll();
      pollOnce();
      pollTimer = setInterval(pollOnce, 2500);
    }

    async function postJson(url) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: '{}',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok && res.status !== 409) {
        throw new Error(data.message || ('HTTP ' + res.status));
      }
      return data;
    }

    importBtn.addEventListener('click', async function () {
      if (importBtn.disabled) return;
      const pending = Number(importBtn.dataset.pending || 0);
      const msg = pending > 0
        ? ('Импортировать ' + pending + ' новых DOCX в иврит? Нужен worker: php artisan queue:work')
        : 'Проверить doc/ и импортировать недостающие файлы?';
      if (!confirm(msg)) return;
      try {
        const data = await postJson(importBtn.dataset.url);
        alert(data.message || 'Запущено');
        startPoll();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    translateBtn.addEventListener('click', async function () {
      if (translateBtn.disabled) return;
      const needs = Number(translateBtn.dataset.needs || 0);
      const msg = needs > 0
        ? ('Перевести ' + needs + ' продуктов (название + JSON)? Пауза между запросами Gemini. Worker: php artisan queue:work')
        : 'Запустить перевод всех продуктов без полного RU?';
      if (!confirm(msg)) return;
      try {
        const data = await postJson(translateBtn.dataset.url);
        alert(data.message || 'Запущено');
        startPoll();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    cancelBtn.addEventListener('click', async function () {
      if (!cancelUrl || !confirm('Остановить текущую задачу?')) return;
      try {
        await postJson(cancelUrl);
        await pollOnce();
      } catch (e) {
        alert(e.message || String(e));
      }
    });

    fetchStatus().then(function (data) {
      if (data.import && data.import.pending_files != null && importBtn) {
        importBtn.dataset.pending = String(data.import.pending_files);
      }
      if (data.import && data.import.state === 'running') {
        activeMode = 'import';
        startPoll();
      } else if (data.translate && data.translate.state === 'running') {
        activeMode = 'translate';
        startPoll();
      }
    });
  })();
</script>
@endsection
