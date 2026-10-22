@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">
    Short routes
    <span class="text-muted fw-normal small">· краткие каркасы директора</span>
    <span class="badge bg-label-primary rounded-pill">{{ $total }}</span>
  </h4>
</div>

<form method="get" action="{{ route('admin.route-skeletons.index') }}" class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div class="flex-grow-1" style="min-width:12rem">
      <label class="form-label small mb-1">Поиск</label>
      <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="название, хаб, id, место…">
    </div>
    <div style="width:7rem">
      <label class="form-label small mb-1">Дней</label>
      <input type="number" name="days" value="{{ $days }}" class="form-control" min="1" placeholder="10">
    </div>
    <button type="submit" class="btn btn-primary">Найти</button>
    @if($q !== '' || $days !== null && $days !== '')
      <a href="{{ route('admin.route-skeletons.index') }}" class="btn btn-outline-secondary">Сброс</a>
    @endif
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Python id</th>
          <th>Название</th>
          <th>Дней</th>
          <th>Прилёт → выезд</th>
          <th>Старт</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>{{ $item->source_route_id }}</td>
            <td>
              <a href="{{ route('admin.route-skeletons.show', $item) }}" class="fw-medium text-heading">
                {{ $item->title_ru ?: ($item->title ?: '—') }}
              </a>
              @if($item->title_ru)
                <span class="badge bg-label-success">RU</span>
              @endif
              @if($item->is_template)
                <span class="badge bg-label-info">template</span>
              @endif
            </td>
            <td>{{ $item->days_count }}</td>
            <td class="small">
              {{ $item->arrival_hub ?: '—' }}
              →
              {{ $item->departure_hub ?: '—' }}
            </td>
            <td class="small text-nowrap">{{ $item->start_date?->format('d.m.Y') ?? '—' }}</td>
            <td>
              <a href="{{ route('admin.route-skeletons.show', $item) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Открыть">
                <i class="bx bx-show"></i>
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Нет данных — импорт: php artisan travel:import-route-skeletons</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($items->hasPages())
    <div class="card-footer">{{ $items->links() }}</div>
  @endif
</div>
@endsection
