<?php

namespace App\Classes;

class OpenRouter extends AiProvider
{
    protected array $models = [
        'xiaomi/mimo-v2-flash:free' => 'xiaomi/mimo-v2-flash:free',
        'openai/gpt-oss-20b' => 'openai/gpt-oss-20b',
        'meta-llama/llama-3.1-8b-instruct' => 'meta-llama/llama-3.1-8b-instruct',
        'google/gemini-2.5-flash-lite' => 'google/gemini-2.5-flash-lite',
        'google/gemini-2.0-flash-001' => 'google/gemini-2.0-flash-001',
        'openai/gpt-4o-mini' => 'openai/gpt-4o-mini',
        'x-ai/grok-4.1-fast' => 'x-ai/grok-4.1-fast',
        'x-ai/grok-4-fast' => 'x-ai/grok-4-fast',
        'google/gemini-2.5-flash' => 'google/gemini-2.5-flash',
        'google/gemini-3-flash-preview' => 'google/gemini-3-flash-preview',


    ];

    public function __construct()
    {
        $this->aiApiLink = config('services.openrouter.url', 'https://openrouter.ai/api/v1/chat/completions');
        $this->model = config('services.openrouter.model', 'xiaomi/mimo-v2-flash:free');
        $this->apiKey = config('services.openrouter.key');
    }

    public static function getProviderKey(): string
    {
        return 'openrouter';
    }

    public static function getProviderName(): string
    {
        return 'OpenRouter';
    }

    public function askForContext($instruction = 'You are a helpful English language assistant.', $question = '', $model = ''): ?string
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

        return $this->markdownToHtml($result['choices'][0]['message']['content']);
    }
}
