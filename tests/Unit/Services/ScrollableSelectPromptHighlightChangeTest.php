<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ScrollableSelectPrompt;
use Laravel\Prompts\Key;
use Tests\TestCase;

class ScrollableSelectPromptHighlightChangeTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function onHighlightChangeFiresOnceForEachMoveUsingTheNewValue(): void
    {
        // Regression coverage for HybridUIService::selectCoverWithPreview(): it needs
        // onHighlightChange to fire once per arrow-key move, using the *new* highlighted
        // value (i.e. after SelectPrompt's own listener has already updated it) — it does
        // not fire for the initial default, since HybridUIService::selectCoverWithPreview()
        // triggers that notification separately, once, before prompt() is even called.
        ScrollableSelectPrompt::fake([Key::DOWN, Key::DOWN, Key::ENTER]);

        $seen = [];

        $prompt = new ScrollableSelectPrompt(
            label: 'Select cover source',
            options: ['1' => 'Embedded', '2' => 'Local file', '0' => 'None'],
            default: '1',
            onHighlightChange: function (int|string|null $key) use (&$seen): void {
                $seen[] = $key;
            },
        );

        $result = $prompt->prompt();

        // Numeric-string option keys ('1', '2', '0') come back as PHP int array keys.
        $this->assertSame(0, $result);
        $this->assertSame([2, 0], $seen);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function onHighlightChangeIsNotCalledWhenNull(): void
    {
        ScrollableSelectPrompt::fake([Key::DOWN, Key::ENTER]);

        $prompt = new ScrollableSelectPrompt(
            label: 'Select cover source',
            options: ['1' => 'Embedded', '2' => 'Local file'],
            default: '1',
        );

        // Should not throw despite no onHighlightChange callback being provided.
        $result = $prompt->prompt();

        $this->assertSame(2, $result);
    }
}
