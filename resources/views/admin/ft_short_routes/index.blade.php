@extends('admin.layouts.layout')
@section('content')
@php
  $ui = ($uiLang ?? 'he') === 'ru' ? 'ru' : 'he';
  $rtl = $ui === 'he';
  $pending = (int) ($pending ?? 0);
  $pendingRu = (int) ($pendingRu ?? 0);
  $nsrTotal = (int) ($nsrTotal ?? 0);
  $reverseReady = (int) ($reverseReady ?? 0);
@endphp
<div @if($rtl) dir="rtl" @endif>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bx bx-brain text-primary me-1"></i>
    {{ __('ft_short.title') }}
    <span class="text-muted fw-normal small">· {{ __('ft_short.title_sub') }}</span>
    <span class="badge bg-label-primary rounded-pill">{{ $total }}</span>
    @if($pending > 0)
      <span class="badge bg-label-warning rounded-pill">{{ __('ft_short.badge_build', ['n' => $pending]) }}</span>
    @endif
    @if($pendingRu > 0)
      <span class="badge bg-label-info rounded-pill">{{ __('ft_short.badge_no_ru', ['n' => $pendingRu]) }}</span>
    @endif
    <span class="badge bg-label-success rounded-pill">{{ __('ft_short.badge_re', ['n' => $reverseReady]) }}</span>
  </h4>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-primary ft-build-btn"
            title="{{ __('ft_short.btn_build_title') }}"
            data-url="{{ route('admin.ft-short-routes.build-batch') }}"
            data-csrf="{{ csrf_token() }}"
            data-pending="{{ $pending }}">
      <i class="bx bx-magic-wand me-1"></i> {{ __('ft_short.btn_build') }}
      @if($pending > 0)
        <span class="badge bg-white text-primary ms-1">{{ $pending }}</span>
      @endif
    </button>
    <button type="button" class="btn btn-outline-primary ft-translate-batch-btn"
            title="{{ __('ft_short.btn_translate_title') }}"
            data-url="{{ route('admin.ft-short-routes.translate-ru-batch') }}"
            data-csrf="{{ csrf_token() }}"
            data-pending="{{ $pendingRu }}">
      <i class="bx bx-globe me-1"></i> {{ __('ft_short.btn_translate') }}
      @if($pendingRu > 0)
        <span class="badge bg-label-dark ms-1">{{ $pendingRu }}</span>
      @endif
    </button>
    <div class="btn-group" role="group">
      <button type="button" class="btn btn-success ft-reverse-direct-btn" disabled
              title="{{ __('ft_short.btn_re_direct_gemini_title') }}"
              data-provider="gemini"
              data-csrf="{{ csrf_token() }}">
        <i class="bx bx-play-circle me-1"></i> Gemini
        <span class="badge bg-dark ms-1 ft-selected-count">0</span>
      </button>
      <button type="button" class="btn btn-warning ft-reverse-batch-btn" disabled
              title="{{ __('ft_short.btn_re_batch_gemini_title') }}"
              data-provider="gemini"
              data-start-url="{{ route('admin.ft-short-routes.reverse-pipeline.start') }}"
              data-status-url="{{ route('admin.ft-short-routes.reverse-pipeline.status', ['provider' => 'gemini']) }}"
              data-cancel-url="{{ route('admin.ft-short-routes.reverse-pipeline.cancel') }}"
              data-csrf="{{ csrf_token() }}">
        <i class="bx bx-search-alt me-1"></i> Gemini · queue
        <span class="badge bg-dark ms-1 ft-selected-count">0</span>
      </button>
      <button type="button" class="btn btn-outline-dark ft-reverse-batch-btn" disabled
              title="{{ __('ft_short.btn_re_batch_claude_title') }}"
              data-provider="claude"
              data-start-url="{{ route('admin.ft-short-routes.reverse-pipeline.start') }}"
              data-status-url="{{ route('admin.ft-short-routes.reverse-pipeline.status', ['provider' => 'claude']) }}"
              data-cancel-url="{{ route('admin.ft-short-routes.reverse-pipeline.cancel') }}"
              data-csrf="{{ csrf_token() }}">
        <i class="bx bx-bot me-1"></i> Claude · queue
        <span class="badge bg-dark ms-1 ft-selected-count">0</span>
      </button>
    </div>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card mb-3 border-primary">
  <div class="card-body py-3">
    <div class="fw-semibold mb-2"><i class="bx bx-info-circle me-1"></i> {{ __('ft_short.info_title') }}</div>
    <div class="small mb-0">
      {{ __('ft_short.info_body', ['nsr' => $nsrTotal, 'ft' => $total, 're' => $reverseReady, 'noru' => $pendingRu]) }}
    </div>
  </div>
</div>

