{{-- Упрощённый документ только для PDF: без Bootstrap flex (html2canvas не режет левый край) --}}
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

{{-- Для захвата canvas всегда LTR-контейнер; направление только у текста --}}
<div class="ft-short-doc" dir="ltr">
  <h1 class="pdf-h1" dir="auto">{{ $item->displayTitle($showRu) ?: (__('ft_short.title').' #'.$item->id) }}</h1>
  <div class="pdf-meta" dir="auto">
    @if($code){{ __('ft_short.code', ['code' => $code]) }} · @endif
    {{ __('ft_short.meta', ['id' => $item->id, 'nsr' => $item->new_short_route_id, 'days' => $item->days_count]) }}
    · {{ $hubLang }}
    @if($isClaude) · Claude @endif
  </div>

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
    if ($constraintsList === [] && ! empty($audience['constraints'])) {
      $constraintsList = [(string) $audience['constraints']];
    }
    $whyFits = $ta['why_route_fits_audience'] ?? $fit['why_this_route_fits'] ?? ($arch['archetype_name'] ?? null);
    $queries = $arch['potential_client_queries'] ?? $ta['potential_user_queries'] ?? $fit['possible_queries'] ?? [];
  @endphp

  <div class="pdf-card">
    <div class="pdf-card-h" dir="auto">
      {{ __('ft_short.re_block_title', ['lang' => $hubLang, 'v' => $rp['schema_version'] ?? 5]) }}
      @if($code)
        <span class="pdf-badge">{{ __('ft_short.code', ['code' => $code]) }}</span>
      @endif
      @if(! empty($rp['extracted_at']))
        <span class="pdf-muted"> · {{ $rp['extracted_at'] }}</span>
      @endif
    </div>
    <div class="pdf-card-b">
      @if(! empty($arch['archetype_name']))
        <div class="pdf-box primary" dir="auto"><span class="pdf-label">{{ __('ft_short.re_archetype') }}</span> {{ $arch['archetype_name'] }}</div>
      @endif

      <div class="pdf-section">{{ __('ft_short.re_s1') }}</div>
      <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_group') }}</span> {{ $group ?: '—' }}</div>
      <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_pace') }}</span> {{ $pace ?: '—' }}</div>
      @if(! empty($cultural))
        <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_cultural') }}</span> {{ $cultural }}</div>
      @endif
      @if(count($constraintsList))
        <div class="pdf-box">
          <div class="pdf-label">{{ __('ft_short.re_constraints') }}</div>
          <ul>
            @foreach($constraintsList as $c)
              <li dir="auto">{{ $c }}</li>
            @endforeach
          </ul>
        </div>
      @endif
      @if(! empty($whyFits))
        <div class="pdf-box warn" dir="auto"><span class="pdf-label">{{ __('ft_short.re_why') }}</span><br>{{ $whyFits }}</div>
      @endif
      @if(is_array($queries) && count($queries))
        <div class="pdf-box">
          <div class="pdf-label">{{ __('ft_short.re_queries') }}</div>
          <ol>
            @foreach($queries as $q)
              <li dir="auto">{{ $q }}</li>
            @endforeach
          </ol>
        </div>
      @endif

      <div class="pdf-section">{{ __('ft_short.re_s2') }}</div>
      @if(! empty($macro['topology']))
        <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_topology') }}</span> {{ $macro['topology'] }}</div>
      @endif
      <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_bases') }}</span> {{ $macro['base_camps_logic'] ?? $macro['base_camps_strategy'] ?? $macro['routing_strategy'] ?? $rp['geo_logic'] ?? '—' }}</div>
      @if(! empty($macro['highway_transit_strategy']) || ! empty($macro['transit_days_handling']))
        <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_highway') }}</span> {{ $macro['highway_transit_strategy'] ?? $macro['transit_days_handling'] }}</div>
      @endif
      @if(! empty($macro['arrival_fatigue_management']))
        <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_fatigue') }}</span> {{ $macro['arrival_fatigue_management'] }}</div>
      @endif
      @if(! empty($macro['region_selection_logic']))
        <div class="pdf-box" dir="auto"><span class="pdf-label">{{ __('ft_short.re_regions') }}</span> {{ $macro['region_selection_logic'] }}</div>
      @endif

      @if($micro !== [])
        <div class="pdf-section">{{ __('ft_short.re_s3') }}</div>
        @foreach($micro as $m)
          <div class="pdf-day">
            <div dir="auto">
              <strong>{{ __('ft_short.re_day', ['n' => $m['day_number'] ?? '?']) }}</strong>
              — {{ $m['day_title'] ?? $m['day_description'] ?? '—' }}
            </div>
            @if(! empty($m['day_formula_check']) || ! empty($m['audience_adapted_logic']))
              <div dir="auto"><span class="pdf-label">{{ __('ft_short.re_formula') }}</span> {{ $m['day_formula_check'] ?? $m['audience_adapted_logic'] ?? $m['practical_logic'] ?? '—' }}</div>
            @endif
            @if(! empty($m['signature_wildcard']))
              <div class="pdf-box warn" dir="auto"><span class="pdf-label">{{ __('ft_short.re_wildcard') }}</span> {{ $m['signature_wildcard'] }}</div>
            @endif
            @if(! empty($m['locations_selection_logic']) && is_array($m['locations_selection_logic']))
              <ul>
                @foreach($m['locations_selection_logic'] as $loc)
                  <li dir="auto">
                    <strong>{{ $loc['location_name'] ?? '—' }}</strong>
                    @if(! empty($loc['selection_reason'])) — {{ $loc['selection_reason'] }}@endif
                    @if(! empty($loc['allocated_time_slot'])) <span class="pdf-muted">({{ $loc['allocated_time_slot'] }})</span>@endif
                  </li>
                @endforeach
              </ul>
            @endif
            <div class="pdf-muted" dir="auto">
              <span class="pdf-label">{{ __('ft_short.re_logistics') }}</span>
              @if(! empty($m['logistics_and_safety']['estimated_driving_time_min']))
                {{ $m['logistics_and_safety']['estimated_driving_time_min'] }} мин ·
              @endif
              {{ $m['logistics_and_safety']['ztl_and_parking_solution'] ?? $m['logistics_and_timing'] ?? $m['logistics_and_fatigue'] ?? '—' }}
            </div>
          </div>
        @endforeach
      @endif

      @if(! empty($ft['question']) || ! empty($ft['answer']))
        <hr>
        <div class="pdf-section">{{ __('ft_short.re_ft_pair') }}</div>
        <div class="pdf-box info" dir="auto"><span class="pdf-label">{{ __('ft_short.re_question') }}</span> {{ $ft['question'] ?? $abstract ?? '—' }}</div>
        <div class="pdf-box" dir="auto">
          <span class="pdf-label">{{ __('ft_short.re_answer') }}</span>
          {{ is_array($ft['answer'] ?? null) ? ($ft['answer']['summary'] ?? '—') : '—' }}
          @if(! empty($ft['answer']['days']))
            <div class="pdf-muted" style="margin-top:8px">
              @foreach($ft['answer']['days'] as $fd)
                <div>
                  {{ __('ft_short.re_day', ['n' => $fd['day_index'] ?? '?']) }}@if(! empty($fd['title'])) — {{ $fd['title'] }}@endif
                  @if(! empty($fd['places_text'])) · {{ $fd['places_text'] }}@endif
                </div>
              @endforeach
            </div>
          @endif
        </div>
      @endif
    </div>
  </div>
