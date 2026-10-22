@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Attributes</h4>
  <div>
    <button type="submit" form="attrForm" class="btn btn-icon btn-primary"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.attributes.index') }}" class="btn btn-icon btn-outline-secondary"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>
<div class="card"><div class="card-body">
  @include('admin.partials.errors')
  <form id="attrForm" method="post" action="{{ route('admin.attributes.store') }}">@csrf @include('admin.attributes._form')</form>
</div></div>
@endsection