{{-- Промпт для ручной работы в чате Gemini — гармошка, по умолчанию свёрнута --}}
<div class="card mb-3 border-info">
  <div class="card-header py-2">
    <a class="d-flex justify-content-between align-items-center text-decoration-none text-body"
       data-bs-toggle="collapse" href="#ftManualPromptCollapse" role="button"
       aria-expanded="false" aria-controls="ftManualPromptCollapse">
      <span class="fw-semibold">
        <i class="bx bx-chevron-down me-1"></i>
        {{ __('ft_short.manual_prompt_title') }}
      </span>
      <span class="small text-muted">{{ __('ft_short.manual_prompt_toggle') }}</span>
    </a>
  </div>
  <div id="ftManualPromptCollapse" class="collapse">
    <div class="card-body pt-0">
      <div class="small text-muted mb-2">{{ __('ft_short.manual_prompt_hint') }}</div>
      <textarea id="ftManualPromptInput" class="form-control font-monospace" rows="12" spellcheck="false" dir="ltr">{{ $manualPrompt ?? '' }}</textarea>
      <div class="d-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top">
        <span id="ftManualPromptStatus" class="small text-muted me-auto"></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="ftManualPromptCopy">
          <i class="bx bx-copy me-1"></i> {{ __('ft_short.btn_copy') }}
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="ftManualPromptReset">{{ __('ft_short.btn_reset') }}</button>
        <button type="button" class="btn btn-sm btn-primary" id="ftManualPromptSave" title="{{ __('ft_short.btn_save') }}">
          <i class="bx bx-save me-1"></i> {{ __('ft_short.btn_save') }}
        </button>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">{{ __('ft_short.prompt_title') }}</div>
      <div class="small text-muted">
        {{ __('ft_short.prompt_hint') }}
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span id="ftReversePromptStatus" class="small text-muted"></span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="ftReversePromptReset">{{ __('ft_short.btn_reset') }}</button>
      <button type="button" class="btn btn-sm btn-primary" id="ftReversePromptSave">
        <i class="bx bx-save me-1"></i> {{ __('ft_short.btn_save') }}
      </button>
    </div>
  </div>
  <div class="card-body pt-0">
    <textarea id="ftReversePromptInput" class="form-control font-monospace" rows="18" spellcheck="false" dir="ltr">{{ $reversePrompt ?? '' }}</textarea>
  </div>
</div>

<div class="ft-build-progress border rounded p-3 mb-3 bg-light" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="fw-semibold ft-build-label">{{ __('ft_short.progress_build') }}</div>
    <div class="fw-bold ft-build-pct">0%</div>
  </div>
  <div class="progress mb-1" style="height:10px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary ft-build-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small ft-build-status">{{ __('ft_short.progress_prep') }}</div>
</div>

<div class="ft-translate-progress border rounded p-3 mb-3 bg-label-primary" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="fw-semibold ft-translate-label">{{ __('ft_short.progress_translate') }}</div>
    <div class="fw-bold ft-translate-pct">0%</div>
  </div>
  <div class="progress mb-1" style="height:10px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary ft-translate-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small ft-translate-status">{{ __('ft_short.progress_prep') }}</div>
</div>

<div class="ft-reverse-batch-progress border rounded p-3 mb-3 bg-label-warning" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
    <div class="fw-semibold ft-re-batch-label">{{ __('ft_short.progress_re_batch') }}</div>
    <div class="d-flex align-items-center gap-2">
      <div class="fw-bold ft-re-batch-pct">0%</div>
      <button type="button" class="btn btn-sm btn-outline-danger ft-re-batch-cancel">{{ __('ft_short.pipeline_cancel') }}</button>
    </div>
  </div>
  <div class="progress mb-1" style="height:10px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning ft-re-batch-bar" role="progressbar" style="width:0%"></div>
  </div>
  <div class="small ft-re-batch-status">{{ __('ft_short.progress_prep') }}</div>
  <div class="small text-danger ft-re-batch-error mt-1" style="display:none;"></div>
  <div class="small text-muted mt-1">{{ __('ft_short.pipeline_need_worker') }}</div>
</div>

<div class="alert alert-secondary py-2 mb-3 ft-selection-bar" style="display:none;">
  <span class="ft-selection-text">{{ __('ft_short.selected_zero') }}</span>
  <button type="button" class="btn btn-sm btn-outline-secondary ms-2 ft-select-none">{{ __('ft_short.btn_select_none') }}</button>
</div>

