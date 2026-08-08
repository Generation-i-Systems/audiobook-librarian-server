<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\DetectsPendingEscapeSequence;
use Closure;
use Laravel\Prompts\Key;
use Laravel\Prompts\SearchPrompt;
use Laravel\Prompts\Themes\Default\SearchPromptRenderer;

class ScrollableSearchPrompt extends SearchPrompt
{
    use DetectsPendingEscapeSequence;

    private bool $initialRenderDone = false;

    private bool $needsReposition = false;

    private bool $cancelled = false;

    public function __construct(
        string $label,
        Closure $options,
        string $placeholder = '',
        int $scroll = 5,
        private readonly int $cursorRow = 1,
        private readonly ?Closure $onScrollUp = null,
        private readonly ?Closure $onScrollDown = null,
    ) {
        parent::__construct(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            scroll: $scroll,
        );

        $this->on('key', function (string $key): void {
            if ($key === Key::PAGE_UP && $this->onScrollUp !== null) {
                $this->needsReposition = true;
                ($this->onScrollUp)();
            } elseif ($key === Key::PAGE_DOWN && $this->onScrollDown !== null) {
                $this->needsReposition = true;
                ($this->onScrollDown)();
            } elseif ($key === Key::ESCAPE && !$this->escapeStartsPendingSequence()) {
                $this->cancelled = true;

                // matches() lazily populates $this->matches on first access; the
                // 'submit' state's renderer calls label(), which does
                // array_keys($this->matches) unconditionally. If Escape is hit
                // before any filtering has happened, $this->matches is still
                // null and that blows up with a TypeError. Force it populated.
                $this->matches();

                $this->submit();
            }
        });
    }

    /**
     * Whether the user pressed Escape to back out of this selection without
     * picking an option. SearchPrompt has no built-in cancel path (it always
     * requires a value), so callers must check this explicitly rather than
     * relying on the returned value. This only cancels the one prompt — it's
     * not a "quit the whole import" signal.
     */
    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * SearchPrompt is always `required`, so the base submit() refuses to
     * finish while nothing is highlighted. Escape has nothing highlighted
     * by definition, so bypass that validation and end the loop directly.
     */
    protected function submit(): void
    {
        if ($this->cancelled) {
            $this->state = 'submit';

            return;
        }

        parent::submit();
    }

    /**
     * Prompt::render() always renders one more 'submit'-state frame before
     * the loop exits, and SearchPromptRenderer's 'submit' case truncates
     * label() as a plain string. With nothing highlighted, the base
     * label() returns null, which TypeErrors inside truncate(). Cancelling
     * has no label to show, so render it as empty.
     */
    public function label(): ?string
    {
        if ($this->cancelled) {
            return '';
        }

        return parent::label();
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
        return new SearchPromptRenderer($this);
    }

    /**
     * HybridUIService installs a custom Output that rtrims trailing blank
     * lines from every write() to avoid extra padding at the bottom of the
     * screen. Prompt::render() uses the *untrimmed* frame length to compute
     * how many lines to move the cursor up before the next redraw. If the
     * frame we hand back here still has trailing blank lines that the
     * Output strips at write time, that mismatch makes every subsequent
     * render move the cursor one (or more) rows too far up, and the error
     * compounds on every keystroke. Trimming here keeps the frame Prompt
     * bookkeeps in sync with what actually lands on the terminal.
     */
    protected function renderTheme(): string
    {
        return rtrim(parent::renderTheme(), "\r\n");
    }
}
