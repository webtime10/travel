<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sort_order',
        'status',
        'meta',
    ];

    protected $casts = [
        'status' => 'boolean',
        'meta' => 'array',
    ];

    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }
}
