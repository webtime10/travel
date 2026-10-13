<?php

namespace App\Services\Itinerary;

/**
 * Lightweight parse of free-text questionnaire for the full-tour formatter.
 */
class QuestionnaireBrief
{
    /**
     * @return array{
     *   days: int|null,
     *   date_start: string|null,
     *   date_end: string|null,
     *   arrival_time: string|null,
     *   departure_time: string|null,
     *   arrival_city: string|null,
     *   no_big_cities: bool,
     *   nature: bool,
     *   family: bool,
     *   brief_format: bool,
     *   answer_lang: string
     * }
     */
    public function parse(string $question): array
    {
        $q = $question;
        $answerLang = preg_match('/[А-Яа-яЁё]/u', $q) === 1 ? 'ru' : (
            preg_match('/[\x{0590}-\x{05FF}]/u', $q) === 1 ? 'he' : 'ru'
        );
        $days = null;
        if (preg_match('/\b(\d{1,2})\s*(?:дн|день|дней|day|days)\b/iu', $q, $m)) {
            $days = max(1, min(31, (int) $m[1]));
        }

        $dateStart = null;
        $dateEnd = null;
        if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?\s*[–—\-доto]+\s*(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?/iu', $q, $m)) {
            $dateStart = $this->normDate((int) $m[1], (int) $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null);
            $dateEnd = $this->normDate((int) $m[4], (int) $m[5], isset($m[6]) && $m[6] !== '' ? (int) $m[6] : null);
        } elseif (preg_match('/(?:с\s+)?(\d{1,2})\s+до\s+(\d{1,2})\s+([а-яё]+)/iu', $q, $m)) {
            $month = $this->monthNum($m[3]);
            if ($month !== null) {
                $dateStart = $this->normDate((int) $m[1], $month, null);
                $dateEnd = $this->normDate((int) $m[2], $month, null);
            }
        }

        if ($days === null && $dateStart && $dateEnd) {
            $days = $this->daysBetween($dateStart, $dateEnd);
        }

        $arrivalTime = null;
        $departureTime = null;
        if (preg_match('/прил[её]т[^0-9]{0,40}(\d{1,2})[:.\-](\d{2})/iu', $q, $m)
            || preg_match('/\b(\d{1,2})[:.\-](\d{2})\b[^.\n]{0,30}прил/iu', $q, $m)) {
            $arrivalTime = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/(?:отл[её]т|вылет)[^0-9]{0,40}(\d{1,2})[:.\-](\d{2})/iu', $q, $m)
            || preg_match('/\b(\d{1,2})[:.\-](\d{2})\b[^.\n]{0,30}(?:отл|вылет)/iu', $q, $m)) {
            $departureTime = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        $arrivalCity = null;
        if (preg_match('/(?:прил[её]т|вылет|отл[её]т)[^.\n]{0,40}(милан|milan|мальпенса|mxp|верона|venice|венеци)/iu', $q, $m)) {
            $arrivalCity = mb_strtolower($m[1]);
        } elseif (preg_match('/\b(милан|milan)\b/iu', $q, $m)) {
            $arrivalCity = mb_strtolower($m[1]);
        }

        $noBigCities = (bool) preg_match('/без\s+больш\w*\s+город|не\s+больш\w*\s+город|no\s+big\s+cit/iu', $q);
        $nature = (bool) preg_match('/природ|пеш(ие|их)|поход|hiking|nature|парк/iu', $q);
        $family = (bool) preg_match('/семь|дет(и|ей)|family|children/iu', $q);
        $briefFormat = (bool) preg_match('/кратко|только\s+числ|дата\s+и\s+назван/iu', $q);

        return [
            'days' => $days,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'arrival_time' => $arrivalTime,
            'departure_time' => $departureTime,
            'arrival_city' => $arrivalCity,
            'no_big_cities' => $noBigCities,
            'nature' => $nature,
            'family' => $family,
            'brief_format' => $briefFormat,
            'answer_lang' => $answerLang,
        ];
    }

    private function normDate(int $day, int $month, ?int $year): string
    {
        $year = $year ?? (int) date('Y');
        if ($year < 100) {
            $year += 2000;
        }

        return sprintf('%02d.%02d.%04d', $day, $month, $year);
    }

    private function monthNum(string $name): ?int
    {
        $name = mb_strtolower(trim($name));
        $map = [
            'января' => 1, 'январь' => 1,
            'февраля' => 2, 'февраль' => 2,
            'марта' => 3, 'март' => 3,
            'апреля' => 4, 'апрель' => 4,
            'мая' => 5, 'май' => 5,
            'июня' => 6, 'июнь' => 6,
            'июля' => 7, 'июль' => 7,
            'августа' => 8, 'август' => 8,
            'сентября' => 9, 'сентябрь' => 9,
            'октября' => 10, 'октябрь' => 10,
            'ноября' => 11, 'ноябрь' => 11,
            'декабря' => 12, 'декабрь' => 12,
        ];

        return $map[$name] ?? null;
    }

    private function daysBetween(string $start, string $end): ?int
    {
        $a = \DateTimeImmutable::createFromFormat('d.m.Y', $start);
        $b = \DateTimeImmutable::createFromFormat('d.m.Y', $end);
        if (! $a || ! $b) {
            return null;
        }
        $diff = (int) $a->diff($b)->days;

        return max(1, $diff + 1);
    }
}
