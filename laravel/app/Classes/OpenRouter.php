<?php

namespace App\Classes;

use App\Models\AiModel;

class OpenRouter extends AiProvider
{
    public function __construct()
    {
        $this->aiApiLink = config('services.openrouter.url', 'https://openrouter.ai/api/v1/chat/completions');
        $this->model = config('services.openrouter.model', 'google/gemini-3.1-flash-lite-preview');
        $this->apiKey = config('services.openrouter.key');

        $this->models = AiModel::query()
            ->forProvider(static::getProviderKey())
            ->enabled()
            ->unexpired()
            ->get()
            ->mapWithKeys(fn (AiModel $model): array => [
                $model->external_id => $model->displayLabel(),
            ])
            ->all();
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

        if (! isset($result['choices'][0]['message']['content'])) {
            $this->throwMalformedResponse($response);
        }

        return $this->markdownToHtml($result['choices'][0]['message']['content']);
    }

    public function markdownToHtml($text): string
    {
        $parsedown = new \Parsedown;
        $text = $this->normalizeArrowNotation($text);
        $text = $parsedown->text($text);

        return $text;
    }
}
