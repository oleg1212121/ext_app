<?php

// UI strings: interactive word popups (Components/WordText.jsx, Components/WordPopup.jsx).
return [
    'word.loading' => ['en' => 'Loading…', 'ru' => 'Загрузка…', 'be' => 'Загрузка…'],
    // The missing-word card (popup-ai-enrich ticket 03): an unmapped token's
    // popup says honestly that the word is not in the dictionary — nothing is
    // fetched, so the copy must never claim anything is loading or being
    // fetched.
    'word.not_in_dictionary' => ['en' => 'This word isn\'t in the dictionary yet.', 'ru' => 'Этого слова пока нет в словаре.', 'be' => 'Гэтага слова пакуль няма ў слоўніку.'],
    'word.load_failed' => ['en' => 'Couldn\'t load the word (:status)', 'ru' => 'Не удалось загрузить слово (:status)', 'be' => 'Не ўдалося загрузіць слова (:status)'],
    'word.form_of' => ['en' => 'form of', 'ru' => 'форма слова', 'be' => 'форма слова'],
    'word.no_definitions' => ['en' => 'No definitions yet.', 'ru' => 'Определений пока нет.', 'be' => 'Вызначэнняў пакуль няма.'],
    'word.examples' => ['en' => 'Examples (:count)', 'ru' => 'Примеры (:count)', 'be' => 'Прыклады (:count)'],
    'word.i_know_this' => ['en' => 'I know this word', 'ru' => 'Я знаю это слово', 'be' => 'Я ведаю гэта слова'],
    'word.remove_mark' => ['en' => 'Remove mark', 'ru' => 'Снять отметку', 'be' => 'Зняць пазначэнне'],
    'word.familiarity' => ['en' => 'Familiarity: :value/:max', 'ru' => 'Знакомость: :value/:max', 'be' => 'Знаёмасць: :value/:max'],
    'word.frequency' => ['en' => 'Frequency: #:rank', 'ru' => 'Частотность: №:rank', 'be' => 'Частотнасць: №:rank'],
    'word.etymology' => ['en' => 'Etymology (:count)', 'ru' => 'Этимология (:count)', 'be' => 'Этымалогія (:count)'],
    'word.more' => ['en' => '+:count more…', 'ru' => 'ещё :count…', 'be' => 'яшчэ :count…'],
    // The toolbar's on-demand Explanation toggle (popup-ai-enrich ticket 01):
    // the old "Dictionary"/"Explanation" tab titles are retired with the tab
    // strip — the dictionary content always renders now.
    'word.explain_toggle' => ['en' => 'Explanation', 'ru' => 'Разбор', 'be' => 'Разбор'],
    // The Ask-AI toolbar icon (popup-ai-enrich ticket 04, re-ask per the
    // ADR 0082 amendment): the live enrichment's label states plus the
    // failed-attempt copy — a word the AI already enriched once shows the
    // re-ask variant whose hint says this is not the first ask, a request
    // in flight reads "sending", failures show beneath the toolbar with the
    // server's guidance verbatim.
    'word.ask_ai' => ['en' => 'Ask AI', 'ru' => 'Спросить ИИ', 'be' => 'Спытаць ШІ'],
    'word.ask_ai_again' => ['en' => 'Ask AI again (already enriched)', 'ru' => 'Спросить ИИ ещё раз (уже дополнено)', 'be' => 'Спытаць ШІ яшчэ раз (ужо дапоўнена)'],
    'word.ask_ai_sending' => ['en' => 'Asking AI…', 'ru' => 'Спрашиваем ИИ…', 'be' => 'Спытваем ШІ…'],
    'word.ask_ai_failed' => ['en' => 'Ask AI failed (:status)', 'ru' => 'Не удалось спросить ИИ (:status)', 'be' => 'Не ўдалося спытаць ШІ (:status)'],
    // The ask error state's Retry control (the explain pane's error state
    // has one — the four-state contract words the error treatment
    // strictly): re-sends the ask through the same guarded callback.
    'word.ask_ai_retry' => ['en' => 'Retry', 'ru' => 'Повторить', 'be' => 'Паўтарыць'],
    'word.explain_loading' => ['en' => 'Explaining…', 'ru' => 'Объясняю…', 'be' => 'Тлумачу…'],
    'word.explain_failed' => ['en' => 'Explanation failed (:status)', 'ru' => 'Не удалось объяснить (:status)', 'be' => 'Не ўдалося патлумачыць (:status)'],
    'word.explain_retry' => ['en' => 'Retry', 'ru' => 'Повторить', 'be' => 'Паўтарыць'],
    'word.explain_again' => ['en' => 'Ask again', 'ru' => 'Спросить ещё раз', 'be' => 'Спытаць яшчэ раз'],
    'word.explain_choose_model' => ['en' => 'Choose an AI model in your Profile to get word explanations.', 'ru' => 'Выберите ИИ-модель в профиле, чтобы получать объяснения слов.', 'be' => 'Выберыце ШІ-мадэль у профілі, каб атрымліваць тлумачэнні слоў.'],
    'word.explain_no_key' => ['en' => 'Add an API key in your Profile to get word explanations.', 'ru' => 'Добавьте API-ключ в профиле, чтобы получать объяснения слов.', 'be' => 'Дадайце API-ключ у профілі, каб атрымліваць тлумачэнні слоў.'],
    'word.explain_go_to_settings' => ['en' => 'Go to Profile → AI Models', 'ru' => 'Перейти в профиль → ИИ-модели', 'be' => 'Перайсці ў профіль → ШІ-мадэлі'],
    'word.explain_preview' => ['en' => 'This is where the **context explanation** appears — an AI answer about what this word means in its sentence. Try the real thing in the reader or the simulator.', 'ru' => 'Здесь появляется **контекстный разбор** — ответ ИИ о том, что это слово значит в своём предложении. Попробуйте настоящий разбор в читалке или симуляторе.', 'be' => 'Тут з\'яўляецца **кантэкстны разбор** — адказ ШІ пра тое, што гэта слова значыць у сваім сказе. Паспрабуйце сапраўдны разбор у чыталцы або сімулятары.'],
    'word.definitions' => ['en' => 'Definitions (:count)', 'ru' => 'Определения (:count)', 'be' => 'Вызначэнні (:count)'],
    // The usage-label chips (popup-usage-labels ticket 01): the closed six
    // render before their sense text — the dimming tier (obsolete, archaic,
    // rare, dialectal) dims the line, dated/colloquial stay a muted chip.
    // ru/be use the dictionary abbreviations.
    'word.label_obsolete' => ['en' => 'obsolete', 'ru' => 'устар.', 'be' => 'устар.'],
    'word.label_archaic' => ['en' => 'archaic', 'ru' => 'арх.', 'be' => 'арх.'],
    'word.label_dated' => ['en' => 'dated', 'ru' => 'не совр.', 'be' => 'не суч.'],
    'word.label_colloquial' => ['en' => 'colloquial', 'ru' => 'разг.', 'be' => 'разг.'],
    'word.label_rare' => ['en' => 'rare', 'ru' => 'редк.', 'be' => 'рэдк.'],
    'word.label_dialectal' => ['en' => 'dialectal', 'ru' => 'диал.', 'be' => 'дыял.'],
    'word.no_translations' => ['en' => 'No translations yet — automatic translation may still be in progress.', 'ru' => 'Переводов пока нет — автоматический перевод ещё может выполняться.', 'be' => 'Перакладаў пакуль няма — аўтаматычны пераклад яшчэ можа выконвацца.'],
    // The staged raw candidates line (popup-provenance ticket 02, ADR 0085):
    // the tiny muted label prefixing the word's unlinked raw translation
    // strings — the amber line under the linked green translations. The calm
    // placeholder above shows only when linked AND staged are both empty.
    'word.raw_translations' => ['en' => 'raw:', 'ru' => 'сырые:', 'be' => 'сырыя:'],
    'word.anki_send' => ['en' => 'Save to Anki', 'ru' => 'Сохранить в Anki', 'be' => 'Захаваць у Anki'],
    'word.anki_update' => ['en' => 'Update in Anki', 'ru' => 'Обновить в Anki', 'be' => 'Абнавіць у Anki'],
    'word.anki_sending' => ['en' => 'Saving…', 'ru' => 'Сохраняем…', 'be' => 'Захоўваем…'],
    'word.anki_added' => ['en' => 'Added to Anki.', 'ru' => 'Добавлено в Anki.', 'be' => 'Дададзена ў Anki.'],
    'word.anki_updated' => ['en' => 'Updated in Anki.', 'ru' => 'Обновлено в Anki.', 'be' => 'Абноўлена ў Anki.'],
    // Only the foreign-model duplicate still lands here (anki-popup-refresh):
    // a note of the app's own types updates in place and reports
    // word.anki_updated instead.
    'word.anki_already' => ['en' => 'Already in Anki.', 'ru' => 'Уже в Anki.', 'be' => 'Ужо ў Anki.'],
    'word.anki_unreachable' => ['en' => 'Can\'t reach Anki — make sure desktop Anki with AnkiConnect is running.', 'ru' => 'Нет связи с Anki — убедитесь, что запущен настольный Anki с AnkiConnect.', 'be' => 'Няма сувязі з Anki — пераканайцеся, што запушчаны настольны Anki з AnkiConnect.'],
    // The quiet-probe fourth state (2026-10-24): the page session has NO
    // successful Anki answer on record (fresh reload, Anki never up), so
    // opens stopped probing — the endpoint is asked only when the reader
    // engages the icon. The click IS the check: the send's find-before-add
    // talks to AnkiConnect for real.
    'word.anki_unknown' => ['en' => 'Haven\'t heard from Anki this session — click to try saving (checks :endpoint).', 'ru' => 'Anki пока не отвечала в этой сессии — нажмите, чтобы попробовать сохранить (проверка: :endpoint).', 'be' => 'Anki пакуль не адказвала ў гэтай сесіі — націсніце, каб паспрабаваць захаваць (праверка: :endpoint).'],
    // :model carries the note type the mismatch was raised against — with
    // per-language types ("ExtApp Vocabulary ⋅ <code>") any of them can
    // mismatch, so the copy can no longer hardcode the base name.
    'word.anki_mismatch' => ['en' => 'The ":model" note type in Anki is out of date. In Anki, open Tools → Manage Note Types and delete ":model" (its notes are deleted with it), then sync again from Profile → Anki and send your words again.', 'ru' => 'Тип заметок «:model» в Anki устарел. В Anki откройте «Инструменты → Управление типами заметок» и удалите «:model» (его заметки удалятся вместе с ним), затем снова синхронизируйте профиль → Anki и отправьте слова заново.', 'be' => 'Тып нататак «:model» у Anki састарэў. У Anki адкрыйце «Інструменты → Кіраванне тыпамі нататак» і выдаліце «:model» (яго нататкі выдаляцца разам з ім), затым зноў сінхранізуйце профіль → Anki і адпраўце словы яшчэ раз.'],
    'word.anki_failed' => ['en' => 'Anki error: :message', 'ru' => 'Ошибка Anki: :message', 'be' => 'Памылка Anki: :message'],
    'word.anki_go_to_settings' => ['en' => 'Go to Profile → Anki', 'ru' => 'Перейти в профиль → Anki', 'be' => 'Перайсці ў профіль → Anki'],
];
