<?php

namespace App\Classes;



class OpenRouter
{
    private $model;
    // private $model = "gemini-2.5-flash";
    private $models = [
        "minimax/minimax-m2:free",
        "tngtech/deepseek-r1t2-chimera:free",
        "z-ai/glm-4.5-air:free",
        "tngtech/deepseek-r1t-chimera:free",
        "deepseek/deepseek-chat-v3-0324:free",
        "deepseek/deepseek-r1-0528:free",
        "qwen/qwen3-235b-a22b:free",
        "qwen/qwen3-coder:free",
        "google/gemini-2.0-flash-exp:free",
        "meituan/longcat-flash-chat:free",
        "meta-llama/llama-3.3-70b-instruct:free",
        "microsoft/mai-ds-r1:free",
        "openai/gpt-oss-20b:free",
        "deepseek/deepseek-r1:free",
        "google/gemma-3-27b-it:free",
        "nvidia/nemotron-nano-12b-v2-vl:free",
        "meta-llama/llama-4-maverick:free",
        "deepseek/deepseek-r1-distill-llama-70b:free",
        "deepseek/deepseek-chat-v3.1:free",
        "cognitivecomputations/dolphin-mistral-24b-venice-edition:free",
        "deepseek/deepseek-r1-0528-qwen3-8b:free",
        "mistralai/mistral-nemo:free",
        "alibaba/tongyi-deepresearch-30b-a3b:free",
        "mistralai/mistral-small-3.1-24b-instruct:free",
        "mistralai/mistral-small-3.2-24b-instruct:free",
        "qwen/qwen3-14b:free",
        "mistralai/mistral-7b-instruct:free",
        "qwen/qwen3-30b-a3b:free",
        "nousresearch/hermes-3-llama-3.1-405b:free",
        "nvidia/nemotron-nano-9b-v2:free",
        "meta-llama/llama-3.3-8b-instruct:free",
        "meta-llama/llama-4-scout:free",
        "qwen/qwen2.5-vl-32b-instruct:free",
        "qwen/qwen-2.5-72b-instruct:free",
        "qwen/qwen-2.5-coder-32b-instruct:free",
        "moonshotai/kimi-k2:free",
        "mistralai/mistral-small-24b-instruct-2501:free",
        "qwen/qwen3-4b:free",
        "meta-llama/llama-3.2-3b-instruct:free",
        "google/gemma-3-4b-it:free",
        "arliai/qwq-32b-arliai-rpr-v1:free",
        "google/gemma-3n-e2b-it:free",
        "google/gemma-3-12b-it:free",
        "google/gemma-3n-e4b-it:free",
        "agentica-org/deepcoder-14b-preview:free" 
    ];
    private $apiKey;
    private $aiApiLink;

    public function __construct()
    {        
        $this->aiApiLink = env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
        $this->model = env('OPENROUTER_MODEL', 'deepseek/deepseek-r1-0528-qwen3-8b:free');
        $this->apiKey = env('OPENROUTER_API_KEY');
    }
    
    
    
    public function askForContext($instruction='You are a helpful English language assistant.', $question='', $model="nvidia/nemotron-nano-12b-v2-vl:free") {
        $model = $this->resolveModel($model);
        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $instruction
                ],
                [
                    'role' => 'user',
                    'content' => $question
                ]
            ],
            'temperature' => 0.2,
            // 'max_tokens' => 512,
            'stream' => false
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->aiApiLink,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return "Error: HTTP $httpCode - $response";
        }
        
        $result = json_decode($response, true);
        // $result = $result['choices'][0]['message']['content'];
        $result = $this->markdownToHtml($result['choices'][0]['message']['content']);
        return $result;
    }

    protected function formatting($answer)
    {
        $answer = strip_tags((string)$answer);

        // Normalize arrows (order matters)
        $answer = preg_replace('/\=\>/', '— ', $answer);
        $answer = preg_replace('/>/', '— ', $answer);

        // Escape HTML (keep quotes so we can match them)
        $answer = htmlspecialchars($answer, ENT_NOQUOTES, 'UTF-8');

        // Quote emphasis first to avoid matching attributes later
        $answer = preg_replace('/"([^"\n]{1,200})"/', '<em class="cursor-pointer hover:bg-orange-50">"$1"</em>', $answer);

        // List bullets
        $answer = preg_replace('/^\s*[\-\*]\s+/m', '— ', $answer);

        // Inline code
        $answer = preg_replace('/`([^`]+)`/', "<b class='comma cursor-pointer hover:bg-orange-50'>$1</b>", $answer);

        // Bold and single-star with safer patterns
        $answer = preg_replace('/(?<!\*)\*\*(.+?)\*\*(?!\*)/s', "<b class='ssss cursor-pointer hover:bg-orange-50'>$1</b>", $answer);
        $answer = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', "<b class='star'>$1</b>", $answer);

        // Markdown headers
        $answer = preg_replace('/^#{1,3}\s*(.+)$/m', "<b class='reshetka cursor-pointer hover:bg-orange-50'>$1</b>", $answer);

        // Cleanup and line breaks
        $answer = str_replace('---', '', $answer);
        // $answer = str_replace(array("\r\n", "\r", "\n"), "<br /><hr />", $answer);
        $answer = nl2br($answer);
        return $answer;
    }

    function markdownToHtml($text) {
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

    protected function resolveModel($model=''){
        if(isset($this->models[$model])){
            $this->model = $this->models[$model];
        }
        return $this->model;
    }
}