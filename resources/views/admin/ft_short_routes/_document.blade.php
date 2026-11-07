{{-- Общий документ FT Short (экран + PDF): разбор + маршрут + дни --}}
@php
  $showRu = ($lang ?? 'he') === 'ru';
  $hubLang = $showRu ? 'RU' : 'HE';
  $engine = (($engine ?? 'gemini') === 'claude') ? 'claude' : 'gemini';
  $isClaude = $engine === 'claude';
  $routeText = $item->routeTextForDisplay($showRu);
  $days = is_array($item->days) ? $item->days : [];
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

<div class="ft-short-doc" @if(!$showRu) dir="rtl" @else dir="ltr" @endif>
  @if(!empty($forPdf))
  <div class="mb-3">
    <h3 class="mb-1" dir="auto">{{ $item->displayTitle($showRu) ?: (__('ft_short.title').' #'.$item->id) }}</h3>
    <div class="text-muted small">
      @if($code){{ __('ft_short.code', ['code' => $code]) }} · @endif
      {{ __('ft_short.meta', ['id' => $item->id, 'nsr' => $item->new_short_route_id, 'days' => $item->days_count]) }}
      · {{ $hubLang }}
    </div>
  </div>
  @endif

@if($hasRp)
  @php
    $arch = is_array($rp['trip_archetype'] ?? null) ? $rp['trip_archetype'] : [];
    $tp = is_array($arch['target_profile'] ?? null) ? $arch['target_profile'] : [];
    $ta = is_array($rp['target_audience'] ?? null) ? $rp['target_audience'] : [];
    $abstract = $rp['abstract_user_prompt'] ?? $rp['synthetic_query'] ?? null;
    $macro = is_array($rp['macro_architecture'] ?? null) ? $rp['macro_architecture'] : [];
    $micro = is_array($rp['micro_clustering'] ?? null) ? $rp['micro_clustering'] : [];
    $audience = is_array($rp['audience_portrait'] ?? null) ? $rp['audience_portrait'] : [];
    $fit = is_array($rp['audience_fit'] ?? null) ? $rp['audience_fit'] : [];
    $ft = is_array($rp['fine_tune'] ?? null) ? $rp['fine_tune'] : [];
    $group = $tp['group_composition'] ?? $ta['group_profile'] ?? $audience['group_composition'] ?? null;
    $pace = $tp['pace_and_physical_load'] ?? $ta['activity_and_pace_level'] ?? $audience['activity_and_comfort'] ?? null;
    $cultural = $tp['cultural_bias'] ?? null;
    $constraintsList = is_array($tp['special_constraints'] ?? null) ? $tp['special_constraints'] : [];
    if ($constraintsList === [] && is_array($ta['special_constraints'] ?? null)) {
      $constraintsList = $ta['special_constraints'];
    }
    if ($constraintsList === [] && !empty($audience['constraints'])) {
      $constraintsList = [(string) $audience['constraints']];
    }
    $whyFits = $ta['why_route_fits_audience'] ?? $fit['why_this_route_fits'] ?? ($arch['archetype_name'] ?? null);
    $queries = $arch['potential_client_queries'] ?? $ta['potential_user_queries'] ?? $fit['possible_queries'] ?? [];
  @endphp
  <div class="card mb-3 {{ $isClaude ? 'border-warning' : 'border-warning' }}" id="ft-reverse-block">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 {{ $isClaude ? 'bg-label-warning' : '' }}">
      <span>
        <i class="bx {{ $isClaude ? 'bx-bot' : 'bx-search-alt' }} me-1"></i>
        {{ __('ft_short.re_block_title', ['lang' => $hubLang, 'v' => $rp['schema_version'] ?? 5]) }}
        @if($isClaude)
          <span class="badge bg-warning text-dark ms-2">Claude</span>
        @endif
        @if($code)
          <span class="badge bg-primary ms-2">{{ __('ft_short.code', ['code' => $code]) }}</span>
        @endif
      </span>
      @if(!empty($rp['extracted_at']))
        <span class="small text-muted">{{ $rp['extracted_at'] }}</span>
      @endif
    </div>
    <div class="card-body small">
      @if(!empty($arch['archetype_name']))
        <div class="mb-3 p-2 rounded bg-label-primary" dir="auto">
          <strong>{{ __('ft_short.re_archetype') }}</strong> {{ $arch['archetype_name'] }}
        </div>
      @endif

      <div class="fw-semibold mb-2">{{ __('ft_short.re_s1') }}</div>
      <div class="mb-2 p-2 rounded border" dir="auto"><strong>{{ __('ft_short.re_group') }}</strong> {{ $group ?: '—' }}</div>
      <div class="mb-2 p-2 rounded border" dir="auto"><strong>{{ __('ft_short.re_pace') }}</strong> {{ $pace ?: '—' }}</div>
      @if(!empty($cultural))
        <div class="mb-2 p-2 rounded border" dir="auto"><strong>{{ __('ft_short.re_cultural') }}</strong> {{ $cultural }}</div>
      @endif
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
      @if(!empty($macro['topology']))
        <div class="mb-2" dir="auto"><strong>{{ __('ft_short.re_topology') }}</strong> {{ $macro['topology'] }}</div>
      @endif
      <div class="mb-2" dir="auto"><strong>{{ __('ft_short.re_bases') }}</strong> {{ $macro['base_camps_logic'] ?? $macro['base_camps_strategy'] ?? $macro['routing_strategy'] ?? $rp['geo_logic'] ?? '—' }}</div>
      @if(!empty($macro['highway_transit_strategy']) || !empty($macro['transit_days_handling']))
        <div class="mb-2" dir="auto"><strong>{{ __('ft_short.re_highway') }}</strong> {{ $macro['highway_transit_strategy'] ?? $macro['transit_days_handling'] }}</div>
      @endif
      @if(!empty($macro['arrival_fatigue_management']))
        <div class="mb-3" dir="auto"><strong>{{ __('ft_short.re_fatigue') }}</strong> {{ $macro['arrival_fatigue_management'] }}</div>
      @endif
      @if(!empty($macro['region_selection_logic']))
        <div class="mb-3" dir="auto"><strong>{{ __('ft_short.re_regions') }}</strong> {{ $macro['region_selection_logic'] }}</div>
      @endif

      @if($micro !== [])
        <div class="fw-semibold mb-2">{{ __('ft_short.re_s3') }}</div>
        @foreach($micro as $m)
          <div class="mb-3 p-2 border rounded">
            <div>
              <strong>{{ __('ft_short.re_day', ['n' => $m['day_number'] ?? '?']) }}</strong>
              — {{ $m['day_title'] ?? $m['day_description'] ?? '—' }}
              @if(!empty($m['weekend_shield_applied']))
                <span class="badge bg-label-success ms-1">weekend shield</span>
              @endif
            </div>
            @if(!empty($m['day_formula_check']) || !empty($m['audience_adapted_logic']))
              <div class="mt-1" dir="auto"><strong>{{ __('ft_short.re_formula') }}</strong> {{ $m['day_formula_check'] ?? $m['audience_adapted_logic'] ?? $m['practical_logic'] ?? '—' }}</div>
            @endif
            @if(!empty($m['signature_wildcard']))
              <div class="mt-1 p-1 rounded bg-label-warning" dir="auto"><strong>{{ __('ft_short.re_wildcard') }}</strong> {{ $m['signature_wildcard'] }}</div>
            @endif
            @if(!empty($m['locations_selection_logic']) && is_array($m['locations_selection_logic']))
              <ul class="mb-1 mt-2 ps-3">
                @foreach($m['locations_selection_logic'] as $loc)
                  <li dir="auto">
                    <strong>{{ $loc['location_name'] ?? '—' }}</strong>
                    @if(!empty($loc['selection_reason'])) — {{ $loc['selection_reason'] }}@endif
                    @if(!empty($loc['allocated_time_slot'])) <span class="text-muted">({{ $loc['allocated_time_slot'] }})</span>@endif
                  </li>
                @endforeach
              </ul>
            @endif
            <div class="mt-1 text-muted" dir="auto">
              <strong>{{ __('ft_short.re_logistics') }}</strong>
              @if(!empty($m['logistics_and_safety']['estimated_driving_time_min']))
                {{ $m['logistics_and_safety']['estimated_driving_time_min'] }} мин ·
              @endif
              {{ $m['logistics_and_safety']['ztl_and_parking_solution'] ?? $m['logistics_and_timing'] ?? $m['logistics_and_fatigue'] ?? '—' }}
            </div>
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
    <pre class="mb-0 small bg-light border rounded p-3" style="white-space:pre-wrap" dir="auto">{{ $routeText !== '' ? $routeText : '—' }}</pre>
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
