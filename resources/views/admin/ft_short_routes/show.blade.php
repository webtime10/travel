@extends('admin.layouts.layout')
@section('content')
@php
  $ui = ($uiLang ?? 'he') === 'ru' ? 'ru' : 'he';
  $uiRtl = $ui === 'he';
  $showRu = ($lang ?? 'he') === 'ru';
  $hubLang = $showRu ? 'RU' : 'HE';
  $routeText = $item->routeTextForDisplay($showRu);
  $days = is_array($item->days) ? $item->days : [];
  $code = $item->doc_code ? (string) $item->doc_code : null;
  $hasRu = $item->hasRussian();
  $hasRpHe = $item->hasReversePrinciple();
  $rp = $item->reversePrincipleForDisplay($showRu);
  $hasRp = is_array($rp) && $rp !== [];
  $hasRpRu = is_array($item->reverse_principle_ru) && ($item->reverse_principle_ru !== []) && empty($item->reverse_principle_ru['skipped']);
@endphp
<div @if($uiRtl) dir="rtl" @endif>
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
  <div>
    <a href="{{ route('admin.ft-short-routes.index') }}" class="small text-muted d-inline-block mb-1">{{ __('ft_short.back') }}</a>
    <h4 class="mb-1 d-flex flex-wrap align-items-center gap-2">
      <i class="bx bx-brain text-primary"></i>
      <span dir="auto">{{ $item->displayTitle($showRu) ?: (__('ft_short.title').' #'.$item->id) }}</span>
      @if($code)
        <span class="badge bg-primary text-white rounded-pill fs-6 px-3 py-2">{{ __('ft_short.code', ['code' => $code]) }}</span>
      @endif
      <span class="badge {{ $showRu ? 'bg-primary' : 'bg-secondary' }}">{{ $hubLang }}</span>
      @if(!$hasRu)
        <span class="badge bg-secondary">HE</span>
      @else
        <span class="badge bg-success">RU</span>
      @endif
      @if($hasRpHe)
        <span class="badge bg-danger">RE HE</span>
      @endif
      @if($hasRpRu)
        <span class="badge bg-danger">RE RU</span>
      @endif
    </h4>
    <div class="text-muted small">
      {{ __('ft_short.meta', ['id' => $item->id, 'nsr' => $item->new_short_route_id, 'days' => $item->days_count]) }}
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center" dir="ltr">
    <div class="btn-group btn-group-sm" role="group">
      <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'he']) }}"
         class="btn btn-outline-secondary {{ !$showRu ? 'active' : '' }}">HE</a>
      <a href="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'ru']) }}"
         class="btn btn-outline-secondary {{ $showRu ? 'active' : '' }}">RU</a>
    </div>
    <button type="button" class="btn btn-warning ft-reverse-one-btn"
            title="{{ __('ft_short.btn_get_re_title') }}"
            data-url="{{ route('admin.ft-short-routes.reverse', $item) }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-search-alt me-1"></i> {{ __('ft_short.btn_get_re') }}
    </button>
    <a href="{{ route('admin.new-short-routes.show', $item->new_short_route_id) }}" class="btn btn-outline-info">
      <i class="bx bx-file-blank me-1"></i> {{ __('ft_short.btn_new_short') }}
    </a>
    <button type="button" class="btn btn-outline-primary ft-translate-one-btn"
            data-url="{{ route('admin.ft-short-routes.translate-ru', $item) }}"
            data-has-re-he="{{ $hasRpHe ? '1' : '0' }}"
            data-reload="{{ route('admin.ft-short-routes.show', [$item, 'lang' => 'ru']) }}"
            data-csrf="{{ csrf_token() }}"
            title="{{ __('ft_short.btn_translate_title') }}">
      <i class="bx bx-globe me-1"></i> {{ __('ft_short.btn_translate') }}
    </button>
    <button type="button" class="btn btn-outline-secondary ft-rebuild-one-btn"
            data-url="{{ route('admin.ft-short-routes.rebuild', $item) }}"
            data-csrf="{{ csrf_token() }}">
      <i class="bx bx-refresh me-1"></i> {{ __('ft_short.btn_rebuild_he') }}
    </button>
    <form action="{{ route('admin.ft-short-routes.destroy', $item) }}" method="post" class="d-inline"
          onsubmit="return confirm(@json(__('ft_short.confirm_delete_one', ['id' => $item->id])));">
      @csrf
      @method('DELETE')
      <button type="submit" class="btn btn-outline-danger"><i class="bx bx-trash me-1"></i></button>
    </form>
  </div>
