@extends('admin.layouts.layout')
@section('content')
@php
  $ui = ($uiLang ?? 'he') === 'ru' ? 'ru' : 'he';
  $uiRtl = $ui === 'he';
  $showRu = ($lang ?? 'he') === 'ru';
  $hubLang = $showRu ? 'RU' : 'HE';
  $engine = (($engine ?? 'gemini') === 'claude') ? 'claude' : 'gemini';
  $isClaude = $engine === 'claude';
  $code = $item->doc_code ? (string) $item->doc_code : null;
  $hasRu = $item->hasRussian();
  if ($isClaude) {
    $hasRpHe = $item->hasReversePrincipleClaude();
    $rp = $item->reversePrincipleClaudeForDisplay($showRu);
    $hasRpRu = is_array($item->reverse_principle_claude_ru) && ($item->reverse_principle_claude_ru !== []) && empty($item->reverse_principle_claude_ru['skipped']);
  } else {
    $hasRpHe = $item->hasReversePrinciple();
    $rp = $item->reversePrincipleForDisplay($showRu);
    $hasRpRu = is_array($item->reverse_principle_ru) && ($item->reverse_principle_ru !== []) && empty($item->reverse_principle_ru['skipped']);
  }
  $hasRp = is_array($rp) && $rp !== [];
