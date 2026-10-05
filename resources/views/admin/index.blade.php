@extends('admin.layouts.layout')

@section('content')
<div class="card">
  <div class="card-body">
    <h5 class="card-title mb-1">Dashboard</h5>
    <p class="mb-0 text-muted">Welcome, {{ auth()->user()->name }}.</p>
  </div>
</div>
@endsection
