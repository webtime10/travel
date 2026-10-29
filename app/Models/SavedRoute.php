<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedRoute extends Model
{
    protected $fillable = [
        'title',
        'country_code',
        'days_count',
        'query_text',
        'payload',
        'prefs',
        'notes',
    ];

    protected $casts = [
        'payload' => 'array',
        'prefs' => 'array',
        'days_count' => 'integer',
    ];
}
