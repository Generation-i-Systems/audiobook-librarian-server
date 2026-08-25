<?php

namespace App\Services;

use App\Support\TypeaheadFilter;

use function Laravel\Prompts\confirm;

class HybridUIService extends ImportUIService
{
    public function __construct()
    {
        // Use a custom output to suppress the 2-newline padding Laravel Prompts adds by default
        // and rtrim the output to avoid extra blank lines at the bottom.
        // Also remove the 1-char indentation from the beginning of each line.
        \Laravel\Prompts\Prompt::setOutput(new class () extends \Laravel\Prompts\Output\ConsoleOutput {
            public function newLinesWritten(): int
            {
                return 2; // Pretend we've already written newlines to avoid automatic padding
            }

            public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
            {
                if (is_string($messages)) {
                    $messages = preg_replace('/^ /m', '', $messages);
                    $messages = rtrim($messages, "\r\n");
                }
                parent::write($messages, false, $options);
            }
        });
    }

    /**
     * Override drawBox to use Laravel Prompts styling (thin borders)
     */
    protected function drawBox(int $x, int $y, int $w, int $h, string $title = "", string $color = "white"): void
    {
        $colors = [
            'white' => "\e[37m",
            'cyan' => "\e[36m",
            'green' => "\e[32m",
            'yellow' => "\e[33m",
            'red' => "\e[31m",
            'gray' => "\e[90m",
            'reset' => "\e[0m",
        ];

        // Map colors to Prompts-style shades
        if ($color === 'white') {
            $color = 'gray';
        }

        $c = $colors[$color] ?? $colors['gray'];
        $reset = $colors['reset'];

        $title = trim($title);
        $titleLen = mb_strwidth($title);
        $titleLabel = $titleLen > 0 ? " {$title} " : '';

        // Prompts style: ┌ Title ──────┐
        $innerW = $w - 2;
        $topBorderWidth = $innerW - $titleLen - ($titleLen > 0 ? 0 : 0);
        // If there's a title, Prompts usually puts it right after the corner: ┌ Title ───
        // Let's match: ┌ Title ───────┐
        $topBorder = str_repeat('─', max(0, $innerW - $titleLen - ($titleLen > 0 ? 1 : 0)));

        // Draw top border
        $this->screen->write("\e[{$y};{$x}H{$c}┌{$titleLabel}{$topBorder}┐{$reset}");

        // Draw sides
        for ($i = 1; $i < $h - 1; $i++) {
            $currentY = $y + $i;
            $this->screen->write("\e[{$currentY};{$x}H{$c}│{$reset}");
            $this->screen->write("\e[{$currentY};" . ($x + $w - 1) . "H{$c}│{$reset}");
        }

        // Draw bottom border
        $this->screen->write("\e[" . ($y + $h - 1) . ";{$x}H{$c}└" . str_repeat('─', $innerW) . "┘{$reset}");
    }

    /**
     * Override drawFooter to remove the separator line and prompt
     */
    protected function drawFooter(): void
    {
        // No-op - line and '>' prompt removed as requested
    }

    protected function drawPrompt(): void
    {
        // Normally a no-op: the area below the outer box is left empty for Laravel Prompts to
        // render into (ask()/select()/selectFiltered()/confirm() never populate $this->promptLines).
        // But a few raw-TTY prompts (e.g. selectCoverWithPreview(), used for automatic cover-source
        // selection) bypass Prompts entirely and rely on the base class's drawPrompt() to draw
        // $this->promptLines directly — silencing it unconditionally left that menu computed and
        // waiting for input, but never actually drawn, making the screen look frozen.
        if (!empty($this->promptLines)) {
            parent::drawPrompt();
        }
    }

    protected function drawLogs(): void
    {
        $layout = $this->computeLayout();
        $y = $layout['logY'];
        $h = $layout['logHeight'] + 2;
        $maxLogs = max(1, $layout['maxLogs'] + 2);

        $totalLogs = count($this->logs);
        $maxOffset = max(0, $totalLogs - $maxLogs);
        $this->logScrollOffset = min($this->logScrollOffset, $maxOffset);
        $offset = $this->logScrollOffset;

        $title = $offset > 0 ? " Activity Log [PgDn↓ — {$offset} lines below] " : ' Activity Log ';

        $this->drawBox(2, $y, $this->width - 2, $h, $title, 'yellow');

        $displayLogs = $offset === 0 ? array_slice($this->logs, -$maxLogs) : array_slice($this->logs, -($maxLogs + $offset), $maxLogs);

        $row = $y + 1;
        foreach ($displayLogs as $log) {
            $clean = mb_convert_encoding($log, 'UTF-8', 'UTF-8');
            $this->screen->write("\e[{$row};4H" . mb_substr($clean, 0, $this->width - 6));
            $row++;
        }
    }

    protected function getPromptCursorY(): int
    {
        return $this->getFooterSeparatorY() + 2;
    }

    public function ask(string $question, string $default = '', bool $clearPrompt = true): string
    {
        // Ensure UI is up to date
        $this->renderFull();

        // Move cursor to prompt position (where the separator used to be)
        $cursorY = $this->getPromptCursorY();
        $cursorX = 1;

        echo "\e[{$cursorY};{$cursorX}H";

        $prompt = new ScrollableTextPrompt(
            label: $question,
            default: $default,
            required: false,
        );

        $response = $prompt->prompt();

        // wasCancelled() means Escape was pressed: value() already reverted to
        // $default, so skip the 'q' quit-sentinel check entirely — cancelling
        // shouldn't quit the import just because a field's original text
        // happened to be the literal word "q".
        if (!$prompt->wasCancelled() && strtolower(trim((string) $response)) === 'q') {
            return 'q';
        }

        // Restore layout
        $this->renderFull();

        return (string) $response;
    }

