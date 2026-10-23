<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Services\PromptsUIService;
use App\Services\ScrollableSelectPrompt;
use App\Support\PromptSelection;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Laravel Prompts returns the selected *label* when the options array is a list
 * (keys '0','1','2' become ints 0,1,2), and the key otherwise. Callers always expect
 * the key, so "Use an existing series location instead?" (keys from '0') silently
 * fell back to option 0 whatever was chosen.
 */
class PromptSelectionTest extends TestCase
{
    private const SERIES_OPTIONS = [
        '0' => 'Keep computed location: Kids/Charlie Higson & Ian Fleming/...',
        '1' => 'Action/Charlie Higson/Young Bond (2 books)',
        '2' => 'Action/Charlie Higson & Ian Fleming/Young Bond_ Blood Fever (0 books)',
    ];

    public static function responseProvider(): array
    {
        $menu = ['1' => 'Accept', '2' => 'Edit', 'q' => 'Quit'];

        return [
            'list options return the label' => [self::SERIES_OPTIONS, self::SERIES_OPTIONS['1'], '1'],
            'list options, first label' => [self::SERIES_OPTIONS, self::SERIES_OPTIONS['0'], '0'],
            'keyed options return the int key' => [$menu, 2, '2'],
            'keyed options return a string key' => [$menu, 'q', 'q'],
            'filtered search returned a label' => [$menu, 'Edit', '2'],
            'unknown response' => [$menu, 'nope', ''],
            'cancelled' => [$menu, null, ''],
        ];
    }

    #[Test]
    #[DataProvider('responseProvider')]
    public function keyForMapsPromptResponseToOptionKey(array $options, int|string|null $response, string $expected): void
    {
        $this->assertSame($expected, PromptSelection::keyFor($options, $response));
    }

    #[Test]
    public function scrollableSelectPromptReturnsLabelForListOptions(): void
    {
        ScrollableSelectPrompt::fake([Key::DOWN, Key::ENTER]);

        $prompt = new ScrollableSelectPrompt(label: 'Q', options: self::SERIES_OPTIONS, default: '0');

        $this->assertSame(self::SERIES_OPTIONS['1'], $prompt->prompt());
    }

    #[Test]
    public function promptsUiSelectReturnsKeyForListOptions(): void
    {
        // Artisan command tests call Prompt::fallbackWhen(true), which is sticky (it ORs
        // and has no reset), so later SelectPrompt calls would go to their mocked output.
        $shouldFallback = new \ReflectionProperty(Prompt::class, 'shouldFallback');
        $previous = $shouldFallback->getValue();
        $shouldFallback->setValue(null, false);

        try {
            Prompt::fake([Key::DOWN, Key::ENTER]);

            $choice = (new PromptsUIService())->select(
                'Use an existing series location instead?',
                self::SERIES_OPTIONS,
                '0'
            );
        } finally {
            $shouldFallback->setValue(null, $previous);
        }

        $this->assertSame('1', $choice);
    }
}
