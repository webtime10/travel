@extends('admin.layouts.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Users</h4>
  <div class="page-toolbar">
    <button type="submit" form="userForm" class="btn btn-icon btn-primary" title="Save"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.users.index') }}" class="btn btn-icon btn-outline-secondary" title="Back"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>
<div class="card">
  <div class="card-body">
    @include('admin.partials.errors')
    <form id="userForm" action="{{ route('admin.users.update', $user->id) }}" method="POST">
      @csrf @method('PUT')
      @include('admin.users._form')
    </form>
  </div>
</div>
@endsection
