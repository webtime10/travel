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
    <button type="button" class="btn btn-outline-secondary ft-rebuild-all-btn"
            title="{{ __('ft_short.btn_rebuild_title') }}"
            data-url="{{ route('admin.ft-short-routes.build-batch') }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-refresh me-1"></i> {{ __('ft_short.btn_rebuild') }}
    </button>
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

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">{{ __('ft_short.prompt_title') }}</div>
      <div class="small text-muted">
        {{ $ui === 'ru' ? __('ft_short.prompt_hint') : __('ft_short.prompt_hint_he_mode') }}
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
    <textarea id="ftReversePromptInput" class="form-control font-monospace" rows="14" spellcheck="false"
              @if($ui === 'he') dir="rtl" @endif>{{ $reversePrompt ?? '' }}</textarea>
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
          <th style="width:4rem">{{ __('ft_short.col_id') }}</th>
          <th style="width:5rem">{{ __('ft_short.col_code') }}</th>
          <th>{{ __('ft_short.col_title') }}</th>
          <th style="width:5rem">{{ __('ft_short.col_days') }}</th>
          <th>{{ __('ft_short.col_route_he') }}</th>
          <th>{{ __('ft_short.col_route_ru') }}</th>
          <th style="width:6.5rem">{{ __('ft_short.col_status') }}</th>
          <th style="width:11rem"></th>
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
          @endphp
          <tr>
            <td class="text-muted">{{ $item->id }}</td>
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
            </td>
            <td class="text-end text-nowrap">
              <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'he']) }}"
                 class="btn btn-sm btn-warning"
                 title="{{ __('ft_short.btn_parse_title') }}">
                <i class="bx bx-search-alt me-1"></i>{{ __('ft_short.btn_parse') }}
              </a>
              <button type="button"
                      class="btn btn-icon btn-sm btn-outline-primary ft-row-translate-btn"
                      title="{{ __('ft_short.btn_translate_title') }}"
                      data-url="{{ route('admin.ft-short-routes.translate-ru', $item) }}"
                      data-has-re-he="{{ $hasRe ? '1' : '0' }}"
                      data-csrf="{{ csrf_token() }}">
                <i class="bx bx-globe"></i>
              </button>
              <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'he']) }}" class="btn btn-icon btn-sm btn-outline-secondary" title="{{ __('ft_short.btn_open_he') }}">
                <i class="bx bx-show"></i>
              </a>
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
            <td colspan="8" class="text-center text-muted py-4">
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
    promptBuildHowMany: @json(__('ft_short.prompt_build_how_many')),
    promptRebuildHowMany: @json(__('ft_short.prompt_rebuild_how_many')),
    confirmRebuild: @json(__('ft_short.confirm_rebuild')),
    promptTranslateHowMany: @json(__('ft_short.prompt_translate_how_many')),
    confirmTranslateBatch: @json(__('ft_short.confirm_translate_batch', ['n' => '__N__'])),
    alertNeedRe: @json(__('ft_short.alert_need_re_first')),
    confirmTranslateRow: @json(__('ft_short.confirm_translate_row')),
    promptSaving: @json(__('ft_short.prompt_saving')),
    promptSaved: @json(__('ft_short.prompt_saved')),
    promptResetConfirm: @json(__('ft_short.prompt_reset_confirm')),
    promptResetOk: @json(__('ft_short.prompt_reset_ok')),
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

  async function runBuild(force) {
    const btn = force
      ? document.querySelector('.ft-rebuild-all-btn')
      : document.querySelector('.ft-build-btn');
    if (!btn) return;
    const url = btn.dataset.url;
    let remainingHint = force
      ? {{ (int) $nsrTotal }}
      : Math.max(1, parseInt(btn.dataset.pending || '0', 10) || {{ (int) $nsrTotal }});
    const raw = prompt(
      force ? i18n.promptRebuildHowMany : i18n.promptBuildHowMany,
      String(Math.min(50, remainingHint || 50))
    );
    if (raw === null) return;
    const limit = Math.max(1, Math.min(200, parseInt(raw, 10) || 50));
    document.querySelectorAll('.ft-build-btn, .ft-rebuild-all-btn, .ft-translate-batch-btn').forEach(b => b.disabled = true);
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
          body: JSON.stringify({ limit, force: force ? 1 : 0 }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        created += data.created || 0;
        updated += data.updated || 0;
        failed += data.failed || 0;
        setProgress(created + updated, Math.max((data.nsr_total || 0), created + updated, 1), data.message || '');
        if (force) break;
        if (!data.processed || data.pending === 0) break;
        if ((data.created || 0) + (data.updated || 0) === 0) break;
      }
      setProgress(1, 1, i18n.progressDone + ': +' + created + ' / upd ' + updated + (failed ? ', err ' + failed : ''));
      setTimeout(() => window.location.reload(), 600);
    } catch (e) {
      if (status) status.textContent = e.message || String(e);
      document.querySelectorAll('.ft-build-btn, .ft-rebuild-all-btn, .ft-translate-batch-btn').forEach(b => b.disabled = false);
    }
  }

  const buildBtn = document.querySelector('.ft-build-btn');
  if (buildBtn) buildBtn.addEventListener('click', () => runBuild(false));
  const rebuildBtn = document.querySelector('.ft-rebuild-all-btn');
  if (rebuildBtn) rebuildBtn.addEventListener('click', () => {
    if (!confirm(i18n.confirmRebuild)) return;
    runBuild(true);
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

  document.querySelectorAll('.ft-row-translate-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (btn.dataset.hasReHe !== '1') {
        alert(i18n.alertNeedRe);
        return;
      }
      if (!confirm(i18n.confirmTranslateRow)) return;
      const box = document.querySelector('.ft-translate-progress');
      const trLabel = document.querySelector('.ft-translate-label');
      const pctEl = document.querySelector('.ft-translate-pct');
      const trBar = document.querySelector('.ft-translate-bar');
      const trStatus = document.querySelector('.ft-translate-status');
      btn.disabled = true;
      if (box) box.style.display = '';
      if (trLabel) trLabel.textContent = i18n.progressTranslate;
      try {
        if (trStatus) trStatus.textContent = i18n.progressPrep;
        if (trBar) trBar.style.width = '55%';
        let guard = 0;
        while (guard++ < 60) {
          const res = await fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': btn.dataset.csrf || csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ limit: 10 }),
          });
          const data = await res.json().catch(() => ({}));
          const got = Number(data.translated || 0);
          const rem = Number(data.remaining ?? 0);
          if (!res.ok && got === 0) throw new Error(data.message || ('HTTP ' + res.status));
          if (trStatus) trStatus.textContent = '+' + got + ' · ' + rem;
          if (pctEl) pctEl.textContent = rem === 0 ? '100%' : String(rem);
          if (trBar) trBar.style.width = (rem === 0 ? 100 : Math.min(95, 55 + guard * 3)) + '%';
          if (data.done || rem === 0) break;
          if (got === 0) throw new Error(data.message || data.warning || '0');
        }
        if (trLabel) trLabel.textContent = i18n.progressDone;
        setTimeout(() => window.location.reload(), 400);
      } catch (e) {
        if (trLabel) trLabel.textContent = i18n.progressError;
        if (trStatus) trStatus.textContent = e.message || String(e);
        btn.disabled = false;
      }
    });
  });

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
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ prompt: promptInput.value, lang: uiLang }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        if (promptStatus) promptStatus.textContent = data.message || i18n.promptSaved;
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