@elseif($showRu && $hasRpHe && ! $hasRpRu)
  <div class="pdf-box info">{{ __('ft_short.alert_re_he_only') }}</div>
@elseif(! $hasRpHe)
  <div class="pdf-box warn">
    {{ __('ft_short.alert_no_re_he') }}
    @if($hasRpRu)
      <div>{{ __('ft_short.alert_old_ru') }}</div>
    @endif
  </div>
@endif

  <div class="pdf-card">
    <div class="pdf-card-h" dir="auto">
      {{ __('ft_short.route_card', ['lang' => $hubLang]) }}
      <span class="pdf-muted"> · {{ __('ft_short.days_n', ['n' => $item->days_count]) }}</span>
    </div>
    <div class="pdf-card-b">
      @if($showRu && ! $hasRu)
        <div class="pdf-muted">{{ __('ft_short.no_ru_yet') }}</div>
      @endif
      <pre class="pdf-pre" dir="auto">{{ $routeText !== '' ? $routeText : '—' }}</pre>
    </div>
  </div>

@if(count($days) > 0)
  <div class="pdf-card">
    <div class="pdf-card-h" dir="auto">{{ __('ft_short.days_struct', ['lang' => $hubLang]) }}</div>
    <div class="pdf-card-b">
      <table class="pdf-table">
        <thead>
          <tr>
            <th style="width:4rem">{{ __('ft_short.th_day') }}</th>
            <th>{{ __('ft_short.th_places') }}</th>
            <th>{{ __('ft_short.th_options') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($days as $d)
            @php
              $places = $showRu
                ? (trim((string) ($d['places_ru'] ?? '')) !== '' ? $d['places_ru'] : '—')
                : ($d['places'] ?? '');
              $opts = $showRu
                ? ($d['options_ru'] ?? [])
                : ($d['options'] ?? []);
            @endphp
            <tr>
              <td>{{ (int) ($d['day_index'] ?? 0) }}</td>
              <td dir="auto">{{ $places !== '' ? $places : '—' }}</td>
              <td dir="auto">
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
