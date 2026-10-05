@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Users</h4>
  <div class="page-toolbar">
    <a href="{{ route('admin.users.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>
<div class="card">
  <div class="table-responsive text-nowrap">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="table-border-bottom-0">
        @forelse($users as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>{{ $item->name }}</td>
            <td>{{ $item->email }}</td>
            <td>{{ $item->roleRelation->name ?? '—' }}</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.users.edit', $item->id) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.users.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Delete"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($users->hasPages())
  <div class="card-footer">{{ $users->links() }}</div>
  @endif
</div>
@endsection
