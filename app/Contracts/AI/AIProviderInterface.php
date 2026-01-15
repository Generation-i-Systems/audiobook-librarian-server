<?php

namespace App\Contracts\AI;

interface AIProviderInterface
{
    /**
     * Complete a text prompt and return the response
     */
    public function complete(string $prompt, array $options = []): AIResponse;

    /**
     * Complete a structured prompt with JSON response
     */
    public function completeStructured(string $prompt, array $schema, array $options = []): AIResponse;

    /**
     * Transcribe audio file to text
     */
    public function transcribe(string $audioPath): ?string;

    /**
     * Get the provider name
     */
    public function getName(): string;

    /**
     * Get the model name
     */
    public function getModel(): string;

    /**
     * Check if the provider can make a request (rate limiting)
     */
    public function canMakeRequest(): bool;

    /**
     * Get usage statistics
     */
    public function getUsageStats(): array;
}
