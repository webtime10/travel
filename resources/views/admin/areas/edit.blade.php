@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Areas · {{ $area->nameFor($defaultLanguage) }}</h4>
  <div>
    <button type="submit" form="areaForm" class="btn btn-icon btn-primary"><i class="bx bx-save bx-md"></i></button>
    <a href="{{ route('admin.areas.index') }}" class="btn btn-icon btn-outline-secondary"><i class="bx bx-arrow-back bx-md"></i></a>
  </div>
</div>
<div class="card mb-4"><div class="card-body">
  @include('admin.partials.errors')
  <form id="areaForm" method="post" action="{{ route('admin.areas.update', $area) }}">@csrf @method('PUT') @include('admin.areas._form')</form>
</div></div>

<div class="card">
  <div class="card-body">
    <h5 class="mb-2">Авто-привязка локаций</h5>
    <p class="text-muted small mb-3">
      Проставит <code>area_id</code> всем локациям с координатами внутри радиуса
      ({{ $area->radius_km }} км от центра). Уже привязанные к другому району по умолчанию не трогает.
    </p>
    <form method="post" action="{{ route('admin.areas.assign-locations', $area) }}" class="d-flex flex-wrap align-items-center gap-3">
      @csrf
      <div class="form-check mb-0">
        <input class="form-check-input" type="checkbox" name="force" value="1" id="aforce">
        <label class="form-check-label" for="aforce">Перезаписать чужой area_id</label>
      </div>
      <button type="submit" class="btn btn-outline-primary" onclick="return confirm('Привязать локации в радиусе?');">
        Привязать локации в радиусе
      </button>
    </form>
  </div>
</div>
@endsection
