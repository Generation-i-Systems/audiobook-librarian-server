<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImportEditSelectPrompt;
use App\Services\ScrollableSelectPrompt;
use Laravel\Prompts\Key;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportEditSelectPromptTest extends TestCase
{
    #[Test]
    public function leftAndRightArrowsJumpToFirstAndLastOptions(): void
    {
        $prompt = new ImportEditSelectPrompt(label: 'Select field to edit', options: ['a' => 'A', 'b' => 'B', 'c' => 'C'], default: 'b');
        $prompt->emit('key', Key::LEFT);
        $this->assertSame('a', $prompt->highlightedValue());
        $prompt->emit('key', Key::RIGHT);
        $this->assertSame('c', $prompt->highlightedValue());
    }
    #[Test]
    public function hybridMenuJumpsOnlyWhenEnabled(): void
    {
        $options = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
        $edit = new ScrollableSelectPrompt(label: 'Edit', options: $options, default: 'b', jumpToEnds: true);
        $edit->emit('key', Key::RIGHT_ARROW);
        $this->assertSame('d', $edit->highlightedValue());
        $edit->emit('key', Key::LEFT_ARROW);
        $this->assertSame('a', $edit->highlightedValue());

        $normal = new ScrollableSelectPrompt(label: 'Other', options: $options, default: 'b');
        $normal->emit('key', Key::RIGHT_ARROW);
        $this->assertSame('c', $normal->highlightedValue());
    }
}
