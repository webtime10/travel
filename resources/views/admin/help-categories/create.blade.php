@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Help — Add</h4>
  <div class="page-toolbar">
    <button type="submit" form="helpCategoryForm" class="btn btn-icon btn-primary" title="Save"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.help-categories.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>
<div class="card">
  <div class="card-body">
    @include('admin.partials.errors')
    <form id="helpCategoryForm" action="{{ route('admin.help-categories.store') }}" method="POST">
      @csrf
      @include('admin.help-categories._form')
    </form>
  </div>
</div>
@endsection

@push('styles')
@include('admin.help-categories._summernote-styles')
@endpush

@push('scripts')
@include('admin.help-categories._summernote-scripts')
@endpush
