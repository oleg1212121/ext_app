<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'url' => env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
        'model' => env('OPENROUTER_MODEL', 'xiaomi/mimo-v2-flash:free'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
    ],

    'huggingface' => [
        'key' => env('HUGGINFACE_API_KEY'),
        'url' => env('HUGGINGFACE_API_URL', 'https://router.huggingface.co/v1/chat/completions'),
        'model' => env('HUGGINGFACE_MODEL', 'deepseek-ai/DeepSeek-V3.1-Terminus:novita'),
    ],

    'cohere' => [
        'key' => env('COHERE_API_KEY'),
        'url' => env('COHERE_API_URL', 'https://api.cohere.ai/v1/chat'),
        'model' => env('COHERE_MODEL', 'command-a-03-2025'),
    ],

    'perplexity' => [
        'key' => env('PERPLEXITY_API_KEY'),
        'url' => env('PERPLEXITY_API_URL', 'https://api.perplexity.ai/chat/completions'),
        'model' => env('PERPLEXITY_MODEL', 'sonar'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'url' => env('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],

    'proxy' => [
        'login' => env('PROXY_LOGIN'),
        'password' => env('PROXY_PASSWORD'),
        'ip' => env('PROXY_IP'),
        'port' => env('PROXY_PORT'),
    ],

    'python' => [
        'url' => env('PYTHON_API_URL', 'http://ext_python:8000'),
        'timeout' => env('PYTHON_TIMEOUT', 30),
        'align_timeout' => env('PYTHON_ALIGN_TIMEOUT', 600),
        'has_similar_batch_size' => (int) env('PYTHON_HAS_SIMILAR_BATCH', 200),
        'sentence_split_chunk_bytes' => (int) env('PYTHON_SPLIT_CHUNK_BYTES', 262_144),
    ],

];
