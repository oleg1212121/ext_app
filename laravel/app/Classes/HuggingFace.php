<?php

namespace App\Classes;



class HuggingFace extends AiProvider
{
    protected $aiApiLink;
    protected $apiKey;
    protected $models = [
        "MiniMaxAI/MiniMax-M2:novita" => "MiniMaxAI/MiniMax-M2:novita",
        "openai/gpt-oss-safeguard-20b:groq" => "openai/gpt-oss-safeguard-20b:groq",
        "zai-org/GLM-4.6:novita" =>         "zai-org/GLM-4.6:novita",
        "openai/gpt-oss-20b:novita" =>         "openai/gpt-oss-20b:novita",
        "meta-llama/Llama-3.1-8B-Instruct:novita" =>         "meta-llama/Llama-3.1-8B-Instruct:novita",
        "openai/gpt-oss-120b:novita" =>         "openai/gpt-oss-120b:novita",
        "deepseek-ai/DeepSeek-V3.2-Exp:novita" =>         "deepseek-ai/DeepSeek-V3.2-Exp:novita",
        "moonshotai/Kimi-K2-Instruct-0905:novita" =>         "moonshotai/Kimi-K2-Instruct-0905:novita",
        "meta-llama/Meta-Llama-3-8B-Instruct:novita" =>         "meta-llama/Meta-Llama-3-8B-Instruct:novita",
        "deepseek-ai/DeepSeek-R1:novita" =>         "deepseek-ai/DeepSeek-R1:novita",
        "Qwen/Qwen3-Next-80B-A3B-Instruct:novita" =>         "Qwen/Qwen3-Next-80B-A3B-Instruct:novita",
        // "meta-llama/Llama-3.2-1B-Instruct:novita" =>         "meta-llama/Llama-3.2-1B-Instruct:novita",
        "moonshotai/Kimi-K2-Instruct:novita" =>         "moonshotai/Kimi-K2-Instruct:novita",
        "Qwen/Qwen3-Coder-480B-A35B-Instruct:novita" =>         "Qwen/Qwen3-Coder-480B-A35B-Instruct:novita",
        "meta-llama/Llama-3.2-3B-Instruct:novita" =>         "meta-llama/Llama-3.2-3B-Instruct:novita",
        "meta-llama/Llama-3.3-70B-Instruct:novita" =>         "meta-llama/Llama-3.3-70B-Instruct:novita",
        "deepseek-ai/DeepSeek-V3:novita" =>         "deepseek-ai/DeepSeek-V3:novita",
        "zai-org/GLM-4.5:novita" =>         "zai-org/GLM-4.5:novita",
        "zai-org/GLM-4.5-Air:novita" =>         "zai-org/GLM-4.5-Air:novita",
        "Qwen/Qwen3-235B-A22B-Thinking-2507:novita" =>         "Qwen/Qwen3-235B-A22B-Thinking-2507:novita",
        "Qwen/Qwen3-30B-A3B:novita" =>         "Qwen/Qwen3-30B-A3B:novita",
        "deepseek-ai/DeepSeek-R1-0528:novita" =>         "deepseek-ai/DeepSeek-R1-0528:novita",
        "deepseek-ai/DeepSeek-V3.1-Terminus:novita" =>         "deepseek-ai/DeepSeek-V3.1-Terminus:novita",
        "deepseek-ai/DeepSeek-R1-Distill-Qwen-14B:novita" =>         "deepseek-ai/DeepSeek-R1-Distill-Qwen-14B:novita",
        "Qwen/Qwen3-32B:novita" =>         "Qwen/Qwen3-32B:novita",
        "Qwen/Qwen3-235B-A22B:novita" =>         "Qwen/Qwen3-235B-A22B:novita",
        "Qwen/Qwen3-235B-A22B-Instruct-2507:novita" =>         "Qwen/Qwen3-235B-A22B-Instruct-2507:novita",
        "Qwen/Qwen3-Next-80B-A3B-Thinking:novita" =>         "Qwen/Qwen3-Next-80B-A3B-Thinking:novita",
        "meta-llama/Meta-Llama-3-70B-Instruct:novita" =>         "meta-llama/Meta-Llama-3-70B-Instruct:novita",
        "Sao10K/L3-8B-Stheno-v3.2:novita" =>         "Sao10K/L3-8B-Stheno-v3.2:novita",
        "deepseek-ai/DeepSeek-R1-Distill-Qwen-32B:novita" =>         "deepseek-ai/DeepSeek-R1-Distill-Qwen-32B:novita",
        "deepseek-ai/DeepSeek-R1-0528-Qwen3-8B:novita" =>         "deepseek-ai/DeepSeek-R1-0528-Qwen3-8B:novita",
        "MiniMaxAI/MiniMax-M1-80k:novita" =>         "MiniMaxAI/MiniMax-M1-80k:novita",
        "deepseek-ai/DeepSeek-V3.1:novita" =>         "deepseek-ai/DeepSeek-V3.1:novita",
        "Sao10K/L3-70B-Euryale-v2.1:novita" =>         "Sao10K/L3-70B-Euryale-v2.1:novita",
        "deepseek-ai/DeepSeek-R1-Distill-Llama-70B:novita" =>         "deepseek-ai/DeepSeek-R1-Distill-Llama-70B:novita",
        "deepseek-ai/DeepSeek-V3-0324:novita" =>         "deepseek-ai/DeepSeek-V3-0324:novita",
        "zai-org/GLM-4-32B-0414:novita" =>         "zai-org/GLM-4-32B-0414:novita",
        "baidu/ERNIE-4.5-21B-A3B-PT:novita" =>         "baidu/ERNIE-4.5-21B-A3B-PT:novita",
        "baichuan-inc/Baichuan-M2-32B:novita" =>         "baichuan-inc/Baichuan-M2-32B:novita",
        "alpindale/WizardLM-2-8x22B:novita" =>         "alpindale/WizardLM-2-8x22B:novita",
        "NousResearch/Hermes-2-Pro-Llama-3-8B:novita" =>         "NousResearch/Hermes-2-Pro-Llama-3-8B:novita",
        "Sao10K/L3-8B-Lunaris-v1:novita" =>         "Sao10K/L3-8B-Lunaris-v1:novita",
        "Qwen/Qwen2.5-72B-Instruct:novita" =>         "Qwen/Qwen2.5-72B-Instruct:novita",
        "deepseek-ai/DeepSeek-Prover-V2-671B:novita" =>         "deepseek-ai/DeepSeek-Prover-V2-671B:novita",
        "baidu/ERNIE-4.5-300B-A47B-Base-PT:novita" =>         "baidu/ERNIE-4.5-300B-A47B-Base-PT:novita",
        "baidu/ERNIE-4.5-0.3B-PT:novita" =>         "baidu/ERNIE-4.5-0.3B-PT:novita",
    ];
    protected $model = "";

    public function __construct()
    {
        $this->aiApiLink = env('HUGGINGFACE_API_URL', 'https://router.huggingface.co/v1/chat/completions');
        $this->model = env('HUGGINGFACE_MODEL', "deepseek-ai/DeepSeek-V3.1-Terminus:novita");
        $this->apiKey = env('HUGGINFACE_API_KEY');
    }



    public function askForContext($instruction = 'You are a helpful English language assistant.', $question = '', $model = "")
    {
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
            // 'temperature' => 0.2,
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
}
