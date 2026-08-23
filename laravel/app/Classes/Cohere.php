<?php

namespace App\Classes;

class Cohere extends AiProvider
{
    public function __construct()
    {
        $this->aiApiLink = config('services.cohere.url', 'https://api.cohere.ai/v1/chat');
        $this->model = config('services.cohere.model', 'command-a-03-2025');
        $this->apiKey = config('services.cohere.key');

        $this->loadModels();
    }

    public static function getProviderKey(): string
    {
        return 'cohere';
    }

    public static function getProviderName(): string
    {
        return 'Cohere';
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
            $this->throwHttpError($httpCode, $response);
        }

        $result = json_decode($response, true);

        if (! isset($result['message']['content'])) {
            $this->throwMalformedResponse($response);
        }

        return $this->markdownToHtml($result['message']['content']);
    }

    public function askForContextStreamed($instruction = 'You are a helpful English language assistant.', $question = '', $model = '', ?callable $onChunk = null): void
    {
        if ($onChunk === null) {
            return;
        }

        $model = $this->resolveModel($model);

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instruction],
                ['role' => 'user', 'content' => $question],
            ],
            'temperature' => 0.2,
        ];

        $this->streamOpenAiCompatible($this->aiApiLink, $data, [
            'Authorization: Bearer '.$this->apiKey,
            'Content-Type: application/json',
        ], $onChunk);
    }
}
