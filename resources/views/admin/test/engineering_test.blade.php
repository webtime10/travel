@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1">Engineering test</h4>
    <p class="text-muted small mb-0">
      Черновик для глаз: коридор → шаблон → дни (ночёвка + id точек).
      Данные демо (не из БД). Потом сделаем Engineering 2 по директору.
    </p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <a href="{{ route('admin.test.index') }}" class="btn btn-sm btn-outline-secondary">← Test</a>
    <span class="badge bg-label-warning">DEMO</span>
  </div>
</div>

<div class="alert alert-secondary small">
  <strong>Как читать:</strong>
  слева — коридоры (направления).
  В центре — шаблоны внутри коридора (повторяющиеся наборы дней).
  Справа — дни шаблона: <em>ночёвка</em> = где спим, <em>id</em> = локации/блоки программы.
</div>

<div class="row g-3">
  {{-- Коридоры --}}
  <div class="col-12 col-lg-3">
    <div class="card h-100">
      <div class="card-header py-2">
        <span class="fw-semibold">1. Коридоры</span>
        <div class="small text-muted">как назвал бы директор</div>
      </div>
      <div class="list-group list-group-flush">
        @foreach ($corridors as $c)
          <a href="{{ route('admin.test.engineering', ['corridor' => $c['key']]) }}"
             class="list-group-item list-group-item-action {{ ($corridor['key'] ?? '') === $c['key'] ? 'active' : '' }}">
            <div class="fw-semibold">{{ $c['name'] }}</div>
            <div class="small {{ ($corridor['key'] ?? '') === $c['key'] ? 'text-white-50' : 'text-muted' }}">
              хабы: {{ implode(', ', $c['hubs'] ?? []) }}
            </div>
            <div class="small">шаблонов: {{ count($c['templates'] ?? []) }}</div>
          </a>
        @endforeach
      </div>
    </div>
  </div>

  {{-- Шаблоны --}}
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-header py-2">
        <span class="fw-semibold">2. Шаблоны · {{ $corridor['name'] ?? '—' }}</span>
        <div class="small text-muted">{{ $corridor['note'] ?? '' }}</div>
      </div>
      <div class="list-group list-group-flush">
        @forelse ($templates as $t)
          <a href="{{ route('admin.test.engineering', ['corridor' => $corridor['key'], 'template' => $t['id']]) }}"
             class="list-group-item list-group-item-action {{ (($template['id'] ?? null) === $t['id']) ? 'active' : '' }}">
            <div class="d-flex justify-content-between gap-2">
              <span class="fw-semibold">{{ $t['name'] }}</span>
              <span class="badge bg-label-primary">{{ $t['days_count'] }} дн.</span>
            </div>
            <div class="small {{ (($template['id'] ?? null) === $t['id']) ? 'text-white-50' : 'text-muted' }} mt-1">
              сезон: {{ $t['season'] }} · {{ $t['audience'] }}
            </div>
            <div class="small mt-1">
              источники туров: #{{ implode(', #', $t['source_route_ids'] ?? []) }}
              · совпадений ≈ {{ $t['matched'] ?? '—' }}
            </div>
          </a>
        @empty
          <div class="list-group-item text-muted small">Нет шаблонов</div>
        @endforelse
      </div>
    </div>
  </div>

  {{-- Дни --}}
  <div class="col-12 col-lg-5">
    <div class="card h-100">
      <div class="card-header py-2">
        <span class="fw-semibold">3. Дни шаблона</span>
        @if ($template)
          <div class="small text-muted">{{ $template['name'] }}</div>
        @endif
      </div>
      <div class="card-body p-0">
        @if ($template)
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th style="width:3rem">День</th>
                  <th>Программа</th>
                  <th>Ночёвка</th>
                  <th>ID точек</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($template['days'] as $d)
                  <tr class="{{ !empty($d['optional']) ? 'table-warning' : '' }}">
                    <td class="fw-semibold">{{ $d['day_index'] }}</td>
                    <td>
                      {{ $d['title'] }}
                      @if (!empty($d['optional']))
                        <span class="badge bg-label-warning">опция</span>
                      @endif
                    </td>
                    <td><span class="text-primary">{{ $d['overnight'] }}</span></td>
                    <td>
                      @foreach ($d['location_ids'] as $lid)
                        <code class="me-1">#{{ $lid }}</code>
                      @endforeach
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="p-3 small text-muted border-top">
            Жёлтая строка = опциональный день (можно заменить днём из другого шаблона того же коридора).
            При запросе «Рим, 5 дней, зима» система берёт такой шаблон и подставляет эти дни целиком.
          </div>
        @else
          <div class="p-3 text-muted small">Выберите шаблон слева</div>
        @endif
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-body small">
    <div class="fw-semibold mb-2">Демо-запрос → что должно сработать</div>
    <ol class="mb-0 ps-3">
      <li>Коридор <strong>Рим</strong> (= как «Одесская область»): шаблоны «Рим → Остия», «Рим → Тиволи», «Рим → Фраскати».</li>
      <li>«Рим → Остия → Тиволи → Фраскати» — склейка дней из этих трёх коротких (длинного тура может не быть).</li>
      <li>«Тиволи → Фраскати через …» в запросе — подтянутся только названные куски.</li>
      <li>Нет кусков → запасной путь (скелеты / Gemini / Vertex fine-tuning).</li>
    </ol>
  </div>
</div>
@endsection
