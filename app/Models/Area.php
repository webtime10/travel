<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = [
        'slug',
        'lat',
        'lng',
        'radius_km',
        'default_base_location_id',
        'min_days',
        'max_days',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'radius_km' => 'float',
        'status' => 'boolean',
    ];

    public function descriptions(): HasMany
    {
        return $this->hasMany(AreaDescription::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function defaultBase(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_base_location_id');
    }

    public function nameFor(?Language $lang = null): string
    {
        $lang = $lang ?? Language::admin();
        $d = $lang ? $this->descriptions->firstWhere('language_id', $lang->id) : null;

        return $d->name ?? $this->descriptions->first()?->name ?? ($this->slug ?: '#'.$this->id);
    }
}
