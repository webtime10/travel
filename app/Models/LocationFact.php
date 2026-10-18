<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationFact extends Model
{
    public const GROUPS = [
        'about' => 'О месте',
        'parking' => 'Парковка',
        'attraction' => 'Достопримечательность',
        'activity' => 'Активность',
        'food' => 'Еда',
        'overnight' => 'Ночёвка',
        'transport' => 'Транспорт',
        'time' => 'Время и часы',
        'tip' => 'Совет',
        'other' => 'Прочее',
    ];

    protected $fillable = [
        'location_id',
        'group',
        'address',
        'map_url',
        'waze_url',
        'website',
        'is_kosher',
        'source_file_id',
        'sort_order',
    ];

    protected $casts = [
        'is_kosher' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(LocationFactDescription::class);
    }
}
