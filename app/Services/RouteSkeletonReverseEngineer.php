<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Location;
use App\Models\RouteSkeleton;
use App\Models\RouteSkeletonDay;

/**
 * One-pass reverse engineering of a short route:
 * synthetic client query + tour principle + per-day stops with location_id tags.
 */
class RouteSkeletonReverseEngineer
{
    public const SCHEMA_VERSION = 3;

    public const PROMPT_SETTING_KEY = 'travel.reverse_engineer.route_skeleton_prompt';

    public function __construct(private GeminiJsonClient $gemini) {}

    public static function resolvePrompt(): string
    {
        $stored = trim((string) AppSetting::getValue(self::PROMPT_SETTING_KEY, ''));
        if ($stored !== '') {
            return $stored;
        }

        $fresh = self::defaultPrompt();
        AppSetting::putValue(self::PROMPT_SETTING_KEY, $fresh);

        return $fresh;
    }

    public static function defaultPrompt(): string
    {
        return <<<'PROMPT'
Промпт для Обратного Инжиниринга: Фокус на Аудитории и Логике Автора

Ты — старший инженер-аналитик туристических данных и эксперт по клиентскому профилированию в автопутешествиях.

ЦЕЛЬ ТВОЕЙ РАБОТЫ:
Провести глубокий обратный инжиниринг реального маршрута эксперта. Главный фокус: ТОЧНОЕ ОПРЕДЕЛЕНИЕ ЦЕЛЕВОЙ АУДИТОРИИ (для кого этот тур, почему он собран именно так, какие боли и негласные ожидания клиента закрывает каждая деталь).
Результат пойдёт в обучающий датасет (Fine-Tuning): модель учится распознавать профиль туриста по коротким фразам и подбирать подходящие архитектурные решения.

ПРАВИЛО ПРАГМАТИЗМА (Grounding Rule):
Будь предельно прагматичен. Не придумывай литературных метафор. Объясняй логику через реальные потребности людей: физическая выносливость, возраст детей, религиозные ограничения, страх горных дорог, усталость от вождения. Если локация стоит в плане просто потому, что лежит прямо по трассе без съезда, так и пиши: «Удобный транзитный стоп по пути».
Не делай отдельный акцент на зонах ограниченного движения / парковочных запретах — упоминай их только если это реально ключевой фактор дня, без раздувания темы.

Вход: после --- SOURCE --- будет JSON каталога (дни, остановки с location_id, хабы). Изучи его и ответь СТРОГО JSON.

1. ДЕТАЛЬНЫЙ ПОРТРЕТ АУДИТОРИИ (Кто этот клиент)
- Состав и статус группы: пара без детей / семья с детьми до 7 / с подростками / пожилые / компания друзей.
- Уровень активности и комфорта: много пешком или пассивный видовой туризм; ежедневная смена отелей или одна база.
- Ограничения и фильтры: питание (кошер, вегетарианство), Шаббат/кашрут, страх долгих переездов, неприязнь к шумным городам.

2. АНАЛИЗ СООТВЕТСТВИЯ (Почему маршрут идеален именно для них)
- Конкретными решениями маршрута закрой боли этой аудитории (дети → пологие тропы/парки; соблюдающие → Шаббат; пара → релакс).
- Спектр запросов: 3 РАЗНЫХ варианта формулировки клиента (от короткой эмоциональной до детальной с ограничениями) — без обязательных топонимов в этих трёх (абстрактно). Они нужны, чтобы модель связывала разные фразы с одной структурой.
- abstract_user_prompt: один сводный абстрактный запрос (без названий городов/озёр/гор).

3. МАКРО-АРХИТЕКТУРА: Регионы и базы под эту аудиторию
- Почему эти регионы для данного типа людей?
- Схема ночёвок: один базовый лагерь с радиальными выездами ИЛИ линейный road-trip — и почему так для этой аудитории.
- Как решены транзитные дни с учётом выносливости.

4. МИКРО-КЛАСТЕРИЗАЦИЯ ДНЕЙ (Умная выборка)
Если тур ≤ 5 дней — все дни; если длиннее — 3–5 самых показательных (транзит / радиальный выезд / релакс и т.п.). Не больше 5.
Для каждого:
- Практическая логика: почему точки в связке (коридор, по пути, чередование нагрузки и отдыха под эту аудиторию).
- Логистика и безопасность: усталость от руля, время работы объектов, темп дня. Без отдельного акцента на парковочных зонах.

5. FINE-TUNE (отдельно от п.1–2)
fine_tune.question ≠ abstract_user_prompt.
Короткий рабочий запрос как на сайте: хабы/города ЭТОГО тура + N дней (+ опционально одно пожелание). Уникально для маршрута.
fine_tune.answer.summary — кратко: регионы, базы, транзит.
fine_tune.answer.days — ВСЕ дни со ВСЕМИ location_id из входа (не выдумывай id).

JSON-схема ответа:

{
  "audience_portrait": {
    "group_composition": "кто в группе",
    "activity_and_comfort": "темп, базы vs смена отелей",
    "constraints": "питание, Шаббат, страхи логистики"
  },
  "audience_fit": {
    "why_this_route_fits": "как маршрут закрывает боли этой аудитории",
    "possible_queries": [
      "короткий эмоциональный запрос без топонимов",
      "средний запрос с ограничениями без топонимов",
      "детальный запрос с ограничениями без топонимов"
    ]
  },
  "abstract_user_prompt": "Сводный абстрактный запрос клиента без топонимов.",
  "macro_architecture": {
    "region_selection_logic": "Почему эти регионы для этой аудитории.",
    "routing_strategy": "Базы / линейный road-trip / транзит под выносливость."
  },
  "micro_clustering": [
    {
      "day_number": 1,
      "day_description": "Кратко роль дня",
      "practical_logic": "Почему точки вместе (по пути, рядом, ритм нагрузки).",
      "logistics_and_fatigue": "Усталость от руля, темп, время объектов — без акцента на парковочных зонах."
    }
  ],
  "fine_tune": {
    "question": "Хабы/города + N дней (+ опционально пожелание). Уникально для тура.",
    "answer": {
      "summary": "Регионы, базы, транзит — как собран тур.",
      "days": [
        {
          "day_index": 0,
          "title": "кратко роль дня",
          "location_ids": [1, 2, 3]
        }
      ]
    }
  }
}
PROMPT;
    }

