<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaDescription extends Model
{
    protected $fillable = ['area_id', 'language_id', 'name'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
