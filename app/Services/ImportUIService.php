<?php

namespace App\Services;

use SoloTerm\Screen\Screen;

class ImportUIService
{
    protected ?Screen $screen = null;
    protected int $width = 120;
    protected int $height = 40;
    protected array $logs = [];
    protected int $maxLogs = 15;
    protected array $currentBook = [];
    protected int $progressCurrent = 0;
    protected int $progressTotal = 0;
    protected array $promptLines = [];
    protected bool $alternateScreenEnabled = false;
    protected ?string $cachedCoverUrl = null;
    protected ?string $cachedCoverTempFile = null;
    protected ?string $renderedCoverUrl = null;
    protected ?string $renderedCoverSignature = null;
    protected int $promptHeight = 10;
    protected int $inlineCoverCols = 26;
    protected int $inlineCoverRows = 9;
    protected int $inlineCoverPadding = 2;
    protected int $maxCoverDownloadBytes = 10000000;

    protected function getDirectoryLabel(): string
    {
        $directoryPath = $this->stringifyForDisplay($this->currentBook['directory_path'] ?? null) ?: '';

        return $directoryPath !== '' ? $directoryPath : 'N/A';
    }

    protected function getPromptHeight(): int
    {
        // Ensure we always have room for the main UI above
        $max = max(3, $this->height - 18);

        $linesNeeded = max(3, count($this->promptLines));

        return min($linesNeeded, $this->promptHeight, $max);
    }

    protected function getFooterSeparatorY(): int
    {
        // One separator line above prompt area + input line
        return $this->height - ($this->getPromptHeight() + 2);
    }

    public function initialize(int $width, int $height): void
    {
        // Avoid writing to the last row/column of the terminal.
        // Many terminals will auto-wrap when drawing the bottom-right corner, which causes a scroll.
        $this->width = max(40, $width - 1);
        $this->height = max(20, $height);
        $this->maxLogs = max(5, $this->height - 25);
        $this->screen = new Screen($this->width, $this->height);

        $this->enableAlternateScreen();

        register_shutdown_function(function (): void {
            $this->cleanupCoverTempFile();
            $this->disableAlternateScreen();
        });
    }

    protected function enableAlternateScreen(): void
    {
        if ($this->alternateScreenEnabled) {
            return;
        }

        // Switch to alternate screen buffer and hide cursor
        echo "\e[?1049h\e[?25l";
        $this->alternateScreenEnabled = true;
    }

    protected function disableAlternateScreen(): void
    {
        if (!$this->alternateScreenEnabled) {
            return;
        }

        // Restore normal screen buffer and show cursor
        echo "\e[?25h\e[?1049l";
        $this->alternateScreenEnabled = false;
    }

    protected function cleanupCoverTempFile(): void
    {
        if ($this->cachedCoverTempFile && file_exists($this->cachedCoverTempFile)) {
            @unlink($this->cachedCoverTempFile);
        }

        $this->cachedCoverTempFile = null;
        $this->cachedCoverUrl = null;
        $this->renderedCoverUrl = null;
        $this->renderedCoverSignature = null;
    }

    public function drawInitialLayout(): void
    {
        $this->renderFull();
    }

    protected function renderFull(): void
    {
        if (!$this->screen) {
            return;
        }

        // Clear buffer
        $this->screen->write("\e[H\e[J");

        // Draw outer border
        $this->drawBox(1, 1, $this->width, $this->height, " Audiobook Import Librarian ", "cyan");

        // Header section (Progress)
        $this->drawProgress();

        // Main section (Book Details)
        $this->drawBookDetails();

        // Logs section
        $this->drawLogs();

        // Footer / Input section
        $this->drawFooter();

        // Prompt section
        $this->drawPrompt();

        $this->render();
    }

