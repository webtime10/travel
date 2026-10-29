@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0" dir="auto">{{ $item->title }}</h4>
  <div class="page-toolbar d-flex flex-wrap gap-1">
    <a href="{{ route('admin.saved-routes.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card mb-3">
  <div class="card-header">Название маршрута</div>
  <div class="card-body">
    <form method="post" action="{{ route('admin.saved-routes.update', $item) }}" class="row g-3 align-items-end">
      @csrf @method('PUT')
      <div class="col-md-6">
        <label class="form-label">Название</label>
        <input type="text" name="title" class="form-control" required value="{{ old('title', $item->title) }}" dir="auto">
      </div>
      <div class="col-md-4">
        <label class="form-label">Заметки</label>
        <input type="text" name="notes" class="form-control" value="{{ old('notes', $item->notes) }}" dir="auto">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary">Сохранить название</button>
      </div>
    </form>
    <div class="small text-muted mt-3">
      #{{ $item->id }} · {{ $item->country_code }} · {{ $item->days_count }} дн. · {{ $item->created_at?->format('d.m.Y H:i') }}
    </div>
    @if($item->query_text)
      <div class="mt-2 p-2 rounded bg-label-secondary small" dir="auto">{{ $item->query_text }}</div>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-header">Дни маршрута</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th style="width:4rem">День</th>
          <th>Hub</th>
          <th>Golden day</th>
          <th>Метки</th>
          <th>Стопы</th>
        </tr>
      </thead>
      <tbody>
        @foreach(($item->payload['days'] ?? []) as $row)
          @php
            $gd = $goldenDays->get((int) ($row['golden_day_id'] ?? 0));
            $title = $row['title'] ?? ($gd?->displayTitle() ?: '—');
          @endphp
          <tr>
            <td>{{ $row['day'] ?? '—' }}</td>
            <td>{{ $row['hub'] ?? '—' }}</td>
            <td>
              @if($gd)
                <a href="{{ route('admin.golden-days.show', $gd) }}">#{{ $gd->id }}</a>
                <span class="ms-1" dir="auto">{{ $title }}</span>
              @else
                #{{ $row['golden_day_id'] ?? '—' }} <span dir="auto">{{ $title }}</span>
              @endif
            </td>
            <td class="small">
              @foreach(($row['labels'] ?? []) as $lab)
                <span class="badge bg-label-info me-1">{{ is_string($lab) ? $lab : ($lab['slug'] ?? '') }}</span>
              @endforeach
              @if(empty($row['labels']) && $gd)
                @foreach($gd->labels as $lab)
                  <span class="badge bg-label-secondary me-1">{{ $lab->slug }}</span>
                @endforeach
              @endif
            </td>
            <td class="small" dir="auto">
              @if($gd)
                {{ implode(' → ', array_slice($gd->stopNames(true), 0, 5)) }}{{ $gd->stops_count > 5 ? '…' : '' }}
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
@endsection
