@php
  $heLang = $languages->firstWhere('code', 'he');
  $facts = $location->facts;
  $groupCounts = $facts->countBy('group');
  $review = is_array($location->facts_review) ? $location->facts_review : [];
  $st = \App\Models\Location::FACTS_STATUSES[$location->facts_status] ?? null;
  $issueKinds = [
    'invented' => 'Выдумано',
    'wrong_data' => 'Неверные данные',
    'misattributed' => 'Не к тому месту',
    'missing' => 'Пропущено',
    'wrong_group' => 'Не та группа',
    'other' => 'Другое',
  ];
  $issuesByFact = collect($review['issues'] ?? [])->groupBy(fn ($i) => $i['fact'] ?? 0);
@endphp

<div class="card mt-4" id="facts">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <h5 class="mb-0 d-flex align-items-center gap-2">
        Факты
        <span class="badge bg-label-primary rounded-pill">{{ $facts->count() }}</span>
        @foreach(\App\Models\LocationFact::GROUPS as $g => $gLabel)
          @if($groupCounts->get($g))
            <span class="badge bg-label-secondary fw-normal">{{ $gLabel }}: {{ $groupCounts->get($g) }}</span>
          @endif
        @endforeach
      </h5>
      <div class="d-flex gap-1">
        @if($facts->count())
          <button
            type="button"
            class="btn btn-outline-secondary location-check-facts-btn"
            data-url="{{ route('admin.locations.check-facts', $location->id) }}"
            data-csrf="{{ csrf_token() }}"
            title="Проверить заново после ручных правок"
          >
            <i class="bx bx-check-shield me-1"></i> Проверить
          </button>
        @endif
        <button
          type="button"
          class="btn btn-outline-primary location-split-facts-btn"
          data-url="{{ route('admin.locations.split-facts', $location->id) }}"
          data-csrf="{{ csrf_token() }}"
          data-count="{{ $facts->count() }}"
        >
          <i class="bx bx-git-branch me-1"></i> Разбить
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#factNew">
          <i class="bx bx-plus me-1"></i> Добавить
        </button>
      </div>
    </div>
    <div class="small text-muted mb-3 location-split-facts-status" style="display:none;"></div>

    @if($st || !empty($review['error']))
      <div class="border rounded p-3 mb-3 {{ $st['class'] ?? 'bg-label-dark' }}">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-1">
          <span class="fw-bold">{{ $st['label'] ?? 'Ошибка' }}</span>
          @isset($review['coverage'])
            <span>Покрытие текста: <b>{{ $review['coverage'] }}%</b></span>
          @endisset
          <span>Замечаний: <b>{{ count($review['issues'] ?? []) }}</b></span>
          @if(!empty($review['dropped_links']))
            <span>Отброшено выдуманных ссылок/адресов: <b>{{ $review['dropped_links'] }}</b></span>
          @endif
          @if(!empty($review['checked_at']))
            <span class="small opacity-75">{{ \Illuminate\Support\Carbon::parse($review['checked_at'])->format('d.m.Y H:i') }}</span>
          @endif
        </div>
        @if(!empty($review['error']))
          <div class="small">{{ $review['error'] }}</div>
        @endif
        @if(!empty($review['issues']))
          <ul class="mb-1 ps-3 small">
            @foreach($review['issues'] as $issue)
              <li>
                <b>{{ $issueKinds[$issue['kind']] ?? $issue['kind'] }}</b>
                @if(!empty($issue['fact']))
                  · <a href="#fact-{{ $facts->values()->get($issue['fact'] - 1)?->id }}">ячейка #{{ $issue['fact'] }}</a>
                @endif
                — {{ $issue['note'] }}
              </li>
            @endforeach
          </ul>
        @endif
        @if(!empty($review['repaired']['issues_before']))
          <details class="small mb-1">
            <summary>Исправлено автоматически: {{ count($review['repaired']['issues_before']) }} замечаний (правок: {{ $review['repaired']['changed'] ?? 0 }})</summary>
            <ul class="mb-0 ps-3 mt-1">
              @foreach($review['repaired']['issues_before'] as $issue)
                <li><b>{{ $issueKinds[$issue['kind']] ?? $issue['kind'] }}</b> — {{ $issue['note'] }}</li>
              @endforeach
            </ul>
          </details>
        @endif
        @if(!empty($review['uncovered']))
          <details class="small">
            <summary>Строки Word, не попавшие в факты ({{ count($review['uncovered']) }})</summary>
            <ul class="mb-0 ps-3 mt-1" dir="auto">
              @foreach($review['uncovered'] as $line)
                <li dir="auto">{{ $line }}</li>
              @endforeach
            </ul>
          </details>
        @endif
      </div>
    @endif

    <div class="collapse mb-3" id="factNew">
      <form method="POST" action="{{ route('admin.locations.facts.store', $location->id) }}" class="border rounded p-3 bg-light">
        @csrf
        @include('admin.locations._fact_fields', ['fact' => null, 'd' => null, 'uid' => 'new'])
        <button type="submit" class="btn btn-primary btn-sm">Добавить факт</button>
      </form>
    </div>

    @forelse($facts as $fact)
      @php $d = $heLang ? $fact->descriptions->firstWhere('language_id', $heLang->id) : $fact->descriptions->first(); @endphp
      <div class="border rounded p-3 mb-2 {{ $issuesByFact->has($loop->iteration) ? 'border-danger' : '' }}" id="fact-{{ $fact->id }}">
        <div class="small text-muted mb-1">#{{ $loop->iteration }}</div>
        @foreach($issuesByFact->get($loop->iteration, []) as $issue)
          <div class="small text-danger mb-1"><b>{{ $issueKinds[$issue['kind']] ?? $issue['kind'] }}:</b> {{ $issue['note'] }}</div>
        @endforeach
        <form method="POST" action="{{ route('admin.locations.facts.update', [$location->id, $fact->id]) }}">
          @csrf @method('PUT')
          @include('admin.locations._fact_fields', ['fact' => $fact, 'd' => $d, 'uid' => $fact->id])
          <div class="d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bx bx-save me-1"></i>Сохранить</button>
            <button type="submit" form="factDelete-{{ $fact->id }}" class="btn btn-sm btn-outline-danger" onclick="return confirm('Удалить факт?');"><i class="bx bx-trash"></i></button>
          </div>
        </form>
        <form id="factDelete-{{ $fact->id }}" method="POST" action="{{ route('admin.locations.facts.destroy', [$location->id, $fact->id]) }}">
          @csrf @method('DELETE')
        </form>
      </div>
    @empty
      <p class="text-muted mb-0">Фактов пока нет. Нажмите «Разбить» — Gemini разложит текст Word по ячейкам.</p>
    @endforelse
  </div>
