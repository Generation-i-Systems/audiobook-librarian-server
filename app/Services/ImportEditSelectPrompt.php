<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Laravel\Prompts\Key;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\Themes\Default\SelectPromptRenderer;

class ImportEditSelectPrompt extends SelectPrompt
{
    /** @param array<int|string, string>|Collection<int|string, string> $options */
    public function __construct(string $label, array|Collection $options, int|string|null $default = null, int $scroll = 5, bool $jumpToEnds = true)
    {
        parent::__construct(label: $label, options: $options, default: $default, scroll: $scroll);
        if ($jumpToEnds) {
            $this->on('key', function (string $key): void {
                if ($key === Key::LEFT || $key === Key::LEFT_ARROW) {
                    $this->highlight(0);
                } elseif ($key === Key::RIGHT || $key === Key::RIGHT_ARROW) {
                    $this->highlight(count($this->options) - 1);
                }
            });
        }
    }
    protected function getRenderer(): callable
    {
        return new SelectPromptRenderer($this);
    }
}
