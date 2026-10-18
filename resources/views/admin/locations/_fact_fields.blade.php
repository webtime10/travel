<div class="row g-2 mb-2">
  <div class="col-12 col-md-3">
    <label class="form-label small mb-0">Группа</label>
    <select name="group" class="form-select form-select-sm">
      @foreach(\App\Models\LocationFact::GROUPS as $g => $gLabel)
        <option value="{{ $g }}" {{ ($fact->group ?? 'other') === $g ? 'selected' : '' }}>{{ $gLabel }}</option>
      @endforeach
    </select>
  </div>
  <div class="col-12 col-md-3">
    <label class="form-label small mb-0">Тип (свой)</label>
    <input type="text" name="type" class="form-control form-control-sm" dir="auto" value="{{ $d->type ?? '' }}">
  </div>
  <div class="col-12 col-md-5">
    <label class="form-label small mb-0">Название</label>
    <input type="text" name="name" class="form-control form-control-sm" dir="auto" value="{{ $d->name ?? '' }}" required>
  </div>
  <div class="col-12 col-md-1 d-flex align-items-end">
    <input type="hidden" name="is_kosher" value="0">
    <div class="form-check mb-1">
      <input class="form-check-input" type="checkbox" name="is_kosher" value="1" id="fk-{{ $uid }}" {{ ($fact->is_kosher ?? false) ? 'checked' : '' }}>
      <label class="form-check-label small" for="fk-{{ $uid }}">Кошер</label>
    </div>
  </div>
</div>
<div class="row g-2 mb-2">
  <div class="col-12 col-md-7">
    <label class="form-label small mb-0">Текст</label>
    <textarea name="text" class="form-control form-control-sm" dir="auto" rows="2">{{ $d->text ?? '' }}</textarea>
  </div>
  <div class="col-12 col-md-5">
    <label class="form-label small mb-0">Часы</label>
    <textarea name="hours" class="form-control form-control-sm" dir="auto" rows="2">{{ $d->hours ?? '' }}</textarea>
  </div>
</div>
<div class="row g-2 mb-2">
  <div class="col-12 col-md-3">
    <label class="form-label small mb-0">Адрес</label>
    <input type="text" name="address" class="form-control form-control-sm" dir="auto" value="{{ $fact->address ?? '' }}">
  </div>
  @foreach(['map_url' => 'Карта', 'waze_url' => 'Waze', 'website' => 'Сайт'] as $field => $fLabel)
    <div class="col-12 col-md-3">
      <label class="form-label small mb-0">
        {{ $fLabel }}
        @if(!empty($fact?->{$field}))
          <a href="{{ $fact->{$field} }}" target="_blank" rel="noopener noreferrer" class="ms-1"><i class="bx bx-link-external"></i></a>
        @endif
      </label>
      <input type="text" name="{{ $field }}" class="form-control form-control-sm" dir="ltr" value="{{ $fact->{$field} ?? '' }}">
    </div>
  @endforeach
</div>