<form method="get" action="{{ route('admin.ft-short-routes.index') }}" class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div class="flex-grow-1" style="min-width:12rem">
      <label class="form-label small mb-1">{{ __('ft_short.search') }}</label>
      <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="{{ __('ft_short.search_ph') }}">
    </div>
    <button class="btn btn-primary">{{ __('ft_short.btn_find') }}</button>
    <a href="{{ route('admin.ft-short-routes.index') }}" class="btn btn-outline-secondary">{{ __('ft_short.btn_clear') }}</a>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th style="width:2.5rem" class="text-center">
            <input type="checkbox" class="form-check-input ft-select-all" title="{{ __('ft_short.btn_select_page') }}">
          </th>
          <th style="width:4rem">{{ __('ft_short.col_num') }}</th>
          <th style="width:5rem">{{ __('ft_short.col_code') }}</th>
          <th>{{ __('ft_short.col_title') }}</th>
          <th style="width:5rem">{{ __('ft_short.col_days') }}</th>
          <th>{{ __('ft_short.col_route_he') }}</th>
          <th>{{ __('ft_short.col_route_ru') }}</th>
          <th style="width:6.5rem">{{ __('ft_short.col_status') }}</th>
          <th style="width:13rem"></th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $item)
          @php
            $previewHe = \Illuminate\Support\Str::limit($item->routeTextForDisplay(false), 140);
            $previewRu = \Illuminate\Support\Str::limit($item->routeTextForDisplay(true), 140);
            $hasRu = $item->hasRussian();
            $hasRe = $item->hasReversePrinciple();
            $hasReRu = is_array($item->reverse_principle_ru) && $item->reverse_principle_ru !== [] && empty($item->reverse_principle_ru['skipped']);
            $hasReClaude = $item->hasReversePrincipleClaude();
            $hasReClaudeRu = is_array($item->reverse_principle_claude_ru) && $item->reverse_principle_claude_ru !== [] && empty($item->reverse_principle_claude_ru['skipped']);
          @endphp
          <tr>
            <td class="text-center">
              <input type="checkbox" class="form-check-input ft-row-check"
                     value="{{ $item->id }}"
                     data-reverse-url="{{ route('admin.ft-short-routes.reverse', $item) }}"
                     data-translate-url="{{ route('admin.ft-short-routes.translate-ru', $item) }}">
            </td>
            <td class="text-muted">{{ ($items->firstItem() ?? 1) + $loop->index }}</td>
            <td>
              @if($item->doc_code)
                <span class="badge bg-label-info">{{ $item->doc_code }}</span>
              @else
                —
              @endif
            </td>
            <td>
              <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => $hasRu ? 'ru' : 'he']) }}" class="fw-medium text-heading" dir="auto">
                {{ $item->displayTitle($hasRu) ?: ($item->displayTitle(false) ?: 'FT #'.$item->id) }}
              </a>
              @if($hasRu && $item->displayTitle(false) !== $item->displayTitle(true))
                <div class="small text-muted" dir="auto">HE: {{ $item->displayTitle(false) }}</div>
              @endif
              <div class="small text-muted">NSR #{{ $item->new_short_route_id }}</div>
            </td>
            <td>{{ $item->days_count }}</td>
            <td class="small" style="white-space:pre-wrap;max-width:18rem" dir="auto">{{ $previewHe !== '' ? $previewHe : '—' }}</td>
            <td class="small" style="white-space:pre-wrap;max-width:18rem" dir="auto">
              @if($hasRu && $previewRu !== '')
                {{ $previewRu }}
              @else
                <span class="text-muted">—</span>
              @endif
            </td>
            <td class="small">
              <div class="d-flex flex-wrap gap-1 mb-1" title="{{ __('ft_short.status_route') }}">
                @if($hasRu)
                  <span class="badge bg-success">RU</span>
                @else
                  <span class="badge bg-secondary">HE</span>
                @endif
              </div>
              @if($hasRe || $hasReRu)
                <div class="d-flex flex-column gap-1" title="{{ __('ft_short.status_re') }}">
                  @if($hasRe)
                    <span class="badge bg-danger">RE HE</span>
                  @endif
                  @if($hasReRu)
                    <span class="badge bg-danger">RE RU</span>
                  @endif
                </div>
              @endif
              @if($hasReClaude || $hasReClaudeRu)
                <div class="d-flex flex-column gap-1 mt-1" title="Claude RE">
                  @if($hasReClaude)
                    <span class="badge bg-warning text-dark">C HE</span>
                  @endif
                  @if($hasReClaudeRu)
                    <span class="badge bg-warning text-dark">C RU</span>
                  @endif
                </div>
              @endif
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'he']) }}" class="btn btn-icon btn-sm btn-outline-secondary" title="{{ __('ft_short.btn_open_he') }}">
                <i class="bx bx-show"></i>
              </a>
              <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'he', 'engine' => 'claude']) }}" class="btn btn-icon btn-sm btn-warning" title="{{ __('ft_short.btn_open_claude') }}">
                <i class="bx bx-show"></i>
              </a>
              @if($hasRe || $hasReRu)
                <button type="button" class="btn btn-icon btn-sm btn-outline-secondary ft-clear-re-btn"
                        title="{{ __('ft_short.btn_clear_re_title') }}"
                        data-url="{{ route('admin.ft-short-routes.clear-reverse', $item) }}"
                        data-provider="gemini"
                        data-csrf="{{ csrf_token() }}">
                  <i class="bx bx-eraser"></i>
                </button>
              @endif
              @if($hasReClaude || $hasReClaudeRu)
                <button type="button" class="btn btn-icon btn-sm btn-outline-warning ft-clear-re-btn"
                        title="{{ __('ft_short.btn_clear_re_claude_title') }}"
                        data-url="{{ route('admin.ft-short-routes.clear-reverse', $item) }}"
                        data-provider="claude"
                        data-csrf="{{ csrf_token() }}">
                  <i class="bx bx-eraser"></i>
                </button>
              @endif
              <form action="{{ route('admin.ft-short-routes.destroy', $item) }}" method="post" class="d-inline"
                    onsubmit="return confirm(@json(__('ft_short.confirm_delete', ['id' => $item->id])));">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="{{ __('ft_short.btn_delete') }}">
                  <i class="bx bx-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              {{ __('ft_short.empty') }}
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($items->hasPages())
    <div class="card-footer">{{ $items->links() }}</div>
  @endif