@endphp
<div @if($uiRtl) dir="rtl" @endif>
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="{{ route('admin.ft-short-routes.index') }}" class="small text-muted d-inline-block mb-1 d-print-none">{{ __('ft_short.back') }}</a>
    <h4 class="mb-1 d-flex flex-wrap align-items-center gap-2">
      <i class="bx bx-brain {{ $isClaude ? 'text-warning' : 'text-primary' }}"></i>
      <span dir="auto">{{ $item->displayTitle($showRu) ?: (__('ft_short.title').' #'.$item->id) }}</span>
      @if($code)
        <span class="badge bg-primary text-white rounded-pill fs-6 px-3 py-2">{{ __('ft_short.code', ['code' => $code]) }}</span>
      @endif
      @if($isClaude)
        <span class="badge bg-warning text-dark">Claude</span>
      @endif
      <span class="badge {{ $showRu ? 'bg-primary' : 'bg-secondary' }}">{{ $hubLang }}</span>
      @if(!$hasRu)
        <span class="badge bg-secondary">HE</span>
      @else
        <span class="badge bg-success">RU</span>
      @endif
      @if($hasRpHe)
        <span class="badge {{ $isClaude ? 'bg-warning text-dark' : 'bg-danger' }}">RE HE</span>
      @endif
      @if($hasRpRu)
        <span class="badge {{ $isClaude ? 'bg-warning text-dark' : 'bg-danger' }}">RE RU</span>
      @endif
    </h4>
    <div class="text-muted small">
      {{ __('ft_short.meta', ['id' => $item->id, 'nsr' => $item->new_short_route_id, 'days' => $item->days_count]) }}
      @if($isClaude)
        · {{ __('ft_short.engine_claude_hint') }}
      @endif
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center d-print-none" dir="ltr">
    <div class="btn-group btn-group-sm" role="group">
      <a href="{{ route('admin.ft-short-routes.show', [$item] + array_filter(['lang' => 'he', 'engine' => $isClaude ? 'claude' : null])) }}"
         class="btn btn-outline-secondary {{ !$showRu ? 'active' : '' }}">HE</a>
      <a href="{{ route('admin.ft-short-routes.show', [$item] + array_filter(['lang' => 'ru', 'engine' => $isClaude ? 'claude' : null])) }}"
         class="btn btn-outline-secondary {{ $showRu ? 'active' : '' }}">RU</a>
    </div>
    <div class="btn-group btn-group-sm" role="group">
      <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => $lang]) }}"
         class="btn {{ !$isClaude ? 'btn-secondary' : 'btn-outline-secondary' }}" title="Gemini">Gemini</a>
      <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => $lang, 'engine' => 'claude']) }}"
         class="btn {{ $isClaude ? 'btn-warning' : 'btn-outline-warning' }}" title="Claude">Claude</a>
    </div>
    <div class="btn-group btn-group-sm" role="group">
      <a href="{{ route('admin.ft-short-routes.pdf', [$item, 'he']) }}" target="_blank" rel="noopener"
         class="btn btn-outline-danger" title="{{ __('ft_short.btn_pdf_he_title') }}">
        <i class="bx bxs-file-pdf me-1"></i> PDF HE
      </a>
      <a href="{{ route('admin.ft-short-routes.pdf', [$item, 'ru']) }}" target="_blank" rel="noopener"
         class="btn btn-outline-danger" title="{{ __('ft_short.btn_pdf_ru_title') }}">
        <i class="bx bxs-file-pdf me-1"></i> PDF RU
      </a>
    </div>
    @if($isClaude)
      <button type="button" class="btn btn-warning ft-reverse-one-btn"
              title="{{ __('ft_short.btn_get_re_claude_title') }}"
              data-url="{{ route('admin.ft-short-routes.reverse', $item) }}"
              data-provider="claude"
              data-csrf="{{ csrf_token() }}">
        <i class="bx bx-bot me-1"></i> Claude
      </button>
    @else
      <button type="button" class="btn btn-warning ft-reverse-one-btn"
              title="{{ __('ft_short.btn_get_re_gemini_title') }}"
              data-url="{{ route('admin.ft-short-routes.reverse', $item) }}"
              data-provider="gemini"
              data-csrf="{{ csrf_token() }}">
        <i class="bx bx-search-alt me-1"></i> {{ __('ft_short.btn_get_re') }}
      </button>
    @endif
    <a href="{{ route('admin.new-short-routes.show', $item->new_short_route_id) }}" class="btn btn-outline-info">
      <i class="bx bx-file-blank me-1"></i> {{ __('ft_short.btn_new_short') }}
    </a>
    <button type="button" class="btn btn-outline-primary ft-translate-one-btn"
            data-url="{{ route('admin.ft-short-routes.translate-ru', $item) }}"
            data-engine="{{ $engine }}"
            data-has-re-he="{{ $hasRpHe ? '1' : '0' }}"
            data-reload="{{ route('admin.ft-short-routes.show', [$item] + array_filter(['lang' => 'ru', 'engine' => $isClaude ? 'claude' : null])) }}"
            data-csrf="{{ csrf_token() }}"
            title="{{ __('ft_short.btn_translate_title') }}">
      <i class="bx bx-globe me-1"></i> {{ __('ft_short.btn_translate') }}
    </button>
    @unless($isClaude)
      <button type="button" class="btn btn-outline-secondary ft-rebuild-one-btn"
              data-url="{{ route('admin.ft-short-routes.rebuild', $item) }}"
              data-csrf="{{ csrf_token() }}">
        <i class="bx bx-refresh me-1"></i> {{ __('ft_short.btn_rebuild_he') }}
      </button>
    @endunless
    <form action="{{ route('admin.ft-short-routes.destroy', $item) }}" method="post" class="d-inline"
          onsubmit="return confirm(@json(__('ft_short.confirm_delete_one', ['id' => $item->id])));">
      @csrf
      @method('DELETE')
      <button type="submit" class="btn btn-outline-danger"><i class="bx bx-trash me-1"></i></button>
    </form>
  </div>
</div>

<div class="ft-status border rounded p-2 mb-3 bg-light small d-print-none" style="display:none;"></div>

<div class="ft-reverse-progress border rounded p-3 mb-3 bg-label-warning d-print-none" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="fw-semibold ft-reverse-label">{{ __('ft_short.re_progress') }}</div>
    <div class="fw-bold ft-reverse-pct">…</div>
  </div>
  <div class="progress mb-1" style="height:10px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning ft-reverse-bar" role="progressbar" style="width:35%"></div>
  </div>
  <div class="small ft-reverse-status">{{ __('ft_short.re_sending') }}</div>
