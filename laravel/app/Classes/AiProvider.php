<?php

namespace App\Classes;

class AiProvider
{

    protected $models = [
        "command-a-03-2025" => "command-a-03-2025",
        "command-r-plus-08-2024" => "command-r-plus-08-2024",
        "command-r-08-2024" => "command-r-08-2024",
        "command-r7b-12-2024" => "command-r7b-12-2024",
        "command" => "command",
        "command-light" => "command-light",
        "command-nightly" => "command-nightly",
        "command-light-nightly" => "command-light-nightly",
    ];
    protected $apiKey;
    protected $aiApiLink;
    protected $model;

    function markdownToHtml($text)
    {
        // Escape HTML first
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Convert headers
        $html = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $html);

        // Convert bold and italic
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<b>$1</b>', $html);
        $html = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $html);

        // Convert inline code
        $html = preg_replace('/`(.*?)`/s', '<code>$1</code>', $html);

        // Convert code blocks
        $html = preg_replace('/```(\w+)?\n(.*?)\n```/s', '<pre><code class="language-$1">$2</code></pre>', $html, 1);
        $html = preg_replace('/```\n(.*?)\n```/s', '<pre><code>$1</code></pre>', $html);

        // Convert lists
        $html = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<\/li>\n<li>)/s', '</li><li>', $html);
        $html = preg_replace('(<li>(.*)</li>)', '<ul><li>$1</li></ul>', $html);

        // Convert line breaks
        $html = str_replace("\n", '<br>', $html);

        return $html;
    }

    protected function resolveModel($model = '')
    {
        if ($model && isset($this->models[$model])) {
            return $this->models[$model];
        }
        return $this->model;
    }
}
