<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewShortRoute extends Model
{
    protected $fillable = [
        'doc_code',
        'source_filename',
        'title',
        'title_ru',
        'start_date',
        'days_count',
        'arrival_hub',
        'arrival_hub_ru',
        'departure_hub',
        'departure_hub_ru',
        'arrival_location_id',
        'departure_location_id',
        'search_blob',
        'route_text',
        'route_text_ru',
        'body_text',
        'body_text_ru',
        'meta',
        'ru_units',
    ];

    protected $casts = [
        'start_date' => 'date',
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
        return $this->hasMany(NewShortRouteDay::class)->orderBy('day_index');
    }

    public function ftShortRoute(): HasOne
    {
        return $this->hasOne(FtShortRoute::class, 'new_short_route_id');
    }

    public function arrivalLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'arrival_location_id');
    }

    public function departureLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'departure_location_id');
    }

    public function toBriefText(bool $preferRu = true): string
    {
        if ($preferRu && trim((string) ($this->route_text_ru ?? '')) !== '') {
            return (string) $this->route_text_ru;
        }
        if (trim((string) ($this->route_text ?? '')) !== '') {
            return (string) $this->route_text;
        }

        $lines = [];
        $title = $this->displayTitle($preferRu);
        if ($title !== '') {
            $lines[] = $title.' ('.$this->days_count.' дн.)';
        }
        foreach ($this->days as $day) {
            $useRu = $preferRu && $day->hasRussian();
            $dayTitle = $useRu && trim((string) ($day->title_ru ?? '')) !== ''
                ? (string) $day->title_ru
                : (string) ($day->title ?? '');
            $lines[] = 'День '.$day->day_index.': '.($dayTitle !== '' ? $dayTitle : '—');
        }

        return implode("\n", $lines);
    }

    public function bodyForDisplay(bool $preferRu = true): string
    {
        if ($preferRu && trim((string) ($this->body_text_ru ?? '')) !== '') {
            return (string) $this->body_text_ru;
        }

        return (string) ($this->body_text ?? '');
    }

    /**
     * Маршрут для FineTunes: без календарных дат (14/4) и дней недели.
     * В карточке route_text остаётся как есть.
     */
    public function routeTextForFineTune(bool $preferRu = true): string
    {
        $raw = $this->toBriefText($preferRu);
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            // «Среда - 14/4 - места» / «יום … - 14/4 - …» → места
            $t = preg_replace(
                '/^(?:Воскресенье|Понедельник|Вторник|Среда|Четверг|Пятница|Суббота|יוֹ?ם\s+\S+|יום\s+\S+)\s*[-–—]\s*\d{1,2}\s*\/\s*\d{1,2}\s*[-–—]\s*/u',
                '',
                $t
            ) ?? $t;
            // на всякий: голая дата в начале «14/4 - »
            $t = preg_replace('/^\d{1,2}\s*\/\s*\d{1,2}\s*[-–—]\s*/u', '', $t) ?? $t;
            $t = trim($t);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return implode("\n", $out);
    }
}