    protected function drawBox(int $x, int $y, int $w, int $h, string $title = "", string $color = "white"): void
    {
        $colors = [
            'white' => "\e[37m",
            'cyan' => "\e[36m",
            'green' => "\e[32m",
            'yellow' => "\e[33m",
            'red' => "\e[31m",
            'reset' => "\e[0m",
        ];
        $c = $colors[$color] ?? $colors['white'];
        $reset = $colors['reset'];

        // Draw corners and lines
        $this->screen->write("\e[{$y};{$x}H{$c}┌" . str_repeat("─", $w - 2) . "┐{$reset}");
        for ($i = 1; $i < $h - 1; $i++) {
            $this->screen->write("\e[" . ($y + $i) . ";{$x}H{$c}│\e[" . ($y + $i) . ";" . ($x + $w - 1) . "H│{$reset}");
        }
        $this->screen->write("\e[" . ($y + $h - 1) . ";{$x}H{$c}└" . str_repeat("─", $w - 2) . "┘{$reset}");

        if ($title) {
            $titleLen = strlen($title);
            $posX = $x + (int) (($w - $titleLen) / 2);
            $this->screen->write("\e[{$y};{$posX}H{$c} $title {$reset}");
        }
    }

    protected function readLineWithPrefill(string $default): string
    {
        $result = null;
        $done = false;

        readline_callback_handler_install('', function ($line) use (&$result, &$done): void {
            $result = $line;
            $done = true;
        });

        readline_info('line_buffer', $default);
        readline_info('point', strlen($default));

        if (function_exists('readline_redisplay')) {
            readline_redisplay();
        }

        while (!$done) {
            $read = [STDIN];
            $write = [];
            $except = [];
            if (stream_select($read, $write, $except, null) === false) {
                break;
            }
            readline_callback_read_char();
        }

        readline_callback_handler_remove();

        return (string) $result;
    }

    protected function terminalSupportsArrowInput(): bool
    {
        if (!function_exists('shell_exec') || !is_resource(STDIN)) {
            return false;
        }

        if (function_exists('app') && app()->environment('testing')) {
            return false;
        }

        if (function_exists('stream_isatty') && !stream_isatty(STDIN)) {
            return false;
        }

        return true;
    }

    protected function enableRawInput(): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $state = (string) @shell_exec('stty -g');
        @shell_exec('stty -icanon -echo min 1 time 0');

