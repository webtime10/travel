@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Attribute Groups</h4>
  <div>
    <button type="submit" form="agForm" class="btn btn-icon btn-primary"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.attribute-groups.index') }}" class="btn btn-icon btn-outline-secondary"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>
<div class="card"><div class="card-body">
  @include('admin.partials.errors')
  <form id="agForm" method="post" action="{{ route('admin.attribute-groups.update', $group) }}">@csrf @method('PUT') @include('admin.attribute_groups._form')</form>
</div></div>
@endsection
