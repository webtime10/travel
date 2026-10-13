<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaceCoordinate extends Model
{
    protected $fillable = [
        'place_key',
        'query',
        'lat',
        'lng',
        'display_name',
        'source',
        'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