</div>
</div>
@endsection

@section('scripts')
<script>
(function () {
  const csrf = '{{ csrf_token() }}';
  const uiLang = @json($ui);
  const i18n = {
    progressBuild: @json(__('ft_short.progress_build')),
    progressTranslate: @json(__('ft_short.progress_translate')),
    progressPrep: @json(__('ft_short.progress_prep')),
    progressDone: @json(__('ft_short.progress_done')),
    progressError: @json(__('ft_short.progress_error')),
    progressBatch: @json(__('ft_short.progress_batch')),
    progressReBatch: @json(__('ft_short.progress_re_batch')),
    promptBuildHowMany: @json(__('ft_short.prompt_build_how_many')),
    promptTranslateHowMany: @json(__('ft_short.prompt_translate_how_many')),
    confirmTranslateBatch: @json(__('ft_short.confirm_translate_batch', ['n' => '__N__'])),
    alertNeedRe: @json(__('ft_short.alert_need_re_first')),
    confirmTranslateRow: @json(__('ft_short.confirm_translate_row')),
    confirmReBatch: @json(__('ft_short.confirm_re_batch')),
    confirmReDirect: @json(__('ft_short.confirm_re_direct')),
    selectedN: @json(__('ft_short.selected_n')),
    reBatchItem: @json(__('ft_short.re_batch_item')),
    reBatchTranslate: @json(__('ft_short.re_batch_translate')),
    alertSelectFirst: @json(__('ft_short.alert_select_first')),
    promptSaving: @json(__('ft_short.prompt_saving')),
    promptSaved: @json(__('ft_short.prompt_saved')),
    promptResetConfirm: @json(__('ft_short.prompt_reset_confirm')),
    promptResetOk: @json(__('ft_short.prompt_reset_ok')),
    pipelineNeedWorker: @json(__('ft_short.pipeline_need_worker')),
    pipelineDone: @json(__('ft_short.pipeline_done')),
    pipelinePaused: @json(__('ft_short.pipeline_paused')),
    pipelineCancelled: @json(__('ft_short.pipeline_cancelled')),
    confirmClearRe: @json(__('ft_short.confirm_clear_re')),
  };

  const progress = document.querySelector('.ft-build-progress');
  const bar = document.querySelector('.ft-build-bar');
  const pct = document.querySelector('.ft-build-pct');
  const status = document.querySelector('.ft-build-status');
  const label = document.querySelector('.ft-build-label');

  function setProgress(done, total, msg) {
    if (!progress) return;
    progress.style.display = 'block';
    const p = total > 0 ? Math.min(100, Math.round(done / total * 100)) : 100;
    if (bar) bar.style.width = p + '%';
    if (pct) pct.textContent = p + '%';
    if (status) status.textContent = msg || '';
  }

  async function runBuild() {
    const btn = document.querySelector('.ft-build-btn');
    if (!btn) return;
    const url = btn.dataset.url;
    let remainingHint = Math.max(1, parseInt(btn.dataset.pending || '0', 10) || {{ (int) $nsrTotal }});
    const raw = prompt(i18n.promptBuildHowMany, String(Math.min(50, remainingHint || 50)));
    if (raw === null) return;
    const limit = Math.max(1, Math.min(200, parseInt(raw, 10) || 50));
    document.querySelectorAll('.ft-build-btn, .ft-translate-batch-btn, .ft-reverse-batch-btn, .ft-reverse-direct-btn').forEach(b => b.disabled = true);
    if (label) label.textContent = i18n.progressBuild;

    let created = 0, updated = 0, failed = 0, guard = 0;
    try {
      while (guard++ < 80) {
        setProgress(created + updated, Math.max(remainingHint + created + updated, 1), i18n.progressBatch);
        const res = await fetch(url, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ limit, force: 0 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        created += data.created || 0;
        updated += data.updated || 0;
        failed += data.failed || 0;
        setProgress(created + updated, Math.max((data.nsr_total || 0), created + updated, 1), data.message || '');
        if (!data.processed || data.pending === 0) break;
        if ((data.created || 0) + (data.updated || 0) === 0) break;
      }
      setProgress(1, 1, i18n.progressDone + ': +' + created + ' / upd ' + updated + (failed ? ', err ' + failed : ''));
      setTimeout(() => window.location.reload(), 600);
    } catch (e) {
      if (status) status.textContent = e.message || String(e);
      document.querySelectorAll('.ft-build-btn, .ft-translate-batch-btn').forEach(b => b.disabled = false);
      updateSelectionUi();
    }
  }

  const buildBtn = document.querySelector('.ft-build-btn');
  if (buildBtn) buildBtn.addEventListener('click', () => runBuild());

  function selectedChecks() {
    return Array.from(document.querySelectorAll('.ft-row-check:checked'));
  }

  function updateSelectionUi() {
    const n = selectedChecks().length;
    document.querySelectorAll('.ft-selected-count').forEach(el => { el.textContent = String(n); });
    const barSel = document.querySelector('.ft-selection-bar');
    const text = document.querySelector('.ft-selection-text');
    if (barSel) barSel.style.display = n > 0 ? '' : 'none';
    if (text) text.textContent = i18n.selectedN.replace(':n', String(n));
    document.querySelectorAll('.ft-reverse-batch-btn, .ft-reverse-direct-btn').forEach(reBtn => {
      if (!reBtn.dataset.busy) reBtn.disabled = n === 0;
    });
    const all = document.querySelector('.ft-select-all');
    const boxes = document.querySelectorAll('.ft-row-check');
    if (all && boxes.length) {
      all.checked = n > 0 && n === boxes.length;
      all.indeterminate = n > 0 && n < boxes.length;
    }
  }

  document.querySelectorAll('.ft-row-check').forEach(cb => {
    cb.addEventListener('change', updateSelectionUi);
  });
  const selectAll = document.querySelector('.ft-select-all');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      document.querySelectorAll('.ft-row-check').forEach(cb => { cb.checked = selectAll.checked; });
      updateSelectionUi();
    });
  }
  const selectNone = document.querySelector('.ft-select-none');
  if (selectNone) {
    selectNone.addEventListener('click', function () {
      document.querySelectorAll('.ft-row-check').forEach(cb => { cb.checked = false; });
      if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
      updateSelectionUi();
    });
  }

  async function translateOneRoute(translateUrl, engine) {
    const eng = engine || 'gemini';
    let guard = 0;
    while (guard++ < 60) {
      const res = await fetch(translateUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ limit: 10, engine: eng }),
      });
      const data = await res.json().catch(() => ({}));
      const got = Number(data.translated || 0);
      const rem = Number(data.remaining ?? 0);
      if (!res.ok && got === 0) throw new Error(data.message || ('HTTP ' + res.status));
      if (data.done || rem === 0) return;
      if (got === 0) throw new Error(data.message || data.warning || '0');
    }
  }

  const reDirectBtns = document.querySelectorAll('.ft-reverse-direct-btn');
  const reBatchBtns = document.querySelectorAll('.ft-reverse-batch-btn');
  const box = document.querySelector('.ft-reverse-batch-progress');
  const reLabel = document.querySelector('.ft-re-batch-label');
  const rePct = document.querySelector('.ft-re-batch-pct');
  const reBar = document.querySelector('.ft-re-batch-bar');
  const reStatus = document.querySelector('.ft-re-batch-status');
  const reErr = document.querySelector('.ft-re-batch-error');
  const cancelBtn = document.querySelector('.ft-re-batch-cancel');
  let activeProvider = 'gemini';
  let pollTimer = null;
  let directAbort = false;

  function setBatchBusy(busy) {
    document.querySelectorAll('.ft-reverse-batch-btn, .ft-reverse-direct-btn').forEach(b => {
      if (busy) b.dataset.busy = '1';
      else delete b.dataset.busy;
    });
    updateSelectionUi();
    document.querySelectorAll('.ft-build-btn, .ft-translate-batch-btn').forEach(b => { b.disabled = busy; });
  }

  // Gemini без воркера: цикл в браузере reverse → translate.
  reDirectBtns.forEach(function (directBtn) {
    directBtn.addEventListener('click', async function () {
      const checks = selectedChecks();
      if (!checks.length) {
        alert(i18n.alertSelectFirst);
        return;
      }
      const provider = directBtn.dataset.provider || 'gemini';
      const msg = (i18n.confirmReDirect || i18n.confirmReBatch).replace(':n', String(checks.length));
      if (!confirm(msg)) return;
      directAbort = false;
      setBatchBusy(true);
      if (box) box.style.display = '';
      if (cancelBtn) cancelBtn.style.display = '';
      if (reLabel) reLabel.textContent = i18n.progressReBatch + ' · ' + provider + ' (direct)';
      if (reErr) { reErr.style.display = 'none'; reErr.textContent = ''; }
      if (reBar) {
        reBar.classList.add('progress-bar-animated');
        reBar.classList.remove('bg-warning');
        reBar.classList.add('bg-success');
      }
      let ok = 0, fail = 0;
      const total = checks.length;
      try {
        for (let i = 0; i < checks.length; i++) {
          if (directAbort) break;
          const cb = checks[i];
          const id = cb.value;
          const p = Math.round((i / total) * 100);
          if (rePct) rePct.textContent = (i + 1) + '/' + total;
          if (reBar) reBar.style.width = Math.max(5, p) + '%';
          if (reStatus) reStatus.textContent = i18n.reBatchItem.replace(':id', id).replace(':i', String(i + 1)).replace(':n', String(total));
          try {
            const res = await fetch(cb.dataset.reverseUrl, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: JSON.stringify({ provider }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
            if (reStatus) reStatus.textContent = i18n.reBatchTranslate.replace(':id', id);
            await translateOneRoute(cb.dataset.translateUrl, provider);
            ok++;
            cb.checked = false;
          } catch (e) {
            fail++;
            if (reStatus) reStatus.textContent = '#' + id + ': ' + (e.message || String(e));
            if (reErr) { reErr.style.display = ''; reErr.textContent = e.message || String(e); }
          }
        }
        if (reBar) reBar.style.width = '100%';
        if (rePct) rePct.textContent = '100%';
        if (reLabel) reLabel.textContent = directAbort ? i18n.pipelineCancelled : i18n.progressDone;
        if (reStatus) reStatus.textContent = 'OK ' + ok + (fail ? ' · err ' + fail : '') + (directAbort ? ' · stopped' : '');
        setBatchBusy(false);
        // Если всё упало — не перезагружать, иначе ошибка пропадает и кажется, что «ничего не было».
        if (ok === 0) {
          alert('Разбор не сохранился. OK 0 · err ' + fail + (reErr && reErr.textContent ? ('\n' + reErr.textContent) : ''));
          return;
        }
        setTimeout(() => window.location.reload(), fail ? 2000 : 700);
      } catch (e) {
        if (reLabel) reLabel.textContent = i18n.progressError;
        if (reStatus) reStatus.textContent = e.message || String(e);
        setBatchBusy(false);
        alert(e.message || String(e));
      }
    });
  });

  if (reBatchBtns.length) {

    function renderPipeline(s) {
      const total = Number(s.total || 0);
      const done = Number(s.done || 0) + Number(s.failed || 0);
      const pct = total > 0 ? Math.round(done / total * 100) : 0;
      if (box) box.style.display = '';
      if (reBar) {
        reBar.style.width = pct + '%';
        if (s.state === 'running') reBar.classList.add('progress-bar-animated');
        else reBar.classList.remove('progress-bar-animated');
      }
      if (rePct) rePct.textContent = pct + '%';
      if (reLabel) reLabel.textContent = i18n.progressReBatch + ' · ' + (s.provider || activeProvider);
      if (reStatus) {
        reStatus.textContent = done + ' / ' + total
          + ' · OK ' + (s.ok || 0)
          + (s.failed ? (' · err ' + s.failed) : '')
          + (s.pending ? (' · queue ' + s.pending) : '')
          + (s.current ? (' · ' + s.current) : '');
      }
      if (reErr) {
        if (s.last_error) { reErr.style.display = ''; reErr.textContent = s.last_error; }
        else { reErr.style.display = 'none'; reErr.textContent = ''; }
      }
      if (s.state === 'running') {
        setBatchBusy(true);
      } else {
        setBatchBusy(false);
        if (pollTimer && (s.state === 'done' || s.state === 'cancelled' || s.state === 'paused_quota')) {
          clearInterval(pollTimer);
          pollTimer = null;
          const prefix = s.state === 'done' ? i18n.pipelineDone
            : (s.state === 'paused_quota' ? i18n.pipelinePaused : i18n.pipelineCancelled);
          if (reStatus) reStatus.textContent = prefix + ' ' + reStatus.textContent;
          if (s.state === 'done') setTimeout(() => window.location.reload(), 900);
        }
      }
    }

    async function pollProvider(provider, silent) {
      const btn = Array.from(reBatchBtns).find(b => (b.dataset.provider || 'gemini') === provider) || reBatchBtns[0];
      const url = btn.dataset.statusUrl;
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const s = await res.json().catch(() => ({}));
      s.provider = s.provider || provider;
      if (!silent || s.state === 'running' || s.state === 'paused_quota') {
        renderPipeline(s);
      }
      return s;
    }

    function startPolling(provider) {
      activeProvider = provider;
      if (pollTimer) clearInterval(pollTimer);
      pollProvider(provider, false);
      pollTimer = setInterval(() => pollProvider(provider, false), 3000);
    }

    reBatchBtns.forEach(function (reBatchBtn) {
      reBatchBtn.addEventListener('click', async function () {
        const checks = selectedChecks();
        if (!checks.length) {
          alert(i18n.alertSelectFirst);
          return;
        }
        const provider = reBatchBtn.dataset.provider || 'gemini';
        const msg = i18n.confirmReBatch.replace(':n', String(checks.length));
        if (!confirm(msg + ' · ' + provider + '\n' + i18n.pipelineNeedWorker)) return;
        const ids = checks.map(cb => parseInt(cb.value, 10)).filter(n => n > 0);
        try {
          const res = await fetch(reBatchBtn.dataset.startUrl, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ provider, ids }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok && res.status !== 409) throw new Error(data.message || ('HTTP ' + res.status));
          if (!data.started && res.status !== 409) {
            alert(data.message || i18n.alertSelectFirst);
            return;
          }
          if (data.message) {
            if (reStatus) reStatus.textContent = data.message;
          }
          startPolling(provider);
        } catch (e) {
          alert(e.message || String(e));
        }
      });
    });

    if (cancelBtn) {
      cancelBtn.addEventListener('click', async function () {
        if (!confirm(i18n.pipelineCancelled + '?')) return;
        directAbort = true;
        const btn = Array.from(reBatchBtns).find(b => (b.dataset.provider || 'gemini') === activeProvider) || reBatchBtns[0];
        if (!btn || !btn.dataset.cancelUrl) return;
        try {
          await fetch(btn.dataset.cancelUrl, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ provider: activeProvider }),
          });
          await pollProvider(activeProvider);
        } catch (e) {
          alert(e.message || String(e));
        }
      });
    }

    // Resume UI if pipeline already running after page reload.
    Promise.all(['gemini', 'claude'].map(p => pollProvider(p, true).catch(() => null))).then(list => {
      const running = (list || []).find(s => s && s.state === 'running');
      if (running) startPolling(running.provider || 'gemini');
    });
  }

  updateSelectionUi();

  document.querySelectorAll('.ft-clear-re-btn').forEach(function (clearBtn) {
    clearBtn.addEventListener('click', async function () {
      if (!confirm(i18n.confirmClearRe)) return;
      clearBtn.disabled = true;
      try {
        const res = await fetch(clearBtn.dataset.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': clearBtn.dataset.csrf || csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({
            provider: clearBtn.dataset.provider || 'gemini',
            _token: clearBtn.dataset.csrf || csrf,
          }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
          throw new Error(data.message || ('HTTP ' + res.status));
        }
        window.location.reload();
      } catch (e) {
        alert(e.message || String(e));
        clearBtn.disabled = false;
      }
    });
  });

  const trBatch = document.querySelector('.ft-translate-batch-btn');
  if (trBatch) {
    const box = document.querySelector('.ft-translate-progress');
    const trLabel = document.querySelector('.ft-translate-label');
    const pctEl = document.querySelector('.ft-translate-pct');
    const trBar = document.querySelector('.ft-translate-bar');
    const trStatus = document.querySelector('.ft-translate-status');

    trBatch.addEventListener('click', async function () {
      let startRemaining = parseInt(trBatch.dataset.pending || '0', 10) || 0;
      const raw = prompt(i18n.promptTranslateHowMany, String(Math.min(20, Math.max(1, startRemaining || 5))));
      if (raw === null) return;
      const maxRoutes = parseInt(String(raw).trim(), 10) || 5;
      if (!confirm(i18n.confirmTranslateBatch.replace('__N__', String(maxRoutes)))) return;

      trBatch.disabled = true;
      box.style.display = '';
      trLabel.textContent = i18n.progressTranslate;
      let translated = 0;
      let safety = Math.max(50, maxRoutes * 2);

      try {
        let unitsTotal = 0;
        while (safety-- > 0 && translated < maxRoutes) {
          const res = await fetch(trBatch.dataset.url, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ routes: 1, units: 16 }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || data.ok === false) {
            throw new Error(data.message || ('HTTP ' + res.status));
          }
          const rem = data.remaining_routes ?? 0;
          const got = (data.processed || 0);
          const units = (data.translated_units || 0);
          unitsTotal += units;
          if (got === 0 && units === 0) {
            throw new Error(data.message || '0');
          }
          if (startRemaining <= 0) startRemaining = rem + got;
          translated += got;
          const target = Math.min(maxRoutes, startRemaining || maxRoutes);
          const p = target > 0 ? Math.min(100, Math.round(100 * translated / target)) : 100;
          pctEl.textContent = p + '%';
          trBar.style.width = p + '%';
          trStatus.textContent = translated + ' / ' + target + ' · +' + unitsTotal + ' · ' + rem;
          if (data.done || rem === 0) {
            trLabel.textContent = i18n.progressDone;
            setTimeout(() => window.location.reload(), 500);
            return;
          }
        }
        trLabel.textContent = i18n.progressDone;
        setTimeout(() => window.location.reload(), 500);
      } catch (e) {
        trLabel.textContent = i18n.progressError;
        trStatus.textContent = e.message || String(e);
        trBatch.disabled = false;
      }
    });
  }

  // Ручной промпт Gemini (гармошка)
  const manualSaveUrl = @json(route('admin.ft-short-routes.manual-prompt.save'));
  const manualResetUrl = @json(route('admin.ft-short-routes.manual-prompt.reset'));
  const manualInput = document.getElementById('ftManualPromptInput');
  const manualStatus = document.getElementById('ftManualPromptStatus');
  const manualSave = document.getElementById('ftManualPromptSave');
  const manualReset = document.getElementById('ftManualPromptReset');
  const manualCopy = document.getElementById('ftManualPromptCopy');
  if (manualCopy && manualInput) {
    manualCopy.addEventListener('click', async function () {
      try {
        await navigator.clipboard.writeText(manualInput.value || '');
        if (manualStatus) manualStatus.textContent = @json(__('ft_short.manual_prompt_copied'));
      } catch (e) {
        manualInput.focus();
        manualInput.select();
        if (manualStatus) manualStatus.textContent = e.message || String(e);
      }
    });
  }
  if (manualSave && manualInput) {
    manualSave.addEventListener('click', async function () {
      manualSave.disabled = true;
      if (manualStatus) manualStatus.textContent = i18n.promptSaving;
      try {
        const res = await fetch(manualSaveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ prompt: manualInput.value, _token: csrf }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        if (typeof data.prompt === 'string') manualInput.value = data.prompt;
        if (manualStatus) {
          manualStatus.textContent = (data.message || @json(__('ft_short.manual_prompt_saved')))
            + (data.chars ? (' · ' + data.chars + ' симв.') : '');
        }
      } catch (e) {
        if (manualStatus) manualStatus.textContent = e.message || String(e);
      } finally {
        manualSave.disabled = false;
      }
    });
  }
  if (manualReset && manualInput) {
    manualReset.addEventListener('click', async function () {
      if (!confirm(@json(__('ft_short.manual_prompt_reset_confirm')))) return;
      manualReset.disabled = true;
      try {
        const res = await fetch(manualResetUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ _token: csrf }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        if (data.prompt) manualInput.value = data.prompt;
        if (manualStatus) manualStatus.textContent = data.message || @json(__('ft_short.manual_prompt_reset_ok'));
      } catch (e) {
        if (manualStatus) manualStatus.textContent = e.message || String(e);
      } finally {
        manualReset.disabled = false;
      }
    });
  }

  const promptSaveUrl = @json(route('admin.ft-short-routes.reverse-prompt.save'));
  const promptResetUrl = @json(route('admin.ft-short-routes.reverse-prompt.reset'));
  const promptInput = document.getElementById('ftReversePromptInput');
  const promptStatus = document.getElementById('ftReversePromptStatus');
  const promptSave = document.getElementById('ftReversePromptSave');
  const promptReset = document.getElementById('ftReversePromptReset');
  if (promptSave && promptInput) {
    promptSave.addEventListener('click', async function () {
      promptSave.disabled = true;
      if (promptStatus) promptStatus.textContent = i18n.promptSaving;
      try {
        const res = await fetch(promptSaveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ prompt: promptInput.value, lang: uiLang, _token: csrf }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          const ve = data.errors && data.errors.prompt ? data.errors.prompt[0] : null;
          throw new Error(ve || data.message || ('HTTP ' + res.status + (res.status === 419 ? ' (CSRF — обнови страницу)' : '')));
        }
        if (typeof data.prompt === 'string') promptInput.value = data.prompt;
        if (promptStatus) {
          promptStatus.textContent = (data.message || i18n.promptSaved)
            + (data.chars ? (' · ' + data.chars + ' симв.') : '');
        }
      } catch (e) {
        if (promptStatus) promptStatus.textContent = e.message || String(e);
      } finally {
        promptSave.disabled = false;
      }
    });
  }
  if (promptReset && promptInput) {
    promptReset.addEventListener('click', async function () {
      if (!confirm(i18n.promptResetConfirm)) return;
      promptReset.disabled = true;
      try {
        const res = await fetch(promptResetUrl + '?lang=' + encodeURIComponent(uiLang), {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ lang: uiLang }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        if (data.prompt) promptInput.value = data.prompt;
        if (promptStatus) promptStatus.textContent = data.message || i18n.promptResetOk;
      } catch (e) {
        if (promptStatus) promptStatus.textContent = e.message || String(e);
      } finally {
        promptReset.disabled = false;
      }
    });
  }
})();
</script>
@endsection
