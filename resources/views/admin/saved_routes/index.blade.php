@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0 d-flex align-items-center gap-2">
    Маршруты
    <span class="badge bg-label-primary rounded-pill">{{ $routes->total() }}</span>
  </h4>
  <a href="{{ route('admin.test.php') }}" class="btn btn-outline-primary btn-sm">
    <i class="bx bx-plus me-1"></i>Собрать в Украина
  </a>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<form method="get" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label mb-1">Поиск</label>
        <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Название маршрута…">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">Найти</button>
        @if($q !== '')
          <a href="{{ route('admin.saved-routes.index') }}" class="btn btn-outline-secondary">Сброс</a>
        @endif
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Название</th>
          <th>Страна</th>
          <th>Дней</th>
          <th>Запрос</th>
          <th>Создан</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($routes as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td dir="auto" class="fw-semibold">{{ $item->title }}</td>
            <td>{{ $item->country_code }}</td>
            <td>{{ $item->days_count }}</td>
            <td class="small text-muted" style="max-width:280px;white-space:normal" dir="auto">
              {{ \Illuminate\Support\Str::limit((string) $item->query_text, 80) ?: '—' }}
            </td>
            <td class="small text-nowrap">{{ $item->created_at?->format('d.m.Y H:i') }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.saved-routes.show', $item) }}" class="btn btn-sm btn-primary">
                <i class="bx bx-show me-1"></i>Открыть
              </a>
              <form action="{{ route('admin.saved-routes.destroy', $item) }}" method="post" class="d-inline"
                    onsubmit="return confirm('Удалить маршрут «{{ addslashes($item->title) }}»?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="text-center text-muted py-4">—</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($routes->hasPages())
    <div class="card-footer">{{ $routes->withQueryString()->links() }}</div>
  @endif
</div>
@endsection
