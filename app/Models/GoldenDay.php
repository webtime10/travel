<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoldenDay extends Model
{
    protected $fillable = [
        'title',
        'title_ru',
        'area_id',
        'category_id',
        'hub_key',
        'search_blob',
        'source_skeleton_id',
        'source_skeleton_day_id',
        'source_day_index',
        'start_point',
        'start_point_ru',
        'end_point',
        'end_point_ru',
        'signature',
        'stops_count',
        'usage_count',
        'is_active',
        'junk_reason',
        'meta',
        'ru_units',
    ];

    protected $casts = [
        'meta' => 'array',
        'ru_units' => 'array',
        'is_active' => 'boolean',
        'stops_count' => 'integer',
        'usage_count' => 'integer',
        'source_day_index' => 'integer',
    ];

    public function hasRussian(): bool
    {
        return trim((string) ($this->title_ru ?? '')) !== '';
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(GoldenDayStop::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sourceSkeleton(): BelongsTo
    {
        return $this->belongsTo(RouteSkeleton::class, 'source_skeleton_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(GoldenLabel::class, 'golden_day_label')->withTimestamps();
    }

    public function displayTitle(): string
    {
        $ru = trim((string) ($this->title_ru ?? ''));
        if ($ru !== '') {
            return $ru;
        }

        return trim((string) ($this->title ?? '')) ?: ('Golden day #'.$this->id);
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    public function stopNames(bool $preferRu = true): array
    {
        $names = [];
        foreach ($this->stops as $stop) {
            $n = '';
            if ($preferRu) {
                $n = trim((string) ($stop->name_ru ?? ''));
            }
            if ($n === '') {
                $n = trim((string) ($stop->name ?? ''));
            }
            if ($n === '' && $stop->location) {
                $n = $stop->location->nameFor();
            }
            if ($n !== '') {
                $names[] = $n;
            }
        }

        return $names;
    }

    public function overnightAxis(bool $preferRu = true): string
    {
        $axis = $preferRu
            ? trim((string) ($this->start_point_ru ?? ''))
            : '';
        if ($axis === '') {
            $axis = trim((string) ($this->start_point ?? ''));
        }
        $end = $preferRu
            ? trim((string) ($this->end_point_ru ?? ''))
            : '';
        if ($end === '') {
            $end = trim((string) ($this->end_point ?? ''));
        }
        if ($end !== '' && $end !== $axis) {
            return $axis !== '' ? $axis.' → '.$end : $end;
        }

        return $axis;
    }

    public function toBriefText(bool $preferRu = true): string
    {
        $names = $this->stopNames($preferRu);
        $line = $this->displayTitle().' ('.$this->stops_count.' точек)';
        if ($names !== []) {
            $line .= ': '.implode(' → ', $names);
        }
        $axis = $this->overnightAxis($preferRu);
        if ($axis !== '') {
            $line .= '. Ночёвка: '.$axis;
        }

        return $line;
    }
}
