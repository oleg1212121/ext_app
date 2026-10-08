<?php

// UI strings: the Recommendations page (ADR 0075).
return [
    'recommendations.title' => ['en' => 'Recommendations', 'ru' => 'Рекомендации'],
    'recommendations.subtitle' => [
        'en' => 'Texts you know at or above the threshold, least known first — easy reading with a little headroom.',
        'ru' => 'Тексты, которые вы знаете на уровне порога и выше, — от менее знакомых к знакомым. Лёгкое чтение с небольшим запасом.',
    ],
    'recommendations.search_placeholder' => ['en' => 'Search works or texts…', 'ru' => 'Поиск по произведениям и текстам…'],
    'recommendations.knowledge' => ['en' => 'Min. knowledge, %', 'ru' => 'Мин. знание, %'],
    'recommendations.language' => ['en' => 'Language', 'ru' => 'Язык'],
    'recommendations.apply' => ['en' => 'Apply', 'ru' => 'Применить'],
    'recommendations.show_texts' => ['en' => 'Show texts', 'ru' => 'Показать тексты'],
    'recommendations.hide_texts' => ['en' => 'Hide texts', 'ru' => 'Скрыть тексты'],
    'recommendations.empty_no_scores' => ['en' => 'No recommendations yet.', 'ru' => 'Пока нет рекомендаций.'],
    'recommendations.empty_no_scores_hint' => [
        'en' => "Recommendations rank texts you've opened at least once — open a few in the Library and come back.",
        'ru' => 'Рекомендации ранжируют тексты, которые вы открывали хотя бы раз. Откройте несколько в Библиотеке и вернитесь.',
    ],
    'recommendations.empty_filtered' => [
        'en' => 'No texts at or above :knowledge% for this language.',
        'ru' => 'Нет текстов на уровне :knowledge% и выше для этого языка.',
    ],
    'recommendations.empty_filtered_hint' => [
        'en' => 'Lower the knowledge threshold or clear the search.',
        'ru' => 'Понизьте порог знания или очистите поиск.',
    ],
];
