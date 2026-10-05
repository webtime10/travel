@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Languages</h4>
  <div class="page-toolbar">
    <a href="{{ route('admin.languages.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>
<div class="card">
  <div class="table-responsive text-nowrap">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>#</th>
          <th>Code</th>
          <th>Name</th>
          <th>Locale</th>
          <th>Sort</th>
          <th>Default</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="table-border-bottom-0">
        @forelse($languages as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td><strong>{{ strtoupper($item->code) }}</strong></td>
            <td>{{ $item->name }}</td>
            <td><code>{{ $item->locale }}</code></td>
            <td>{{ $item->sort_order }}</td>
            <td>@if($item->is_default)<span class="badge bg-label-success">Yes</span>@else<span class="badge bg-label-secondary">No</span>@endif</td>
            <td>@if($item->is_active)<span class="badge bg-label-success">Yes</span>@else<span class="badge bg-label-secondary">No</span>@endif</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.languages.edit', $item->id) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
              @unless($item->is_default)
              <form action="{{ route('admin.languages.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Delete"><i class="bx bx-trash"></i></button>
              </form>
              @endunless
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($languages->hasPages())
  <div class="card-footer">{{ $languages->links() }}</div>
  @endif
</div>
@endsection
