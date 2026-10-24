<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldenDayStop extends Model
{
    protected $fillable = [
        'golden_day_id',
        'sort_order',
        'location_id',
        'source_model',
        'name',
        'name_ru',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function goldenDay(): BelongsTo
    {
        return $this->belongsTo(GoldenDay::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
