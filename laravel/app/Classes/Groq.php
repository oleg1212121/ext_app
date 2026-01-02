<?php

namespace App\Classes;

class Groq extends AiProvider
{
    protected array $models = [
        'llama-3.3-70b-versatile' => 'llama-3.3-70b-versatile',
        'llama-3.1-70b-versatile' => 'llama-3.1-70b-versatile',
        'llama-3.1-8b-instruct' => 'llama-3.1-8b-instruct',
        'mixtral-8x7b-32768' => 'mixtral-8x7b-32768',
        'gemma-7b-it' => 'gemma-7b-it',
        'llama-3.2-90b-vision' => 'llama-3.2-90b-vision',
        'llama-3.2-11b-vision' => 'llama-3.2-11b-vision',
        'llama-3.2-1b-preview' => 'llama-3.2-1b-preview',
        'llama-3.2-90b-vision-preview' => 'llama-3.2-90b-vision-preview',
        'llama-3.1-405b-reasoning' => 'llama-3.1-405b-reasoning',
        'llama-3-8b-8192' => 'llama-3-8b-8192',
        'llama-3-70b-8192' => 'llama-3-70b-8192',
    ];

    public function __construct()
    {
        $this->aiApiLink = config('services.groq.url', 'https://api.groq.com/openai/v1/chat/completions');
        $this->model = config('services.groq.model', 'llama-3.3-70b-versatile');
        $this->apiKey = config('services.groq.key');
    }

    public static function getProviderKey(): string
    {
        return 'groq';
    }

    public static function getProviderName(): string
    {
        return 'Groq';
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
                'Authorization: Bearer '.$this->apiKey,
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

        if (! isset($result['choices'][0]['message']['content'])) {
            return 'Error in response: '.print_r($result, true);
        }

        return $this->markdownToHtml($result['choices'][0]['message']['content']);
    }
}