    public function select(string $question, array $options, string $default = ''): string
    {
        $this->renderFull();

        $cursorY = $this->getPromptCursorY();

        if (empty($options)) {
            return '';
        }

        $formattedOptions = [];
        foreach ($options as $key => $label) {
            $formattedOptions[(string) $key] = $label;
        }

        $defaultKey = (string) array_key_first($formattedOptions);
        if ($default !== '' && isset($formattedOptions[$default])) {
            $defaultKey = $default;
        }

        $layout = $this->computeLayout();
        $scroll = max(5, min(count($formattedOptions), $layout['menuHeight'] - 4));

        $prompt = new ScrollableSelectPrompt(
            label: $question,
            options: $formattedOptions,
            default: $defaultKey,
            scroll: $scroll,
            cursorRow: $cursorY,
            onScrollUp: function (): void {
                $this->scrollLog('up');
            },
            onScrollDown: function (): void {
                $this->scrollLog('down');
            },
        );

        $response = $prompt->prompt();

        $this->renderFull();

        return (string) $response;
    }

    public function selectFiltered(string $question, array $options, string $default = ''): string
    {
        $this->renderFull();

        $cursorY = $this->getPromptCursorY();

        if (empty($options)) {
            return '';
        }

        $formattedOptions = [];
        foreach ($options as $key => $label) {
            $formattedOptions[(string) $key] = $label;
        }

        $layout = $this->computeLayout();
        $scroll = max(5, min(count($formattedOptions), $layout['menuHeight'] - 4));

        $prompt = new ScrollableSearchPrompt(
            label: $question,
            options: fn (string $value) => TypeaheadFilter::filter($formattedOptions, $value),
            placeholder: 'Type to filter...',
            scroll: $scroll,
            cursorRow: $cursorY,
            onScrollUp: function (): void {
                $this->scrollLog('up');
            },
            onScrollDown: function (): void {
                $this->scrollLog('down');
            },
        );

        $response = $prompt->prompt();

        $this->renderFull();

        if ($prompt->wasCancelled()) {
            // Not the 'q' sentinel: Escape backs out of just this selection, it
            // doesn't quit the whole import. Every call site falls back to the
            // current/default value when the returned key isn't a valid option
            // (e.g. `$genreOptions[$selectedGenreIdx] ?? $displayGenre`), and no
            // real option key is ever an empty string, so this reliably no-ops.
            return '';
        }

        return (string) $response;
    }

    /**
     * Like select(), but updates the inline cover preview as the user navigates between
     * options — used for automatic cover-source selection when a book has more than one
     * cover candidate (e.g. an embedded cover and a standalone cover.jpg). Previously this
     * always fell through to the raw-TTY ImportUIService::selectCoverWithPreview() (a
     * horizontal grid layout), regardless of UI mode, instead of matching the vertical
     * Laravel Prompts style every other Hybrid menu uses.
     */
    public function selectCoverWithPreview(string $question, array $options, string $default, array $coverPathByKey): string
    {
        $this->renderFull();

        $cursorY = $this->getPromptCursorY();

        if (empty($options)) {
            return '';
        }

        $formattedOptions = [];
        foreach ($options as $key => $label) {
            $formattedOptions[(string) $key] = $label;
        }

        $defaultKey = (string) array_key_first($formattedOptions);
        if ($default !== '' && isset($formattedOptions[$default])) {
            $defaultKey = $default;
        }

        $layout = $this->computeLayout();
        $scroll = max(5, min(count($formattedOptions), $layout['menuHeight'] - 4));

        $savedBook = $this->currentBook;

        $updatePreview = function (int|string|null $key) use ($coverPathByKey, $savedBook): void {
            $path = $coverPathByKey[(string) $key] ?? null;
            $updated = $savedBook;
            if ($path !== null && $path !== '') {
                $updated['cover_url'] = $path;
                $updated['cover_is_local_file'] = true;
            } else {
                unset($updated['cover_url']);
            }
            // Update inline cover without a full book context reset
            $this->currentBook = $updated;
            $this->cacheCoverForCurrentBook();
            $this->renderedCoverUrl = null;
            $this->renderFull();
        };

        $prompt = new ScrollableSelectPrompt(
            label: $question,
            options: $formattedOptions,
            default: $defaultKey,
            scroll: $scroll,
            cursorRow: $cursorY,
            onScrollUp: function (): void {
                $this->scrollLog('up');
            },
            onScrollDown: function (): void {
                $this->scrollLog('down');
            },
            onHighlightChange: $updatePreview,
        );

        // Show the default selection's cover before any key is pressed.
        $updatePreview($defaultKey);

        $response = $prompt->prompt();

        $this->renderFull();

        return (string) $response;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $this->renderFull();

        $cursorY = $this->getPromptCursorY();
        $cursorX = 1;
        echo "\e[{$cursorY};{$cursorX}H";

        $response = confirm(
            label: $question,
            default: $default
        );

        // Restore layout
        $this->renderFull();

        return $response;
    }
}
