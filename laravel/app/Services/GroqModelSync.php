<?php

namespace App\Services;

class GroqModelSync extends AiModelSync
{
    public function provider(): string
    {
        return 'groq';
    }
}
