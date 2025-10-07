<?php

namespace App\Classes;



class Gemini
{
    private $model;
    // private $model = "gemini-2.5-flash";
    private $models = [
        "gemini-2.5-flash-lite",
        "gemini-2.5-flash",
        "gemini-2.5-flash-preview-09-2025",
        "gemini-2.5-flash-lite-preview-09-2025",
    ];
    private $proxyLogin;
    private $proxyPassword;
    private $proxyIP;
    private $proxyPort;
    private $apiKey;
    private $aiApiLink;
    private $modelGoal = ":generateContent";
    private $payload = [
        "system_instruction" => [
            "parts" => [
                [
                    "text" => ""
                ]
            ]
        ],
        'contents' => [
            'parts' => [
                ['text' => ""]
            ]
        ],
        "generationConfig" => [
            "thinkingConfig" => [
                "thinkingBudget" => 0
            ]
        ]
    ];
    private $questions = [
        "askForDefinitions" =>
        "Give me a list of definitions of a word '@@@@'.
            No addition information.
            Each definition at a new line.",

    ];
    private $instructions = [
        "askForCollocations" => "
            Word: [X]. Output: 5 English phrases (different senses) with **X**. After a dot, a number of ngram probability of the word **X** multiplied by 1000. Just comma separated phrases, no extra text.
            Example:
            Word: crummy.
            Output:
            a **crummy** job, a **crummy** apartment, this **crummy** weather [...]. (0.002%)
            ",
    ];

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
        $this->aiApiLink = env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/');
        $this->model = env('GEMINI_MODEL', 'gemini-2.5-flash-lite');
        $this->proxyLogin = env('PROXY_LOGIN');
        $this->proxyPassword = env('PROXY_PASSWORD');
        $this->proxyIP = env('PROXY_IP');
        $this->proxyPort = env('PROXY_PORT');
    }
    private function buildAiUrl()
    {
        return $this->aiApiLink . $this->model . $this->modelGoal;
    }

    private function buildProxyUrl()
    {
        return "http://{$this->proxyLogin}:{$this->proxyPassword}@{$this->proxyIP}:{$this->proxyPort}";
    }

    private function buildAiPayloadWithQuestion($question = '', $word = null)
    {
        $payload = $this->payload;
        unset($payload["system_instruction"]);
        $content = str_replace('@@@@', $word, $question);
        $payload["contents"]["parts"][0]["text"] = $content;
        return $payload;
    }

    private function buildAiPayloadWithInstruction($instruction = null, $content = null)
    {
        $payload = $this->payload;
        $payload["system_instruction"]["parts"][0]["text"] = $instruction;
        $payload["contents"]["parts"][0]["text"] = $content;
        return $payload;
    }

    private function buildAiHeaders()
    {
        $headers = [
            "Content-Type: application/json",
            "x-goog-api-key: {$this->apiKey}"
        ];
        return $headers;
    }

    public function ask($word = '', $model = "gemini-2.5-flash-lite")
    {
        if (!$word) return null;
        $this->resolveModel($model);
        $res = null;
        $data = $this->buildAiPayloadWithQuestion($this->questions['askForDefinitions'], $word);
        $url = $this->buildAiUrl();
        $headers = $this->buildAiHeaders();
        $proxy = $this->buildProxyUrl();

        $answer = $this->request($url, $data, $headers, $proxy);

        if (isset($answer['candidates'][0]['content']['parts'][0]['text'])) {
            $res = $answer['candidates'][0]['content']['parts'][0]['text'];
        } else {
            $res = "Error in response: " . print_r($answer, true);
        }

        return $res;
    }

    public function askForContext($instruction = '', $prompt = '', $model = "gemini-2.5-flash-lite")
    {
        if (!$prompt || !$instruction) return null;
        $this->resolveModel($model);
        $res = null;
        $data = $this->buildAiPayloadWithInstruction(
            $instruction,
            $prompt
        );
        $url = $this->buildAiUrl();
        $headers = $this->buildAiHeaders();
        $proxy = $this->buildProxyUrl();

        $answer = $this->request($url, $data, $headers, $proxy);

        if (isset($answer['candidates'][0]['content']['parts'][0]['text'])) {
            $res = $answer['candidates'][0]['content']['parts'][0]['text'];
            $res = $this->formatting($res);
        } else {
            $res = "Error in response: " . print_r($answer, true);
        }

        return $res;
    }
    private function request($url, $data, $headers, $proxy)
    {

        // Initialize cURL
        $ch = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PROXY, $proxy);

        // Execute the request
        $response = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
        } else {
            $res = json_decode($response, true);
        }

        curl_close($ch);
        return $res;
    }

    protected function formatting($answer)
    {
   $answer = strip_tags((string)$answer);

    // Normalize arrows (order matters)
    $answer = preg_replace('/\=\>/', '— ', $answer);
    $answer = preg_replace('/>/', '— ', $answer);

    // Escape HTML (keep quotes so we can match them)
    $answer = htmlspecialchars($answer, ENT_NOQUOTES, 'UTF-8');

    // Quote emphasis first to avoid matching attributes later
    $answer = preg_replace('/"([^"\n]{1,200})"/', '<em class="cursor-pointer hover:bg-orange-50">"$1"</em>', $answer);

    // List bullets
    $answer = preg_replace('/^\s*[\-\*]\s+/m', '— ', $answer);

    // Inline code
    $answer = preg_replace('/`([^`]+)`/', "<b class='comma cursor-pointer hover:bg-orange-50'>$1</b>", $answer);

    // Bold and single-star with safer patterns
    $answer = preg_replace('/(?<!\*)\*\*(.+?)\*\*(?!\*)/s', "<b class='ssss cursor-pointer hover:bg-orange-50'>$1</b>", $answer);
    $answer = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/s', "<b class='star'>$1</b>", $answer);

    // Markdown headers
    $answer = preg_replace('/^#{1,3}\s*(.+)$/m', "<b class='reshetka cursor-pointer hover:bg-orange-50'>$1</b>", $answer);

    // Cleanup and line breaks
    $answer = str_replace('---', '', $answer);
    // $answer = str_replace(array("\r\n", "\r", "\n"), "<br /><hr />", $answer);
    $answer = nl2br($answer);
    return $answer;
    }

    protected function resolveModel($model=''){
        if(isset($this->models[$model])){
            $this->model = $this->models[$model];
        }
        return $this->model;
    }
}