</div>

@if($isClaude && !$hasRpHe && !$hasRpRu)
  <div class="alert alert-warning d-print-none">
    {{ __('ft_short.claude_empty') }}
  </div>
@endif

@include('admin.ft_short_routes._document', ['item' => $item, 'lang' => $lang, 'engine' => $engine, 'forPdf' => false])
</div>
@endsection

@section('scripts')
<script>
(function () {
  const i18n = {
    confirmGetRe: @json(__('ft_short.confirm_get_re')),
    reProgress: @json(__('ft_short.re_progress')),
    reSending: @json(__('ft_short.re_sending')),
    reAttempt: @json(__('ft_short.re_attempt', ['n' => '__N__'])),
    reBusy: @json(__('ft_short.re_busy')),
    reDone: @json(__('ft_short.re_done')),
    reDoneReload: @json(__('ft_short.re_done_reload')),
    err503: @json(__('ft_short.err_503')),
    err429: @json(__('ft_short.err_429')),
    progressError: @json(__('ft_short.progress_error')),
    progressDone: @json(__('ft_short.progress_done')),
    jsNeedRe: @json(__('ft_short.js_need_re')),
    jsTranslateProgress: @json(__('ft_short.js_translate_progress')),
    reThenTranslate: @json(__('ft_short.re_then_translate')),
    btnRebuildHe: @json(__('ft_short.btn_rebuild_he')),
  };

  const status = document.querySelector('.ft-status');
  function showStatus(msg, ok) {
    if (!status) return;
    status.style.display = 'block';
    status.className = 'ft-status border rounded p-2 mb-3 small ' + (ok ? 'bg-label-success' : 'bg-label-danger');
    status.textContent = msg;
  }

  function friendlyGeminiError(raw) {
    const s = String(raw || '');
    if (/503|UNAVAILABLE|high demand/i.test(s)) return i18n.err503;
    if (/429|RESOURCE_EXHAUSTED|quota/i.test(s)) return i18n.err429;
    return s.length > 280 ? (s.slice(0, 280) + '…') : s;
  }

  const revBtns = document.querySelectorAll('.ft-reverse-one-btn');
  const revBox = document.querySelector('.ft-reverse-progress');
  const revLabel = document.querySelector('.ft-reverse-label');
  const revPct = document.querySelector('.ft-reverse-pct');
  const revBar = document.querySelector('.ft-reverse-bar');
  const revStatus = document.querySelector('.ft-reverse-status');
  const btn = document.querySelector('.ft-translate-one-btn');
  const pageEngine = btn ? (btn.dataset.engine || 'gemini') : 'gemini';

  function setReverseButtonsDisabled(disabled) {
    revBtns.forEach(b => { b.disabled = disabled; });
  }

  async function runTranslateRu() {
    if (!btn) throw new Error(i18n.jsNeedRe);
    const engine = btn.dataset.engine || 'gemini';
    const hdr = {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': btn.dataset.csrf,
      'X-Requested-With': 'XMLHttpRequest',
    };
    if (revBox) revBox.style.display = '';
    if (revLabel) revLabel.textContent = i18n.jsTranslateProgress;
    if (revBar) revBar.style.width = '55%';
    if (revStatus) revStatus.textContent = i18n.jsTranslateProgress;
    showStatus(i18n.jsTranslateProgress, true);

    let guard = 0;
    let lastRemaining = null;
    while (guard++ < 60) {
      const res = await fetch(btn.dataset.url, {
        method: 'POST',
        headers: hdr,
        body: JSON.stringify({ limit: 10, engine }),
      });
      const data = await res.json().catch(() => ({}));
      const got = Number(data.translated || 0);
      const rem = Number(data.remaining ?? 0);
      if (!res.ok && got === 0) throw new Error(data.message || ('HTTP ' + res.status));
      showStatus('+' + got + ' · ' + rem, true);
      if (revPct) revPct.textContent = rem === 0 ? '100%' : String(rem);
      if (revBar) {
        const total = Number(data.total || 0);
        const doneN = total > 0 ? Math.max(0, total - rem) : 0;
        const pct = total > 0 ? Math.min(99, Math.round(55 + (doneN / total) * 45)) : 70;
        revBar.style.width = (rem === 0 ? 100 : pct) + '%';
      }
      if (revStatus) revStatus.textContent = rem === 0 ? i18n.progressDone : String(rem);
      if (data.done || rem === 0) break;
      if (got === 0) throw new Error(data.message || data.warning || '0');
      if (lastRemaining !== null && rem >= lastRemaining && got === 0) {
        throw new Error('0');
      }
      lastRemaining = rem;
    }
    window.location.href = btn.dataset.reload;
  }

  revBtns.forEach(function (oneBtn) {
    oneBtn.addEventListener('click', async function () {
      if (!confirm(i18n.confirmGetRe)) return;
      const provider = oneBtn.dataset.provider || pageEngine || 'gemini';
      setReverseButtonsDisabled(true);
      if (btn) btn.disabled = true;
      if (revBox) revBox.style.display = '';
      if (revLabel) revLabel.textContent = i18n.reProgress + ' · ' + provider;
      if (revPct) revPct.textContent = '…';
      if (revBar) revBar.style.width = '35%';
      if (revStatus) revStatus.textContent = i18n.reSending;
      showStatus(i18n.reSending + ' (' + provider + ')', true);
      let lastErr = null;
      try {
        for (let attempt = 1; attempt <= 3; attempt++) {
          if (revStatus) revStatus.textContent = i18n.reAttempt.replace('__N__', String(attempt));
          if (revBar) revBar.style.width = (25 + attempt * 20) + '%';
          if (revPct) revPct.textContent = attempt + '/3';
          const res = await fetch(oneBtn.dataset.url, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': oneBtn.dataset.csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ provider }),
          });
          const data = await res.json().catch(() => ({}));
          if (res.ok) {
            if (revLabel) revLabel.textContent = i18n.reThenTranslate;
            if (revStatus) revStatus.textContent = i18n.reThenTranslate;
            showStatus(i18n.reThenTranslate, true);
            await runTranslateRu();
            return;
          }
          lastErr = new Error(data.message || ('HTTP ' + res.status));
          const transient = /503|UNAVAILABLE|high demand|429/i.test(String(lastErr.message));
          if (!transient || attempt === 3) break;
          if (revStatus) revStatus.textContent = i18n.reBusy;
          await new Promise(r => setTimeout(r, 12000));
        }
        throw lastErr || new Error('error');
      } catch (e) {
        const msg = friendlyGeminiError(e.message || String(e));
        if (revLabel) revLabel.textContent = i18n.progressError;
        if (revStatus) revStatus.textContent = msg;
        showStatus(msg, false);
        setReverseButtonsDisabled(false);
        if (btn) btn.disabled = false;
      }
    });
  });

  const rebuild = document.querySelector('.ft-rebuild-one-btn');
  if (rebuild) {
    rebuild.addEventListener('click', async function () {
      rebuild.disabled = true;
      showStatus(i18n.btnRebuildHe + '…', true);
      try {
        const res = await fetch(rebuild.dataset.url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': rebuild.dataset.csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        showStatus(data.message || i18n.progressDone, true);
        window.location.reload();
      } catch (e) {
        showStatus(e.message || String(e), false);
        rebuild.disabled = false;
      }
    });
  }

  if (!btn) return;
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    setReverseButtonsDisabled(true);
    try {
      if (btn.dataset.hasReHe !== '1') {
        throw new Error(i18n.jsNeedRe);
      }
      await runTranslateRu();
    } catch (e) {
      if (revLabel) revLabel.textContent = i18n.progressError;
      if (revStatus) revStatus.textContent = e.message || String(e);
      showStatus(e.message || String(e), false);
      btn.disabled = false;
      setReverseButtonsDisabled(false);
    }
  });
})();
</script>
@endsection
