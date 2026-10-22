<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Location;
use App\Models\LocationFactDescription;
use RuntimeException;

class LocationFactRuTranslator
{
    public function __construct(private GeminiTranslator $gemini) {}

    /**
     * Translate Hebrew fact fields into location_fact_descriptions for RU.
     *
     * @return array{translated: int, facts: int, fields: int}
     */
    public function translate(Location $location): array
    {
        $he = Language::where('code', 'he')->firstOrFail();
        $ru = Language::where('code', 'ru')->first();
        if (! $ru) {
            throw new RuntimeException('Нет языка ru');
        }

        $location->load('facts.descriptions');
        $items = [];
        foreach ($location->facts as $fact) {
            $d = $fact->descriptions->firstWhere('language_id', $he->id);
            if (! $d) {
                continue;
            }
            foreach (['type', 'name', 'text', 'hours'] as $field) {
                $value = trim((string) ($d->{$field} ?? ''));
                if ($value !== '') {
                    $items[$fact->id.'_'.$field] = $value;
                }
            }
        }
        if ($items === []) {
            throw new RuntimeException('Нет еврейских фактов для перевода');
        }

        $map = [];
        foreach (array_chunk($items, 8, true) as $chunk) {
            try {
                $map += $this->gemini->translateMap($chunk);
            } catch (RuntimeException $e) {
                foreach ($chunk as $key => $text) {
                    try {
                        $map += $this->gemini->translateMap([$key => $text]);
                    } catch (RuntimeException $e2) {
                        // leave this field for a later click
                    }
                }
            }
        }

        $translated = 0;
        foreach ($location->facts as $fact) {
            $heD = $fact->descriptions->firstWhere('language_id', $he->id);
            if (! $heD) {
                continue;
            }
            $row = [];
            foreach (['type', 'name', 'text', 'hours'] as $field) {
                $key = $fact->id.'_'.$field;
                $ruText = trim((string) ($map[$key] ?? ''));
                $src = trim((string) ($heD->{$field} ?? ''));
                if ($src === '') {
                    $row[$field] = null;
                } elseif ($ruText !== '') {
                    $row[$field] = $field === 'name' || $field === 'type' ? mb_substr($ruText, 0, 255) : $ruText;
                    $translated++;
                } else {
                    $row[$field] = $src;
                }
            }
            if (trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            LocationFactDescription::updateOrCreate(
                ['location_fact_id' => $fact->id, 'language_id' => $ru->id],
                $row
            );
        }

        return [
            'translated' => $translated,
            'facts' => $location->facts->count(),
            'fields' => count($items),
        ];
    }
}
