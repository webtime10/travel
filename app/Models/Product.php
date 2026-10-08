<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'model',
        'sort_order',
        'status',
        'meta',
        'meta_ru',
    ];

    protected $casts = [
        'status' => 'boolean',
        'meta' => 'array',
        'meta_ru' => 'array',
    ];

    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    /**
     * Hebrew (source) sequential JSON without RU fields.
     *
     * @return array<string, mixed>|null
     */
    public function heMeta(): ?array
    {
        $meta = $this->meta;
        if (! is_array($meta) || ($meta['format'] ?? '') !== 'docx_sequential_v1') {
            return null;
        }

        return $meta;
    }

    /**
     * Russian sequential JSON (separate full copy).
     *
     * @return array<string, mixed>|null
     */
    public function ruMeta(): ?array
    {
        $meta = $this->meta_ru;
        if (! is_array($meta) || ($meta['format'] ?? '') !== 'docx_sequential_v1') {
            return null;
        }

        return $meta;
    }
}