</div>

<div class="ft-status border rounded p-2 mb-3 bg-light small" style="display:none;"></div>

<div class="ft-reverse-progress border rounded p-3 mb-3 bg-label-warning" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="fw-semibold ft-reverse-label">{{ __('ft_short.re_progress') }}</div>
    <div class="fw-bold ft-reverse-pct">…</div>
  </div>
  <div class="progress mb-1" style="height:10px">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning ft-reverse-bar" role="progressbar" style="width:35%"></div>
  </div>
  <div class="small ft-reverse-status">{{ __('ft_short.re_sending') }}</div>
</div>

@if($hasRp)
  @php
    $ta = is_array($rp['target_audience'] ?? null) ? $rp['target_audience'] : [];
    $abstract = $rp['abstract_user_prompt'] ?? $rp['synthetic_query'] ?? null;
    $macro = is_array($rp['macro_architecture'] ?? null) ? $rp['macro_architecture'] : [];
    $micro = is_array($rp['micro_clustering'] ?? null) ? $rp['micro_clustering'] : [];
    $audience = is_array($rp['audience_portrait'] ?? null) ? $rp['audience_portrait'] : [];
    $fit = is_array($rp['audience_fit'] ?? null) ? $rp['audience_fit'] : [];
    $ft = is_array($rp['fine_tune'] ?? null) ? $rp['fine_tune'] : [];
    $group = $ta['group_profile'] ?? $audience['group_composition'] ?? null;
    $pace = $ta['activity_and_pace_level'] ?? $audience['activity_and_comfort'] ?? null;
    $constraintsList = is_array($ta['special_constraints'] ?? null) ? $ta['special_constraints'] : [];
    if ($constraintsList === [] && !empty($audience['constraints'])) {
      $constraintsList = [(string) $audience['constraints']];
    }
    $whyFits = $ta['why_route_fits_audience'] ?? $fit['why_this_route_fits'] ?? null;
    $queries = $ta['potential_user_queries'] ?? $fit['possible_queries'] ?? [];
  @endphp
  <div class="card mb-3 border-warning" id="ft-reverse-block" @if(!$showRu) dir="rtl" @endif>
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>
        <i class="bx bx-search-alt me-1"></i>
        {{ __('ft_short.re_block_title', ['lang' => $hubLang, 'v' => $rp['schema_version'] ?? 4]) }}
        @if($code)
          <span class="badge bg-primary ms-2">{{ __('ft_short.code', ['code' => $code]) }}</span>
        @endif
      </span>
      @if(!empty($rp['extracted_at']))
        <span class="small text-muted">{{ $rp['extracted_at'] }}</span>
      @endif
    </div>
    <div class="card-body small">
      <div class="fw-semibold mb-2">{{ __('ft_short.re_s1') }}</div>
      <div class="mb-2 p-2 rounded border" dir="auto"><strong>{{ __('ft_short.re_group') }}</strong> {{ $group ?: '—' }}</div>
      <div class="mb-2 p-2 rounded border" dir="auto"><strong>{{ __('ft_short.re_pace') }}</strong> {{ $pace ?: '—' }}</div>
      @if(count($constraintsList))
        <div class="mb-2">
          <strong>{{ __('ft_short.re_constraints') }}</strong>
          <ul class="mb-0 ps-3">
            @foreach($constraintsList as $c)
              <li dir="auto">{{ $c }}</li>
            @endforeach
          </ul>
        </div>
      @endif
      @if(!empty($whyFits))
        <div class="mb-3 p-2 rounded bg-label-warning" dir="auto">
          <strong>{{ __('ft_short.re_why') }}</strong><br>{{ $whyFits }}
        </div>
      @endif
      @if(is_array($queries) && count($queries))
        <div class="mb-3">
          <strong>{{ __('ft_short.re_queries') }}</strong>
          <ol class="mb-0 ps-3">
            @foreach($queries as $q)
              <li dir="auto">{{ $q }}</li>
            @endforeach
          </ol>
        </div>
      @endif

      <div class="fw-semibold mb-2">{{ __('ft_short.re_s2') }}</div>
      <div class="mb-2" dir="auto"><strong>{{ __('ft_short.re_regions') }}</strong> {{ $macro['region_selection_logic'] ?? $rp['principle'] ?? '—' }}</div>
      <div class="mb-2" dir="auto"><strong>{{ __('ft_short.re_bases') }}</strong> {{ $macro['base_camps_strategy'] ?? $macro['routing_strategy'] ?? $rp['geo_logic'] ?? '—' }}</div>
      @if(!empty($macro['transit_days_handling']))
        <div class="mb-3" dir="auto"><strong>{{ __('ft_short.re_transit') }}</strong> {{ $macro['transit_days_handling'] }}</div>
      @endif

      @if($micro !== [])
        <div class="fw-semibold mb-2">{{ __('ft_short.re_s3') }}</div>
        @foreach($micro as $m)
          <div class="mb-3 p-2 border rounded">
            <div><strong>{{ __('ft_short.re_day', ['n' => $m['day_number'] ?? '?']) }}</strong> — {{ $m['day_description'] ?? '—' }}</div>
            <div class="mt-1" dir="auto"><strong>{{ __('ft_short.re_why_day') }}</strong> {{ $m['audience_adapted_logic'] ?? $m['practical_logic'] ?? '—' }}</div>
            <div class="mt-1 text-muted" dir="auto"><strong>{{ __('ft_short.re_logistics') }}</strong> {{ $m['logistics_and_timing'] ?? $m['logistics_and_fatigue'] ?? $m['logistics_and_ztl'] ?? '—' }}</div>
          </div>
        @endforeach
      @endif

      @if(!empty($ft['question']) || !empty($ft['answer']))
        <hr class="my-2">
        <div class="fw-semibold mb-1">{{ __('ft_short.re_ft_pair') }}</div>
        <div class="mb-2 p-2 rounded bg-label-info" dir="auto"><strong>{{ __('ft_short.re_question') }}</strong> {{ $ft['question'] ?? $abstract ?? '—' }}</div>
        <div class="mb-2 p-2 rounded border" dir="auto">
          <strong>{{ __('ft_short.re_answer') }}</strong> {{ is_array($ft['answer'] ?? null) ? ($ft['answer']['summary'] ?? '—') : '—' }}
          @if(!empty($ft['answer']['days']))
            <div class="mt-2 small text-muted">
              @foreach($ft['answer']['days'] as $fd)
                <div>
                  {{ __('ft_short.re_day', ['n' => $fd['day_index'] ?? '?']) }}@if(!empty($fd['title'])) — {{ $fd['title'] }}@endif
                  @if(!empty($fd['places_text'])) · {{ $fd['places_text'] }}@endif
                </div>
              @endforeach
            </div>
          @endif
        </div>
      @endif
    </div>
  </div>
