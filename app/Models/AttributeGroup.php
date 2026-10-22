<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeGroup extends Model
{
    protected $fillable = ['sort_order'];

    public function descriptions(): HasMany
    {
        return $this->hasMany(AttributeGroupDescription::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class)->orderBy('sort_order')->orderBy('id');
    }

    public function nameFor(?Language $lang = null): string
    {
        $lang = $lang ?? Language::admin();
        $d = $lang ? $this->descriptions->firstWhere('language_id', $lang->id) : null;

        return $d->name ?? $this->descriptions->first()?->name ?? '#'.$this->id;
    }
}
