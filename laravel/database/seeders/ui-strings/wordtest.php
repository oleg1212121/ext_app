<?php

// UI strings: word test page (Pages/WordTest/WordTest.jsx).
return [
    'wordtest.title' => ['en' => 'Word test', 'ru' => 'Тест слов'],
    'wordtest.language' => ['en' => 'Language', 'ru' => 'Язык'],
    'wordtest.intro' => [
        'en' => 'Tick every word you know, then submit — your answers place you on a 0–20,000 word-frequency scale, and all more common words are marked as familiar.',
        'ru' => 'Отметьте все слова, которые вы знаете, и нажмите «Отправить» — ответы определят ваше место на шкале частотности от 0 до 20 000, а все более частые слова будут отмечены как знакомые.',
    ],
    'wordtest.known' => ['en' => 'I know this word', 'ru' => 'Я знаю это слово'],
    'wordtest.checklist' => ['en' => ':checked / :total', 'ru' => ':checked / :total'],
    'wordtest.submit' => ['en' => 'Submit', 'ru' => 'Отправить'],
    'wordtest.submitting' => ['en' => 'Submitting…', 'ru' => 'Отправка…'],
    'wordtest.score_label' => ['en' => 'Your score', 'ru' => 'Ваш результат'],
    'wordtest.marked' => ['en' => 'Words marked as familiar: :count', 'ru' => 'Слов отмечено как знакомых: :count'],
    'wordtest.new_sample' => ['en' => 'Take a new test', 'ru' => 'Пройти тест заново'],
    'wordtest.error' => ['en' => 'Something went wrong while submitting your answers.', 'ru' => 'Что-то пошло не так при отправке ответов.'],
    'wordtest.no_data' => [
        'en' => 'No frequency data for :language yet — the test needs a ranked word list.',
        'ru' => 'Для языка «:language» пока нет данных частотности — нужен ранжированный список слов.',
    ],
    'wordtest.no_languages' => ['en' => 'No languages are enabled yet.', 'ru' => 'Ни один язык пока не включён.'],
    'wordtest.validation.expired' => ['en' => 'This test has expired — take a new one.', 'ru' => 'Этот тест устарел — пройдите новый.'],
    'wordtest.validation.foreign_word' => ['en' => 'The submitted word does not belong to this test.', 'ru' => 'Отправленное слово не принадлежит этому тесту.'],
];
