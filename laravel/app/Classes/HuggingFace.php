<?php

namespace App\Classes;

use App\Models\AiModel;

class HuggingFace extends AiProvider
{
    public function __construct()
    {
        $this->aiApiLink = config('services.huggingface.url', 'https://router.huggingface.co/v1/chat/completions');
        $this->model = config('services.huggingface.model', 'deepseek-ai/DeepSeek-V3.1-Terminus:novita');
        $this->apiKey = config('services.huggingface.key');

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
        return 'huggingface';
    }

    public static function getProviderName(): string
    {
        return 'HuggingFace';
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
}
