@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Locations</h4>
  <div class="page-toolbar">
    <button type="submit" form="locationForm" class="btn btn-icon btn-primary" title="Save"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.locations.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>
<div class="card">
  <div class="card-body">
    @include('admin.partials.errors')
    <form id="locationForm" action="{{ route('admin.locations.update', $location->id) }}" method="POST">
      @csrf @method('PUT')
      @include('admin.locations._form')
    </form>
  </div>
</div>
@include('admin.locations._facts')
@endsection
