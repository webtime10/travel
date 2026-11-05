<?php

namespace App\Models;

use App\Services\FtShortRouteReverseEngineer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtShortRoute extends Model
{
    protected $fillable = [
        'new_short_route_id',
        'doc_code',
        'title',
        'title_ru',
        'days_count',
        'route_text',
        'route_text_ru',
        'days',
        'meta',
        'ru_units',
        'reverse_principle',
        'reverse_principle_ru',
    ];

    protected $casts = [
        'days_count' => 'integer',
        'days' => 'array',
        'meta' => 'array',
        'ru_units' => 'array',
        'reverse_principle' => 'array',
        'reverse_principle_ru' => 'array',
    ];

    public function hasReversePrinciple(): bool
    {
        $rp = $this->reverse_principle;

        return is_array($rp) && empty($rp['skipped']) && $rp !== [] && $this->reversePrincipleIsHebrew($rp);
    }

    /** HE оригинал разбора; RU — только после перевода. */
    public function reversePrincipleForDisplay(bool $preferRu = false): ?array
    {
        if ($preferRu) {
            $ru = $this->reverse_principle_ru;
            if (is_array($ru) && $ru !== [] && empty($ru['skipped'])) {
                return $ru;
            }

            return null;
        }

        $he = $this->reverse_principle;
        if (is_array($he) && $he !== [] && empty($he['skipped']) && $this->reversePrincipleIsHebrew($he)) {
            return $he;
        }

        return null;
    }

    /**
     * Старые разборы ошибочно писались по-русски в reverse_principle.
     * Перекладываем в reverse_principle_ru и чистим HE — нужен новый разбор на иврите.
     */
    public function repairMisplacedRussianReverse(): bool
    {
        $he = $this->reverse_principle;
        if (! is_array($he) || $he === [] || ! empty($he['skipped'])) {
            return false;
        }
        if ($this->reversePrincipleIsHebrew($he)) {
            return false;
        }
        if (! FtShortRouteReverseEngineer::isCyrillicHeavy(FtShortRouteReverseEngineer::sampleText($he))) {
            return false;
        }

        $ru = $this->reverse_principle_ru;
        $ruEmpty = ! is_array($ru) || $ru === [] || ! empty($ru['skipped']);
        if ($ruEmpty) {
            $he['translated_from_he'] = false;
            $he['misplaced_russian_from_he_slot'] = true;
            $he['output_language'] = 'ru';
            $this->reverse_principle_ru = $he;
        }
        $this->reverse_principle = null;
        $this->save();

        return true;
    }

    /** @param  array<string, mixed>  $rp */
    private function reversePrincipleIsHebrew(array $rp): bool
    {
        $sample = FtShortRouteReverseEngineer::sampleText($rp);
        if ($sample === '') {
            return true;
        }
        if (FtShortRouteReverseEngineer::isHebrewHeavy($sample)) {
            return true;
        }
        // Явно русский в слоте HE — не считаем валидным HE.
        if (FtShortRouteReverseEngineer::isCyrillicHeavy($sample)) {
            return false;
        }

        return true;
    }

    public function newShortRoute(): BelongsTo
    {
        return $this->belongsTo(NewShortRoute::class, 'new_short_route_id');
    }

    public function hasRussian(): bool
    {
        return trim((string) ($this->title_ru ?? '')) !== ''
            || trim((string) ($this->route_text_ru ?? '')) !== '';
    }

    /** По умолчанию оригинал HE. */
    public function displayTitle(bool $ru = false): string
    {
        if ($ru && trim((string) ($this->title_ru ?? '')) !== '') {
            return (string) $this->title_ru;
        }

        return (string) ($this->title ?? '');
    }

    /** По умолчанию оригинал HE. */
    public function routeTextForDisplay(bool $preferRu = false): string
    {
        if ($preferRu && trim((string) ($this->route_text_ru ?? '')) !== '') {
            return (string) $this->route_text_ru;
        }

        return (string) ($this->route_text ?? '');
    }
}
