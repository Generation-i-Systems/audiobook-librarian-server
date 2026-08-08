<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Concerns\DetectsPendingEscapeSequence;
use Closure;
use Laravel\Prompts\Key;
use Laravel\Prompts\TextPrompt;
use Laravel\Prompts\Themes\Default\TextPromptRenderer;

class ScrollableTextPrompt extends TextPrompt
{
    use DetectsPendingEscapeSequence;

    private bool $cancelled = false;

    public function __construct(
        string $label,
        string $placeholder = '',
        string $default = '',
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
        ?Closure $transform = null,
    ) {
        parent::__construct(
            label: $label,
            placeholder: $placeholder,
            default: $default,
            required: $required,
            validate: $validate,
            hint: $hint,
            transform: $transform,
        );

        $this->on('key', function (string $key): void {
            if ($key === Key::ESCAPE && !$this->escapeStartsPendingSequence()) {
                $this->cancelled = true;
                $this->submit();
            }
        });
    }

    /**
     * Whether the user pressed Escape to abandon the edit. The returned
     * value() already reverts to the original text in this case, but
     * callers that need to distinguish "cancelled" from "user retyped the
     * same text" should check this explicitly.
     */
    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Escape means "leave the original content", not "submit whatever's
     * been typed so far" — return the value the field started with.
     */
    public function value(): string
    {
        if ($this->cancelled) {
            return $this->default;
        }

        return parent::value();
    }

    /**
     * Bypass validation when cancelling — the point of Escape is to bail
     * out without whatever was typed needing to pass `required`/`validate`.
     */
    protected function submit(): void
    {
        if ($this->cancelled) {
            $this->state = 'submit';

            return;
        }

        parent::submit();
    }

    protected function getRenderer(): callable
    {
        return new TextPromptRenderer($this);
    }

    /**
     * See ScrollableSearchPrompt::renderTheme() for why this is needed: the
     * custom Output installed by HybridUIService rtrims trailing blank lines
     * at write time, but Prompt::render() sizes its next cursor-up move off
     * the untrimmed frame. Trimming here keeps the two in sync so the box
     * doesn't creep upward on repeated renders (this renderer has the same
     * "space for errors" trailing blank line as SearchPromptRenderer).
     */
    protected function renderTheme(): string
    {
        return rtrim(parent::renderTheme(), "\r\n");
    }
}
