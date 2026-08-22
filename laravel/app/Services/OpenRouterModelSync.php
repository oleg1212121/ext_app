<?php

namespace App\Services;

class OpenRouterModelSync extends AiModelSync
{
    public function provider(): string
    {
        return 'openrouter';
    }
}