</div>

<script>
  (function () {
    const btn = document.querySelector('.location-split-facts-btn');
    const status = document.querySelector('.location-split-facts-status');
    if (!btn) return;
    btn.addEventListener('click', async function () {
      const count = Number(btn.dataset.count || 0);
      const msg = count > 0
        ? ('Разбить заново? Текущие ' + count + ' фактов (и ручные правки) будут заменены.')
        : 'Разбить текст Word на факты через Gemini?';
      if (!confirm(msg)) return;
      btn.disabled = true;
      status.style.display = 'block';
      status.classList.remove('text-danger');
      status.textContent = 'Gemini разбирает текст и проверяет результат… (до минуты)';
      try {
        const res = await fetch(btn.dataset.url, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': btn.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
        status.textContent = 'Готово: ' + data.count + ' фактов, покрытие ' + data.coverage + '%'
          + (data.repaired ? (' · автоисправлений: ' + data.repaired) : '')
          + (data.dropped_links ? (' · отброшено выдуманных ссылок/адресов: ' + data.dropped_links) : '') + '. Обновляю…';
        window.location.hash = 'facts';
        window.location.reload();
      } catch (e) {
        status.classList.add('text-danger');
        status.textContent = 'Ошибка: ' + (e && e.message ? e.message : e);
        btn.disabled = false;
      }
    });

    const checkBtn = document.querySelector('.location-check-facts-btn');
    if (checkBtn) {
      checkBtn.addEventListener('click', async function () {
        checkBtn.disabled = true;
        status.style.display = 'block';
        status.classList.remove('text-danger');
        status.textContent = 'Проверяю факты…';
        try {
          const res = await fetch(checkBtn.dataset.url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': checkBtn.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest' },
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) throw new Error(data.message || ('HTTP ' + res.status));
          window.location.hash = 'facts';
          window.location.reload();
        } catch (e) {
          status.classList.add('text-danger');
          status.textContent = 'Ошибка: ' + (e && e.message ? e.message : e);
          checkBtn.disabled = false;
        }
      });
    }
  })();
</script>
