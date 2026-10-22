@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-1">Test</h4>
    <p class="text-muted mb-0 small">Тестовые чаты сборки маршрута — без продакшена.</p>
  </div>
  <div class="d-flex gap-2">
    <span class="badge bg-label-success">В векторе: {{ (int) $indexed }}</span>
    <span class="badge bg-label-primary">Gemini keys: {{ $geminiKeys }}</span>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-md-6">
    <a href="{{ route('admin.test.locations') }}" class="card h-100 text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <span class="avatar avatar-md bg-label-primary rounded">
            <i class="bx bx-message-rounded-dots bx-md"></i>
          </span>
          <div>
            <h5 class="mb-1 text-body">Поиск мест</h5>
            <p class="mb-0 small text-muted">
              PHP: каталог → Packer / Refiner / TIME → текст дней. Бухгалтерский путь.
            </p>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-12 col-md-6">
    <a href="{{ route('admin.test.scenario') }}" class="card h-100 text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <span class="avatar avatar-md bg-label-info rounded">
            <i class="bx bx-map-alt bx-md"></i>
          </span>
          <div>
            <h5 class="mb-1 text-body">Gemini сценарий (тест 2)</h5>
            <p class="mb-0 small text-muted">
              Сборка + один аудит. Средние промпты.
            </p>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-12 col-md-6">
    <a href="{{ route('admin.test.chain') }}" class="card h-100 text-decoration-none">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <span class="avatar avatar-md bg-label-warning rounded">
            <i class="bx bx-git-branch bx-md"></i>
          </span>
          <div>
            <h5 class="mb-1 text-body">Тест 3 — Цепочка</h5>
            <p class="mb-0 small text-muted">
              V1 → география → день → покрытие → V2. Жёсткие узкие промпты.
            </p>
          </div>
        </div>
      </div>
    </a>
  </div>
</div>
@endsection
