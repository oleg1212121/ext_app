<?php

namespace App\Contracts;

interface ModelSync
{
    /**
     * The provider key this syncer is responsible for (e.g. 'openrouter', 'groq').
     */
    public function provider(): string;

    /**
     * Fetch the available models from the provider's API and upsert them into
     * the ai_models table, deleting any that are no longer returned.
     *
     * @return int The number of models present in the API response
     */
    public function sync(): int;
}
