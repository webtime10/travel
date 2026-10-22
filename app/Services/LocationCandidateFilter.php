<?php

namespace App\Services;

/**
 * Вектор даёт 30 кандидатов → оставляем 10–15 нормальных точек.
 * Дубли, multi-day, мусор — до расклада дней.
 */
class LocationCandidateFilter
{
    private const MAX_KEEP = 18;

    /** Точка дальше этого км от ближайшей базы поездки — чужой регион (Сардиния и т.п.). */
    private const MAX_KM_FROM_BASE = 200.0;

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array{locations: list<array<string, mixed>>, dropped: list<string>}
     */
    public function filter(array $locations): array
    {
        $dropped = [];
        $kept = [];
        $seenKeys = [];
        $seenCoords = [];

        usort($locations, function (array $a, array $b) {
            $ba = ! empty($a['is_base']) || ($a['role'] ?? '') === 'base' ? 0 : (($a['role'] ?? '') === 'anchor' ? 1 : 2);
            $bb = ! empty($b['is_base']) || ($b['role'] ?? '') === 'base' ? 0 : (($b['role'] ?? '') === 'anchor' ? 1 : 2);
            if ($ba !== $bb) {
                return $ba <=> $bb;
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        foreach ($locations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $snippet = trim((string) ($row['snippet'] ?? ''));
            $blob = mb_strtolower($name.' '.$snippet);
            $isBase = ! empty($row['is_base']) || ($row['role'] ?? '') === 'base';
            $isAnchor = ($row['role'] ?? '') === 'anchor';

            if ($id <= 0) {
                $dropped[] = 'id=0';
                continue;
            }
            if ($this->isJunk($blob) && ! $isBase && ! $isAnchor) {
                $dropped[] = '#'.$id.' junk';
                continue;
            }
            if ($this->isMultiDay($blob) && ! $isBase) {
                $dropped[] = '#'.$id.' multi-day';
                continue;
            }

            $key = $this->dedupeKey($name, $blob);
            if ($key !== '' && isset($seenKeys[$key]) && ! $isBase) {
                $dropped[] = '#'.$id.' duplicate of #'.$seenKeys[$key];
                continue;
            }

            // Одинаковые координаты ≈ один объект под разными id (215/216 Col de Flam)
            $coordKey = $this->coordDedupeKey($row);
            if ($coordKey !== '' && isset($seenCoords[$coordKey]) && ! $isBase) {
                $dropped[] = '#'.$id.' same coords as #'.$seenCoords[$coordKey];
                continue;
            }

            if ($key !== '') {
                $seenKeys[$key] = $id;
            }
            if ($coordKey !== '') {
                $seenCoords[$coordKey] = $id;
            }
            if ($this->isEndPointName($blob)) {
                $row['is_end_point'] = true;
                if (! $isBase) {
                    $row['role'] = 'end_point';
                }
            }
            $kept[] = $row;
        }

        // Лимит: базы/якоря всегда, остальные до MAX_KEEP
        $out = [];
        $extra = 0;
        $must = 0;
        foreach ($kept as $row) {
            if (! empty($row['is_base']) || in_array(($row['role'] ?? ''), ['base', 'anchor'], true)) {
                $out[] = $row;
                $must++;
                continue;
            }
            if (count($out) >= self::MAX_KEEP) {
                $dropped[] = '#'.(int) ($row['id'] ?? 0).' over cap';
                continue;
            }
            $out[] = $row;
            $extra++;
        }

        [$out, $geoDropped] = $this->dropFarFromBases($out);
        foreach ($geoDropped as $msg) {
            $dropped[] = $msg;
        }

        return ['locations' => $out, 'dropped' => $dropped];
    }

    /**
     * Убрать POI, которые географически не относятся к базам текущего набора.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function dropFarFromBases(array $rows): array
    {
        $bases = [];
        foreach ($rows as $row) {
            $isBase = ! empty($row['is_base'])
                || ($row['role'] ?? '') === 'base'
                || ($row['trip_role'] ?? '') === 'base';
            if (! $isBase) {
                continue;
            }
            if (! isset($row['lat'], $row['lng']) || ! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
                continue;
            }
            $bases[] = [(float) $row['lat'], (float) $row['lng']];
        }
        if ($bases === []) {
            return [$rows, []];
        }

        $kept = [];
        $dropped = [];
        foreach ($rows as $row) {
            $isBase = ! empty($row['is_base'])
                || in_array(($row['role'] ?? ''), ['base', 'anchor', 'end_point'], true)
                || in_array(($row['trip_role'] ?? ''), ['base', 'end'], true)
                || ! empty($row['is_end_point']);
            if ($isBase) {
                $kept[] = $row;
                continue;
            }
            if (! isset($row['lat'], $row['lng']) || ! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
                $kept[] = $row;
                continue;
            }
            $lat = (float) $row['lat'];
            $lng = (float) $row['lng'];
            $minKm = PHP_FLOAT_MAX;
            foreach ($bases as [$blat, $blng]) {
                $minKm = min($minKm, $this->distanceKm($lat, $lng, $blat, $blng));
            }
            if ($minKm > self::MAX_KM_FROM_BASE) {
                $dropped[] = '#'.(int) ($row['id'] ?? 0).' geo '.round($minKm).'km from nearest base';
                continue;
            }
            $kept[] = $row;
        }

        return [$kept, $dropped];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function isJunk(string $blob): bool
    {
        return (bool) preg_match(
            '/adventure|ג\'ימבורי|gimbor|קניונינג|canyon|каньон|wellness|וולנס|ספא\b|spa\b|'
            .'waterpark|аквапарк|funbob|מגלשות|карта зоны|zone map|מפת אזור|'
            .'отдых на озере|מנוחה על|מסעדות מישלן|michelin|'
            .'תחשובה ציבורית|תחבורה ציבורית|public transport|'
            .'בלי רכבל למשפחות|למבוגרים בלבד/ui',
            $blob
        );
    }

    private function isMultiDay(string $blob): bool
    {
        return (bool) preg_match(
            '/יומיים|שני ימים|two[- ]?day|2[- ]?day|двухднев|на 2 дня|לשני ימים|hike 2|לינה.*מסלול|מסלול.*לינה/ui',
            $blob
        );
    }

    private function dedupeKey(string $name, string $blob): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;

        // Одна лодочная связка Мальчезине–Лимоне
        if (preg_match('/(malcesine|מלצ|мальчез).{0,40}(limone|לימונה|лимоне)|(limone|לימונה|лимоне).{0,40}(malcesine|מלצ|мальчез)/ui', $blob)
            || preg_match('/שייט.*(לימונה|limone)|(לימונה|limone).*שייט|boat.*(limone|лимоне)/ui', $blob)) {
            return 'boat:malcesine-limone';
        }
        // Нардис
        if (preg_match('/nardis|נארדיס|нардис/ui', $blob)) {
            return 'place:nardis';
        }
        // Мольвено (озеро, без «ресторан»)
        if (preg_match('/molveno|מולבנו|мольвен/ui', $blob) && ! preg_match('/מסעד|restaurant/ui', $blob)) {
            return 'place:molveno';
        }
        // Брайес
        if (preg_match('/braies|בראייס|брайес|pragser/ui', $blob)) {
            return 'place:braies';
        }
        // Tre Cime / 3–5 пиков
        if (preg_match('/tre cime|drei zinnen|3 פסגות|5 פסגות|פסגות דולומיטים/ui', $blob)) {
            return 'trek:tre-cime';
        }
        // Трек 5 озёр
        if (preg_match('/5\s*אגמים|חמש\s*אגמים|cinq(ue)?\s*lagh|5 lakes|пять оз/ui', $blob)) {
            return 'trek:five-lakes';
        }
        // Верёвочный парк Ortisei / Col de Flam (разные id, одно место)
        if (preg_match('/col\s*de\s*flam|emozion|פארק\s*חבלים|веревочн|rope\s*park|adventure\s*park|high\s*rope/ui', $blob)
            && preg_match('/ortisei|אורטיזיי|ортизеи|gardena|gardeña/ui', $blob)) {
            return 'park:ortisei-ropes';
        }
        if (preg_match('/col\s*de\s*flam|emozion.*flam|flam.*emozion/ui', $blob)) {
            return 'park:ortisei-ropes';
        }
        // Пешие Ortisei–Selva
        if (preg_match('/(ortisei|אורטיזיי).{0,30}(selva|סלבה)|(selva|סלבה).{0,30}(ortisei|אורטיזיי)/ui', $blob)) {
            return 'hike:ortisei-selva';
        }

        if (mb_strlen($s) < 4) {
            return '';
        }

        // Короткий ключ по первым словам латиницы/иврита
        $norm = preg_replace('/\b(lago di|lake|אגם|מפל|cascata|waterfall|מסלול|טיול|route|hike)\b/ui', '', $s) ?? $s;
        $norm = trim(preg_replace('/\s+/u', ' ', $norm) ?? $norm);

        return mb_substr($norm, 0, 40);
    }

    private function isEndPointName(string $blob): bool
    {
        return (bool) preg_match(
            '/borghetto|боргетто|בורגטו|malpensa|мальпенса|airport|аэропорт|linate|линате/ui',
            $blob
        );
    }

    /**
     * Округление координат ~11 м — один физический объект под разными карточками.
     *
     * @param  array<string, mixed>  $row
     */
    private function coordDedupeKey(array $row): string
    {
        if (! isset($row['lat'], $row['lng']) || ! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
            return '';
        }
        $lat = round((float) $row['lat'], 4);
        $lng = round((float) $row['lng'], 4);

        return 'coord:'.$lat.','.$lng;
    }
}
