@extends('admin.layouts.layout')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0">Golden labels <span class="text-muted fw-normal small">· метки дней</span></h4>
  <a href="{{ route('admin.golden-labels.create') }}" class="btn btn-icon btn-primary" title="Add"><i class="bx bx-plus bx-md"></i></a>
</div>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif

<form method="get" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label mb-1">Страна</label>
        <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="ALL" {{ ($country ?? 'ALL') === 'ALL' ? 'selected' : '' }}>Все</option>
          <option value="UA" {{ ($country ?? '') === 'UA' ? 'selected' : '' }}>Ukraine (+ общие)</option>
          <option value="IT" {{ ($country ?? '') === 'IT' ? 'selected' : '' }}>Italy (+ общие)</option>
          <option value="BOTH" {{ ($country ?? '') === 'BOTH' ? 'selected' : '' }}>Только общие</option>
        </select>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Страна</th>
          <th>Name</th>
          <th>Slug</th>
          <th>Sort</th>
          <th>Active</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($labels as $item)
          <tr>
            <td>{{ $item->id }}</td>
            <td>
              @php $cc = $item->country_code ?: 'BOTH'; @endphp
              @if($cc === 'UA')
                <span class="badge bg-label-primary">UA</span>
              @elseif($cc === 'IT')
                <span class="badge bg-label-info">IT</span>
              @else
                <span class="badge bg-label-secondary">BOTH</span>
              @endif
            </td>
            <td>
              {{ $item->nameFor($defaultLanguage) }}
              <div class="small text-muted">
                @foreach($item->descriptions as $d)
                  <span class="me-2">{{ optional($d->language)->code ?? $d->language_id }}: {{ $d->name }}</span>
                @endforeach
              </div>
            </td>
            <td class="small"><code>{{ $item->slug }}</code></td>
            <td>{{ $item->sort_order }}</td>
            <td>
              @if($item->status)
                <span class="badge bg-label-success">yes</span>
              @else
                <span class="badge bg-label-secondary">no</span>
              @endif
            </td>
            <td class="text-nowrap">
              <a href="{{ route('admin.golden-labels.edit', $item) }}" class="btn btn-icon btn-sm btn-outline-primary"><i class="bx bx-edit-alt"></i></a>
              <form action="{{ route('admin.golden-labels.destroy', $item) }}" method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                @csrf @method('DELETE')
                <button class="btn btn-icon btn-sm btn-outline-danger"><i class="bx bx-trash"></i></button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">Нет меток — создайте (stay, transfer, hard…)</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
