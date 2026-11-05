<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationDescription;

/**
 * Soft match overnight / hub text → locations.id
 */
class NewShortRouteLocationMatcher
{
    /**
     * @return array{id: int, name: string, score: float}|null
     */
    public function match(string $text): ?array
    {
        $q = $this->normalize($text);
        if ($q === '' || mb_strlen($q) < 3) {
            return null;
        }

        // Direct model / name hits
        $candidates = LocationDescription::query()
            ->select(['location_id', 'name'])
            ->where('name', 'like', '%'.$this->likeEscape($q).'%')
            ->orderBy('location_id')
            ->limit(20)
            ->get();

        if ($candidates->isEmpty()) {
            // Try first significant token (Hebrew / Latin)
            $token = $this->mainToken($q);
            if ($token !== null && $token !== $q) {
                $candidates = LocationDescription::query()
                    ->select(['location_id', 'name'])
                    ->where('name', 'like', '%'.$this->likeEscape($token).'%')
                    ->orderBy('location_id')
                    ->limit(20)
                    ->get();
            }
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $row) {
            $name = $this->normalize((string) $row->name);
            if ($name === '') {
                continue;
            }
            $score = 0.0;
            if ($name === $q) {
                $score = 1.0;
            } elseif (str_contains($name, $q) || str_contains($q, $name)) {
                $score = 0.85;
            } else {
                similar_text($q, $name, $pct);
                $score = $pct / 100.0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'id' => (int) $row->location_id,
                    'name' => (string) $row->name,
                    'score' => $score,
                ];
            }
        }

        if ($best === null || $bestScore < 0.45) {
            // Airport codes
            if (preg_match('/\b(MXP|LIN|BGY|FCO|CIA|NAP|VCE|VRN|TRN|GOA)\b/ui', $text, $m)) {
                $code = strtoupper($m[1]);
                $hit = Location::query()
                    ->where('model', 'like', '%'.$code.'%')
                    ->orWhereHas('descriptions', fn ($w) => $w->where('name', 'like', '%'.$code.'%'))
                    ->orderBy('id')
                    ->first();
                if ($hit) {
                    $dn = $hit->descriptions->first()?->name ?? $code;

                    return ['id' => (int) $hit->id, 'name' => (string) $dn, 'score' => 0.7];
                }
            }

            return null;
        }

        return $best;
    }

    private function normalize(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';

        return mb_strtolower($s);
    }

    private function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    private function mainToken(string $q): ?string
    {
        $parts = preg_split('/[\s,.;:\/\-–—]+/u', $q) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => mb_strlen($p) >= 4));
        if ($parts === []) {
            return null;
        }
        usort($parts, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $parts[0];
    }
}
