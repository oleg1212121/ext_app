<?php

namespace App\Classes;



class Groq extends AiProvider
{
    protected $model;
    protected $models = [
        "llama-3.3-70b-versatile" => "llama-3.3-70b-versatile",
        "llama-3.1-70b-versatile" => "llama-3.1-70b-versatile",
        "llama-3.1-8b-instruct" => "llama-3.1-8b-instruct",
        "mixtral-8x7b-32768" => "mixtral-8x7b-32768",
        "gemma-7b-it" => "gemma-7b-it",
        "llama-3.2-90b-vision" => "llama-3.2-90b-vision",
        "llama-3.2-11b-vision" => "llama-3.2-11b-vision",
        "llama-3.2-1b-preview" => "llama-3.2-1b-preview",
        "llama-3.2-90b-vision-preview"  => "llama-3.2-90b-vision-preview",
        "llama-3.1-8b-instruct"     => "llama-3.1-8b-instruct",
        "llama-3.1-70b-versatile"   => "llama-3.1-70b-versatile",
        "llama-3.1-405b-reasoning"  => "llama-3.1-405b-reasoning",
        "llama-3-8b-8192"           => "llama-3-8b-8192",
        "llama-3-70b-8192" => "llama-3-70b-8192",
    ];
    protected $apiKey;
    protected $aiApiLink;

    public function __construct()
    {
        $this->aiApiLink = env('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
        $this->model = env('GROQ_MODEL', 'openai/gpt-oss-120b');
        $this->apiKey = env('GROQ_API_KEY');
    }

}