        return trim($state) !== '' ? trim($state) : null;
    }

    protected function restoreRawInput(?string $state): void
    {
        if ($state === null || !function_exists('shell_exec')) {
            return;
        }

        @shell_exec('stty ' . escapeshellarg($state));
    }

    protected function selectWithArrowKeys(string $question, array $options, string $default = ''): string
    {
        $keys = array_keys($options);
        if (count($keys) === 0) {
            return '';
        }

        $selectedIndex = 0;
        if ($default !== '' && in_array($default, $keys, true)) {
            $selectedIndex = (int) array_search($default, $keys, true);
        }

        $numericBuffer = '';
        $rawState = $this->enableRawInput();

        try {
            while (true) {
                $lines = ["\e[1;33m{$question}\e[0m", 'Use Up/Down + Enter, or type option key'];

                foreach ($keys as $idx => $key) {
                    $label = (string) ($options[$key] ?? '');
                    if ($idx === $selectedIndex) {
                        $lines[] = "\e[7m{$key}) {$label}\e[0m";
                    } else {
                        $lines[] = "{$key}) {$label}";
                    }
                }

                if ($numericBuffer !== '') {
                    $lines[] = "Typed: {$numericBuffer}";
                }

                $this->promptLines = $lines;
                $this->renderFull();

                $char = @fread(STDIN, 1);
                if (!is_string($char) || $char === '') {
                    if (feof(STDIN)) {
                        break;
                    }
                    continue;
                }

                if ($char === "\n" || $char === "\r") {
                    if ($numericBuffer !== '') {
                        $choice = strtolower(trim($numericBuffer));
                        $numericBuffer = '';
                        if ($this->isQuitInput($choice)) {
                            $this->promptLines = [];
                            $this->renderFull();
                            return 'q';
                        }
                        if (array_key_exists($choice, $options)) {
                            $this->promptLines = [];
                            $this->renderFull();
                            return $choice;
                        }
                        continue;
                    }

                    $selectedKey = (string) ($keys[$selectedIndex] ?? '');
                    if ($selectedKey !== '' && array_key_exists($selectedKey, $options)) {
                        $this->promptLines = [];
                        $this->renderFull();
                        return $selectedKey;
                    }

                    continue;
                }

                if ($char === "\e") {
                    $seq = $char . (string) @fread(STDIN, 2);
                    if ($seq === "\e[A") {
                        $selectedIndex = max(0, $selectedIndex - 1);
                        $numericBuffer = '';
                        continue;
                    }
                    if ($seq === "\e[B") {
                        $selectedIndex = min(count($keys) - 1, $selectedIndex + 1);
                        $numericBuffer = '';
                        continue;
                    }
                }

                if (ctype_digit($char) || ctype_alpha($char)) {
                    $numericBuffer .= $char;
                    continue;
                }

                $lower = strtolower($char);
                if ($lower === 'q') {
                    $this->promptLines = [];
                    $this->renderFull();
                    return 'q';
                }

                if ($lower === 'i') {
                    $this->showCoverPreview();
                    $numericBuffer = '';
                    continue;
                }
            }
        } finally {
            $this->restoreRawInput($rawState);
            // Hide cursor again for normal rendering
            echo "\e[?25l";
        }

        if ($default !== '' && array_key_exists($default, $options)) {
            return $default;
        }

        $firstKey = (string) array_key_first($options);
        return $firstKey !== '' ? $firstKey : '';
    }

    protected function drawProgress(): void
    {
        $percent = $this->progressTotal > 0 ? round(($this->progressCurrent / $this->progressTotal) * 100) : 0;
        $barWidth = $this->width - 30;
        $filledWidth = (int) ($barWidth * ($percent / 100));
        $bar = str_repeat("█", $filledWidth) . str_repeat("░", $barWidth - $filledWidth);

        $progressText = "\e[3;4H\e[1;33mProgress:\e[0m [\e[32m{$bar}\e[0m] " .
            "{$percent}% ({$this->progressCurrent}/{$this->progressTotal})";
        $this->screen->write($progressText);
    }

    protected function drawBookDetails(): void
    {
        $y = 5;
        $footerTop = $this->getFooterSeparatorY();
        $logHeight = min(8, max(6, $this->height - 30));
        $detailsHeight = max(10, $footerTop - $y - $logHeight);
        $this->drawBox(2, $y, $this->width - 2, $detailsHeight, " Current Book Details ", "green");

        if (empty($this->currentBook)) {
            $this->screen->write("\e[" . ($y + 5) . ";10HNo book currently processing...");
            return;
        }

        $seriesNumber = $this->currentBook['series_number'] ?? null;
        $seriesSuffix = '';
        $hasNumericSeries = is_int($seriesNumber) ||
            is_float($seriesNumber) ||
            (is_string($seriesNumber) && is_numeric($seriesNumber));
        if ($hasNumericSeries) {
            $seriesNumber = (int) $seriesNumber;
            if ($seriesNumber > 0) {
                $seriesSuffix = ' #' . $seriesNumber;
            }
        }

        $sourcePath = $this->stringifyForDisplay($this->currentBook['source_path'] ?? '');
        $pathLabel = $sourcePath !== '' ? basename($sourcePath) : 'N/A';

        $directoryLabel = $this->getDirectoryLabel();

        $sourcePathLabel = $sourcePath !== '' ? $sourcePath : 'N/A';

        $coverUrl = $this->stringifyForDisplay($this->currentBook['cover_url'] ?? null) ?: '';
        $coverUrlLabel = $coverUrl !== '' ? $coverUrl : 'N/A';

        $description = $this->stringifyForDisplay($this->currentBook['description'] ?? null) ?: '';
        if ($description !== '' && strlen($description) > 140) {
            $description = substr($description, 0, 140) . '...';
        }
        $descriptionLabel = $description !== '' ? $description : 'N/A';

        $publisher = $this->stringifyForDisplay($this->currentBook['publisher'] ?? null) ?: 'N/A';
        $year = $this->stringifyForDisplay($this->currentBook['year'] ?? null) ?: 'N/A';
        $confidence = $this->stringifyForDisplay($this->currentBook['confidence'] ?? null);
        $confidenceLabel = $confidence !== '' ? ($confidence . '%') : 'N/A';

        $coverSource = $this->stringifyForDisplay($this->currentBook['cover_source'] ?? null);
        $coverSourceLabel = $coverSource !== '' ? $coverSource : 'None';
        if ($coverSourceLabel === 'None' && $coverUrl !== '') {
            $coverSourceLabel = 'Available';
        }

        if ($this->shouldRenderCoverInline()) {
            $coverSourceLabel = 'Inline';
        }

        $reserveForCover = $this->shouldRenderCoverInline();
        $coverReserveWidth = $reserveForCover ? ($this->inlineCoverCols + $this->inlineCoverPadding) : 0;

        $labelX = 5;
        $labelWidth = 12;
        $valueX = $labelX + $labelWidth + 1;

        // Ensure we never write over the right border of the box.
        // The Book Details box right border is at x = ($this->width - 1).
        $rightLimit = ($this->width - 2) - $coverReserveWidth;
        $valueMaxWidth = max(5, $rightLimit - $valueX + 1);

        $fields = [
            'Title' => $this->stringifyForDisplay($this->currentBook['title'] ?? null) ?: 'N/A',
            'Author' => $this->stringifyForDisplay($this->currentBook['author'] ?? null) ?: 'N/A',
            'Narrator' => $this->stringifyForDisplay($this->currentBook['narrator'] ?? null) ?: 'N/A',
            'Series' => ($this->stringifyForDisplay($this->currentBook['series'] ?? null) ?: 'N/A') . $seriesSuffix,
            'Genre' => $this->stringifyForDisplay($this->currentBook['genre'] ?? null) ?: 'N/A',
            'Year' => $year,
            'Publisher' => $publisher,
            'Confidence' => $confidenceLabel,
            'Cover' => $coverSourceLabel,
            'Cover URL' => $coverUrlLabel,
            'Directory' => $directoryLabel,
            'Path' => $sourcePathLabel,
            'Desc' => $descriptionLabel,
            'File' => $pathLabel,
        ];

        $row = $y + 2;
        $maxRows = ($y + $detailsHeight) - 2;
        foreach ($fields as $label => $value) {
            if ($row > $maxRows) {
                break;
            }

            $displayValue = $this->stringifyForDisplay($value);
            $maxExtraLines = $label === 'Desc' ? 3 : 1;

            $wrapped = wordwrap($displayValue, $valueMaxWidth, "\n", true);
            $lines = explode("\n", $wrapped);
            $lines = array_slice($lines, 0, 1 + $maxExtraLines);
            if (count($lines) === 0) {
                $lines = [''];
            }

            $firstLine = array_shift($lines);
            $labelText = str_pad($label . ':', $labelWidth);
            $this->screen->write("\e[{$row};{$labelX}H\e[1;36m{$labelText}\e[0m");
            $this->screen->write("\e[{$row};{$valueX}H" . substr($firstLine, 0, $valueMaxWidth));
            $row++;

            foreach ($lines as $line) {
                if ($row > $maxRows) {
                    break;
                }

                $this->screen->write(
                    "\e[{$row};{$labelX}H\e[1;36m" . str_repeat(' ', $labelWidth) . "\e[0m"
                );
                $this->screen->write("\e[{$row};{$valueX}H" . substr($line, 0, $valueMaxWidth));
                $row++;
            }
        }
    }

    protected function drawLogs(): void
    {
        $footerTop = $this->getFooterSeparatorY();
        $h = min(8, max(6, $this->height - 30));
        $y = $footerTop - $h;
        $this->drawBox(2, $y, $this->width - 2, $h, " Activity Log ", "yellow");

        $row = $y + 1;
        $displayLogs = array_slice($this->logs, -$h + 2);
        foreach ($displayLogs as $log) {
            $this->screen->write("\e[{$row};4H" . substr($log, 0, $this->width - 6));
            $row++;
        }
    }

    protected function drawFooter(): void
    {
        $separatorY = $this->getFooterSeparatorY();
        $this->screen->write("\e[{$separatorY};2H" . str_repeat("─", $this->width - 2));
        $this->screen->write("\e[" . ($this->height - 1) . ";2H" . str_repeat(' ', $this->width - 4));
        $this->screen->write("\e[" . ($this->height - 1) . ";4H\e[1;37m>\e[0m ");
    }

    protected function drawPrompt(): void
    {
        if (!$this->screen) {
            return;
        }

        $startY = $this->height - ($this->getPromptHeight() + 1);
        $endY = $this->height - 2;
        for ($y = $startY; $y <= $endY; $y++) {
            $this->screen->write("\e[{$y};2H" . str_repeat(' ', $this->width - 4));
        }

        $maxLines = $endY - $startY + 1;
        $lines = array_slice($this->promptLines, -$maxLines);
        $row = $endY - count($lines) + 1;
        foreach ($lines as $line) {
            if ($row > $endY) {
                break;
            }
            $this->screen->write("\e[{$row};4H" . substr($line, 0, $this->width - 6));
            $row++;
        }
    }

    protected function stripAnsi(string $value): string
    {
        return preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $value) ?? $value;
    }

    protected function isQuitInput(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === 'q' || $value === 'quit';
    }

    protected function padAnsi(string $value, int $width): string
    {
        $visibleLen = strlen($this->stripAnsi($value));
        if ($visibleLen >= $width) {
            return $value;
        }

        return $value . str_repeat(' ', $width - $visibleLen);
    }

    protected function truncateOptionValue(string $value, int $maxWidth): string
    {
        $value = trim($value);
        if ($maxWidth <= 0) {
            return '';
        }

        if (strlen($value) <= $maxWidth) {
            return $value;
        }

        if ($maxWidth <= 1) {
            return substr($value, 0, $maxWidth);
        }

        return substr($value, 0, $maxWidth - 1) . '…';
    }

    protected function formatOptionsAsColumns(array $options, int $columns = 3): array
    {
        $availableWidth = max(20, $this->width - 8);
        $columns = max(1, min($columns, 4));

        $maxItemLen = 0;
        foreach ($options as $key => $val) {
            $candidate = '(' . $key . ') ' . $val;
            $maxItemLen = max($maxItemLen, strlen($candidate));
        }

        $idealColWidth = min($availableWidth, max(10, $maxItemLen + 2));
        $maxColumnsThatFit = (int) max(1, floor($availableWidth / $idealColWidth));
        $columns = min($columns, $maxColumnsThatFit);
        $separator = '  ';
        $separatorWidth = strlen($separator);
        $maxCellWidth = (int) floor(
            max(10, ($availableWidth - (($columns - 1) * $separatorWidth)) / $columns)
        );

        $items = [];
        foreach ($options as $key => $val) {
            $prefix = "(\e[1;36m{$key}\e[0m) ";
            $prefixVisibleLen = strlen('(' . $key . ') ');
            $valueWidth = max(0, $maxCellWidth - $prefixVisibleLen);
            $items[] = $prefix . $this->truncateOptionValue($val, $valueWidth);
        }

        $rows = (int) ceil(count($items) / $columns);
        $lines = [];
        $colWidths = array_fill(0, $columns, 0);
        for ($c = 0; $c < $columns; $c++) {
            $maxWidth = 0;
            for ($r = 0; $r < $rows; $r++) {
                $idx = ($c * $rows) + $r;
                if (!isset($items[$idx])) {
                    continue;
                }

                $maxWidth = max($maxWidth, strlen($this->stripAnsi($items[$idx])));
            }

            $colWidths[$c] = min($maxCellWidth, $maxWidth);
        }

        for ($r = 0; $r < $rows; $r++) {
            $line = '';
            $writtenCols = 0;
            for ($c = 0; $c < $columns; $c++) {
                $idx = ($c * $rows) + $r;
                if (!isset($items[$idx])) {
                    continue;
                }

                if ($writtenCols > 0) {
                    $line .= $separator;
                }

                $line .= $this->padAnsi($items[$idx], $colWidths[$c]);
                $writtenCols++;
            }

            $lines[] = rtrim($line);
        }

        return $lines;
    }

    protected function formatList($val): string
    {
        if (is_array($val)) {
            return implode(', ', $val);
        }
        return (string) $val;
    }

    protected function stringifyForDisplay($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $normalized = array_map(function ($item) {
                if ($item === null) {
                    return null;
                }

                if (is_string($item)) {
                    return trim($item);
                }

                if (is_int($item) || is_float($item) || is_bool($item)) {
                    return (string) $item;
                }

                return null;
            }, $value);

            $normalized = array_values(array_filter($normalized, static fn ($item) => $item !== null && $item !== ''));

            return implode(', ', $normalized);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        return '';
    }

    public function updateProgress(int $current, int $total): void
    {
        $this->progressCurrent = $current;
        $this->progressTotal = $total;
        $this->renderFull();
    }

    public function logMessage(string $message): void
    {
        $this->logs[] = "[" . date('H:i:s') . "] " . $this->sanitizeLogMessage($message);
        if (count($this->logs) > 100) {
            array_shift($this->logs);
        }
        $this->renderFull();
    }

    protected function sanitizeLogMessage(string $message): string
    {
        $message = str_replace(["\r\n", "\r", "\n"], ' | ', $message);

        // Strip ANSI escape sequences that can move the cursor or change formatting
        $message = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $message) ?? $message;

        // Strip remaining control characters
        $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message) ?? $message;
        $message = preg_replace('/\s{2,}/', ' ', $message) ?? $message;

        return trim($message);
    }

    public function setCurrentBook(array $metadata): void
    {
        $this->currentBook = $metadata;
        $this->cacheCoverForCurrentBook();
        $this->renderFull();
    }

    protected function cacheCoverForCurrentBook(): void
    {
        $coverUrl = $this->stringifyForDisplay($this->currentBook['cover_url'] ?? null);
        if ($coverUrl === '' || $coverUrl === $this->cachedCoverUrl) {
            return;
        }

        $this->cleanupCoverTempFile();
        $this->cachedCoverUrl = $coverUrl;

        // Download cover to a temp file for kitten/kitty rendering
        $tempFile = tempnam(sys_get_temp_dir(), 'import_cover_');
        if (!$tempFile) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
            ],
            'https' => [
                'timeout' => 10,
            ],
        ]);

        $data = $this->downloadCoverBytes($coverUrl, $context);
        if (!$this->isSupportedImageData($data)) {
            @unlink($tempFile);
            return;
        }

        if (@file_put_contents($tempFile, $data) === false) {
            @unlink($tempFile);
            return;
        }

        $this->cachedCoverTempFile = $tempFile;
        $this->renderedCoverUrl = null;
        $this->renderedCoverSignature = null;
    }

    protected function downloadCoverBytes(string $coverUrl, mixed $context): string
    {
        $handle = @fopen($coverUrl, 'rb', false, $context);
        if ($handle === false) {
            return '';
        }

        $data = '';
        $remaining = $this->maxCoverDownloadBytes;
        while (!feof($handle) && $remaining > 0) {
            $chunkSize = min(8192, $remaining);
            $chunk = @fread($handle, $chunkSize);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        $reachedEof = feof($handle);
        @fclose($handle);

        if (!$reachedEof) {
            return '';
        }

        return $data;
    }

    protected function isSupportedImageData(string $data): bool
    {
        if ($data === '') {
            return false;
        }

        if (strlen($data) > $this->maxCoverDownloadBytes) {
            return false;
        }

        $magic = substr($data, 0, 12);

        if (str_starts_with($magic, "\xFF\xD8\xFF")) {
            return true;
        }

        if (substr($magic, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return true;
        }

        if (str_starts_with($magic, 'GIF87a') || str_starts_with($magic, 'GIF89a')) {
            return true;
        }

        if (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP') {
            return true;
        }

        return false;
    }

    protected function shouldRenderCoverInline(): bool
    {
        if (!$this->terminalSupportsKitty()) {
            return false;
        }

        if (!$this->cachedCoverTempFile || !file_exists($this->cachedCoverTempFile)) {
            return false;
        }

        return true;
    }

    public function ask(string $question, string $default = '', bool $clearPrompt = true): string
    {
        if (empty($this->promptLines)) {
            $promptLabel = $question . ':';
            $this->promptLines = ["\e[1;33m{$promptLabel}\e[0m"];
        }
        $this->renderFull();

        // Calculate where the cursor should be after the prompt
        // We need to move the cursor to the input position after echoing the whole screen
        $cursorX = 7;
        $cursorY = $this->height - 1;

        // Show cursor for interactive input
        echo "\e[?25h\e[{$cursorY};{$cursorX}H";

        while (true) {
            if (extension_loaded('readline')) {
                echo "\e[{$cursorY};{$cursorX}H\e[K";
                $input = $default !== '' ? $this->readLineWithPrefill($default) : (string) readline('');
            } else {
                $input = trim((string) fgets(STDIN));
            }

            if (strtolower(trim($input)) === 'i') {
                $this->showCoverPreview();
                continue;
            }

            if ($this->isQuitInput($input)) {
                if ($clearPrompt) {
                    $this->promptLines = [];
                    $this->renderFull();
                }
                return 'q';
            }

            if ($input === '' && $default !== '') {
                $input = $default;
            }

            if (extension_loaded('readline') && $input !== '') {
                readline_add_history($input);
            }

            if ($clearPrompt) {
                $this->promptLines = [];
                $this->renderFull();
            }

            // Hide cursor again for normal rendering
            echo "\e[?25l";
            return (string) $input;
        }
    }

    protected function showCoverPreview(): void
    {
        if ($this->terminalSupportsKitty() && $this->cachedCoverTempFile) {
            $this->renderCoverInline(true);
            $this->promptLines = ["\e[1;33mCover preview shown. Press Enter to return...\e[0m"];
            $this->renderFull();
            @fgets(STDIN);
            $this->promptLines = [];
            $this->renderFull();
            return;
        }

        $coverUrl = $this->stringifyForDisplay($this->currentBook['cover_url'] ?? null);
        if ($coverUrl === '') {
            $this->promptLines = ["\e[1;33mNo cover URL available for this book\e[0m"];
        } else {
            $this->promptLines = ["\e[1;33mCover URL:\e[0m {$coverUrl}", 'Press Enter to return...'];
        }

        $this->renderFull();
        @fgets(STDIN);
        $this->promptLines = [];
        $this->renderFull();
    }

    protected function terminalSupportsKitty(): bool
    {
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';

        return $termEnv === 'xterm-kitty'
            || $termEnv === 'xterm-ghostty'
            || strpos($termEnv, 'kitty') !== false
            || $termProgram === 'ghostty';
    }

    public function select(string $question, array $options, string $default = ''): string
    {
        if ($this->terminalSupportsArrowInput() && count($options) > 0) {
            return $this->selectWithArrowKeys($question, $options, $default);
        }

        $lines = ["\e[1;33m{$question}\e[0m"];
        $coverUrl = $this->stringifyForDisplay($this->currentBook['cover_url'] ?? null);
        if ($coverUrl !== '' && !$this->shouldRenderCoverInline()) {
            $lines[] = "Type 'i' for cover preview";
        }
        $lines = array_merge($lines, $this->formatOptionsAsColumns($options, 2));

        while (true) {
            $this->promptLines = $lines;
            $choice = strtolower(trim($this->ask('Select option', $default, false)));

            if ($this->isQuitInput($choice)) {
                $this->promptLines = [];
                $this->renderFull();
                return 'q';
            }

            if ($choice === '' && $default !== '') {
                $choice = strtolower(trim($default));
            }

            if (array_key_exists($choice, $options)) {
                $this->promptLines = [];
                $this->renderFull();
                return $choice;
            }

            $lines = array_merge([
                "\e[31mInvalid option '{$choice}'. Please choose one of the listed keys.\e[0m",
            ], $lines);
        }
    }

    public function render(): void
    {
        if (!$this->screen) {
            return;
        }

        echo "\e[H";
        $output = $this->screen->output();
        echo rtrim($output, "\n");

        $this->renderCoverInline();

        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    public function clear(): void
    {
        // Move cursor to home and clear screen
        $this->screen->write("\e[H\e[2J");
        $this->render();
        $this->cleanupCoverTempFile();
        $this->disableAlternateScreen();
    }

    protected function renderCoverInline(bool $force = false): void
    {
        if (!$this->shouldRenderCoverInline()) {
            return;
        }

        $signature = $this->width . 'x' . $this->height;
        $alreadyRendered = $this->renderedCoverUrl === $this->cachedCoverUrl
            && $this->renderedCoverSignature === $signature;
        if (!$force && $alreadyRendered) {
            return;
        }

        $this->renderedCoverUrl = $this->cachedCoverUrl;
        $this->renderedCoverSignature = $signature;

        $kittenPath = '/usr/bin/kitten';
        if (!file_exists($kittenPath) || !is_executable($kittenPath)) {
            return;
        }

        // Place the cover image inside the Book Details panel (top-right)
        $x = max(4, $this->width - ($this->inlineCoverCols + $this->inlineCoverPadding + 2));
        $y = 9;
        $cols = $this->inlineCoverCols;
        $rows = $this->inlineCoverRows;
        $place = $cols . 'x' . $rows . '@' . $x . 'x' . $y;

        $cmd = $kittenPath . ' icat --place=' . escapeshellarg($place) . ' ';
        $cmd .= escapeshellarg($this->cachedCoverTempFile);
        $cmd .= ' 2>/dev/null';
        @system($cmd);

        // Keep the cursor in a safe position after kitten renders
        echo "\e[1;1H";
    }
}
