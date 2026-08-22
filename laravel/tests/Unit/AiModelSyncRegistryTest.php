<?php

use App\Classes\AIModelResolver;
use App\Services\AiModelSyncRegistry;

it('has the same provider keys as AIModelResolver', function () {
    expect((new AiModelSyncRegistry)->keys())
        ->toBe((new AIModelResolver)->keys());
});
