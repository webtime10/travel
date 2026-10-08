@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Categories</h4>
  <div class="page-toolbar">
    <button type="submit" form="categoriesBulkDeleteForm" class="btn btn-icon btn-outline-danger" title="Delete" onclick="return confirm('Delete selected?');"><i class="bx bx-trash bx-md"></i></button>
    <a href="{{ route('admin.categories.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
  </div>
</div>

<form id="categoriesBulkDeleteForm" method="post" action="{{ route('admin.categories.bulk-delete') }}">@csrf</form>
<div class="card">
  <div class="table-responsive text-nowrap">
    <table class="table table-hover">
      <thead>
        <tr>
          <th style="width:40px"><input type="checkbox" class="form-check-input" id="selectAll"></th>
          <th>#</th>
          <th>Name</th>
          <th>Parent</th>
          <th>Sort</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="table-border-bottom-0">
        @forelse($categories as $item)
          @php
            $heId = $heLanguage?->id;
            $heDesc = $heId ? $item->descriptions->firstWhere('language_id', $heId) : null;
            $defId = $defaultLanguage?->id;
            $d = $heDesc ?? ($defId ? $item->descriptions->firstWhere('language_id', $defId) : null);
            $pd = $item->parent && $defId ? $item->parent->descriptions->firstWhere('language_id', $defId) : null;
            if ($item->parent && $heId) {
              $pd = $item->parent->descriptions->firstWhere('language_id', $heId) ?? $pd;
            }
          @endphp
          <tr>
            <td><input type="checkbox" name="selected[]" value="{{ $item->id }}" class="form-check-input row-check" form="categoriesBulkDeleteForm"></td>
            <td>{{ $item->id }}</td>
            <td dir="rtl">{{ $d->name ?? '—' }}</td>
            <td dir="rtl">{{ $pd->name ?? '—' }}</td>
            <td>{{ $item->sort_order }}</td>
            <td>@if($item->status)<span class="badge bg-label-success">On</span>@else<span class="badge bg-label-secondary">Off</span>@endif</td>
            <td class="text-nowrap">
              <a href="{{ route('admin.categories.edit', $item->id) }}" class="btn btn-icon btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.categories.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
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
</div>
@endsection
@section('scripts')
<script>$('#selectAll').on('change', function () { $('.row-check').prop('checked', this.checked); });</script>
@endsection
