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
        'reverse_principle_claude',
        'reverse_principle_claude_ru',
    ];

    protected $casts = [
        'days_count' => 'integer',
        'days' => 'array',
        'meta' => 'array',
        'ru_units' => 'array',
        'reverse_principle' => 'array',
        'reverse_principle_ru' => 'array',
        'reverse_principle_claude' => 'array',
        'reverse_principle_claude_ru' => 'array',
    ];

    public function hasReversePrinciple(): bool
    {
        $rp = $this->reverse_principle;

        return is_array($rp) && empty($rp['skipped']) && $rp !== [] && $this->reversePrincipleIsHebrew($rp);
    }

    /** Claude/Anthropic разбор (отдельный слот, не трогает Gemini). */
    public function hasReversePrincipleClaude(): bool
    {
        $rp = $this->reverse_principle_claude;

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

    /** Claude HE / RU — параллельно Gemini. */
    public function reversePrincipleClaudeForDisplay(bool $preferRu = false): ?array
    {
        if ($preferRu) {
            $ru = $this->reverse_principle_claude_ru;
            if (is_array($ru) && $ru !== [] && empty($ru['skipped'])) {
                return $ru;
            }

            return null;
        }

        $he = $this->reverse_principle_claude;
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
        // Пустой каркас (отказ/hollow) не считаем валидным HE-разбором.
        if (FtShortRouteReverseEngineer::isHollowPrinciple($rp)) {
            return false;
        }

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

    /**
     * Снести разбор Gemini или Claude (HE + RU). Маршрут не трогает.
     *
     * @param  'gemini'|'claude'  $provider
     */
    public function clearReversePrinciple(string $provider = 'gemini'): void
    {
        $provider = strtolower(trim($provider)) === 'claude' ? 'claude' : 'gemini';

        if ($provider === 'claude') {
            $this->reverse_principle_claude = null;
            $this->reverse_principle_claude_ru = null;
            $meta = is_array($this->meta) ? $this->meta : [];
            unset($meta['claude_ru_units']);
            $this->meta = $meta === [] ? null : $meta;
            $this->save();

            return;
        }

        $this->reverse_principle = null;
        $this->reverse_principle_ru = null;
        $this->save();

        $this->loadMissing('newShortRoute');
        $nsr = $this->newShortRoute;
        if ($nsr) {
            $nsr->reverse_principle = null;
            $nsr->reverse_principle_ru = null;
            $nsr->save();
        }
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
