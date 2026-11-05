@php
  $tags = is_array($location->passport_tags ?? null) ? $location->passport_tags : null;
  $ev = is_array($tags['evidence'] ?? null) ? $tags['evidence'] : [];
  $label = [
    'yes' => 'да',
    'no' => 'нет',
    'unknown' => 'неясно',
    'low' => 'низкая',
    'medium' => 'средняя',
    'high' => 'высокая',
    'base' => 'base (ночёвка)',
    'place' => 'place (точка)',
    'end' => 'end',
  ];
@endphp

<div class="card mt-4" id="passport-tags">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h5 class="mb-1">Метки паспорта <span class="badge bg-label-warning fw-normal">проба Gemini</span></h5>
        <div class="small text-muted">Исходный текст Word не меняется. Черновик меток рядом — overnight / дети / кошер / часы / подвижность.</div>
      </div>
      <button
        type="button"
        class="btn btn-outline-primary location-passport-tags-btn"
        data-url="{{ route('admin.locations.passport-tags', $location->id) }}"
        data-csrf="{{ csrf_token() }}"
      >
        <i class="bx bx-tag me-1"></i> Разбить на метки
      </button>
    </div>
    <div class="small text-muted mb-3 location-passport-tags-status" style="display:none;"></div>

    @if($tags)
      <div class="row g-3">
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Ночёвка</div>
            <div class="fw-semibold">
              @if(($tags['overnight'] ?? null) === true) да
              @elseif(($tags['overnight'] ?? null) === false) нет
              @else неясно
              @endif
            </div>
            @if(!empty($ev['overnight']))
              <div class="small text-muted mt-1">«{{ $ev['overnight'] }}»</div>
            @endif
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Trip role</div>
            <div class="fw-semibold">{{ $label[$tags['trip_role'] ?? ''] ?? '—' }}</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">С детьми</div>
            <div class="fw-semibold">{{ $label[$tags['kids'] ?? 'unknown'] ?? 'неясно' }}</div>
            @if(!empty($ev['kids']))
              <div class="small text-muted mt-1">«{{ $ev['kids'] }}»</div>
            @endif
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Кошер</div>
            <div class="fw-semibold">{{ $label[$tags['kosher_food'] ?? 'unknown'] ?? 'неясно' }}</div>
            @if(!empty($ev['kosher_food']))
              <div class="small text-muted mt-1">«{{ $ev['kosher_food'] }}»</div>
            @endif
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Часы на месте</div>
            <div class="fw-semibold">
              @if(isset($tags['visit_hours']) && $tags['visit_hours'] !== null)
                {{ rtrim(rtrim(number_format((float) $tags['visit_hours'], 1, '.', ''), '0'), '.') }} ч
              @else
                —
              @endif
            </div>
            @if(!empty($tags['visit_hours_note']))
              <div class="small text-muted mt-1">{{ $tags['visit_hours_note'] }}</div>
            @endif
            @if(!empty($ev['visit_hours']))
              <div class="small text-muted mt-1">«{{ $ev['visit_hours'] }}»</div>
            @endif
          </div>
        </div>
        <div class="col-md-4">
          <div class="border rounded p-3 h-100">
            <div class="text-muted small">Подвижность</div>
            <div class="fw-semibold">{{ $label[$tags['mobility'] ?? 'unknown'] ?? 'неясно' }}</div>
            @if(!empty($ev['mobility']))
              <div class="small text-muted mt-1">«{{ $ev['mobility'] }}»</div>
            @endif
          </div>
        </div>
      </div>
      <div class="mt-3 d-flex flex-wrap gap-3 align-items-center small text-muted">
        <span>Уверенность: <strong>{{ $tags['confidence'] ?? '—' }}</strong></span>
        @if(!empty($tags['extracted_at']))
          <span>Снято: {{ $tags['extracted_at'] }}</span>
        @endif
        @if(!empty($tags['source_chars']))
          <span>Символов источника: {{ number_format((int) $tags['source_chars']) }}</span>
        @endif
      </div>
      @if(!empty($tags['notes']))
        <div class="alert alert-secondary mt-3 mb-0 small">{{ $tags['notes'] }}</div>
      @endif
    @else
      <p class="text-muted mb-0 small">Ещё не запускали. Нажмите «Разбить на метки» — Gemini предложит черновик по тексту поста.</p>
    @endif
  </div>
</div>

<script>
  (function () {
    const btn = document.querySelector('.location-passport-tags-btn');
    const status = document.querySelector('.location-passport-tags-status');
    if (!btn || !status) return;
    btn.addEventListener('click', async function () {
      if (!confirm('Снять метки через Gemini? Исходный текст не удаляется — только черновик рядом.')) return;
      btn.disabled = true;
      status.style.display = 'block';
      status.classList.remove('text-danger');
      status.textContent = 'Gemini разбирает текст на метки…';
      try {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': btn.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
        window.location.hash = 'passport-tags';
        window.location.reload();
      } catch (e) {
        status.classList.add('text-danger');
        status.textContent = 'Ошибка: ' + (e && e.message ? e.message : e);
        btn.disabled = false;
      }
    });
  })();
</script>
