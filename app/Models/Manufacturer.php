<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manufacturer extends Model
{
    protected $fillable = ['code', 'sort_order', 'status'];

    protected $casts = ['status' => 'boolean'];

    public function descriptions(): HasMany
    {
        return $this->hasMany(ManufacturerDescription::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function nameFor(?Language $lang = null): string
    {
        $lang = $lang ?? Language::admin();
        $d = $lang ? $this->descriptions->firstWhere('language_id', $lang->id) : null;

        return $d->name ?? $this->descriptions->first()?->name ?? ($this->code ?: '#'.$this->id);
    }
}
