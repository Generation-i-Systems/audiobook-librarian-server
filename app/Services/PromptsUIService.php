<?php

namespace App\Services;

use App\Contracts\ImportUIInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class PromptsUIService implements ImportUIInterface
{
    protected int $width = 120;
    protected int $height = 40;
    protected array $currentBook = [];
    protected int $progressCurrent = 0;
    protected int $progressTotal = 0;
    protected bool $plainMode = false;
    protected bool $interrupted = false;

    public function initialize(int $width, int $height): void
    {
        $this->width = max(40, $width);
        $this->height = max(20, $height);
    }

    public function ask(string $question, string $default = ''): string
    {
        if ($this->plainMode) {
            echo "{$question} [{$default}]: ";
            $input = trim((string) fgets(STDIN));
            return $input !== '' ? $input : $default;
        }

        $response = text(
            label: $question,
            default: $default,
            required: false
        );

        if (strtolower(trim($response)) === 'q') {
            return 'q';
        }

        return $response;
    }

    public function select(string $question, array $options, string $default = ''): string
    {
        if ($this->plainMode) {
            return $this->selectPlain($question, $options, $default);
        }

        if (empty($options)) {
            return '';
        }

        $formattedOptions = [];
        foreach ($options as $key => $label) {
            $formattedOptions[(string) $key] = $label;
        }

        $defaultKey = $default !== '' && isset($formattedOptions[$default]) ? $default : (string) array_key_first($formattedOptions);

        $response = select(
            label: $question,
            options: $formattedOptions,
            default: $defaultKey
        );

        return (string) $response;
    }

    protected function selectPlain(string $question, array $options, string $default): string
    {
        echo "{$question}\n";
        foreach ($options as $key => $label) {
            $marker = $key === $default ? ' (default)' : '';
            echo "  [{$key}] {$label}{$marker}\n";
        }
        echo "Enter choice [{$default}]: ";

        $input = strtolower(trim((string) fgets(STDIN)));
        if ($input === '') {
            $input = $default;
        }

        return array_key_exists($input, $options) ? $input : $default;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        if ($this->plainMode) {
            $defaultStr = $default ? 'Y/n' : 'y/N';
            echo "{$question} [{$defaultStr}]: ";
            $input = strtolower(trim((string) fgets(STDIN)));
            if ($input === '') {
                return $default;
            }
            return $input === 'y' || $input === 'yes';
        }

        return confirm(
            label: $question,
            default: $default
        );
    }

    public function progress(string $label, iterable $items, callable $callback): void
    {
        if ($this->plainMode) {
            $this->progressPlain($label, $items, $callback);
            return;
        }

        $itemsArray = is_array($items) ? $items : iterator_to_array($items);
        $total = count($itemsArray);

        $currentIndex = 0;
        progress(
            label: $label,
            steps: $itemsArray,
            callback: function ($item) use ($callback, $total, &$currentIndex) {
                $this->progressCurrent = $currentIndex + 1;
                $this->progressTotal = $total;
                $callback($currentIndex, $item);
                $currentIndex++;
            }
        );
    }

    protected function progressPlain(string $label, iterable $items, callable $callback): void
    {
        $itemsArray = is_array($items) ? $items : iterator_to_array($items);
        $total = count($itemsArray);

        echo "{$label}\n";
        foreach ($itemsArray as $index => $item) {
            $this->progressCurrent = $index + 1;
            $this->progressTotal = $total;
            echo sprintf("\r[%d/%d] Processing...", $index + 1, $total);
            $callback($index, $item);
        }
        echo "\n";
    }

    public function spin(string $message, callable $callback): mixed
    {
        if ($this->plainMode) {
            echo "{$message}... ";
            $result = $callback();
            echo "done\n";
            return $result;
        }

        return spin(
            message: $message,
            callback: function () use ($callback) {
                return $callback();
            }
        );
    }

    public function table(array $headers, array $rows): void
    {
        if ($this->plainMode) {
            $this->tablePlain($headers, $rows);
            return;
        }

        table($headers, $rows);
    }

    protected function tablePlain(array $headers, array $rows): void
    {
        $colWidths = [];
        $allRows = array_merge([$headers], $rows);

        foreach ($allRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_values($row) as $i => $value) {
                $len = strlen((string) $value);
                $colWidths[$i] = max($colWidths[$i] ?? 0, $len);
            }
        }

        foreach ($allRows as $index => $row) {
            if (is_array($row)) {
                $paddedCells = [];
                foreach (array_values($row) as $i => $value) {
                    $paddedCells[] = str_pad((string) $value, $colWidths[$i]);
                }
                echo implode(' | ', $paddedCells) . "\n";

                if ($index === 0) {
                    $separatorParts = [];
                    foreach ($colWidths as $width) {
                        $separatorParts[] = str_repeat('-', $width);
                    }
                    echo implode('-|-', $separatorParts) . "\n";
                }
            }
        }
    }

    public function info(string $message): void
    {
        if ($this->plainMode) {
            echo "[INFO] {$message}\n";
            return;
        }
        info($message);
    }

    public function warning(string $message): void
    {
        if ($this->plainMode) {
            echo "[WARNING] {$message}\n";
            return;
        }
        warning($message);
    }

    public function error(string $message): void
    {
        if ($this->plainMode) {
            echo "[ERROR] {$message}\n";
            return;
        }
        error($message);
    }

    public function logMessage(string $message): void
    {
        if ($this->plainMode) {
            echo "{$message}\n";
            return;
        }
        info($message);
    }

    public function updateProgress(int $current, int $total): void
    {
        $this->progressCurrent = $current;
        $this->progressTotal = $total;

        if ($this->plainMode && $total > 0) {
            $percent = round(($current / $total) * 100);
            echo sprintf("\r[%d/%d] %d%%", $current, $total, $percent);
            if ($current >= $total) {
                echo "\n";
            }
        }
    }

    public function setCurrentBook(array $metadata): void
    {
        $this->currentBook = $metadata;

        if ($this->plainMode) {
            $title = $metadata['title'] ?? 'Unknown';
            $author = $this->formatArrayOrString($metadata['author'] ?? 'Unknown');
            echo "\n--- Current Book ---\n";
            echo "Title: {$title}\n";
            echo "Author: {$author}\n";
            echo "--------------------\n";
        }
    }

    protected function formatArrayOrString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter($value, 'is_string'));
        }
        return (string) $value;
    }

    public function drawInitialLayout(): void
    {
        if (!$this->plainMode) {
            echo "\n";
            info("Audiobook Librarian Import");
            echo "\n";
        }
    }

    public function clear(): void
    {
        if (!$this->plainMode && function_exists('system')) {
            @system('clear 2>/dev/null || cls 2>/dev/null');
        }
    }

    public function setPlainMode(bool $plainMode): void
    {
        $this->plainMode = $plainMode;
    }

    public function requestInterrupt(): void
    {
        $this->interrupted = true;
    }

    public function restoreTerminalState(): void
    {
        if (function_exists('shell_exec')) {
            @shell_exec('stty sane < /dev/tty 2>/dev/null');
        }
    }

    public function isInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function getCurrentBook(): array
    {
        return $this->currentBook;
    }

    public function getProgressCurrent(): int
    {
        return $this->progressCurrent;
    }

    public function getProgressTotal(): int
    {
        return $this->progressTotal;
    }
}
