<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Prompts\Key;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\Themes\Default\SelectPromptRenderer;

class ScrollableSelectPrompt extends SelectPrompt
{
    private bool $initialRenderDone = false;

    private bool $needsReposition = false;

    /**
     * @param  array<int|string, string>|Collection<int|string, string>  $options
     */
    public function __construct(
        string $label,
        array|Collection $options,
        int|string|null $default = null,
        int $scroll = 5,
        private readonly int $cursorRow = 1,
        private readonly ?Closure $onScrollUp = null,
        private readonly ?Closure $onScrollDown = null,
    ) {
        parent::__construct(
            label: $label,
            options: $options,
            default: $default,
            scroll: $scroll,
        );

        $this->on('key', function (string $key): void {
            if ($key === Key::PAGE_UP && $this->onScrollUp !== null) {
                $this->needsReposition = true;
                ($this->onScrollUp)();
            } elseif ($key === Key::PAGE_DOWN && $this->onScrollDown !== null) {
                $this->needsReposition = true;
                ($this->onScrollDown)();
            }
        });
    }

    protected function render(): void
    {
        if (!$this->initialRenderDone) {
            // Explicitly position the cursor before the initial frame is written.
            // The soloterm Screen uses relative DECSC/DECRC positioning, so after
            // screen->output() the cursor is not guaranteed to be at cursorRow when
            // Symfony writes the frame directly to the fd.
            static::writeDirectly("\e[{$this->cursorRow};1H");
            $this->initialRenderDone = true;
        } elseif ($this->needsReposition) {
            // After a log scroll, renderFull() leaves the cursor somewhere in the
            // screen. Prompt::render() uses moveCursorUp(prevFrameHeight-1) which
            // expects the cursor to be at the BOTTOM of the previous frame. Restore
            // that position so the relative movement lands at the frame's top row.
            $prevLines = $this->prevFrame !== '' ? count(explode(PHP_EOL, $this->prevFrame)) : 1;
            static::writeDirectly("\e[" . ($this->cursorRow + $prevLines - 1) . ";1H");
            $this->needsReposition = false;
        }

        parent::render();
    }

    protected function getRenderer(): callable
    {
        return new SelectPromptRenderer($this);
    }
}
