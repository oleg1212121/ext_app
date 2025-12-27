<?php

namespace App\Classes;

class OpenRouter extends AiProvider
{
    protected $model;

    // protected $model = "gemini-2.5-flash";
    protected $models = [
        'minimax/minimax-m2:free' => 'minimax/minimax-m2:free',
        'tngtech/deepseek-r1t2-chimera:free' => 'tngtech/deepseek-r1t2-chimera:free',
        'z-ai/glm-4.5-air:free' => 'z-ai/glm-4.5-air:free',
        'tngtech/deepseek-r1t-chimera:free' => 'tngtech/deepseek-r1t-chimera:free',
        'deepseek/deepseek-chat-v3-0324:free' => 'deepseek/deepseek-chat-v3-0324:free',
        'deepseek/deepseek-r1-0528:free' => 'deepseek/deepseek-r1-0528:free',
        'qwen/qwen3-235b-a22b:free' => 'qwen/qwen3-235b-a22b:free',
        'qwen/qwen3-coder:free' => 'qwen/qwen3-coder:free',
        'google/gemini-2.0-flash-exp:free' => 'google/gemini-2.0-flash-exp:free',
        'meituan/longcat-flash-chat:free' => 'meituan/longcat-flash-chat:free',
        'meta-llama/llama-3.3-70b-instruct:free' => 'meta-llama/llama-3.3-70b-instruct:free',
        'microsoft/mai-ds-r1:free' => 'microsoft/mai-ds-r1:free',
        'openai/gpt-oss-20b:free' => 'openai/gpt-oss-20b:free',
        'deepseek/deepseek-r1:free' => 'deepseek/deepseek-r1:free',
        'google/gemma-3-27b-it:free' => 'google/gemma-3-27b-it:free',
        'nvidia/nemotron-nano-12b-v2-vl:free' => 'nvidia/nemotron-nano-12b-v2-vl:free',
        'meta-llama/llama-4-maverick:free' => 'meta-llama/llama-4-maverick:free',
        'deepseek/deepseek-r1-distill-llama-70b:free' => 'deepseek/deepseek-r1-distill-llama-70b:free',
        'deepseek/deepseek-chat-v3.1:free' => 'deepseek/deepseek-chat-v3.1:free',
        'cognitivecomputations/dolphin-mistral-24b-venice-edition:free' => 'cognitivecomputations/dolphin-mistral-24b-venice-edition:free',
        'deepseek/deepseek-r1-0528-qwen3-8b:free' => 'deepseek/deepseek-r1-0528-qwen3-8b:free',
        'mistralai/mistral-nemo:free' => 'mistralai/mistral-nemo:free',
        'mistralai/mistral-small-3.2-24b-instruct:free' => 'mistralai/mistral-small-3.2-24b-instruct:free',
        'mistralai/mistral-small-3.1-24b-instruct:free' => 'mistralai/mistral-small-3.1-24b-instruct:free',
        'mistralai/mistral-small-24b-instruct-2501:free' => 'mistralai/mistral-small-24b-instruct-2501:free',
        'mistralai/mistral-7b-instruct:free' => 'mistralai/mistral-7b-instruct:free',
        'mistralai/mistral-large-2407:free' => 'mistralai/mistral-large-2407:free',
        'mistralai/pixtral-12b-2409:free' => 'mistralai/pixtral-12b-2409:free',
        'alibaba/tongyi-deepresearch-30b-a3b:free' => 'alibaba/tongyi-deepresearch-30b-a3b:free',
        'qwen/qwen3-14b:free' => 'qwen/qwen3-14b:free',
        'qwen/qwen3-30b-a3b:free' => 'qwen/qwen3-30b-a3b:free',
        'nousresearch/hermes-3-llama-3.1-405b:free' => 'nousresearch/hermes-3-llama-3.1-405b:free',
        'nvidia/nemotron-nano-9b-v2:free' => 'nvidia/nemotron-nano-9b-v2:free',
        'meta-llama/llama-3.3-8b-instruct:free' => 'meta-llama/llama-3.3-8b-instruct:free',
        'meta-llama/llama-4-scout:free' => 'meta-llama/llama-4-scout:free',
        'qwen/qwen2.5-vl-32b-instruct:free' => 'qwen/qwen2.5-vl-32b-instruct:free',
        'qwen/qwen-2.5-72b-instruct:free' => 'qwen/qwen-2.5-72b-instruct:free',
        'qwen/qwen-2.5-coder-32b-instruct:free' => 'qwen/qwen-2.5-coder-32b-instruct:free',
        'moonshotai/kimi-k2:free' => 'moonshotai/kimi-k2:free',
        'qwen/qwen3-4b:free' => 'qwen/qwen3-4b:free',
        'meta-llama/llama-3.2-3b-instruct:free' => 'meta-llama/llama-3.2-3b-instruct:free',
        'google/gemma-3-4b-it:free' => 'google/gemma-3-4b-it:free',
        'arliai/qwq-32b-arliai-rpr-v1:free' => 'arliai/qwq-32b-arliai-rpr-v1:free',
        'google/gemma-3n-e2b-it:free' => 'google/gemma-3n-e2b-it:free',
        'google/gemma-3-12b-it:free' => 'google/gemma-3-12b-it:free',
        'google/gemma-3n-e4b-it:free' => 'google/gemma-3n-e4b-it:free',
        'agentica-org/deepcoder-14b-preview:free' => 'agentica-org/deepcoder-14b-preview:free',
    ];

    protected $apiKey;

    protected $aiApiLink;

    public function __construct()
    {
        $this->aiApiLink = env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
        $this->model = env('OPENROUTER_MODEL', 'xiaomi/mimo-v2-flash:free');
        $this->apiKey = env('OPENROUTER_API_KEY');
    }

    public function askForContext($instruction = 'You are a helpful English language assistant.', $question = '', $model = 'nvidia/nemotron-nano-12b-v2-vl:free')
    {
        $model = $this->resolveModel($model);
        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $instruction,
                ],
                [
                    'role' => 'user',
                    'content' => $question,
                ],
            ],
            'temperature' => 0.2,
            // 'max_tokens' => 512,
            'stream' => false,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->aiApiLink,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return "Error: HTTP $httpCode - $response";
        }

        $result = json_decode($response, true);

        if (!isset($result['choices'][0]['message']['content'])) {
            return 'Error in response: ' . print_r($result, true);
        }

        $result = $this->markdownToHtml($result['choices'][0]['message']['content']);

        return $result;
    }
}