@elseif($showRu && $hasRpHe && !$hasRpRu)
  <div class="alert alert-info small mb-3">
    {{ __('ft_short.alert_re_he_only') }}
  </div>
@elseif(!$hasRpHe)
  <div class="alert alert-warning small mb-3">
    {{ __('ft_short.alert_no_re_he') }}
    @if($hasRpRu)
      <span class="d-block mt-1">{{ __('ft_short.alert_old_ru') }}</span>
    @endif
  </div>
@endif

<div class="card mb-3 border-primary">
  <div class="card-header d-flex justify-content-between align-items-center py-2">
    <span class="fw-semibold"><i class="bx bx-list-ol me-1"></i> {{ __('ft_short.route_card', ['lang' => $hubLang]) }}</span>
    <span class="small text-muted">{{ __('ft_short.days_n', ['n' => $item->days_count]) }}</span>
  </div>
  <div class="card-body">
    @if($showRu && !$hasRu)
      <div class="text-muted mb-2">{{ __('ft_short.no_ru_yet') }}</div>
    @endif
    <pre class="mb-0 small bg-light border rounded p-3" style="white-space:pre-wrap;max-height:22rem;overflow:auto" dir="auto">{{ $routeText !== '' ? $routeText : '—' }}</pre>
  </div>
</div>

