<?php

namespace App\Contracts;

interface AiProviderInterface
{
    /**
     * Get the provider identifier (e.g., 'openrouter', 'gemini')
     */
    public static function getProviderKey(): string;

    /**
     * Get the display name for the provider
     */
    public static function getProviderName(): string;

    /**
     * Get all available models for this provider
     *
     * @return array<string, string> Model key => display name
     */
    public function getModels(): array;

    /**
     * Check if this provider is configured (has API key, etc.)
     */
    public function isConfigured(): bool;

    /**
     * Ask the AI with context (system instruction + user question)
     */
    public function askForContext(
        string $instruction,
        string $question,
        string $model
    ): ?string;

    /**
     * Stream the AI response chunk-by-chunk.
     *
     * The callable receives raw markdown text fragments as they arrive from
     * the upstream provider. Errors are thrown as AiProviderException so the
     * caller can convert them into an SSE error event.
     *
     * @param  callable(string $chunk): void  $onChunk
     */
    public function askForContextStreamed(
        string $instruction,
        string $question,
        string $model,
        callable $onChunk
    ): void;
}
