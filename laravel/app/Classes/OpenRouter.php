<?php

namespace App\Classes;

class OpenRouter extends AiProvider
{
    protected array $models = [
        'nvidia/nemotron-3-nano-30b-a3b:free' => 'NVIDIA: Nemotron 3 Nano 30B A3B (free)',
        'google/gemma-3n-e4b-it' => 'Google: Gemma 3n 4B ($0.02/$0.04)',
        'openai/gpt-oss-20b' => 'OpenAI: gpt-oss-20b ($0.03/$0.11)',
        'sao10k/l3-lunaris-8b' => 'Sao10K: Llama 3 8B Lunaris ($0.04/$0.05)',
        'qwen/qwen-turbo' => 'Qwen: Qwen-Turbo ($0.0325/$0.13)',
        'google/gemma-3-12b-it' => 'Google: Gemma 3 12B ($0.04/$0.13)',
        'openai/gpt-oss-120b' => 'OpenAI: gpt-oss-120b ($0.039/$0.19)',
        'qwen/qwen3.5-9b' => 'Qwen: Qwen3.5-9B ($0.05/$0.15)',
        'qwen/qwen3-235b-a22b-instruct-2507' => 'Qwen: Qwen3 235B A22B Instruct 2507 ($0.071/$0.10)',
        'qwen/qwen3.5-flash-02-23' => 'Qwen: Qwen3.5-Flash ($0.065/$0.26)',
        'google/gemma-3-27b-it' => 'Google: Gemma 3 27B ($0.08/$0.16)',
        'google/gemini-2.0-flash-lite-001' => 'Google: Gemini 2.0 Flash Lite ($0.075/$0.30)',
        'google/gemini-2.5-flash-lite-preview-09-2025' => 'Google: Gemini 2.5 Flash Lite Preview 09-2025 ($0.10/$0.40)',
        'google/gemini-2.5-flash-lite' => 'Google: Gemini 2.5 Flash Lite ($0.10/$0.40)',
        'google/gemini-2.0-flash-001' => 'Google: Gemini 2.0 Flash ($0.10/$0.40)',
        'qwen/qwen3-next-80b-a3b-thinking' => 'Qwen: Qwen3 Next 80B A3B Thinking ($0.0975/$0.78)',
        'openai/gpt-4o-mini' => 'OpenAI: GPT-4o-mini ($0.15/$0.60)',
        'deepseek/deepseek-chat-v3.1' => 'DeepSeek: DeepSeek V3.1 ($0.15/$0.75)',
        'minimax/minimax-m2.5' => 'MiniMax: MiniMax M2.5 ($0.118/$1.25)',
        'x-ai/grok-4.1-fast' => 'xAI: Grok 4.1 Fast ($0.20/$0.50)',
        'x-ai/grok-4-fast' => 'xAI: Grok 4 Fast ($0.20/$0.50)',
        'deepseek/deepseek-chat-v3-0324' => 'DeepSeek: DeepSeek V3 0324 ($0.20/$0.77)',
        'deepseek/deepseek-v3.1-terminus' => 'DeepSeek: DeepSeek V3.1 Terminus ($0.21/$0.79)',
        'deepseek/deepseek-v3.2' => 'DeepSeek: DeepSeek V3.2 ($0.26/$0.38)',
        'openai/gpt-5.4-nano' => 'OpenAI: GPT-5.4 Nano ($0.20/$1.25)',
        'google/gemini-3.1-flash-lite-preview' => 'Google: Gemini 3.1 Flash Lite Preview ($0.25/$1.50)',
        'deepseek/deepseek-chat' => 'DeepSeek: DeepSeek V3 ($0.32/$0.89)',
        'minimax/minimax-m2.7' => 'MiniMax: MiniMax M2.7 ($0.30/$1.20)',
        'openai/gpt-5-mini' => 'OpenAI: GPT-5 Mini ($0.25/$2)',
        'google/gemini-2.5-flash' => 'Google: Gemini 2.5 Flash ($0.30/$2.50)',
        'openai/gpt-4.1-mini' => 'OpenAI: GPT-4.1 Mini ($0.40/$1.60)',
        'moonshotai/kimi-k2.5' => 'MoonshotAI: Kimi K2.5 ($0.3827/$1.909)',
        'google/gemini-3-flash-preview' => 'Google: Gemini 3 Flash Preview ($0.50/$3)',
        'z-ai/glm-5' => 'Z.ai: GLM 5 ($0.72/$2.30)',
        'openai/gpt-5.4-mini' => 'OpenAI: GPT-5.4 Mini ($0.75/$4.50)',
        'anthropic/claude-haiku-4.5' => 'Anthropic: Claude Haiku 4.5 ($1/$5)',
        'openai/o3-mini' => 'OpenAI: o3 Mini ($1.10/$4.40)',
        'z-ai/glm-5-turbo' => 'Z.ai: GLM 5 Turbo ($1.20/$4)',
        'google/gemini-2.5-pro' => 'Google: Gemini 2.5 Pro ($1.25/$10)',
        'openai/gpt-4.1' => 'OpenAI: GPT-4.1 ($2/$8)',
        'openai/gpt-5.2' => 'OpenAI: GPT-5.2 ($1.75/$14)',
        'google/gemini-3.1-pro-preview' => 'Google: Gemini 3.1 Pro Preview ($2/$12)',
        'openai/gpt-4o' => 'OpenAI: GPT-4o ($2.50/$10)',
        'openai/gpt-5.4' => 'OpenAI: GPT-5.4 ($2.50/$15)',
        'anthropic/claude-sonnet-4.6' => 'Anthropic: Claude Sonnet 4.6 ($3/$15)',
        'anthropic/claude-sonnet-4.5' => 'Anthropic: Claude Sonnet 4.5 ($3/$15)',
        'anthropic/claude-opus-4.6' => 'Anthropic: Claude Opus 4.6 ($5/$25)',
    ];

    public function __construct()
    {
        $this->aiApiLink = config('services.openrouter.url', 'https://openrouter.ai/api/v1/chat/completions');
        $this->model = config('services.openrouter.model', 'google/gemini-3-flash-preview');
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

    public function markdownToHtml($text): string
    {
        $parsedown = new \Parsedown;
        $text = preg_replace('/$\\rightarrow$/', '>>>', $text);
        $text = $parsedown->text($text);

        return $text;
    }
}