@if(count($days) > 0)
<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bx bx-table me-1"></i> {{ __('ft_short.days_struct', ['lang' => $hubLang]) }}</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th style="width:5rem">{{ __('ft_short.th_day') }}</th>
          <th>{{ __('ft_short.th_places') }}</th>
          <th>{{ __('ft_short.th_options') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($days as $d)
          @php
            $places = $showRu
              ? (trim((string)($d['places_ru'] ?? '')) !== '' ? $d['places_ru'] : '—')
              : ($d['places'] ?? '');
            $opts = $showRu
              ? ($d['options_ru'] ?? [])
              : ($d['options'] ?? []);
          @endphp
          <tr>
            <td>{{ (int)($d['day_index'] ?? 0) }}</td>
            <td dir="auto">{{ $places !== '' ? $places : '—' }}</td>
            <td class="small" dir="auto">
              @if(is_array($opts) && count($opts))
                {{ implode(' · ', $opts) }}
              @else
                —
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
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

  const revBtn = document.querySelector('.ft-reverse-one-btn');
  const revBox = document.querySelector('.ft-reverse-progress');
  const revLabel = document.querySelector('.ft-reverse-label');
  const revPct = document.querySelector('.ft-reverse-pct');
  const revBar = document.querySelector('.ft-reverse-bar');
  const revStatus = document.querySelector('.ft-reverse-status');
  if (revBtn) {
    revBtn.addEventListener('click', async function () {
      if (!confirm(i18n.confirmGetRe)) return;
      revBtn.disabled = true;
      if (revBox) revBox.style.display = '';
      if (revLabel) revLabel.textContent = i18n.reProgress;
      if (revPct) revPct.textContent = '…';
      if (revBar) revBar.style.width = '35%';
      if (revStatus) revStatus.textContent = i18n.reSending;
      showStatus(i18n.reSending, true);
      let lastErr = null;
      try {
        for (let attempt = 1; attempt <= 3; attempt++) {
          if (revStatus) revStatus.textContent = i18n.reAttempt.replace('__N__', String(attempt));
          if (revBar) revBar.style.width = (25 + attempt * 20) + '%';
          if (revPct) revPct.textContent = attempt + '/3';
          const res = await fetch(revBtn.dataset.url, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': revBtn.dataset.csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const data = await res.json().catch(() => ({}));
          if (res.ok) {
            if (revBar) revBar.style.width = '100%';
            if (revPct) revPct.textContent = '100%';
            if (revLabel) revLabel.textContent = i18n.progressDone;
            if (revStatus) revStatus.textContent = i18n.reDoneReload;
            showStatus(i18n.reDone, true);
            window.location.reload();
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
        revBtn.disabled = false;
      }
    });
  }

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

  const btn = document.querySelector('.ft-translate-one-btn');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    if (revBtn) revBtn.disabled = true;
    const hdr = {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': btn.dataset.csrf,
      'X-Requested-With': 'XMLHttpRequest',
    };
    try {
      if (btn.dataset.hasReHe !== '1') {
        throw new Error(i18n.jsNeedRe);
      }
      if (revBox) revBox.style.display = '';
      if (revLabel) revLabel.textContent = i18n.jsTranslateProgress;
      if (revBar) revBar.style.width = '35%';
      if (revStatus) revStatus.textContent = i18n.jsTranslateProgress;
      showStatus(i18n.jsTranslateProgress, true);

      let guard = 0;
      let lastRemaining = null;
      while (guard++ < 60) {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: hdr,
          body: JSON.stringify({ limit: 10 }),
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
    } catch (e) {
      if (revLabel) revLabel.textContent = i18n.progressError;
      if (revStatus) revStatus.textContent = e.message || String(e);
      showStatus(e.message || String(e), false);
      btn.disabled = false;
      if (revBtn) revBtn.disabled = false;
    }
  });
})();
</script>
@endsection
