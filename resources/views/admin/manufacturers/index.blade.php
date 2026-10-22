@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Manufacturers <span class="text-muted fw-normal small">· страны</span></h4>
  <a href="{{ route('admin.manufacturers.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Code</th><th>Name</th><th>Locations</th><th>Sort</th><th></th></tr></thead>
      <tbody>
        @forelse($manufacturers as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>{{ $item->code ?: '—' }}</td>
            <td>{{ $item->nameFor($defaultLanguage) }}</td>
            <td>{{ $item->locations_count }}</td>
            <td>{{ $item->sort_order }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.manufacturers.edit', $item) }}" class="btn btn-icon btn-sm btn-outline-primary"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.manufacturers.destroy', $item) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">@csrf @method('DELETE')
                <button class="btn btn-icon btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
