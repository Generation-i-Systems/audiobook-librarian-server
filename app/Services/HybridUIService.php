<?php

namespace App\Services;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

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
        // No-op: the area below the outer box is left empty for Laravel Prompts to render into.
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

        $response = text(
            label: $question,
            default: $default,
            required: false
        );

        if (strtolower(trim($response)) === 'q') {
            return 'q';
        }

        // Restore layout
        $this->renderFull();

        return $response;
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
