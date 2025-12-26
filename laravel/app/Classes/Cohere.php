<?php

namespace App\Classes;



class Cohere extends AiProvider
{

    public function __construct()
    {
        $this->aiApiLink = env('COHERE_API_URL', 'https://api.cohere.ai/v1/chat');
        $this->model = env('COHERE_MODEL', 'command-a-03-2025');
        $this->apiKey = env('COHERE_API_KEY');
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
            'temperature' => 0.2,
            // 'max_tokens' => 512,
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

        if (!isset($result['message']['content'])) {
            return "Error in response: " . print_r($result, true);
        }

        $result = $this->markdownToHtml($result['message']['content']);
        return $result;
    }

}
