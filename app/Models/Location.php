<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'model',
        'manufacturer_id',
        'area_id',
        'trip_role',
        'lat',
        'lng',
        'sort_order',
        'weight',
        'visit_hours',
        'kosher_food',
        'status',
        'meta',
        'meta_ru',
        'flexible_blocks',
        'facts_status',
        'facts_coverage',
        'facts_review',
        'facts_split_at',
    ];

    public const TRIP_ROLES = [
        'base' => 'Base (ночёвка)',
        'place' => 'Place (точка)',
        'end' => 'End (выезд/финиш)',
    ];

    /** Flexible Content layouts (admin UI labels). */
    public const FLEXIBLE_LAYOUTS = [
        'text' => 'Текстовое поле',
        'link' => 'Ссылка',
        'photo' => 'Фотография',
        'ad' => 'Рекламный блок',
        'map_screenshot' => 'Скриншот карты',
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
        'flexible_blocks' => 'array',
    ];

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(LocationAttribute::class);
    }

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
    public function nameFor(?Language $lang = null): string
    {
        $lang = $lang ?? Language::admin();
        $d = $lang ? $this->descriptions->firstWhere('language_id', $lang->id) : null;

        return $d->name ?? $this->descriptions->first()?->name ?? ($this->model ?: '#'.$this->id);
    }

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
