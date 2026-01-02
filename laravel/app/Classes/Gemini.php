<?php

namespace App\Classes;

class Gemini extends AiProvider
{
    protected array $models = [
        'gemini-2.5-flash-lite' => 'gemini-2.5-flash-lite',
        'gemini-2.5-flash' => 'gemini-2.5-flash',
        'gemini-2.5-flash-preview-09-2025' => 'gemini-2.5-flash-preview-09-2025',
        'gemini-2.5-flash-lite-preview-09-2025' => 'gemini-2.5-flash-lite-preview-09-2025',
    ];

    protected $proxyLogin;

    protected $proxyPassword;

    protected $proxyIP;

    protected $proxyPort;

    protected $modelGoal = ':generateContent';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->aiApiLink = config('services.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/models/');
        $this->model = config('services.gemini.model', 'gemini-2.5-flash-lite');
        $this->proxyLogin = config('services.proxy.login');
        $this->proxyPassword = config('services.proxy.password');
        $this->proxyIP = config('services.proxy.ip');
        $this->proxyPort = config('services.proxy.port');
    }

    public static function getProviderKey(): string
    {
        return 'gemini';
    }

    public static function getProviderName(): string
    {
        return 'Gemini';
    }

    public function ask($word = '', $model = '')
    {
        if (! $word) {
            return null;
        }

        $model = $this->resolveModel($model);
        $question = "Give me a list of definitions of a word '{$word}'. No addition information. Each definition at a new line.";

        $data = [
            'contents' => [
                'parts' => [
                    ['text' => $question],
                ],
            ],
            'generationConfig' => [
                'thinkingConfig' => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        $url = $this->buildAiUrl($model);
        $headers = $this->buildAiHeaders();
        $proxy = $this->buildProxyUrl();

        $answer = $this->request($url, $data, $headers, $proxy);

        if (isset($answer['candidates'][0]['content']['parts'][0]['text'])) {
            return $answer['candidates'][0]['content']['parts'][0]['text'];
        } else {
            return 'Error in response: '.print_r($answer, true);
        }
    }

    public function askForContext($instruction = 'You are a helpful English language assistant.', $question = '', $model = ''): ?string
    {
        if (! $question || ! $instruction) {
            return null;
        }

        $model = $this->resolveModel($model);

        $data = [
            'system_instruction' => [
                'parts' => [
                    [
                        'text' => $instruction,
                    ],
                ],
            ],
            'contents' => [
                'parts' => [
                    ['text' => $question],
                ],
            ],
            'generationConfig' => [
                'thinkingConfig' => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        $url = $this->buildAiUrl($model);
        $headers = $this->buildAiHeaders();
        $proxy = $this->buildProxyUrl();

        $answer = $this->request($url, $data, $headers, $proxy);

        if (isset($answer['candidates'][0]['content']['parts'][0]['text'])) {
            $res = $answer['candidates'][0]['content']['parts'][0]['text'];
            $res = $this->markdownToHtml($res);
        } else {
            $res = 'Error in response: '.print_r($answer, true);
        }

        return $res;
    }

    private function buildAiUrl($model = null)
    {
        $modelToUse = $model ?? $this->model;

        return $this->aiApiLink.$modelToUse.$this->modelGoal;
    }

    private function buildProxyUrl()
    {
        return "http://{$this->proxyLogin}:{$this->proxyPassword}@{$this->proxyIP}:{$this->proxyPort}";
    }

    private function buildAiHeaders()
    {
        return [
            'Content-Type: application/json',
            "x-goog-api-key: {$this->apiKey}",
        ];
    }

    private function request($url, $data, $headers, $proxy)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['error' => "HTTP $httpCode - $response"];
        }

        return json_decode($response, true);
    }
}
