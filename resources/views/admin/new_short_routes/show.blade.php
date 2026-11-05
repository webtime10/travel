@extends('admin.layouts.layout')
@section('content')
@php
  $showRu = ($lang ?? 'he') === 'ru';
  $code = $item->doc_code ? (string) $item->doc_code : null;
  $hubLang = $showRu ? 'RU' : 'HE';
  $routeText = $showRu && trim((string)($item->route_text_ru ?? '')) !== ''
    ? $item->route_text_ru
    : ($item->route_text ?: $item->toBriefText($showRu));
  $bodyText = $item->bodyForDisplay($showRu);
  $bodyChars = mb_strlen((string)($item->body_text ?? ''));
@endphp
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="{{ route('admin.new-short-routes.index') }}" class="small text-muted d-inline-block mb-1">← к New Short</a>
    <h4 class="mb-1 d-flex flex-wrap align-items-center gap-2">
      <i class="bx bx-file-blank text-info"></i>
      <span>{{ $item->displayTitle($showRu) ?: 'New Short #'.$item->id }}</span>
      @if($code)
        <span class="badge bg-info text-white rounded-pill fs-6 px-3 py-2">Код {{ $code }}</span>
      @endif
      <span class="badge {{ $showRu ? 'bg-primary' : 'bg-secondary' }}">{{ $hubLang }}</span>
    </h4>
    <div class="text-muted small">
      id={{ $item->id }}
      @if($code) · <strong>код={{ $code }}</strong> @endif
      · {{ $item->days_count }} дн.
      · Word {{ number_format($bodyChars) }} симв.
      @if($item->source_filename)
        · {{ \Illuminate\Support\Str::limit($item->source_filename, 50) }}
      @endif
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <div class="btn-group btn-group-sm" role="group" aria-label="Язык контента">
      <a href="{{ route('admin.new-short-routes.show', [$item, 'lang' => 'he']) }}"
         class="btn btn-outline-secondary {{ !$showRu ? 'active' : '' }}">HE</a>
      <a href="{{ route('admin.new-short-routes.show', [$item, 'lang' => 'ru']) }}"
         class="btn btn-outline-secondary {{ $showRu ? 'active' : '' }}">RU</a>
    </div>
    <button type="button" class="btn btn-outline-secondary nsr-rescan-btn"
            data-url="{{ route('admin.new-short-routes.rescan', $item) }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-refresh me-1"></i> Перечитать Word
    </button>
    <button type="button" class="btn btn-outline-primary nsr-translate-ru-btn"
            data-url="{{ route('admin.new-short-routes.translate-ru', $item) }}"
            data-reload="{{ route('admin.new-short-routes.show', [$item, 'lang' => 'ru']) }}">
      <i class="bx bx-globe me-1"></i> Перевод на русский
    </button>
  </div>
</div>

<div class="alert alert-info small mb-3">
  Два слоя: <strong>1) маршрут как есть</strong> (сверху, для работы) ·
  <strong>2) весь текст Word</strong> (снизу, для RAG).
  Без авто-ночёвок и угаданных хабов. HE/RU — контент карточки; <code>he / ru</code> слева — язык меню.
</div>

<div class="nsr-status border rounded p-2 mb-3 bg-light small" style="display:none;"></div>

{{-- 1. Маршрут как есть --}}
<div class="card mb-3 border-primary">
  <div class="card-header d-flex justify-content-between align-items-center py-2">
    <span class="fw-semibold"><i class="bx bx-map-alt me-1"></i> Маршрут (как есть) · {{ $hubLang }}</span>
    <span class="small text-muted">{{ $item->days_count }} дн. · для работы</span>
  </div>
  <div class="card-body">
    <pre class="mb-0 small bg-light border rounded p-3" style="white-space:pre-wrap;max-height:22rem;overflow:auto" dir="auto">{{ $routeText !== '' ? $routeText : '—' }}</pre>
  </div>
</div>

{{-- 2. Полный текст Word → RAG --}}
<div class="card mb-3 border-success">
  <div class="card-header d-flex justify-content-between align-items-center py-2">
    <span class="fw-semibold"><i class="bx bx-file me-1"></i> Текст Word (для RAG) · {{ $hubLang }}</span>
    <span class="small text-muted">{{ number_format($bodyChars) }} симв. · вся страница</span>
  </div>
  <div class="card-body">
    @if($bodyText !== '')
      <pre class="mb-0 small" style="white-space:pre-wrap;max-height:28rem;overflow:auto" dir="auto">{{ $bodyText }}</pre>
    @else
      <div class="text-muted">Текста нет — нажмите «Перечитать Word».</div>
    @endif
  </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const status = document.querySelector('.nsr-status');
  function showStatus(msg, ok) {
    if (!status) return;
    status.style.display = 'block';
    status.className = 'nsr-status border rounded p-2 mb-3 small ' + (ok ? 'bg-label-success' : 'bg-label-danger');
    status.textContent = msg;
  }

  const rescan = document.querySelector('.nsr-rescan-btn');
  if (rescan) {
    rescan.addEventListener('click', async function () {
      if (!confirm('Перечитать docx заново? Маршрут + полный текст обновятся.')) return;
      rescan.disabled = true;
      showStatus('Читаю Word…', true);
      try {
        const res = await fetch(rescan.dataset.url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': rescan.dataset.csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        showStatus(data.message || 'OK', true);
        window.location.reload();
      } catch (e) {
        showStatus(e.message || String(e), false);
        rescan.disabled = false;
      }
    });
  }

  const btn = document.querySelector('.nsr-translate-ru-btn');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    showStatus('Перевод…', true);
    try {
      let guard = 0;
      while (guard++ < 40) {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ limit: 20 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok && !data.translated) throw new Error(data.message || ('HTTP ' + res.status));
        if (data.done || !data.remaining) break;
        if (data.warning && !data.translated) throw new Error(data.message || data.warning);
      }
      window.location.href = btn.dataset.reload;
    } catch (e) {
      showStatus(e.message || String(e), false);
      btn.disabled = false;
    }
  });
})();
</script>
@endsection
