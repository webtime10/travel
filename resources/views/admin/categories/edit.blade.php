@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">Categories</h4>
  <div class="page-toolbar d-flex flex-wrap gap-1">
    <button
      type="button"
      class="btn btn-icon btn-outline-success category-vector-resume-btn"
      title="Continue from previous (skip already indexed)"
      data-url="{{ route('admin.categories.vector.resume', $category->id) }}"
      data-pending="{{ $pendingVectorCount }}"
    >
      <i class="bx bx-skip-next bx-md"></i>
    </button>
    <button
      type="button"
      class="btn btn-icon btn-outline-primary category-vector-btn"
      title="Reindex all products in this category"
      data-url="{{ route('admin.categories.vector.start', $category->id) }}"
      data-cancel-url="{{ route('admin.categories.vector.cancel', $category->id) }}"
      data-status-url="{{ route('admin.categories.vector.status', $category->id) }}"
      data-csrf="{{ csrf_token() }}"
      data-count="{{ $productCount }}"
    >
      <i class="bx bx-cube-alt bx-md"></i>
    </button>
    <button type="submit" form="categoryForm" class="btn btn-icon btn-primary" title="Save"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.categories.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>

<div class="category-vector-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
    <div class="fw-semibold category-vector-label">Send to vector</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold category-vector-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-secondary category-vector-cancel-btn">Stop</button>
    </div>
  </div>
  <div class="progress mb-2" style="height:1rem;">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary category-vector-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small text-muted category-vector-status">—</div>
  <div class="small text-danger category-vector-error mt-1" style="display:none;"></div>
</div>

<div class="card">
  <div class="card-body">
    @include('admin.partials.errors')
    <p class="small text-muted mb-3">
      Products: <strong>{{ $productCount }}</strong>
      · Indexed: <strong class="text-success">{{ $indexedVectorCount }}</strong>
      · Pending: <strong class="text-warning">{{ $pendingVectorCount }}</strong>
      · <span class="text-success">▶▶</span> continue from previous · <span class="text-primary">cube</span> full reindex
    </p>
    <form id="categoryForm" action="{{ route('admin.categories.update', $category->id) }}" method="POST">
      @csrf @method('PUT')
      @include('admin.categories._form')
    </form>
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const btn = document.querySelector('.category-vector-btn');
  const resumeBtn = document.querySelector('.category-vector-resume-btn');
  const box = document.querySelector('.category-vector-progress');
  const label = document.querySelector('.category-vector-label');
  const pctEl = document.querySelector('.category-vector-pct');
  const bar = document.querySelector('.category-vector-bar');
  const statusEl = document.querySelector('.category-vector-status');
  const errEl = document.querySelector('.category-vector-error');
  const cancelBtn = document.querySelector('.category-vector-cancel-btn');
  if (!btn || !box) return;

  let timer = null;

  function setButtonsDisabled(disabled) {
    btn.disabled = disabled;
    if (resumeBtn) resumeBtn.disabled = disabled;
  }

  function setProgress(pct, detail, error) {
    const p = Math.max(0, Math.min(100, Number(pct) || 0));
    bar.style.width = p + '%';
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

  async function fetchStatus() {
    const res = await fetch(btn.dataset.statusUrl, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json().catch(() => ({}));
  }

  function apply(s) {
    const total = Number(s.total || 0);
    const done = Number(s.done || 0) + Number(s.failed || 0);
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const cur = s.current_model ? (' · model ' + s.current_model) : '';
    const extra = (s.indexed != null)
      ? (' · DB indexed ' + s.indexed + '/' + (s.product_total || '?'))
      : '';
    setProgress(
      pct,
      'Queue: ' + done + ' / ' + total + cur + ' · chunks ' + (s.chunks || 0) + extra,
      s.last_error || null
    );
    if (s.state === 'running') {
      box.style.display = 'block';
      setButtonsDisabled(true);
      bar.classList.add('progress-bar-animated');
    } else if (s.state === 'done' || s.state === 'cancelled' || s.state === 'idle') {
      setButtonsDisabled(false);
      bar.classList.remove('progress-bar-animated');
      if (timer && s.state !== 'idle') { clearInterval(timer); timer = null; }
      if (s.state === 'done') {
        setProgress(100, 'Done: ' + s.done + ' OK, ' + s.failed + ' failed, chunks ' + s.chunks, s.last_error || null);
      }
    }
  }

  function startPoll() {
    if (timer) clearInterval(timer);
    fetchStatus().then(apply);
    timer = setInterval(function () { fetchStatus().then(apply); }, 2500);
  }

  async function post(url) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': btn.dataset.csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: '{}',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok && res.status !== 409) throw new Error(data.message || ('HTTP ' + res.status));
    return data;
  }

  btn.addEventListener('click', async function () {
    if (btn.disabled) return;
    const n = Number(btn.dataset.count || 0);
    if (!confirm('FULL reindex of ' + n + ' products? Already indexed will be overwritten.')) return;
    try {
      const data = await post(btn.dataset.url);
      alert(data.message || 'Started');
      box.style.display = 'block';
      label.textContent = 'Full reindex';
      startPoll();
    } catch (e) {
      alert(e.message || String(e));
    }
  });

  if (resumeBtn) {
    resumeBtn.addEventListener('click', async function () {
      if (resumeBtn.disabled) return;
      const pending = Number(resumeBtn.dataset.pending || 0);
      if (!confirm('Continue from previous? Only ' + pending + ' not-yet-indexed products will be processed.')) return;
      try {
        const data = await post(resumeBtn.dataset.url);
        alert(data.message || 'Started');
        if (data.started === 0) return;
        box.style.display = 'block';
        label.textContent = 'Continue from previous';
        startPoll();
      } catch (e) {
        alert(e.message || String(e));
      }
    });
  }

  cancelBtn.addEventListener('click', async function () {
    if (!confirm('Stop vector indexing?')) return;
    try {
      await post(btn.dataset.cancelUrl);
      await fetchStatus().then(apply);
    } catch (e) {
      alert(e.message || String(e));
    }
  });

  fetchStatus().then(function (s) {
    if (s && s.state === 'running') startPoll();
  });
})();
</script>
@endsection
