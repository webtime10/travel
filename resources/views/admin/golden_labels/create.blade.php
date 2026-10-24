@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">New golden label</h4>
  <a href="{{ route('admin.golden-labels.index') }}" class="btn btn-icon btn-outline-secondary"><i class="bx bx-arrow-back bx-md"></i></a>
</div>
<div class="card">
  <div class="card-body">
    <form method="post" action="{{ route('admin.golden-labels.store') }}">
      @csrf
      @include('admin.golden_labels._form')
      <div class="mt-3">
        <button type="submit" class="btn btn-primary">Create</button>
      </div>
    </form>
  </div>
</div>
@endsection
