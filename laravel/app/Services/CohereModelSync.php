<?php

namespace App\Services;

class CohereModelSync extends AiModelSync
{
    public function provider(): string
    {
        return 'cohere';
    }
}
