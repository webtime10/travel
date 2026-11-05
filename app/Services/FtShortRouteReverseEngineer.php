<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\FtShortRoute;

/**
 * Обратный разбор FT Short — промпт директора: аудитория / скрытый смысл / почему так.
 * fine_tune.question + days — отдельно для датасета (places_text, без location_id).
 */
class FtShortRouteReverseEngineer
{
    public const SCHEMA_VERSION = 4;

    /** Ивритский промпт — уходит в Gemini. */
    public const PROMPT_SETTING_KEY = 'travel.reverse_engineer.ft_short_route_prompt_he_v3';

    /** Русский промпт — только UI (чтение/правка). */
    public const PROMPT_SETTING_KEY_RU = 'travel.reverse_engineer.ft_short_route_prompt_ru_v3';

    /** @deprecated старый ключ смеси EN/RU */
    private const PROMPT_SETTING_KEY_LEGACY = 'travel.reverse_engineer.ft_short_route_prompt';

    public function __construct(private GeminiJsonClient $gemini) {}

    /** Промпт для UI: he|ru. В Gemini всегда resolvePromptHe(). */
    public static function resolvePromptForUi(string $uiLang): string
    {
        return strtolower($uiLang) === 'ru'
            ? self::resolvePromptRu()
            : self::resolvePromptHe();
    }

    public static function resolvePrompt(): string
    {
        return self::resolvePromptHe();
    }

    public static function resolvePromptHe(): string
    {
        $stored = trim((string) AppSetting::getValue(self::PROMPT_SETTING_KEY, ''));
        if ($stored !== ''
            && str_contains($stored, 'LANGUAGE_HE_ONLY_V3')
            && str_contains($stored, 'target_audience')
            && str_contains($stored, 'why_route_fits_audience')
        ) {
            return $stored;
        }

        $fresh = self::defaultPromptHe();
        AppSetting::putValue(self::PROMPT_SETTING_KEY, $fresh);

        return $fresh;
    }

    public static function resolvePromptRu(): string
    {
        $stored = trim((string) AppSetting::getValue(self::PROMPT_SETTING_KEY_RU, ''));
        if ($stored !== ''
            && str_contains($stored, 'LANGUAGE_RU_UI_V3')
            && str_contains($stored, 'target_audience')
            && str_contains($stored, 'why_route_fits_audience')
        ) {
            return $stored;
        }

        $fresh = self::defaultPromptRu();
        AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $fresh);

