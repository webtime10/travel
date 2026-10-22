<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationAttribute extends Model
{
    protected $fillable = ['location_id', 'attribute_id', 'language_id', 'text'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
