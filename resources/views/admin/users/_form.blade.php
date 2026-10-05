@php $item = $user ?? null; @endphp
<div class="mb-3">
  <label class="form-label">Name <span class="text-danger">*</span></label>
  <input type="text" name="name" class="form-control" value="{{ old('name', $item->name ?? '') }}" required>
</div>
<div class="mb-3">
  <label class="form-label">Email <span class="text-danger">*</span></label>
  <input type="email" name="email" class="form-control" value="{{ old('email', $item->email ?? '') }}" required>
</div>
<div class="mb-3">
  <label class="form-label">Password @unless($item)<span class="text-danger">*</span>@endunless</label>
  <input type="password" name="password" class="form-control" {{ $item ? '' : 'required' }} minlength="6" autocomplete="new-password">
  @if($item)
    <div class="form-text">Leave blank to keep the current password.</div>
  @endif
</div>
<div class="mb-3">
  <label class="form-label">Confirm password</label>
  <input type="password" name="password_confirmation" class="form-control" {{ $item ? '' : 'required' }} minlength="6" autocomplete="new-password">
</div>
<div class="mb-0">
  <label class="form-label">Role</label>
  <select name="role_id" class="form-select">
    <option value="">—</option>
    @foreach($roles as $role)
      <option value="{{ $role->id }}" {{ (string) old('role_id', $item->role_id ?? '') === (string) $role->id ? 'selected' : '' }}>{{ $role->name }}</option>
    @endforeach
  </select>
</div>