        return $fresh;
    }

    public static function defaultPrompt(): string
    {
        return self::defaultPromptHe();
    }

    /** Полностью на иврите — рабочий промпт для Gemini. */
    public static function defaultPromptHe(): string
    {
        return <<<'PROMPT'
LANGUAGE_HE_ONLY_V3

שפת התשובה (חובה):
כל ערכי המחרוזות ב־JSON חייבים להיות בעברית בלבד.
אין לכתוב רוסית. אין לכתוב אנגלית. מפתחות ה־JSON נשארים בלטינית לפי הסכימה.
אם התשובה ברוסית — היא פסולה.

פרומפט להנדסה לאחור: מיקוד בקהל היעד ובלוגיקת המחבר

אתה אנליסט בכיר של נתוני תיירות ומומחה לפרופיל לקוחות בטיולי רכב.

מטרה:
לבצע הנדסה לאחור עמוקה של מסלול מומחה אמיתי. המיקוד העיקרי: קהל היעד (למי הטיול, למה נבנה כך, אילו כאבים כל פרט סוגר).
אל תצמצם ללוגיסטיקה יבשה. צריך את סיפור ההחלטה של המחבר. התוצאה תיכנס למערך Fine-Tuning.

כלל פרגמטיות:
בלי מטפורות ספרותיות. הסבר דרך צרכים אמיתיים: סיבולת, גיל ילדים, דת, פחד מכבישים הרריים, עייפות נהיגה. עצירת מעבר על הכביש — כתוב כך (בעברית).

אזורים ו־Grounding:
- אזורים ובסיסים רק מתוך SOURCE. אל תוסיף אזורים זרים.
- places_text ב־fine_tune — מהקלט (שמות מקומות כמו במקור; תפקידי ימים — בעברית).

קלט: אחרי --- SOURCE --- יופיע JSON (title, route_text, days). ענה ב־JSON בלבד.

1. דיוקן קהל (הרכב / קצב / מגבלות) — בעברית.
2. למה המסלול מתאים + 3 ניסוחי בקשה של לקוח — בעברית.
3. מאקרו: אזורים, בסיסים, מעבר — בעברית.
4. מיקרו: עד 5 ימים מייצגים — בעברית.
5. fine_tune.question / summary / כותרות ימים — בעברית; places_text מהמקור.

סכימת JSON (ערכים בעברית בלבד):

{
  "target_audience": {
    "group_profile": "תיאור מפורט של הרכב הקבוצה",
    "activity_and_pace_level": "קצב, מאמץ, מגבלות",
    "special_constraints": [
      "מגבלות כשרות / שבת / ילדים וכו'"
    ],
    "why_route_fits_audience": "למה המסלול מתאים בדיוק לפרופיל הזה",
    "potential_user_queries": [
      "ניסוח קצר",
      "ניסוח עם כאבים",
      "ניסוח מפורט"
    ]
  },
  "macro_architecture": {
    "region_selection_logic": "למה האזורים האלה לפרופיל הזה",
    "base_camps_strategy": "בסיסים / החלפת מלונות ולמה",
    "transit_days_handling": "איך מותאמים ימי מעבר"
  },
  "micro_clustering": [
    {
      "day_number": 1,
      "day_description": "תפקיד היום בקצרה",
      "audience_adapted_logic": "למה הנקודות והסדר מתאימים לקהל",
      "logistics_and_timing": "עייפות נהיגה, תזמון"
    }
  ],
  "fine_tune": {
    "question": "יעדים + N ימים (+ בקשה). ייחודי למסלול.",
    "answer": {
      "summary": "למי + אזורים + בסיסים — בקצרה.",
      "days": [
        {
          "day_index": 1,
          "title": "תפקיד היום",
          "places_text": "מקומות מהמקור"
        }
      ]
    }
  }
}

תזכורת אחרונה: כל ערך טקסט = עברית בלבד. בלי רוסית. בלי אנגלית חופשית.
PROMPT;
    }

    /** Полностью на русском — для UI; в Gemini не уходит. */
    public static function defaultPromptRu(): string
    {
        return <<<'PROMPT'
LANGUAGE_RU_UI_V3

Важно для админки:
Это русская копия промпта для чтения и правки.
В Gemini всегда отправляется ивритская версия; ответ обратного разбора — только на иврите (для FineTunes).

Язык ответа модели (обязательно):
Все строковые значения JSON — только на иврите.
Не на русском. Не на английском. Ключи JSON — латиницей по схеме.
Ответ на русском считается неверным.

Промпт обратного разбора: фокус на аудитории и логике автора

Ты — старший аналитик туристических данных и эксперт по профилю клиентов в автопутешествиях.

Цель:
Глубокий обратный разбор реального маршрута эксперта. Главный фокус — целевая аудитория (для кого тур, почему собран так, какие боли закрывает каждая деталь).
Не своди к сухой логистике. Нужна история решения автора. Результат пойдёт в датасет Fine-Tuning.

Правило прагматизма:
Без литературных метафор. Объясняй через реальные потребности: выносливость, возраст детей, религия, страх горных дорог, усталость от руля. Транзитный стоп по трассе — так и пиши (на иврите в ответе модели).

Регионы и grounding:
- Регионы и базы только из SOURCE. Не добавляй чужие районы.
- places_text в fine_tune — из входа (топонимы как в источнике; роли дней — на иврите в ответе).

Вход: после --- SOURCE --- будет JSON (title, route_text, days). Ответ — строго JSON.

1. Портрет аудитории (состав / темп / ограничения) — на иврите в ответе.
2. Почему маршрут подходит + 3 формулировки запроса клиента — на иврите.
3. Макро: регионы, базы, транзит — на иврите.
4. Микро: до 5 показательных дней — на иврите.
5. fine_tune.question / summary / заголовки дней — на иврите; places_text из источника.

Схема JSON (значения в ответе модели — на иврите):

{
  "target_audience": {
    "group_profile": "подробное описание состава группы",
    "activity_and_pace_level": "темп, нагрузка, ограничения",
    "special_constraints": [
      "ограничения: кашрут / шаббат / дети и т.п."
    ],
    "why_route_fits_audience": "почему маршрут идеален именно для этого профиля",
    "potential_user_queries": [
      "короткая формулировка",
      "с болями",
      "детальная"
    ]
  },
  "macro_architecture": {
    "region_selection_logic": "почему эти регионы для этого профиля",
    "base_camps_strategy": "базы / смена отелей и почему",
    "transit_days_handling": "как адаптированы транзитные дни"
  },
  "micro_clustering": [
    {
      "day_number": 1,
      "day_description": "кратко роль дня",
      "audience_adapted_logic": "почему точки и порядок подходят аудитории",
      "logistics_and_timing": "усталость от руля, тайминг"
    }
  ],
  "fine_tune": {
    "question": "хабы + N дней (+ пожелание). Уникально для тура.",
    "answer": {
      "summary": "для кого + регионы + базы — кратко.",
      "days": [
        {
          "day_index": 1,
          "title": "роль дня",
          "places_text": "места из источника"
        }
      ]
    }
  }
}

Напоминание: в ответе Gemini каждый текстовый value = иврит. Без русского. Без английского.
PROMPT;
    }

    public static function savePromptForUi(string $uiLang, string $prompt): void
    {
        $prompt = trim($prompt);
        if (strtolower($uiLang) === 'ru') {
            AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $prompt);
        } else {
            AppSetting::putValue(self::PROMPT_SETTING_KEY, $prompt);
        }
    }

    public static function resetPromptForUi(string $uiLang): string
    {
        if (strtolower($uiLang) === 'ru') {
            $fresh = self::defaultPromptRu();
            AppSetting::putValue(self::PROMPT_SETTING_KEY_RU, $fresh);

            return $fresh;
        }
        $fresh = self::defaultPromptHe();
        AppSetting::putValue(self::PROMPT_SETTING_KEY, $fresh);

        return $fresh;
    }

    /**
     * @return array{principle: array<string, mixed>}
     */
    public function extract(FtShortRoute $route): array
    {
        $days = is_array($route->days) ? $route->days : [];
        if ($days === [] && trim((string) ($route->route_text ?? '')) === '') {
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

        $daysPayload = [];
        foreach ($days as $d) {
            if (! is_array($d)) {
                continue;
            }
            $idx = (int) ($d['day_index'] ?? (count($daysPayload) + 1));
            $places = trim((string) ($d['places'] ?? ''));
            $opts = array_values(array_filter(array_map(
                static fn ($o) => trim((string) $o),
                (array) ($d['options'] ?? [])
            )));
            $daysPayload[] = [
                'day_index' => $idx,
                'places' => $places,
                'options' => $opts,
            ];
        }

        $material = json_encode([
            'ft_short_route_id' => $route->id,
            'doc_code' => $route->doc_code,
            'title' => $route->displayTitle(false),
            'days_count' => $route->days_count ?: count($daysPayload),
            'route_text' => (string) ($route->route_text ?? ''),
            'days' => $daysPayload,
            'output_language' => 'he',
            'note' => 'כל ערכי המחרוזות בעברית בלבד. מקומות כמו במקור.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $material = mb_substr((string) $material, 0, 90000);

        $instruction = trim(self::resolvePromptHe());
        if ($instruction === '') {
            $instruction = self::defaultPromptHe();
        }

        $raw = $this->gemini->ask($instruction, $material, 'minimal');
        $data = $this->normalize($raw, $daysPayload, $this->shortQuestionFallback($route));

        // Если модель всё же ответила по-русски — один жёсткий retry на иврите.
        if ($this->looksLikeRussian($data) && ! $this->looksLikeHebrew($data)) {
            $retryInstruction = $instruction."\n\nנסה שוב: התשובה הקודמת הייתה ברוסית. כתוב מחדש את כל ה־JSON עם ערכים בעברית בלבד. בלי קירילית.";
            $raw = $this->gemini->ask($retryInstruction, $material, 'minimal');
            $data = $this->normalize($raw, $daysPayload, $this->shortQuestionFallback($route));
        }

        if ($this->looksLikeRussian($data) && ! $this->looksLikeHebrew($data)) {
            throw new \RuntimeException(
                'המודל החזיר ניתוח ברוסית במקום בעברית. לחץ שוב על «קבל הנדסה לאחור».'
            );
        }

        $data['schema_version'] = self::SCHEMA_VERSION;
        $data['extracted_at'] = now()->toIso8601String();
        $data['output_language'] = 'he';

        $route->reverse_principle = $data;
        // Новый HE: сбрасываем RU только если он был переводом со старого HE.
        // Ошибочный русский, перенесённый из HE-слота (misplaced), оставляем во вкладке RU.
        $oldRu = is_array($route->reverse_principle_ru) ? $route->reverse_principle_ru : null;
        $keepMisplacedRu = is_array($oldRu)
            && ! empty($oldRu['misplaced_russian_from_he_slot'])
            && empty($oldRu['skipped']);
        if (! $keepMisplacedRu) {
            $route->reverse_principle_ru = null;
        }
        $route->save();

        return ['principle' => $data];
    }

    /** Старые RE ошибочно на русском в поле HE — отдать как RU-кандидат. */
    public static function sampleText(array $rp): string
    {
        $parts = [
            $rp['target_audience']['group_profile'] ?? null,
            $rp['audience_portrait']['group_composition'] ?? null,
            $rp['target_audience']['why_route_fits_audience'] ?? null,
            $rp['macro_architecture']['region_selection_logic'] ?? null,
            $rp['principle'] ?? null,
        ];

        return trim(implode(' ', array_map(static fn ($v) => is_string($v) ? $v : '', $parts)));
    }

    public static function isCyrillicHeavy(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        $heb = preg_match_all('/\p{Hebrew}/u', $text) ?: 0;

        return $cyr >= 20 && $cyr > $heb * 2;
    }

    public static function isHebrewHeavy(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $heb = preg_match_all('/\p{Hebrew}/u', $text) ?: 0;
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;

        return $heb >= 20 && $heb > $cyr;
    }

    /** @param  array<string, mixed>  $data */
    private function looksLikeRussian(array $data): bool
    {
        return self::isCyrillicHeavy(self::sampleText($data));
    }

    /** @param  array<string, mixed>  $data */
    private function looksLikeHebrew(array $data): bool
    {
        return self::isHebrewHeavy(self::sampleText($data));
    }

    private function shortQuestionFallback(FtShortRoute $route): string
    {
        $title = trim((string) ($route->displayTitle(false) ?: ''));
        $n = (int) ($route->days_count ?: 0);
        $hubs = $title !== '' ? $title : 'איטליה';
        if ($n > 0) {
            return $hubs.', '.$n.' ימים';
        }

        return $hubs;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array{day_index:int,places:string,options:list<string>}>  $daysPayload
     * @return array<string, mixed>
     */
    private function normalize(array $raw, array $daysPayload, string $shortQuestionFallback = ''): array
    {
        // Директорская схема target_audience + старая audience_portrait/fit
        $ta = is_array($raw['target_audience'] ?? null) ? $raw['target_audience'] : [];
        $audienceOld = is_array($raw['audience_portrait'] ?? null) ? $raw['audience_portrait'] : [];
        $fitOld = is_array($raw['audience_fit'] ?? null) ? $raw['audience_fit'] : [];

        $groupProfile = $this->str(
            $ta['group_profile'] ?? $audienceOld['group_composition'] ?? null,
            1200
        );
        $activity = $this->str(
            $ta['activity_and_pace_level'] ?? $audienceOld['activity_and_comfort'] ?? null,
            1200
        );

        $constraints = [];
        foreach ((array) ($ta['special_constraints'] ?? []) as $c) {
            $c = $this->str($c, 400);
            if ($c !== null) {
                $constraints[] = $c;
            }
        }
        if ($constraints === []) {
            $one = $this->str($audienceOld['constraints'] ?? null, 800);
            if ($one !== null) {
                $constraints[] = $one;
            }
        }

        $whyFits = $this->str(
            $ta['why_route_fits_audience'] ?? $fitOld['why_this_route_fits'] ?? null,
            2500
        );

        $queries = [];
        foreach ((array) ($ta['potential_user_queries'] ?? $fitOld['possible_queries'] ?? []) as $q) {
            $q = $this->str($q, 500);
            if ($q !== null) {
                $queries[] = $q;
            }
            if (count($queries) >= 5) {
                break;
            }
        }

        $macro = is_array($raw['macro_architecture'] ?? null) ? $raw['macro_architecture'] : [];
        $regionLogic = $this->str($macro['region_selection_logic'] ?? $raw['principle'] ?? null, 2000);
        $bases = $this->str(
            $macro['base_camps_strategy'] ?? $macro['routing_strategy'] ?? $raw['geo_logic'] ?? null,
            2000
        );
        $transit = $this->str($macro['transit_days_handling'] ?? null, 2000);

        $microList = [];
        foreach ((array) ($raw['micro_clustering'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $microList[] = [
                'day_number' => (int) ($row['day_number'] ?? 0),
                'day_description' => $this->str($row['day_description'] ?? null, 400),
                'audience_adapted_logic' => $this->str(
                    $row['audience_adapted_logic'] ?? $row['practical_logic'] ?? null,
                    1500
                ),
                'logistics_and_timing' => $this->str(
                    $row['logistics_and_timing']
                        ?? $row['logistics_and_fatigue']
                        ?? $row['logistics_and_ztl']
                        ?? null,
                    800
                ),
                // совместимость со старым UI
                'practical_logic' => $this->str(
                    $row['audience_adapted_logic'] ?? $row['practical_logic'] ?? null,
                    1500
                ),
                'logistics_and_fatigue' => $this->str(
                    $row['logistics_and_timing']
                        ?? $row['logistics_and_fatigue']
                        ?? $row['logistics_and_ztl']
                        ?? null,
                    800
                ),
            ];
        }

        $abstract = $this->str(
            $raw['abstract_user_prompt'] ?? $queries[1] ?? $queries[0] ?? null,
            2000
        );

        $ft = is_array($raw['fine_tune'] ?? null) ? $raw['fine_tune'] : [];
        $ftQuestion = $this->str($ft['question'] ?? null, 400);
        if ($ftQuestion === '') {
            $ftQuestion = $this->str($shortQuestionFallback !== '' ? $shortQuestionFallback : ($queries[0] ?? $abstract), 400);
        }
        $ftAnswerRaw = is_array($ft['answer'] ?? null) ? $ft['answer'] : [];
        $ftSummary = $this->str(
            $ftAnswerRaw['summary'] ?? trim(($whyFits ?? '').' '.($bases ?? '')),
            2000
        );

        $ftDays = [];
        foreach ((array) ($ftAnswerRaw['days'] ?? []) as $fd) {
            if (! is_array($fd)) {
                continue;
            }
            $ftDays[] = [
                'day_index' => (int) ($fd['day_index'] ?? count($ftDays) + 1),
                'title' => $this->str($fd['title'] ?? null, 200),
                'places_text' => $this->str($fd['places_text'] ?? $fd['places'] ?? null, 500),
            ];
        }
        if ($ftDays === []) {
            foreach ($daysPayload as $d) {
                $chunk = trim((string) ($d['places'] ?? ''));
                foreach ((array) ($d['options'] ?? []) as $o) {
                    $o = trim((string) $o);
                    if ($o !== '') {
                        $chunk = $chunk === '' ? $o : ($chunk.' / '.$o);
                    }
                }
                $ftDays[] = [
                    'day_index' => (int) ($d['day_index'] ?? count($ftDays) + 1),
                    'title' => null,
                    'places_text' => $chunk !== '' ? $chunk : null,
                ];
            }
        }

        return [
            'target_audience' => [
                'group_profile' => $groupProfile,
                'activity_and_pace_level' => $activity,
                'special_constraints' => $constraints,
                'why_route_fits_audience' => $whyFits,
                'potential_user_queries' => $queries,
            ],
            // aliases для старого UI / FineTune exporter
            'audience_portrait' => [
                'group_composition' => $groupProfile,
                'activity_and_comfort' => $activity,
                'constraints' => $constraints !== [] ? implode('; ', $constraints) : null,
            ],
            'audience_fit' => [
                'why_this_route_fits' => $whyFits,
                'possible_queries' => $queries,
            ],
            'abstract_user_prompt' => $abstract,
            'synthetic_query' => $abstract,
            'macro_architecture' => [
                'region_selection_logic' => $regionLogic,
                'base_camps_strategy' => $bases,
                'transit_days_handling' => $transit,
                'routing_strategy' => trim(($bases ?? '').($transit ? ' '.$transit : '')),
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
            'geo_logic' => $bases,
            'confidence' => in_array($raw['confidence'] ?? '', ['high', 'medium', 'low'], true)
                ? $raw['confidence'] : 'medium',
        ];
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
