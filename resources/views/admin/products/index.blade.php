@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Products</h4>
  <div class="page-toolbar">
    <button type="submit" form="productsBulkDeleteForm" class="btn btn-icon btn-outline-danger" title="Delete" onclick="return confirm('Delete selected?');"><i class="bx bx-trash bx-md"></i></button>
    <a href="{{ route('admin.products.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>
<form id="productsBulkDeleteForm" method="post" action="{{ route('admin.products.bulk-delete') }}">@csrf</form>
<div class="card">
  <div class="table-responsive text-nowrap">
    <table class="table table-hover">
      <thead>
        <tr>
          <th style="width:40px"><input type="checkbox" class="form-check-input" id="selectAll"></th>
          <th>#</th>
          <th>Name</th>
          <th>Categories</th>
          <th>Sort</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="table-border-bottom-0">
        @forelse($products as $item)
          @php $d = $item->descriptions->first(); @endphp
          <tr>
            <td><input type="checkbox" name="selected[]" value="{{ $item->id }}" class="form-check-input row-check" form="productsBulkDeleteForm"></td>
            <td>{{ $item->id }}</td>
            <td>{{ $d->name ?? '—' }}</td>
            <td>
              @foreach($item->categories as $cat)
                @php $cd = $defaultLanguage ? $cat->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                <span class="badge bg-label-primary">{{ $cd->name ?? '#'.$cat->id }}</span>
              @endforeach
            </td>
            <td>{{ $item->sort_order }}</td>
            <td>@if($item->status)<span class="badge bg-label-success">On</span>@else<span class="badge bg-label-secondary">Off</span>@endif</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.products.edit', $item->id) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.products.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-icon btn-sm btn-outline-danger" title="Delete"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center">No data</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($products->hasPages())
  <div class="card-footer">{{ $products->links() }}</div>
  @endif
</div>
@endsection
@section('scripts')
<script>$('#selectAll').on('change', function () { $('.row-check').prop('checked', this.checked); });</script>
@endsection
