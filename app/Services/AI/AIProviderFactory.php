<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AI\AIProviderInterface;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;

/**
 * Shared provider-selection logic, extracted from AIAssistantService's constructor
 * so new AI-backed features (embedding cover captions, recommendation ranking) don't
 * each duplicate the same provider/model resolution.
 */
class AIProviderFactory
{
    public static function make(?string $provider = null, ?string $model = null): AIProviderInterface
    {
        $providerName = $provider ?? config('services.ai.default_provider', 'gemini');
        $model ??= config('services.ai.default_model', 'gemini-2.5-flash-lite');

        return match ($providerName) {
            'claude' => new ClaudeProvider($model),
            'openai' => new OpenAIProvider($model),
            default => new GeminiProvider($model),
        };
    }
}
