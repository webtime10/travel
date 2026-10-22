@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Areas <span class="text-muted fw-normal small">· районы тура</span></h4>
  <a href="{{ route('admin.areas.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Центр</th>
          <th>Радиус</th>
          <th>Locations</th>
          <th>Дни</th>
          <th>Sort</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($areas as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>{{ $item->nameFor($defaultLanguage) }}</td>
            <td class="small text-nowrap">
              @if($item->lat !== null && $item->lng !== null)
                {{ number_format((float) $item->lat, 4) }}, {{ number_format((float) $item->lng, 4) }}
              @else
                —
              @endif
            </td>
            <td>{{ $item->radius_km }} км</td>
            <td>{{ $item->locations_count }}</td>
            <td>{{ $item->min_days }}–{{ $item->max_days }}</td>
            <td>{{ $item->sort_order }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.areas.edit', $item) }}" class="btn btn-icon btn-sm btn-outline-primary"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.areas.destroy', $item) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">@csrf @method('DELETE')
                <button class="btn btn-icon btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center">No data — создайте районы (Garda, Molveno…)</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