    /**
     * @return array{principle: array<string, mixed>}
     */
    public function extract(RouteSkeleton $route): array
    {
        $route->loadMissing('days');
        if ($route->days->isEmpty()) {
            $data = [
                'skipped' => true,
                'reason' => 'no_days',
                'schema_version' => self::SCHEMA_VERSION,
                'extracted_at' => now()->toIso8601String(),
            ];
            $route->reverse_principle = $data;
            $route->save();

            return ['principle' => $data];
        }

        $validIds = [];
        $daysPayload = [];
        $locationSnippets = [];

        foreach ($route->days as $day) {
            $stops = $this->stopsFromDay($day);
            foreach ($stops as $s) {
                $validIds[(int) $s['location_id']] = true;
            }
            $daysPayload[] = [
                'day_index' => (int) $day->day_index,
                'title' => $day->title_ru ?: $day->title,
                'start_point' => $day->start_point_ru ?: $day->start_point,
                'end_point' => $day->end_point_ru ?: $day->end_point,
                'stops' => $stops,
            ];
        }

        $ids = array_keys($validIds);
        if ($ids !== []) {
            Location::query()->whereIn('id', array_slice($ids, 0, 60))->get()
                ->each(function (Location $loc) use (&$locationSnippets) {
                    $snippet = $this->locationSnippet($loc);
                    if ($snippet !== null) {
                        $locationSnippets[] = [
                            'location_id' => $loc->id,
                            'text_excerpt' => $snippet,
                            'kosher_food' => $loc->kosher_food,
                            'visit_hours' => $loc->visit_hours,
                            'trip_role' => $loc->trip_role,
                            'weight' => $loc->weight,
                        ];
                    }
                });
        }

        $material = json_encode([
            'route_skeleton_id' => $route->id,
            'title' => $route->displayTitle(true),
            'days_count' => $route->days_count,
            'arrival_hub' => $route->arrival_hub_ru ?: $route->arrival_hub,
            'departure_hub' => $route->departure_hub_ru ?: $route->departure_hub,
            'days' => $daysPayload,
            'location_texts' => $locationSnippets,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $material = mb_substr($material, 0, 90000);

        $instruction = trim(self::resolvePrompt());
        if ($instruction === '') {
            $instruction = self::defaultPrompt();
        }

        $raw = $this->gemini->ask($instruction, $material, 'minimal');
        $data = $this->normalize($raw, $validIds, $daysPayload, $this->shortQuestionFallback($route));
        $data['schema_version'] = self::SCHEMA_VERSION;
        $data['extracted_at'] = now()->toIso8601String();

        $route->reverse_principle = $data;
        $route->save();

        // Метки на локации не пишем — suitability зависит от семьи/запроса, не от ярлыка точки.

        return ['principle' => $data];
    }

    /**
     * Place "id" in skeleton JSON is usually Location.model (Python id), not locations.id.
     *
     * @return list<array{location_id: int, source_model: string|null, name: string}>
     */
    private function stopsFromDay(RouteSkeletonDay $day): array
    {
        $raw = is_array($day->places) && $day->places !== []
            ? $day->places
            : (is_array($day->places_ru) ? $day->places_ru : []);
        $namesRu = is_array($day->places_ru) ? $day->places_ru : [];

        $rawIds = [];
        $parsed = [];
        foreach ($raw as $i => $p) {
            $rawId = 0;
            $name = '';
            if (is_array($p)) {
                $rawId = (int) ($p['id'] ?? $p['location_id'] ?? 0);
                $name = trim((string) ($p['name'] ?? $p['title'] ?? ''));
            } elseif (is_string($p)) {
                $name = trim($p);
            }
            if ($name === '' && isset($namesRu[$i])) {
                $nr = $namesRu[$i];
                if (is_array($nr)) {
                    $name = trim((string) ($nr['name'] ?? $nr['title'] ?? ''));
                    if ($rawId <= 0) {
                        $rawId = (int) ($nr['id'] ?? $nr['location_id'] ?? 0);
                    }
                } elseif (is_string($nr)) {
                    $name = trim($nr);
                }
            }
            if ($rawId <= 0) {
                continue;
            }
            $rawIds[] = $rawId;
            $parsed[] = ['raw_id' => $rawId, 'name' => $name];
        }

        if ($parsed === []) {
            return [];
        }

        $byPk = Location::query()->whereIn('id', $rawIds)->get()->keyBy('id');
        $byModel = Location::query()
            ->whereIn('model', array_map('strval', $rawIds))
            ->get()
            ->keyBy(fn (Location $l) => (string) $l->model);

        $out = [];
        foreach (array_slice($parsed, 0, 20) as $row) {
            $rawId = (int) $row['raw_id'];
            /** @var Location|null $loc */
            $loc = $byPk->get($rawId) ?: $byModel->get((string) $rawId);
            if (! $loc) {
                continue;
            }
            $out[] = [
                'location_id' => (int) $loc->id,
                'source_model' => $loc->model ? (string) $loc->model : (string) $rawId,
                'name' => $row['name'] !== '' ? $row['name'] : $loc->nameFor(),
            ];
        }

        return $out;
    }

    private function locationSnippet(Location $loc): ?string
    {
        $meta = $loc->heMeta();
        if (! is_array($meta)) {
            return null;
        }
        $documents = (array) ($meta['documents'] ?? []);
        $primary = collect($documents)->firstWhere('file_id', $meta['primary_file_id'] ?? null) ?? ($documents[0] ?? null);
        $text = trim((string) ($primary['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 500);
    }

    private function shortQuestionFallback(RouteSkeleton $route): string
    {
        $a = trim((string) ($route->arrival_hub_ru ?: $route->arrival_hub ?: ''));
        $b = trim((string) ($route->departure_hub_ru ?: $route->departure_hub ?: ''));
        $n = (int) ($route->days_count ?: $route->days->count());
        $hubs = $a !== '' && $b !== '' && mb_strtolower($a) !== mb_strtolower($b)
            ? $a.', '.$b
            : ($a !== '' ? $a : ($b !== '' ? $b : 'Италия'));
        if ($n > 0) {
            return $hubs.', '.$n.' '.($n === 1 ? 'день' : ($n >= 2 && $n <= 4 ? 'дня' : 'дней'));
        }

        return $hubs;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, true>  $validIds
     * @param  list<array<string, mixed>>  $daysPayload
     * @return array<string, mixed>
     */
    private function normalize(array $raw, array $validIds, array $daysPayload, string $shortQuestionFallback = ''): array
    {
        $macro = is_array($raw['macro_architecture'] ?? null) ? $raw['macro_architecture'] : [];
        $micro = is_array($raw['micro_clustering'] ?? null) ? $raw['micro_clustering'] : [];
        $audience = is_array($raw['audience_portrait'] ?? null) ? $raw['audience_portrait'] : [];
        $fit = is_array($raw['audience_fit'] ?? null) ? $raw['audience_fit'] : [];

        $abstract = $this->str(
            $raw['abstract_user_prompt'] ?? $raw['synthetic_query'] ?? null,
            2000
        );
        $regionLogic = $this->str($macro['region_selection_logic'] ?? $raw['principle'] ?? null, 2000);
        $routing = $this->str($macro['routing_strategy'] ?? $raw['geo_logic'] ?? null, 2000);

        $possibleQueries = [];
        foreach ((array) ($fit['possible_queries'] ?? []) as $q) {
            $q = $this->str($q, 500);
            if ($q !== '') {
                $possibleQueries[] = $q;
            }
            if (count($possibleQueries) >= 5) {
                break;
            }
        }

        $microByDay = [];
        $microList = [];
        foreach ($micro as $row) {
            if (! is_array($row)) {
                continue;
            }
            // logistics_and_fatigue — без акцента на ZTL; старое поле logistics_and_ztl принимаем как fallback.
            $logistics = $this->str(
                $row['logistics_and_fatigue'] ?? $row['logistics_and_ztl'] ?? null,
                800
            );
            $item = [
                'day_number' => (int) ($row['day_number'] ?? 0),
                'day_description' => $this->str($row['day_description'] ?? null, 400),
                'practical_logic' => $this->str($row['practical_logic'] ?? null, 1200),
                'logistics_and_fatigue' => $logistics,
            ];
            $microList[] = $item;
            // day_number у директора часто 1-based; у нас day_index может быть 0-based.
            $n = $item['day_number'];
            if ($n > 0) {
                $microByDay[$n] = $item;
                $microByDay[$n - 1] = $item;
            }
        }

        $daysOut = [];
        foreach ($daysPayload as $d) {
            $dayIndex = (int) ($d['day_index'] ?? 0);
            $stopsOut = [];
            foreach ((array) ($d['stops'] ?? []) as $s) {
                $lid = (int) ($s['location_id'] ?? 0);
                if ($lid > 0 && ! empty($validIds[$lid])) {
                    $stopsOut[] = ['location_id' => $lid];
                }
            }
            $m = $microByDay[$dayIndex] ?? null;
            $noteParts = array_filter([
                $m['day_description'] ?? null,
                $m['practical_logic'] ?? null,
                $m['logistics_and_fatigue'] ?? null,
            ]);
            $daysOut[] = [
                'day_index' => $dayIndex,
                'note' => $noteParts !== [] ? $this->str(implode("\n", $noteParts), 2000) : null,
                'stops' => $stopsOut,
            ];
        }

        $ft = is_array($raw['fine_tune'] ?? null) ? $raw['fine_tune'] : [];
        $ftQuestion = $this->str($ft['question'] ?? null, 400);
        if ($ftQuestion === '') {
            $ftQuestion = $this->str($shortQuestionFallback !== '' ? $shortQuestionFallback : $abstract, 400);
        }
        $ftAnswerRaw = is_array($ft['answer'] ?? null) ? $ft['answer'] : [];
        $ftSummary = $this->str(
            $ftAnswerRaw['summary'] ?? trim($regionLogic.' '.$routing),
            2000
        );

        $ftDays = [];
        foreach ((array) ($ftAnswerRaw['days'] ?? []) as $fd) {
            if (! is_array($fd)) {
                continue;
            }
            $ids = [];
            foreach ((array) ($fd['location_ids'] ?? []) as $id) {
                $id = (int) $id;
                if ($id > 0 && ! empty($validIds[$id])) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                continue;
            }
            $ftDays[] = [
                'day_index' => (int) ($fd['day_index'] ?? count($ftDays)),
                'title' => $this->str($fd['title'] ?? null, 200),
                'location_ids' => array_values(array_unique($ids)),
            ];
        }
        // Если модель не вернула answer.days — собираем из входа (надёжно для FunTunes).
        if ($ftDays === []) {
            foreach ($daysOut as $d) {
                $ids = array_map(fn ($s) => (int) $s['location_id'], $d['stops'] ?? []);
                $ftDays[] = [
                    'day_index' => (int) $d['day_index'],
                    'title' => null,
                    'location_ids' => $ids,
                ];
            }
        }

        return [
            'audience_portrait' => [
                'group_composition' => $this->str($audience['group_composition'] ?? null, 800),
                'activity_and_comfort' => $this->str($audience['activity_and_comfort'] ?? null, 800),
                'constraints' => $this->str($audience['constraints'] ?? null, 800),
            ],
            'audience_fit' => [
                'why_this_route_fits' => $this->str($fit['why_this_route_fits'] ?? null, 2000),
                'possible_queries' => $possibleQueries,
            ],
            'abstract_user_prompt' => $abstract,
            'synthetic_query' => $abstract,
            'macro_architecture' => [
                'region_selection_logic' => $regionLogic,
                'routing_strategy' => $routing,
            ],
            'micro_clustering' => $microList,
            'fine_tune' => [
                'question' => $ftQuestion,
                'answer' => [
                    'summary' => $ftSummary,
                    'days' => $ftDays,
                ],
            ],
            'principle' => $regionLogic,
            'geo_logic' => $routing,
            'days' => $daysOut,
            'confidence' => in_array($raw['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? $raw['confidence'] : 'medium',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function touchLocations(array $data): void
    {
        foreach ((array) ($data['days'] ?? []) as $day) {
            foreach ((array) ($day['stops'] ?? []) as $row) {
                $lid = (int) ($row['location_id'] ?? 0);
                if ($lid <= 0) {
                    continue;
                }
                $loc = Location::find($lid);
                if (! $loc) {
                    continue;
                }
                $existing = is_array($loc->passport_tags) ? $loc->passport_tags : [];
                if (! empty($existing) && empty($existing['skipped']) && empty($existing['from_route_reverse']) && empty($existing['from_golden_reverse'])) {
                    continue;
                }
                $loc->passport_tags = [
                    'overnight' => $row['overnight'] ?? null,
                    'kids' => $row['kids'] ?? 'unknown',
                    'kosher_food' => $row['kosher_food'] ?? 'unknown',
                    'visit_hours' => $row['visit_hours'] ?? null,
                    'weight' => $row['weight'] ?? null,
                    'mobility' => $row['mobility'] ?? 'unknown',
                    'from_route_reverse' => true,
                    'extracted_at' => now()->toIso8601String(),
                    'confidence' => $data['confidence'] ?? 'low',
                ];
                if (($row['kosher_food'] ?? '') === 'yes' && ($loc->kosher_food ?? 'unknown') === 'unknown') {
                    $loc->kosher_food = 'yes';
                }
                if (! empty($row['visit_hours']) && $loc->visit_hours === null) {
                    $loc->visit_hours = $row['visit_hours'];
                }
                if (! empty($row['weight']) && (int) ($loc->weight ?? 0) <= 5) {
                    $loc->weight = (int) $row['weight'];
                }
                if (($row['overnight'] ?? null) === true && ! $loc->trip_role) {
                    $loc->trip_role = 'base';
                }
                $loc->save();
            }
        }
    }

    private function tri(mixed $v): string
    {
        $v = is_string($v) ? $v : 'unknown';

        return in_array($v, ['yes', 'no', 'unknown'], true) ? $v : 'unknown';
    }

    private function mobility(mixed $v): string
    {
        $v = is_string($v) ? $v : 'unknown';

        return in_array($v, ['low', 'medium', 'high', 'unknown'], true) ? $v : 'unknown';
    }

    private function dayLoad(mixed $v): string
    {
        $v = is_string($v) ? $v : 'unknown';

        return in_array($v, ['short', 'full', 'long', 'unknown'], true) ? $v : 'unknown';
    }

    private function str(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
