<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewShortRouteDay extends Model
{
    protected $fillable = [
        'new_short_route_id',
        'day_index',
        'title',
        'title_ru',
        'start_point',
        'start_point_ru',
        'end_point',
        'end_point_ru',
        'overnight_text',
        'overnight_text_ru',
        'is_overnight',
        'overnight_note',
        'overnight_location_id',
        'places',
        'places_ru',
    ];

    protected $casts = [
        'day_index' => 'integer',
        'is_overnight' => 'boolean',
        'places' => 'array',
        'places_ru' => 'array',
    ];

    public function hasRussian(): bool
    {
        return trim((string) ($this->title_ru ?? '')) !== ''
            || trim((string) ($this->overnight_text_ru ?? '')) !== ''
            || trim((string) ($this->end_point_ru ?? '')) !== '';
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(NewShortRoute::class, 'new_short_route_id');
    }

    public function overnightLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'overnight_location_id');
    }
}
