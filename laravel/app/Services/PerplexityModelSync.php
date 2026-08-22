<?php

namespace App\Services;

class PerplexityModelSync extends AiModelSync
{
    public function provider(): string
    {
        return 'perplexity';
    }
}
