<?php

namespace App\Services;

class HuggingFaceModelSync extends AiModelSync
{
    public function provider(): string
    {
        return 'huggingface';
    }
}
