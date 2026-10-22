<div class="row g-2 mb-2" data-attr-row>
  <div class="col-12 col-md-4">
    <select name="location_attributes[{{ $i }}][attribute_id]" class="form-select form-select-sm">
      <option value="">— атрибут —</option>
      @foreach(($attributeGroups ?? []) as $group)
        <optgroup label="{{ $group->nameFor($defaultLanguage) }}">
          @foreach($group->attributes as $attr)
            <option value="{{ $attr->id }}" {{ (string) ($row['attribute_id'] ?? '') === (string) $attr->id ? 'selected' : '' }}>{{ $attr->nameFor($defaultLanguage) }}</option>
          @endforeach
        </optgroup>
      @endforeach
    </select>
  </div>
  <div class="col-12 col-md-3">
    <input type="text" name="location_attributes[{{ $i }}][text_he]" class="form-control form-control-sm" dir="auto" placeholder="he" value="{{ $row['text_he'] ?? '' }}">
  </div>
  <div class="col-12 col-md-3">
    <input type="text" name="location_attributes[{{ $i }}][text_ru]" class="form-control form-control-sm" placeholder="ru" value="{{ $row['text_ru'] ?? '' }}">
  </div>
  <div class="col-auto">
    <button type="button" class="btn btn-sm btn-outline-danger" data-attr-remove>&times;</button>
  </div>
</div>
