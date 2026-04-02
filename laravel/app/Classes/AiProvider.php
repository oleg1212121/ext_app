<?php

namespace App\Classes;

use App\Contracts\AiProviderInterface;

abstract class AiProvider implements AiProviderInterface
{
    protected array $models = [];

    protected ?string $apiKey = null;

    protected string $aiApiLink = '';

    protected string $model = '';

    abstract public static function getProviderKey(): string;

    abstract public static function getProviderName(): string;

    public function getModels(): array
    {
        return $this->models;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function markdownToHtml($text)
    {
        if (empty($text)) {
            return '';
        }

        // First, handle code blocks (before escaping, as they contain code)
        // Match code blocks with language identifier
        $text = preg_replace_callback('/```(\w+)?\n(.*?)```/s', function ($matches) {
            $lang = ! empty($matches[1]) ? ' class="language-'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'"' : '';
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');

            return '<pre><code'.$lang.'>'.$code.'</code></pre>';
        }, $text);

        // Match code blocks without language identifier
        $text = preg_replace_callback('/```\n(.*?)```/s', function ($matches) {
            $code = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');

            return '<pre><code>'.$code.'</code></pre>';
        }, $text);

        // Now escape HTML for the rest of the content
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Convert headers (must be on their own line)
        $html = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $html);

        // Convert bold (**text**)
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html);

        // Convert italic (*text* or _text_) - but not if it's part of bold
        $html = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $html);
        $html = preg_replace('/_(.+?)_/', '<em>$1</em>', $html);

        // Convert inline code (`code`)
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Convert $\rightarrow$ (`code`)
        $html = preg_replace('/\$\\rightarrow\$/', '=>', $html);

        // Convert unordered lists (* item or - item)
        // First, wrap consecutive list items
        $html = preg_replace_callback('/(?:^[\*\-\+] .*(?:\n|$))+/m', function ($matches) {
            $listContent = $matches[0];
            // Remove the list markers and wrap in <li>
            $listContent = preg_replace('/^[\*\-\+] (.+)$/m', '<li>$1</li>', $listContent);

            return '<ul>'.$listContent.'</ul>';
        }, $html);

        // Convert ordered lists (1. item)
        $html = preg_replace_callback('/(?:^\d+\. .*(?:\n|$))+/m', function ($matches) {
            $listContent = $matches[0];
            $listContent = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $listContent);

            return '<ol>'.$listContent.'</ol>';
        }, $html);

        // Convert line breaks (double newline = paragraph, single = <br>)
        // First, split by double newlines for paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $html);
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }
            // Skip if already wrapped in a block element
            if (preg_match('/^<(h[1-6]|ul|ol|pre|p)/', $paragraph)) {
                $html .= $paragraph."\n";
            } else {
                // Convert single newlines to <br> within paragraphs
                $paragraph = preg_replace('/\n/', '<br>', $paragraph);
                $html .= '<p>'.$paragraph.'</p>'."\n";
            }
        }

        // Clean up any extra whitespace
        $html = trim($html);

        return $html;
    }

    protected function resolveModel($model = '')
    {
        if ($model && isset($this->models[$model])) {
            return $model;
        }

        return $this->model;
    }
}
