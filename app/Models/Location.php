<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'model',
        'lat',
        'lng',
        'sort_order',
        'status',
        'meta',
        'meta_ru',
        'facts_status',
        'facts_coverage',
        'facts_review',
        'facts_split_at',
    ];

    public const FACTS_STATUSES = [
        'ok' => ['label' => 'OK', 'class' => 'bg-label-success'],
        'warn' => ['label' => 'Посмотреть', 'class' => 'bg-label-warning'],
        'bad' => ['label' => 'Проверить', 'class' => 'bg-label-danger'],
        'error' => ['label' => 'Ошибка', 'class' => 'bg-label-dark'],
    ];

    protected $casts = [
        'facts_review' => 'array',
        'facts_split_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'status' => 'boolean',
        'meta' => 'array',
        'meta_ru' => 'array',
    ];

    public function descriptions(): HasMany
    {
        return $this->hasMany(LocationDescription::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(LocationFact::class)->orderBy('sort_order')->orderBy('id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_location');
    }

    /**
     * Hebrew (source) JSON without RU fields.
     *
     * @return array<string, mixed>|null
     */
    public function heMeta(): ?array
    {
        return is_array($this->meta) ? $this->meta : null;
    }

    /**
     * Russian JSON (separate full copy).
     *
     * @return array<string, mixed>|null
     */
    public function ruMeta(): ?array
    {
        return is_array($this->meta_ru) ? $this->meta_ru : null;
    }
}
