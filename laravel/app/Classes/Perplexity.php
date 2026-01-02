<?php

namespace App\Classes;

class Perplexity extends AiProvider
{
    protected array $models = [
        'sonar' => 'sonar',
        'sonar-pro' => 'sonar-pro',
        'sonar-deep-research' => 'sonar-deep-research',
        'sonar-reasoning' => 'sonar-reasoning',
        'sonar-reasoning-pro' => 'sonar-reasoning-pro',
    ];

    public function __construct()
    {
        $this->aiApiLink = config('services.perplexity.url', 'https://api.perplexity.ai/chat/completions');
        $this->model = config('services.perplexity.model', 'sonar');
        $this->apiKey = config('services.perplexity.key');
    }

    public static function getProviderKey(): string
    {
        return 'perplexity';
    }

    public static function getProviderName(): string
    {
        return 'Perplexity';
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
