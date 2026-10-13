<?php

namespace App\Services\Itinerary;

/**
 * Before vector search: interpret questionnaire → what regions/locations we will query.
 */
class SearchPlanBuilder
{
    public function __construct(private QuestionnaireBrief $briefParser) {}

    /**
     * @return array{
     *   hub: string|null,
     *   regions: list<string>,
     *   locations: list<string>,
     *   exclude: list<string>,
     *   vector_query: string,
     *   reply: string,
     *   constraints: array<string, mixed>
     * }
     */
    public function build(string $question): array
    {
        $question = trim($question);
        $constraints = $this->briefParser->parse($question);
        $hub = $this->normalizeHub($constraints['arrival_city'] ?? null, $question);
        $explicit = $this->explicitRegions($question);

        $nature = ! empty($constraints['nature']) || ! empty($constraints['no_big_cities']);
        $regions = $explicit;
        if ($regions === [] && $hub === 'milan' && $nature) {
            $regions = ['garda', 'como', 'dolomiti'];
        } elseif ($regions === [] && $hub === 'verona' && $nature) {
            $regions = ['garda', 'dolomiti'];
        } elseif ($regions === [] && $nature) {
            $regions = ['garda', 'dolomiti'];
        } elseif ($regions === [] && $hub === 'milan') {
            $regions = ['garda', 'como', 'milan'];
        }

        $regions = array_values(array_unique($regions));
        $exclude = $this->defaultExcludes($constraints, $hub, $regions);
        $locations = $this->locationsForRegions($regions, $hub, $nature);

        $vectorQuery = $this->buildVectorQuery($question, $hub, $regions, $locations, $exclude, $constraints);
        $reply = $this->formatReply($hub, $regions, $locations, $exclude, $constraints);

        return [
            'hub' => $hub,
            'regions' => $regions,
            'locations' => $locations,
            'exclude' => $exclude,
            'vector_query' => $vectorQuery,
            'reply' => $reply,
            'constraints' => $constraints,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function refine(array $plan, string $followUp): array
    {
        $followUp = trim($followUp);
        if ($followUp === '') {
            return $plan;
        }

        $baseQuestion = trim((string) ($plan['question'] ?? ''));
        $merged = trim($baseQuestion."\n".$followUp);
        $isRemove = preg_match('/убери|без|не\s+надо|исключи|только\s+не/iu', $followUp) === 1;
        $regions = is_array($plan['regions'] ?? null) ? $plan['regions'] : [];
        if ($regions === []) {
            $regions = $this->build($merged)['regions'];
        }

        $mentioned = $this->explicitRegions($followUp);
        if ($isRemove) {
            foreach ($mentioned as $r) {
                $regions = array_values(array_filter($regions, fn ($x) => $x !== $r));
            }
        } else {
            foreach ($mentioned as $r) {
                if (! in_array($r, $regions, true)) {
                    $regions[] = $r;
                }
            }
            // Soft rebuild if follow-up is a full new brief
            if ($mentioned === [] && mb_strlen($followUp) > 80) {
                $regions = $this->build($merged)['regions'];
            }
        }

        if ($regions === []) {
            $regions = ['garda', 'dolomiti'];
        }

        $next = $this->build($merged);

        $hub = $next['hub'] ?? ($plan['hub'] ?? null);
        $constraints = $next['constraints'];
        $exclude = $this->defaultExcludes($constraints, is_string($hub) ? $hub : null, $regions);
        $locations = $this->locationsForRegions($regions, is_string($hub) ? $hub : null, true);
        $vectorQuery = $this->buildVectorQuery($merged, is_string($hub) ? $hub : null, $regions, $locations, $exclude, $constraints);
        $reply = $this->formatReply(is_string($hub) ? $hub : null, $regions, $locations, $exclude, $constraints);
        $reply = "Уточнил план по вашему сообщению.\n\n".$reply;

        return [
            'hub' => $hub,
            'regions' => array_values($regions),
            'locations' => $locations,
            'exclude' => $exclude,
            'vector_query' => $vectorQuery,
            'reply' => $reply,
            'constraints' => $constraints,
            'question' => $baseQuestion !== '' ? $baseQuestion : $merged,
        ];
    }

    private function normalizeHub(mixed $arrivalCity, string $question): ?string
    {
        $c = is_string($arrivalCity) ? mb_strtolower($arrivalCity) : '';
        if ($c === '' && preg_match('/\b(милан|milan|мальпенс|mxp)\b/iu', $question) === 1) {
            $c = 'milan';
        }
        if (preg_match('/милан|milan|мальпенс|mxp/u', $c) === 1) {
            return 'milan';
        }
        if (preg_match('/верон|verona/u', $c) === 1) {
            return 'verona';
        }
        if (preg_match('/венеци|venice/u', $c) === 1) {
            return 'venice';
        }

        return $c !== '' ? $c : null;
    }

    /**
     * @return list<string>
     */
    private function explicitRegions(string $text): array
    {
        $q = mb_strtolower($text);
        $map = [
            'garda' => '/гард|garda|сирмион|лимоне|рива|тенно|мольвено/u',
            'dolomiti' => '/доломит|трентин|ортизеи|ortisei|альп/u',
            'como' => '/комо|como|белладж|варенн|лекк/u',
            'tuscany' => '/тоскан|tuscany|пьенц|кьянт|флоренц/u',
            'rome' => '/\bрим\b|rome/u',
            'milan' => '/милан\s+город|sightseeing\s+милан|прогулк\w*\s+по\s+милан/u',
        ];
        $out = [];
        foreach ($map as $region => $re) {
            if (preg_match($re, $q) === 1) {
                $out[] = $region;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  list<string>  $regions
     * @return list<string>
     */
    private function defaultExcludes(array $constraints, ?string $hub, array $regions): array
    {
        $exclude = [];
        $noBig = ! empty($constraints['no_big_cities']);
        $nature = ! empty($constraints['nature']);

        if ($noBig || $nature) {
            $exclude[] = 'экскурсии по большим городам (Милан как город, Рим, Флоренция)';
            $exclude[] = 'аутлеты и шопинг';
            if (! in_array('tuscany', $regions, true)) {
                $exclude[] = 'Тоскана';
            }
            if (! in_array('rome', $regions, true)) {
                $exclude[] = 'Рим';
            }
        }
        if ($hub === 'milan' && ($noBig || $nature)) {
            $exclude[] = 'Милан — только аэропорт прилёта/вылета, не программа дня в городе';
        }

        return $exclude;
    }

    /**
     * @param  list<string>  $regions
     * @return list<string>
     */
    private function locationsForRegions(array $regions, ?string $hub, bool $nature): array
    {
        $catalog = [
            'garda' => [
                'Озеро Гарда', 'Рива-дель-Гарда', 'Лимоне', 'Озеро Тенно',
                'Водопад Вароне', 'Озеро Мольвено', 'Тремозине',
            ],
            'como' => [
                'Озеро Комо', 'Варенна', 'Белладжо', 'Лекко', 'Менаджио',
            ],
            'dolomiti' => [
                'Доломиты', 'Трентино', 'Ортизеи', 'Озеро Товель', 'Водопады Нардис',
            ],
            'tuscany' => ['Тоскана', 'Пьенца', 'Сан-Джиминьяно', 'Кьянти'],
            'rome' => ['Рим'],
            'milan' => ['Милан (город)'],
        ];

        $out = [];
        foreach ($regions as $r) {
            foreach ($catalog[$r] ?? [ucfirst($r)] as $loc) {
                $out[] = $loc;
            }
        }
        if ($hub === 'milan') {
            array_unshift($out, 'Аэропорт Милана (только прилёт/вылет)');
        }
        if ($nature && $out === []) {
            $out = ['озёра и природа севера Италии'];
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $regions
     * @param  list<string>  $locations
     * @param  list<string>  $exclude
     * @param  array<string, mixed>  $constraints
     */
    private function buildVectorQuery(
        string $question,
        ?string $hub,
        array $regions,
        array $locations,
        array $exclude,
        array $constraints,
    ): string {
        $bits = [];
        foreach ($regions as $r) {
            $bits[] = match ($r) {
                'garda' => 'Garda גארדה озеро Гарда Tenno Molveno Limone Riva',
                'como' => 'Como קומו Varenna Bellagio Lecco озеро Комо',
                'dolomiti' => 'Dolomiti Trentino Ortisei Tovel Nardis Доломиты',
                'tuscany' => 'Tuscany Toscana',
                'rome' => 'Rome Roma',
                'milan' => 'Milan Milano',
                default => $r,
            };
        }
        $bits[] = 'природа пешие прогулки озеро водопад семья';
        if (! empty($constraints['no_big_cities'])) {
            $bits[] = 'без больших городов без шопинга без аутлета';
        }
        if ($hub === 'milan') {
            $bits[] = 'прилёт Милан аэропорт только трансфер';
        }
        // Keep a short slice of original intent (dates/family) without drowning the geo focus
        $bits[] = mb_substr(preg_replace('/\s+/u', ' ', $question) ?? $question, 0, 280);

        return trim(implode(' ', $bits));
    }

    /**
     * @param  list<string>  $regions
     * @param  list<string>  $locations
     * @param  list<string>  $exclude
     * @param  array<string, mixed>  $constraints
     */
    private function formatReply(
        ?string $hub,
        array $regions,
        array $locations,
        array $exclude,
        array $constraints,
    ): string {
        $hubLabel = match ($hub) {
            'milan' => 'Милан (хаб аэропорта)',
            'verona' => 'Верона',
            'venice' => 'Венеция',
            default => $hub ?: 'не указан',
        };

        $regionLabels = [
            'garda' => 'Гарда',
            'como' => 'Комо',
            'dolomiti' => 'Доломиты / Трентино',
            'tuscany' => 'Тоскана',
            'rome' => 'Рим',
            'milan' => 'Милан (город)',
        ];
        $regText = $regions === []
            ? '—'
            : implode(', ', array_map(fn ($r) => $regionLabels[$r] ?? $r, $regions));

        $lines = [];
        $lines[] = 'План поиска в векторной базе (ещё не искал):';
        $lines[] = '';
        $lines[] = '• Хаб прилёта/вылета: '.$hubLabel;
        if (! empty($constraints['days'])) {
            $lines[] = '• Длительность: ~'.$constraints['days'].' дн.';
        }
        if (! empty($constraints['nature']) || ! empty($constraints['no_big_cities'])) {
            $lines[] = '• Фокус: природа, пешие прогулки'
                .(! empty($constraints['no_big_cities']) ? ', без больших городов' : '');
        }
        $lines[] = '• Регионы для поиска: '.$regText;
        $lines[] = '• Локации / ориентиры:';
        foreach (array_slice($locations, 0, 14) as $loc) {
            $lines[] = '  — '.$loc;
        }
        if ($exclude !== []) {
            $lines[] = '• Не беру в приоритет:';
            foreach ($exclude as $ex) {
                $lines[] = '  — '.$ex;
            }
        }
        $lines[] = '';
        $lines[] = '«Искать в базе» найдёт туры, где есть строки маршрута («дата/день — места») с этими локациями, и покажет сами строки в аккордеоне.';
        $lines[] = 'Если план ок — нажмите «Искать в базе». Если нет — напишите уточнение (например: «убери Комо, больше Доломиты»).';

        return implode("\n", $lines);
    }
}
