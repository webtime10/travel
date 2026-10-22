@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Attribute Groups</h4>
  <div class="d-flex gap-1">
    <a href="{{ route('admin.attributes.index') }}" class="btn btn-outline-secondary">Attributes</a>
    <a href="{{ route('admin.attribute-groups.create') }}" class="btn btn-icon btn-primary"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Name</th><th>Attributes</th><th>Sort</th><th></th></tr></thead>
      <tbody>
        @forelse($groups as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>{{ $item->nameFor($defaultLanguage) }}</td>
            <td>{{ $item->attributes->count() }}</td>
            <td>{{ $item->sort_order }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.attribute-groups.edit', $item) }}" class="btn btn-icon btn-sm btn-outline-primary"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.attribute-groups.destroy', $item) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">@csrf @method('DELETE')
                <button class="btn btn-icon btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
