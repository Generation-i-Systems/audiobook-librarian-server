<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AIBookProcessor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AIBookProcessorPaidTierBindingTest extends TestCase
{
    private function paidTier(AIBookProcessor $processor): bool
    {
        $property = (new \ReflectionClass($processor))->getProperty('paidTier');
        $property->setAccessible(true);

        return (bool) $property->getValue($processor);
    }

    #[Test]
    public function containerBuiltProcessorFollowsTheConfiguredGeminiTier(): void
    {
        config(['services.gemini.paid_tier' => true, 'services.ai.default_model' => 'gemini-2.5-flash-lite']);
        $this->assertTrue($this->paidTier($this->app->make(AIBookProcessor::class)));

        config(['services.gemini.paid_tier' => false]);
        $this->assertFalse($this->paidTier($this->app->make(AIBookProcessor::class)));
    }
}
