<?php

namespace App\Support;

use App\Models\Language;
use App\Models\PromptTemplate;

/**
 * The reading surfaces' AI prompt templates, stored admin-editable in
 * prompt_templates and assembled server-side: the simulator's assessment
 * question (format template + user task list) and the word popup's Context
 * explanation instruction. Fallback constants cover an unseeded or blanked
 * row so a missing admin edit never blanks a prompt.
 */
class PromptTemplates
{
    public const FORMAT_KEY = 'simulator.question.format';

    public const TASKS_KEY = 'simulator.question.tasks';

    public const EXPLANATION_KEY = 'word.explanation';

    public const FORMAT_FALLBACK = 'Compare :base original vs. my :learning translation. '
        .'Format rules: use ## headings for each numbered task; quote every exact word or phrase you discuss '
        .'in straight double quotes; in corrections mark removed words as ~~removed~~ and added words as **added**; '
        .'wrap the few most important weak-point phrases in ==double equals==; put improved versions in > blockquotes.';

    public const TASKS_FALLBACK = 'Tasks: 1. Assess meaning accuracy (with percentile) and point out my weak parts. '
        .'2. Assess grammar (with percentile) and point out my weak parts. 3. Fix grammar/improve my version. '
        .'4. Give a couple of improved versions.';

    public const EXPLANATION_FALLBACK = 'You are a dictionary assistant for a language learner. '
        .'Explain the meaning of the word «:word» as it is used in the sentence labelled "Sentence with the word", '
        .'using the neighbouring sentences only as context. Reply in :native. '
        .'Be concise: 2 to 4 sentences. Name the sense that applies here and, when natural, give the closest '
        .':native equivalent word or phrase. Markdown formatting is allowed. Do not repeat the sentences back.';

    public static function format(): string
    {
        return static::row(static::FORMAT_KEY, static::FORMAT_FALLBACK);
    }

    public static function tasks(): string
    {
        return static::row(static::TASKS_KEY, static::TASKS_FALLBACK);
    }

    /**
     * Substitute the placeholders with the display names of the columns'
     * languages (code itself when unknown, blank when absent) and append the
     * task list — the given customization, or the default when none was sent.
     */
    public static function assemble(?string $tasks, ?string $baseCode, ?string $learningCode): string
    {
        $format = str_replace(':base', static::languageName($baseCode), static::format());
        $format = str_replace(':learning', static::languageName($learningCode), $format);

        $tasks = trim((string) $tasks);

        return $format.' '.($tasks !== '' ? $tasks : static::tasks());
    }

    /**
     * The Context explanation's system message: the admin's template with the
     * clicked word's surface and the user's native language name substituted.
     */
    public static function explanation(string $word, string $nativeName): string
    {
        $template = static::row(static::EXPLANATION_KEY, static::EXPLANATION_FALLBACK);

        return str_replace([':word', ':native'], [$word, $nativeName], $template);
    }

    private static function row(string $key, string $fallback): string
    {
        $text = trim((string) PromptTemplate::query()->where('key', $key)->value('text'));

        return $text !== '' ? $text : $fallback;
    }

    private static function languageName(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        return Language::query()->where('code', $code)->value('name') ?? $code;
    }
}
