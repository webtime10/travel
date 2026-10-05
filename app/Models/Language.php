<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    protected $fillable = [
        'code',
        'name',
        'locale',
        'directory',
        'sort_order',
        'status',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'status' => 'boolean',
    ];

    public function categoryDescriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    public static function getActive()
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public static function forAdminForms()
    {
        $langs = static::getActive();
        if ($langs->isEmpty()) {
            $langs = static::query()->orderBy('sort_order')->orderBy('id')->get();
        }

        return $langs;
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->where('is_active', true)->first()
            ?? static::where('is_active', true)->orderBy('sort_order')->first()
            ?? static::query()->orderBy('id')->first();
    }
}
