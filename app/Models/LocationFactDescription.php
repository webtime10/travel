<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationFactDescription extends Model
{
    protected $fillable = [
        'location_fact_id',
        'language_id',
        'type',
        'name',
        'text',
        'hours',
    ];

    public function fact(): BelongsTo
    {
        return $this->belongsTo(LocationFact::class, 'location_fact_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
