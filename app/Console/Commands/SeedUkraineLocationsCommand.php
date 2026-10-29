<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\AttributeDescription;
use App\Models\AttributeGroup;
use App\Models\AttributeGroupDescription;
use App\Models\Category;
use App\Models\Language;
use App\Models\Location;
use App\Models\LocationAttribute;
use App\Models\LocationDescription;
use App\Models\Manufacturer;
use App\Services\LocationCatalogAssigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedUkraineLocationsCommand extends Command
{
    protected $signature = 'locations:seed-ukraine
        {--count=700 : Target number of UA locations}
        {--fresh : Delete existing UA locations first}';

    protected $description = 'Seed Ukraine training locations (regions, weight, visit time, kosher) for PHP slots tests';

    public function handle(LocationCatalogAssigner $assigner): int
    {
        $target = max(100, min(900, (int) $this->option('count')));
        $he = Language::where('code', 'he')->firstOrFail();
        $ru = Language::where('code', 'ru')->firstOrFail();

        $this->info('Ensuring manufacturers / regions / attributes…');
        $assigner->assign(null);
        $this->ensureExtraAttributes($he, $ru);

        $ua = Manufacturer::query()->where('code', 'UA')->firstOrFail();
        $regions = $this->regionCategoryMap($ua->code);

        if ($this->option('fresh')) {
            $ids = Location::query()->where('manufacturer_id', $ua->id)->pluck('id');
            $this->warn('Deleting '.$ids->count().' existing UA locations…');
            Location::query()->whereIn('id', $ids)->delete();
        }

        $anchors = $this->anchors();
        $catalog = $anchors;
        $n = 1;
        while (count($catalog) < $target) {
            $catalog[] = $this->makeFiller($n++, $regions);
        }
        $catalog = array_slice($catalog, 0, $target);

        $attrIds = $this->attributeIds($ru);
        $created = 0;
        $modelBase = 20000;

        DB::transaction(function () use ($catalog, $ua, $regions, $he, $ru, $attrIds, $modelBase, &$created) {
            foreach ($catalog as $i => $row) {
                $regionKey = $row['region'];
                $categoryId = $regions[$regionKey] ?? null;
                $model = 'ua-'.($modelBase + $i + 1);

                $location = Location::query()->updateOrCreate(
                    ['model' => $model],
                    [
                        'manufacturer_id' => $ua->id,
                        'lat' => $row['lat'],
                        'lng' => $row['lng'],
                        'sort_order' => $i,
                        'weight' => (int) $row['weight'],
                        'visit_hours' => $row['visit_hours'],
                        'kosher_food' => $row['kosher'],
                        'status' => true,
                        'trip_role' => ($row['weight'] >= 9 && str_contains((string) $row['type'], 'хаб')) ? 'base' : 'place',
                        'meta' => [
                            'title' => $row['name_ru'],
                            'country' => 'Украина',
                            'region' => $this->regionRu($regionKey),
                            'format' => 'ua_seed_v1',
                            'place_type' => $row['type'],
                            'activity' => $row['activity'],
                            'family' => $row['family'],
                        ],
                        'meta_ru' => [
                            'title' => $row['name_ru'],
                            'country' => 'Украина',
                            'region' => $this->regionRu($regionKey),
                        ],
                    ]
                );

                LocationDescription::updateOrCreate(
                    ['location_id' => $location->id, 'language_id' => $ru->id],
                    [
                        'name' => $row['name_ru'],
                        'description' => $row['desc_ru'],
                    ]
                );
                LocationDescription::updateOrCreate(
                    ['location_id' => $location->id, 'language_id' => $he->id],
                    [
                        'name' => $row['name_he'],
                        'description' => $row['desc_he'],
                    ]
                );

                if ($categoryId) {
                    $location->categories()->sync([$categoryId]);
                }

                $this->syncAttr($location->id, $attrIds, $ru->id, [
                    'Время на месте' => $this->hoursLabel((float) $row['visit_hours']),
                    'Вес' => (string) $row['weight'],
                    'Тип точки' => $row['type'],
                    'Активность' => $row['activity'],
                    'Для детей' => $row['family'] ? 'да' : 'нет',
                    'Кошер' => match ($row['kosher']) {
                        'yes' => 'да',
                        'no' => 'нет',
                        default => 'неизвестно',
                    },
                    'Не кошер' => $row['kosher'] === 'no' ? 'да' : 'нет',
                    'Сезон' => $row['season'],
                ]);
                $this->syncAttr($location->id, $attrIds, $he->id, [
                    'Время на месте' => $this->hoursLabel((float) $row['visit_hours']),
                    'Вес' => (string) $row['weight'],
                    'Тип точки' => $row['type'],
                    'Активность' => $row['activity'],
                    'Для детей' => $row['family'] ? 'כן' : 'לא',
                    'Кошер' => match ($row['kosher']) {
                        'yes' => 'כן',
                        'no' => 'לא',
                        default => 'לא ידוע',
                    },
                    'Не кошер' => $row['kosher'] === 'no' ? 'כן' : 'לא',
                    'Сезон' => $row['season'],
                ]);

                $created++;
            }
        });

        $total = Location::query()->where('manufacturer_id', $ua->id)->count();
        $this->info("Done. Created/updated {$created}. UA locations now: {$total}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function regionCategoryMap(string $countryCode): array
    {
        $out = [];
        foreach ((array) data_get(config('travel_regions.regions'), $countryCode, []) as $key => $row) {
            $names = array_values(array_unique(array_filter(array_merge(
                [(string) ($row['ru'] ?? '')],
                (array) ($row['legacy_ru'] ?? [])
            ))));
            $cat = Category::query()
                ->whereHas('descriptions', fn ($q) => $q->whereIn('name', $names))
                ->first();
            if ($cat) {
                $out[$key] = $cat->id;
            }
        }

        return $out;
    }

    private function regionRu(string $key): string
    {
        return (string) data_get(config('travel_regions.regions.UA.'.$key), 'ru', $key);
    }

    private function hoursLabel(float $h): string
    {
        if ($h <= 1.5) {
            return '1–1.5 ч';
        }
        if ($h <= 3) {
            return '2–3 ч';
        }
        if ($h <= 5) {
            return 'полдня';
        }

        return 'день';
    }

    /**
     * @param  array<string, int|null>  $attrIds
     * @param  array<string, string>  $values
     */
    private function syncAttr(int $locationId, array $attrIds, int $languageId, array $values): void
    {
        foreach ($values as $ruName => $text) {
            $attrId = $attrIds[$ruName] ?? null;
            if (! $attrId) {
                continue;
            }
            LocationAttribute::updateOrCreate(
                [
                    'location_id' => $locationId,
                    'attribute_id' => $attrId,
                    'language_id' => $languageId,
                ],
                ['text' => $text]
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function attributeIds(Language $ru): array
    {
        $names = ['Время на месте', 'Вес', 'Тип точки', 'Активность', 'Для детей', 'Кошер', 'Не кошер', 'Сезон'];
        $out = [];
        foreach ($names as $name) {
            $id = Attribute::query()
                ->whereHas('descriptions', fn ($q) => $q->where('language_id', $ru->id)->where('name', $name))
                ->value('id');
            $out[$name] = $id ? (int) $id : null;
        }

        return $out;
    }

    private function ensureExtraAttributes(Language $he, Language $ru): void
    {
        $packs = (array) config('travel_regions.attributes', []);
        foreach ($packs as $gSort => $pack) {
            $groupRu = $pack['group']['ru'] ?? null;
            if (! $groupRu) {
                continue;
            }
            $group = AttributeGroup::query()
                ->whereHas('descriptions', fn ($q) => $q->where('name', $groupRu))
                ->first();
            if (! $group) {
                $group = AttributeGroup::create(['sort_order' => 100 + (int) $gSort]);
                AttributeGroupDescription::create([
                    'attribute_group_id' => $group->id,
                    'language_id' => $he->id,
                    'name' => $pack['group']['he'],
                ]);
                AttributeGroupDescription::create([
                    'attribute_group_id' => $group->id,
                    'language_id' => $ru->id,
                    'name' => $groupRu,
                ]);
            }
            foreach ((array) ($pack['items'] ?? []) as $aSort => $item) {
                $exists = Attribute::query()
                    ->where('attribute_group_id', $group->id)
                    ->whereHas('descriptions', fn ($q) => $q->where('name', $item['ru']))
                    ->exists();
                if ($exists) {
                    continue;
                }
                $attr = Attribute::create([
                    'attribute_group_id' => $group->id,
                    'sort_order' => (int) $aSort,
                ]);
                AttributeDescription::create([
                    'attribute_id' => $attr->id,
                    'language_id' => $he->id,
                    'name' => $item['he'],
                ]);
                AttributeDescription::create([
                    'attribute_id' => $attr->id,
                    'language_id' => $ru->id,
                    'name' => $item['ru'],
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anchors(): array
    {
        $rows = [
            // Lviv — городские якоря выше по весу; выезды к замкам — отдельно и слабее.
            ['lviv_castles', 'Львов — площадь Рынок (хаб)', 'לבוב — כיכר רינק', 10, 4.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 49.8419, 24.0315],
            ['lviv_castles', 'Львов — собор Святого Юра', 'קתדרלת סנט ג׳ורג׳', 9, 2.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.8376, 24.0128],
            ['lviv_castles', 'Львов — Высокий замок (смотровая)', 'המצודה הגבוהה — תצפית', 9, 2.5, 'unknown', 'достопримечательность', 'средне', true, 'круглый год', 49.8484, 24.0394],
            ['lviv_castles', 'Львов — обзорный трамвай / туристический автобус', 'חשמלית סיור / אוטובוס תיירים', 9, 2.0, 'unknown', 'активность', 'спокойно', true, 'круглый год', 49.8405, 24.0320],
            ['lviv_castles', 'Львов — ратуша (башня)', 'מגדל העירייה', 8, 1.5, 'unknown', 'достопримечательность', 'средне', true, 'круглый год', 49.8416, 24.0317],
            ['lviv_castles', 'Львов — оперный театр', 'האופרה של לבוב', 8, 2.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.8442, 24.0263],
            ['lviv_castles', 'Львов — Доминиканский собор', 'הכנסייה הדומיניקנית', 7, 1.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.8427, 24.0345],
            ['lviv_castles', 'Львов — еврейский квартал / Золотая Роза', 'רובע יהודי / שושנה הזהב', 8, 2.0, 'yes', 'достопримечательность', 'спокойно', false, 'круглый год', 49.8410, 24.0290],
            ['lviv_castles', 'Львов — Личаковское кладбище', 'בית הקברות ליצ׳קוב', 7, 2.5, 'unknown', 'достопримечательность', 'спокойно', false, 'круглый год', 49.8328, 24.0531],
            ['lviv_castles', 'Львов — парк Стрийский', 'פארק סטרייסקי', 7, 2.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 49.8215, 24.0285],
            ['lviv_castles', 'Львов — Шевченковский гай', 'שמורת שבצ׳נקו', 6, 2.5, 'unknown', 'природа', 'спокойно', true, 'круглый год', 49.8440, 24.0660],
            ['lviv_castles', 'Львов — кофейня / шоколадная мастерская', 'בית קפה / שוקולד', 5, 1.5, 'unknown', 'еда', 'спокойно', true, 'круглый год', 49.8408, 24.0305],
            // Выезды из Львова (не «день у базы»)
            ['lviv_castles', 'Олесский замок', 'טירת אולסקו', 5, 3.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.9683, 24.9011],
            ['lviv_castles', 'Подгорецкий замок', 'טירת פודהורצי', 4, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.8167, 25.0167],
            ['lviv_castles', 'Золочевский замок', 'טירת זלוצ׳ב', 4, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.8070, 24.9030],
            ['lviv_castles', 'Жовква', 'ז׳ובקבה', 4, 3.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 50.0580, 23.9720],
            ['lviv_castles', 'Унивская лавра', 'מנזר אוניב', 3, 2.0, 'unknown', 'достопримечательность', 'спокойно', false, 'круглый год', 49.7160, 24.6160],
            ['lviv_castles', 'Трускавец', 'טרוסקאבץ', 4, 5.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 49.2780, 23.5060],
            ['lviv_castles', 'Винниковский верёвочный парк', 'פארק חבלים ויניקי', 3, 2.0, 'unknown', 'активность', 'средне', true, 'лето', 49.8150, 24.1400],
            // Carpathians
            ['carpathians_galicia', 'Ивано-Франковск (хаб)', 'איבנו־פרנקיבסק', 8, 4.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 48.9226, 24.7111],
            ['carpathians_galicia', 'Славське (хаб гор)', 'סלבסקה', 8, 6.0, 'unknown', 'город-хаб', 'средне', true, 'зима/лето', 48.8470, 23.4450],
            ['carpathians_galicia', 'Тростян / подъёмник', 'טרוסטיאן', 7, 4.0, 'unknown', 'активность', 'активно', true, 'зима/лето', 48.8600, 23.4600],
            ['carpathians_galicia', 'Яремче — Пробий', 'יארמצ׳ה', 8, 3.0, 'unknown', 'природа', 'средне', true, 'круглый год', 48.4580, 24.5560],
            ['carpathians_galicia', 'Буковель', 'בוקובל', 7, 5.0, 'unknown', 'активность', 'активно', true, 'зима/лето', 48.3590, 24.4110],
            ['carpathians_galicia', 'Говерла', 'הוברלה', 9, 8.0, 'unknown', 'активность', 'активно', false, 'лето', 48.1600, 24.5000],
            ['carpathians_galicia', 'Ворохта', 'וורוכטה', 6, 3.0, 'unknown', 'природа', 'средне', true, 'круглый год', 48.2830, 24.5830],
            ['carpathians_galicia', 'Верховина', 'ורחובינה', 6, 3.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 48.1510, 24.8100],
            ['carpathians_galicia', 'Маняваский скит', 'מניאווה', 6, 3.0, 'unknown', 'достопримечательность', 'спокойно', false, 'круглый год', 48.6500, 24.3500],
            ['carpathians_galicia', 'Коломыя — музей Писанка', 'קולומיאה', 6, 2.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 48.5300, 25.0400],
            ['carpathians_galicia', 'Сыневир', 'סינביר', 7, 6.0, 'unknown', 'природа', 'средне', true, 'лето', 48.6170, 23.6830],
            // Zakarpattia
            ['zakarpattia', 'Ужгород — центр (хаб)', 'אוז׳הורוד', 8, 5.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 48.6208, 22.2879],
            ['zakarpattia', 'Мукачево — замок Паланок', 'טירת פאלאנוק', 9, 3.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 48.4310, 22.6870],
            ['zakarpattia', 'Чинадиево', 'צ׳ינדייבו', 5, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 48.4830, 22.8330],
            ['zakarpattia', 'Термы Закарпатья (тест)', 'מעיינות חמים', 5, 3.0, 'unknown', 'активность', 'спокойно', true, 'круглый год', 48.2000, 22.7000],
            // Kyiv
            ['kyiv_ring', 'Киев — София Киевская (хаб)', 'סופיה של קייב', 10, 3.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 50.4529, 30.5144],
            ['kyiv_ring', 'Киево-Печерская лавра', 'לברה קייב', 10, 4.0, 'unknown', 'достопримечательность', 'спокойно', false, 'круглый год', 50.4345, 30.5570],
            ['kyiv_ring', 'Андреевский спуск', 'מדרון אנדריי', 8, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 50.4600, 30.5160],
            ['kyiv_ring', 'Подол', 'פודול', 7, 3.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 50.4650, 30.5150],
            ['kyiv_ring', 'Пирогово', 'פירוגובו', 7, 4.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 50.3550, 30.5030],
            ['kyiv_ring', 'Межигорье', 'מז׳יהוריה', 6, 3.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 50.6160, 30.4680],
            ['kyiv_ring', 'Вышгород', 'וישהורוד', 5, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 50.5850, 30.4900],
            ['kyiv_ring', 'Чернигов (день)', 'צ׳רניהיב', 7, 7.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 51.4980, 31.2890],
            ['kyiv_ring', 'Белая Церковь — Александрия', 'אלכסנדריה', 8, 4.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 49.7950, 30.1160],
            ['kyiv_ring', 'Верёвочный парк у Киева (тест)', 'פארק חבלים ליד קייב', 3, 2.0, 'unknown', 'активность', 'средне', true, 'лето', 50.4000, 30.6000],
            // Uman Berdychiv
            ['uman_berdychiv', 'Умань — Sofiyivka', 'סופייבקה', 9, 4.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 48.7630, 30.2330],
            ['uman_berdychiv', 'Умань — хасидский центр (опц.)', 'אומן — חסידות', 6, 3.0, 'yes', 'достопримечательность', 'спокойно', false, 'круглый год', 48.7480, 30.2200],
            ['uman_berdychiv', 'Бердичев — центр', 'ברדיצ׳ב', 8, 3.0, 'yes', 'город-хаб', 'спокойно', false, 'круглый год', 49.8930, 28.6020],
            ['uman_berdychiv', 'Бердичев — могила р. Леви Ицхака', 'קבר רבי לוי יצחק', 7, 2.0, 'yes', 'достопримечательность', 'спокойно', false, 'круглый год', 49.8900, 28.5800],
            // Odesa
            ['odesa_danube', 'Одесса — Дерибасовская (хаб)', 'אודסה — דריבסקה', 10, 4.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 46.4845, 30.7326],
            ['odesa_danube', 'Потемкинская лестница', 'מדרגות פוטיומקין', 9, 1.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 46.4880, 30.7410],
            ['odesa_danube', 'Одесский оперный театр', 'האופרה של אודסה', 9, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 46.4850, 30.7415],
            ['odesa_danube', 'Одесские катакомбы', 'הקטקומבות', 8, 3.0, 'unknown', 'достопримечательность', 'средне', true, 'круглый год', 46.5500, 30.6500],
            ['odesa_danube', 'Одесса — синагоги / еврейский слой', 'בתי כנסת באודסה', 7, 2.0, 'yes', 'достопримечательность', 'спокойно', false, 'круглый год', 46.4820, 30.7350],
            ['odesa_danube', 'Аркадия / Ланжерон', 'ארקדיה', 7, 3.0, 'unknown', 'природа', 'спокойно', true, 'лето', 46.4400, 30.7600],
            ['odesa_danube', 'Белгород-Днестровский (Аккерман)', 'אקרמן', 8, 5.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 46.2010, 30.3500],
            ['odesa_danube', 'Вилково — город на воде', 'וילקובו', 9, 7.0, 'unknown', 'природа', 'спокойно', true, 'лето', 45.4000, 29.5900],
            ['odesa_danube', 'Шабо — вино', 'שבו', 5, 3.0, 'no', 'еда', 'спокойно', true, 'круглый год', 46.1330, 30.3830],
            // Дальняя точка — не для въезда (outing / nature)
            ['odesa_danube', 'Устричная ферма у моря (тест)', 'חוות צדפות', 4, 2.0, 'no', 'выездная', 'спокойно', true, 'лето', 46.3500, 30.7000],
            ['odesa_danube', 'Затока / Коблево пляж', 'זטוקה / קובלבו', 5, 4.0, 'unknown', 'природа', 'спокойно', true, 'лето', 46.2000, 30.9000],
            // Mykolaiv
            ['mykolaiv_bug', 'Николаев — зоопарк', 'גן החיות מיקולאייב', 8, 3.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 46.9550, 32.0200],
            ['mykolaiv_bug', 'Николаев — набережная Южного Буга', 'טיילת הבוג', 6, 2.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 46.9750, 31.9900],
            ['mykolaiv_bug', 'Очаков', 'אוצ׳קוב', 7, 4.0, 'unknown', 'достопримечательность', 'спокойно', true, 'лето', 46.6180, 31.5400],
            ['mykolaiv_bug', 'Ольвия', 'אולביה', 7, 3.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 46.6900, 31.9000],
            ['mykolaiv_bug', 'Каяки по Южному Бугу', 'קיאקים בבוג', 6, 4.0, 'unknown', 'активность', 'активно', true, 'лето', 47.5500, 31.3000],
            ['mykolaiv_bug', 'Южноукраинск', 'פיווז׳נואוקראינסק', 5, 3.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 47.8200, 31.1800],
            ['mykolaiv_bug', 'Страусиная ферма (Николаевская)', 'חוות יענים', 4, 2.0, 'unknown', 'ферма', 'спокойно', true, 'круглый год', 47.0000, 32.2000],
            // Kharkiv Poltava
            ['kharkiv_poltava', 'Харьков — площадь Свободы / Госпром (хаб)', 'חרקוב — כיכר', 8, 3.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 50.0040, 36.2310],
            ['kharkiv_poltava', 'Харьков — парк Горького', 'פארק גורקי', 7, 3.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 50.0150, 36.2450],
            ['kharkiv_poltava', 'Харьков — Сумская', 'סומסקאיה', 6, 2.0, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.9980, 36.2330],
            ['kharkiv_poltava', 'Полтава — центр', 'פולטבה', 7, 3.0, 'unknown', 'город-хаб', 'спокойно', true, 'круглый год', 49.5880, 34.5510],
            ['kharkiv_poltava', 'Полтавская битва (обзор)', 'שדה הקרב פולטבה', 6, 2.5, 'unknown', 'достопримечательность', 'спокойно', true, 'круглый год', 49.6400, 34.5600],
            ['kharkiv_poltava', 'Днепр — набережная', 'דניפרו — טיילת', 5, 2.5, 'unknown', 'природа', 'спокойно', true, 'круглый год', 48.4640, 35.0460],
            ['kharkiv_poltava', 'Днепр — Монастырский остров', 'האי המונאסטירי', 5, 2.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 48.4620, 35.0700],
            // Одесса — центр для въезда (в конце, чтобы не сдвигать model у якорей)
            ['odesa_danube', 'Ресторан Babel Fish', 'מסעדת Babel Fish', 6, 1.5, 'yes', 'еда', 'спокойно', false, 'круглый год', 46.4838, 30.7348],
            ['odesa_danube', 'Прогулка по Маразлиевской', 'טיול במארזלייבסקה', 5, 1.5, 'unknown', 'по дороге', 'спокойно', true, 'круглый год', 46.4785, 30.7480],
            ['odesa_danube', 'Парк Шевченко (Одесса)', 'פארק שבצ׳נקו אודסה', 6, 2.0, 'unknown', 'природа', 'спокойно', true, 'круглый год', 46.4485, 30.7555],
        ];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'region' => $r[0],
                'name_ru' => $r[1],
                'name_he' => $r[2],
                'weight' => $r[3],
                'visit_hours' => $r[4],
                'kosher' => $r[5],
                'type' => $r[6],
                'activity' => $r[7],
                'family' => $r[8],
                'season' => $r[9],
                'lat' => $r[10],
                'lng' => $r[11],
                'desc_ru' => 'Тестовая локация UA. Время ~'.$r[4].' ч. Вес '.$r[3].'.',
                'desc_he' => 'נקודת בדיקה באוקראינה.',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $regions
     * @return array<string, mixed>
     */
    private function makeFiller(int $n, array $regions): array
    {
        $keys = array_keys(config('travel_regions.regions.UA', []));
        if ($keys === []) {
            $keys = ['kyiv_ring'];
        }
        $region = $keys[$n % count($keys)];
        $centers = [
            'lviv_castles' => [49.84, 24.03],
            'carpathians_galicia' => [48.50, 24.50],
            'zakarpattia' => [48.50, 22.70],
            'kyiv_ring' => [50.40, 30.50],
            'uman_berdychiv' => [48.80, 29.50],
            'odesa_danube' => [46.40, 30.50],
            'mykolaiv_bug' => [47.20, 31.80],
            'kharkiv_poltava' => [49.80, 35.50],
        ];
        [$clat, $clng] = $centers[$region] ?? [49.0, 30.0];

        // Diverse fillers — not the same “rope park” everywhere.
        $kinds = [
            [2, 1.0, 'по дороге', 'спокойно', true, 'круглый год', 'Смотровая площадка'],
            [2, 1.0, 'по дороге', 'спокойно', true, 'круглый год', 'Деревянная церковь'],
            [3, 1.5, 'еда', 'спокойно', true, 'круглый год', 'Кафе у дороги'],
            [3, 1.5, 'еда', 'спокойно', true, 'круглый год', 'Пекарня / кофейня'],
            [3, 2.0, 'еда', 'спокойно', true, 'круглый год', 'Семейный ресторан'],
            [3, 2.0, 'еда', 'спокойно', true, 'круглый год', 'Рынок / стритфуд'],
            [3, 2.0, 'еда', 'спокойно', true, 'лето', 'Мороженое и вафли'],
            [4, 2.5, 'активность', 'средне', true, 'лето', 'Аквапарк / водные горки'],
            [3, 2.0, 'активность', 'средне', true, 'лето', 'Бассейн под открытым небом'],
            [3, 2.0, 'активность', 'средне', true, 'круглый год', 'Карусель и детские аттракционы'],
            [4, 2.5, 'активность', 'средне', true, 'лето', 'Парк развлечений'],
            [3, 1.5, 'активность', 'спокойно', true, 'круглый год', 'Колесо обозрения (мини)'],
            [3, 2.0, 'активность', 'средне', true, 'лето', 'Батутный городок'],
            [3, 2.0, 'активность', 'средне', true, 'лето', 'Картинг'],
            [3, 2.0, 'активность', 'средне', true, 'лето', 'Прокат велосипедов'],
            [4, 2.5, 'активность', 'активно', true, 'лето', 'Сплав на байдарках (мини)'],
            [3, 2.0, 'активность', 'средне', true, 'лето', 'Катание на лошадях'],
            [3, 1.5, 'природа', 'спокойно', true, 'круглый год', 'Городской сквер'],
            [4, 2.0, 'природа', 'спокойно', true, 'лето', 'Парк с прудом'],
            [4, 2.0, 'природа', 'спокойно', true, 'лето', 'Озеро / пляж'],
            [3, 1.5, 'природа', 'спокойно', true, 'круглый год', 'Аллея для прогулок'],
            [4, 2.5, 'природа', 'средне', true, 'лето', 'Тропа у леса'],
            [3, 2.0, 'ферма', 'спокойно', true, 'круглый год', 'Контактный зоопарк'],
            [3, 2.0, 'ферма', 'спокойно', true, 'круглый год', 'Ягодная ферма'],
            [3, 2.0, 'ферма', 'спокойно', true, 'круглый год', 'Сыроварня'],
            [3, 2.0, 'ферма', 'спокойно', true, 'круглый год', 'Пасека / мёд'],
            [4, 2.0, 'достопримечательность', 'спокойно', false, 'круглый год', 'Краеведческий дворик'],
            [3, 1.5, 'достопримечательность', 'спокойно', false, 'круглый год', 'Еврейский двор / мемориал'],
            [3, 1.5, 'достопримечательность', 'спокойно', true, 'круглый год', 'Маленький музей'],
            [2, 1.0, 'по дороге', 'спокойно', true, 'круглый год', 'Фотостоп у моста'],
            [2, 1.0, 'по дороге', 'спокойно', true, 'круглый год', 'Сельская площадь'],
            [2, 1.0, 'по дороге', 'спокойно', true, 'круглый год', 'Часовня у трассы'],
            [3, 1.5, 'еда', 'спокойно', true, 'круглый год', 'Пиццерия'],
            [3, 1.5, 'еда', 'спокойно', true, 'круглый год', 'Шаурмная / фастфуд'],
            [4, 2.0, 'активность', 'средне', true, 'лето', 'Лодочная станция'],
            [3, 2.0, 'активность', 'спокойно', true, 'зима', 'Каток'],
            [3, 2.0, 'природа', 'спокойно', true, 'круглый год', 'Ботанический уголок'],
            [3, 1.5, 'достопримечательность', 'спокойно', true, 'круглый год', 'Уличное граффити / арт-двор'],
        ];
        $kind = $kinds[$n % count($kinds)];
        $kosherPool = ['unknown', 'unknown', 'unknown', 'no', 'yes'];
        $kosher = $kosherPool[$n % count($kosherPool)];
        if (str_contains($kind[6], 'Еврейский')) {
            $kosher = 'yes';
        }
        if (str_contains($kind[6], 'Рынок') || str_contains($kind[6], 'кофе') || str_contains($kind[6], 'Кафе')
            || str_contains($kind[6], 'Пекарня') || str_contains($kind[6], 'Пицц') || str_contains($kind[6], 'Шаурма')
            || str_contains($kind[6], 'Мороженое') || str_contains($kind[6], 'ресторан')) {
            $kosher = $n % 7 === 0 ? 'yes' : ($n % 3 === 0 ? 'no' : 'unknown');
        }

        $village = [
            'Яблунька', 'Сосонка', 'Кринички', 'Заріччя', 'Підгірне', 'Липова', 'Березове', 'Калинівка',
            'Вишнева', 'Дубова', 'Ромашкова', 'Світанкова', 'Тиха', 'Зоряна', 'Веселкова', 'Медова',
        ][$n % 16];
        $nameRu = $kind[6].' «'.$village.'»';
        $jitter = (($n % 17) - 8) * 0.02;

        return [
            'region' => $region,
            'name_ru' => $nameRu,
            'name_he' => 'נקודת מילוי '.$n,
            'weight' => $kind[0],
            'visit_hours' => $kind[1],
            'kosher' => $kosher,
            'type' => $kind[2],
            'activity' => $kind[3],
            'family' => $kind[4],
            'season' => $kind[5],
            'lat' => round($clat + $jitter, 5),
            'lng' => round($clng + (($n % 13) - 6) * 0.03, 5),
            'desc_ru' => 'Тестовая мелочь для PHP-слотов. Вес '.$kind[0].', ~'.$kind[1].' ч.',
            'desc_he' => 'נקודת בדיקה קטנה.',
        ];
    }
}
