<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GoldenLabel extends Model
{
    protected $fillable = [
        'slug',
        'country_code',
        'sort_order',
        'status',
    ];

    public const COUNTRIES = [
        'BOTH' => 'Общие (IT+UA)',
        'IT' => 'Italy',
        'UA' => 'Ukraine',
    ];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function descriptions(): HasMany
    {
        return $this->hasMany(GoldenLabelDescription::class);
    }

    public function days(): BelongsToMany
    {
        return $this->belongsToMany(GoldenDay::class, 'golden_day_label')->withTimestamps();
    }

    public function nameFor(?Language $lang = null): string
    {
        $lang = $lang ?? Language::admin();
        $d = $lang ? $this->descriptions->firstWhere('language_id', $lang->id) : null;

        return $d->name ?? $this->descriptions->first()?->name ?? ($this->slug ?: '#'.$this->id);
    }

    public static function makeSlug(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'label-'.substr(sha1($name), 0, 8);
        }

        return mb_substr($slug, 0, 64);
    }
}
