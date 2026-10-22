<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteSkeleton extends Model
{
    protected $fillable = [
        'source_route_id',
        'title',
        'title_ru',
        'start_date',
        'days_count',
        'is_template',
        'arrival_hub',
        'arrival_hub_ru',
        'departure_hub',
        'departure_hub_ru',
        'search_blob',
        'meta',
        'ru_units',
    ];

    protected $casts = [
        'start_date' => 'date',
        'is_template' => 'boolean',
        'meta' => 'array',
        'ru_units' => 'array',
        'days_count' => 'integer',
    ];

    public function hasRussian(): bool
    {
        return trim((string) ($this->title_ru ?? '')) !== ''
            || (is_array($this->ru_units) && $this->ru_units !== []);
    }

    public function displayTitle(bool $ru = true): string
    {
        if ($ru && trim((string) ($this->title_ru ?? '')) !== '') {
            return (string) $this->title_ru;
        }

        return (string) ($this->title ?? '');
    }

    public function days(): HasMany
    {
        return $this->hasMany(RouteSkeletonDay::class)->orderBy('day_index');
    }

    /**
     * Краткий текст каркаса для промпта / показа.
     */
    public function toBriefText(bool $preferRu = true): string
    {
        $lines = [];
        $title = $this->displayTitle($preferRu);
        if ($title !== '') {
            $lines[] = $title.' ('.$this->days_count.' дн.)';
        }
        foreach ($this->days as $day) {
            $useRu = $preferRu && $day->hasRussian();
            $places = $useRu && is_array($day->places_ru) && $day->places_ru !== []
                ? $day->places_ru
                : (is_array($day->places) ? $day->places : []);
            $names = [];
            foreach ($places as $p) {
                if (is_array($p) && trim((string) ($p['name'] ?? '')) !== '') {
                    $names[] = trim((string) $p['name']);
                }
            }
            $dayTitle = $useRu && trim((string) ($day->title_ru ?? '')) !== ''
                ? (string) $day->title_ru
                : (string) ($day->title ?? '');
            $prog = $names !== [] ? implode(', ', $names) : $dayTitle;
            $axis = $useRu && trim((string) ($day->start_point_ru ?? '')) !== ''
                ? trim((string) $day->start_point_ru)
                : trim((string) ($day->start_point ?? ''));
            $end = $useRu && trim((string) ($day->end_point_ru ?? '')) !== ''
                ? trim((string) $day->end_point_ru)
                : trim((string) ($day->end_point ?? ''));
            if ($end !== '' && $end !== $axis) {
                $axis = $axis !== '' ? $axis.' → '.$end : $end;
            }
            $lines[] = 'День '.$day->day_index.': '.$prog
                .($axis !== '' ? '. Ночёвка: '.$axis : '');
        }

        return implode("\n", $lines);
    }
}
