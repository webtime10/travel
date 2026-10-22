<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteSkeletonDay extends Model
{
    protected $fillable = [
        'route_skeleton_id',
        'day_index',
        'title',
        'title_ru',
        'start_point',
        'start_point_ru',
        'end_point',
        'end_point_ru',
        'places',
        'places_ru',
    ];

    protected $casts = [
        'day_index' => 'integer',
        'places' => 'array',
        'places_ru' => 'array',
    ];

    public function hasRussian(): bool
    {
        return trim((string) ($this->title_ru ?? '')) !== ''
            || trim((string) ($this->start_point_ru ?? '')) !== ''
            || (is_array($this->places_ru) && $this->places_ru !== []);
    }

    public function skeleton(): BelongsTo
    {
        return $this->belongsTo(RouteSkeleton::class, 'route_skeleton_id');
    }
}
