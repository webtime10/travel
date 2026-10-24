<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldenLabelDescription extends Model
{
    protected $fillable = [
        'golden_label_id',
        'language_id',
        'name',
    ];

    public function label(): BelongsTo
    {
        return $this->belongsTo(GoldenLabel::class, 'golden_label_id');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
