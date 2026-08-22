<?php

namespace App\Services;

class GeminiModelSync extends AiModelSync
{
    public function provider(): string
    {
        return 'gemini';
    }
}
