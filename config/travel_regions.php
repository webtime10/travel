<?php

/**
 * Canonical countries (manufacturers) and regions (categories).
 * Aliases are matched case-insensitively after trim.
 */
return [
    'region_parent' => [
        'he' => 'אזורים',
        'ru' => 'Области',
    ],
    'countries' => [
        'IT' => [
            'he' => 'איטליה',
            'ru' => 'Италия',
            'aliases' => ['איטליה', 'italy', 'italia', 'италия'],
        ],
        'CH' => [
            'he' => 'שווייץ',
            'ru' => 'Швейцария',
            'aliases' => ['שווייץ', 'switzerland', 'schweiz', 'suisse', 'швейцария'],
        ],
        'SM' => [
            'he' => 'סן מרינו',
            'ru' => 'Сан-Марино',
            'aliases' => ['סן מרינו', 'san marino', 'сан-марино', 'сан марино'],
        ],
        'AT' => [
            'he' => 'אוסטריה',
            'ru' => 'Австрия',
            'aliases' => ['אוסטריה', 'austria', 'österreich', 'австрия'],
        ],
        'JP' => [
            'he' => 'יפן',
            'ru' => 'Япония',
            'aliases' => ['יפן', 'japan', 'япония'],
        ],
    ],
    'skip_regions' => ['all_regions(dont_touch!)', 'all_regions', 'dont_touch!'],
    'regions' => [
        'IT' => [
            'toscana' => [
                'he' => 'טוסקנה',
                'ru' => 'Тоскана',
                'aliases' => ['toscana', 'tuscany', 'טוסקנה', 'тоскана'],
            ],
            'veneto' => [
                'he' => 'ונטו',
                'ru' => 'Венето',
                'aliases' => ['veneto', 'ונטו', 'венето'],
            ],
            'lombardia' => [
                'he' => 'לומברדיה',
                'ru' => 'Ломбардия',
                'aliases' => ['lombardia', 'lombardy', 'לומברדיה', 'ломбардия'],
            ],
            'trentino' => [
                'he' => "טרנטינו - אלטו אדיג'ה",
                'ru' => 'Трентино — Альто-Адидже',
                'aliases' => [
                    'trentino-alto adige', 'trentino-south tyrol', 'trentino south tyrol',
                    "טרנטינו - אלטו אדיג'ה", 'трентино', 'трентино-альто-адидже',
                ],
            ],
            'piemonte' => [
                'he' => 'פיימונטה',
                'ru' => 'Пьемонт',
                'aliases' => [
                    'piemonte', 'piedmont', 'פיימונטה', 'пьемонт',
                    'città metropolitana di torino', 'citta metropolitana di torino',
                ],
            ],
            'valle_aosta' => [
                'he' => "ואלה ד'אוסטה",
                'ru' => 'Валле-д’Аоста',
                'aliases' => ["valle d'aosta", 'aosta', 'aosta valley', "ואלה ד'אוסטה", 'аоста', 'валле-д’аоста', 'валле д аоста'],
            ],
            'liguria' => [
                'he' => 'ליגוריה',
                'ru' => 'Лигурия',
                'aliases' => ['liguria', 'лигурия'],
            ],
            'emilia' => [
                'he' => 'אמיליה-רומאניה',
                'ru' => 'Эмилия-Романья',
                'aliases' => ['emilia-romagna', 'аמיליה-רומאניה', 'эмилия-романья', 'эмилия романья'],
            ],
            'lazio' => [
                'he' => 'לציו',
                'ru' => 'Лацио',
                'aliases' => ['lazio', 'לציו', 'лацио'],
            ],
            'umbria' => [
                'he' => 'אומבריה',
                'ru' => 'Умбрия',
                'aliases' => ['umbria', 'умбрия'],
            ],
            'abruzzo' => [
                'he' => 'אברוצו',
                'ru' => 'Абруццо',
                'aliases' => ['abruzzo', 'אברוצו', 'абруццо'],
            ],
            'campania' => [
                'he' => 'קמפניה',
                'ru' => 'Кампания',
                'aliases' => [
                    'campania', 'קמפניה', 'кампания',
                    'città metropolitana di napoli', 'citta metropolitana di napoli',
                ],
            ],
            'puglia' => [
                'he' => 'פוליה',
                'ru' => 'Апулия',
                'aliases' => ['puglia', 'apulia', 'פוליה', 'апулия'],
            ],
            'basilicata' => [
                'he' => 'בזיליקטה',
                'ru' => 'Базиликата',
                'aliases' => ['basilicata', 'базиликата'],
            ],
            'calabria' => [
                'he' => 'קלבריה',
                'ru' => 'Калабрия',
                'aliases' => ['calabria', 'קלבריה', 'калабрия'],
            ],
            'sicilia' => [
                'he' => 'סיציליה',
                'ru' => 'Сицилия',
                'aliases' => ['sicilia', 'sicily', 'סיציליה', 'сицилия'],
            ],
            'sardegna' => [
                'he' => 'סרדיניה',
                'ru' => 'Сардиния',
                'aliases' => ['sardegna', 'sardinia', 'סרדיניה', 'сардиния'],
            ],
        ],
        'CH' => [
            'ticino' => [
                'he' => 'טיצ׳ינו',
                'ru' => 'Тичино',
                'aliases' => ['ticino', 'tessin', 'טיסינו', 'טיצ׳ינו', 'тичино'],
            ],
            'valais' => [
                'he' => 'ואלה',
                'ru' => 'Вале',
                'aliases' => ['wallis', 'valais', 'вале', 'валлис'],
            ],
        ],
        'SM' => [
            'borgo' => [
                'he' => "בורגו מג'ורה",
                'ru' => 'Борго-Маджоре',
                'aliases' => ["בורגו מג'ורה", 'borgo maggiore', 'борго-маджоре'],
            ],
        ],
        'AT' => [
            'tirol' => [
                'he' => 'טירול',
                'ru' => 'Тироль',
                'aliases' => ['tirol', 'tyrol', 'тироль'],
            ],
        ],
        'JP' => [
            'tokyo' => [
                'he' => 'טוקיו',
                'ru' => 'Токио',
                'aliases' => ['tokyo', 'токио', 'טוקיו'],
            ],
        ],
    ],
    'attributes' => [
        [
            'group' => ['he' => 'ביקור', 'ru' => 'Посещение'],
            'items' => [
                ['he' => 'זמן במקום', 'ru' => 'Время на месте'],
                ['he' => 'כניסה בתשלום', 'ru' => 'Платный вход'],
                ['he' => 'עונה', 'ru' => 'Сезон'],
            ],
        ],
        [
            'group' => ['he' => 'נגישות', 'ru' => 'Доступность'],
            'items' => [
                ['he' => 'חניה', 'ru' => 'Парковка'],
                ['he' => 'מתאים לילדים', 'ru' => 'Для детей'],
            ],
        ],
        [
            'group' => ['he' => 'אוכל', 'ru' => 'Еда'],
            'items' => [
                ['he' => 'כשר', 'ru' => 'Кошер'],
            ],
        ],
    ],
];
