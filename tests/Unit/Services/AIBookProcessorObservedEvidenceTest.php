<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AIBookProcessor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AIBookProcessorObservedEvidenceTest extends TestCase
{
    private function prompt(array $tags, array $additionalText = []): string
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite', false);
        $method = (new \ReflectionClass($processor))->getMethod('buildPrompt');
        $method->setAccessible(true);

        return $method->invoke($processor, '16 - USS Crusader', ['a.m4b'], ['a.m4b' => $tags], null, [], $additionalText);
    }

    #[Test]
    public function buildPromptIncludesEveryOtherTag(): void
    {
        $prompt = $this->prompt([
            'title' => 'USS Crusader: Echoes of Sheentah',
            'composer' => 'Jimmy Moreland',
            'album_artist' => 'Mark Wayne McGinnis',
            'grouping' => 'USS Crusader',
        ]);

        $this->assertStringContainsString('composer:Jimmy Moreland', $prompt);
        $this->assertStringContainsString('album_artist:Mark Wayne McGinnis', $prompt);
        $this->assertStringContainsString('grouping:USS Crusader', $prompt);
    }

    #[Test]
    public function buildPromptIncludesSmallTextFilesAsSupportingContext(): void
    {
        $prompt = $this->prompt(['title' => 'X'], ['notes.txt' => 'Narrated by Jimmy Moreland']);

        $this->assertStringContainsString('notes.txt', $prompt);
        $this->assertStringContainsString('Narrated by Jimmy Moreland', $prompt);
    }

    #[Test]
    public function buildPromptOmitsTheSupportingTextSectionWhenThereIsNone(): void
    {
        $this->assertStringNotContainsString('ADDITIONAL FILES', $this->prompt(['title' => 'X']));
    }
}
