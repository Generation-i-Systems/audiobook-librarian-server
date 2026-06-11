<?php

namespace App\Services;

use App\Contracts\ImportUIInterface;
use SoloTerm\Screen\Screen;

class ImportUIService implements ImportUIInterface
{
    protected ?Screen $screen = null;
    protected bool $plainMode = false;
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
    protected $ttyStream = null;
    protected ?string $lastKeyDebug = null;
    protected bool $interrupted = false;
    protected int $bookDetailsEndY = 0;
    protected int $logScrollOffset = 0;

    public function setPlainMode(bool $plainMode): void
    {
        $this->plainMode = $plainMode;
    }

    protected function getInputStream()
    {
        if (is_resource($this->ttyStream)) {
            return $this->ttyStream;
        }

        if (!$this->plainMode && !app()->runningUnitTests()) {
            $tty = @fopen('/dev/tty', 'rb');
            if (is_resource($tty)) {
                $this->ttyStream = $tty;
                if (function_exists('stream_set_blocking')) {
                    @stream_set_blocking($this->ttyStream, true);
                }

                return $this->ttyStream;
            }
        }

        return STDIN;
    }

    protected function isKeyDebugEnabled(): bool
    {
        $value = getenv('IMPORT_UI_KEY_DEBUG');
        if (!is_string($value) || trim($value) === '') {
            $envValue = $_ENV['IMPORT_UI_KEY_DEBUG'] ?? null;
            if (is_string($envValue) && trim($envValue) !== '') {
                $value = $envValue;
            }
        }

        if (!is_string($value) || trim($value) === '') {
            $serverValue = $_SERVER['IMPORT_UI_KEY_DEBUG'] ?? null;
            if (is_string($serverValue) && trim($serverValue) !== '') {
                $value = $serverValue;
            }
        }

        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $value = strtolower(trim($value));
        return $value !== '0' && $value !== 'false' && $value !== 'off' && $value !== 'no';
    }

    protected function formatKeyDebug(string $bytes): string
    {
        $hexParts = [];
        $printableParts = [];
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($bytes[$i]);
            $hexParts[] = strtoupper(str_pad(dechex($ord), 2, '0', STR_PAD_LEFT));

            if ($ord >= 32 && $ord <= 126) {
                $printableParts[] = $bytes[$i];
            } elseif ($bytes[$i] === "\x1B") {
                $printableParts[] = 'ESC';
            } elseif ($bytes[$i] === "\r") {
                $printableParts[] = 'CR';
            } elseif ($bytes[$i] === "\n") {
                $printableParts[] = 'LF';
            } else {
                $printableParts[] = '.';
            }
        }

        return 'KeyDebug: ' . implode(' ', $hexParts) . ' | ' . implode(' ', $printableParts);
    }

    protected function getDirectoryLabel(): string
    {
        $directoryPath = $this->stringifyForDisplay($this->currentBook['directory_path'] ?? null) ?: '';

        return $directoryPath !== '' ? $directoryPath : 'N/A';
    }

    protected function getPromptHeight(): int
    {
        $layout = $this->computeLayout();
        return $layout['menuHeight'];
    }

    protected function getFooterSeparatorY(): int
    {
        $layout = $this->computeLayout();
        return $layout['separatorY'];
    }

    protected function computeLayout(): array
    {
        $totalHeight = $this->height;

        // Title row at Y=1, progress immediately below at Y=2, book details at Y=3
        $titleY = 1;
        $progressY = 2;
        $bookDetailsY = 3;
        $bookDetailsHeight = 14;

        // Log starts right after book details
        $logY = $bookDetailsY + $bookDetailsHeight;

        // Work backwards from the bottom: fix the menu to 13 rows.
        // All remaining space goes to the activity log.
        $fixedMenuHeight = 11;
        $remaining = $totalHeight - $logY;

        // Menu gets its fixed allocation; log gets the rest
        $menuHeight = max(10, min($fixedMenuHeight, $remaining - 3));
        $menuStartY = $totalHeight - $menuHeight + 1;
        $separatorY = $menuStartY - 1;

        // Log fills from logY to just before the separator
        $logHeight = max(1, $separatorY - $logY);

        $maxLogs = max(1, $logHeight - 2);

        return [
            'titleY' => $titleY,
            'progressY' => $progressY,
            'bookDetailsY' => $bookDetailsY,
            'bookDetailsHeight' => $bookDetailsHeight,
            'logY' => $logY,
            'logHeight' => $logHeight,
            'separatorY' => $separatorY,
            'menuStartY' => $menuStartY,
            'menuHeight' => $menuHeight,
            'maxLogs' => $maxLogs,
        ];
    }

    public function initialize(int $width, int $height): void
    {
        if ($this->plainMode) {
            $this->width = $width;
            $this->height = $height;
            return;
        }
        // Avoid writing to the last row/column of the terminal.
        // Many terminals will auto-wrap when drawing the bottom-right corner, which causes a scroll.
        $this->width = max(40, $width - 1);
        $this->height = max(20, $height - 1);
        $layout = $this->computeLayout();
        $this->maxLogs = $layout['maxLogs'];
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

    public function requestInterrupt(): void
    {
        $this->interrupted = true;
    }

    public function restoreTerminalState(): void
    {
        if (function_exists('shell_exec')) {
            @shell_exec('stty sane < /dev/tty');
        }

        // Show cursor + restore normal screen buffer
        echo "\e[?25h\e[?1049l";
        $this->alternateScreenEnabled = false;
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

        $this->clearInlineCoverRendering();
    }

    public function drawInitialLayout(): void
    {
        if ($this->plainMode) {
            return;
        }
        $this->enableAlternateScreen();
        $this->render();
    }

    protected function renderFull(): void
    {
        if (!$this->screen) {
            return;
        }

        // Clear buffer
        $this->screen->write("\e[H\e[J");

        $layout = $this->computeLayout();

        // Draw title row
        $titleY = $layout['titleY'];
        $title = " Audiobook Librarian Import ";
        $titleLen = mb_strwidth($title);
        $line = str_repeat('─', max(0, $this->width - $titleLen - 2));
        $this->screen->write("\e[{$titleY};1H\e[36m─{$title}{$line}─\e[0m");

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

        $block = '▒';

        // Draw top border
        $this->screen->write("\e[{$y};{$x}H{$c}" . str_repeat($block, $w) . "{$reset}");

        // Draw sides
        for ($i = 1; $i < $h - 1; $i++) {
            $currentY = $y + $i;
            $this->screen->write("\e[{$currentY};{$x}H{$c}{$block}{$reset}");
            $this->screen->write("\e[{$currentY};" . ($x + $w - 1) . "H{$c}{$block}{$reset}");
        }

        // Draw bottom border
        $this->screen->write("\e[" . ($y + $h - 1) . ";{$x}H{$c}" . str_repeat($block, $w) . "{$reset}");

        if ($title) {
            $titleLen = mb_strlen($title);
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

    protected function parseTerminalKeySequence(string $char): array
    {
        $rawBytes = $char;

        if ($char === "\r" || $char === "\n") {
            if ($this->isKeyDebugEnabled()) {
                $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
            }
            return ['enter'];
        }

        if ($char === "\x7F" || $char === "\x08") {
            if ($this->isKeyDebugEnabled()) {
                $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
            }
            return ['backspace'];
        }

        if ($char !== "\x1B") {
            if ($this->isKeyDebugEnabled()) {
                $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
            }
            return ['char', $char];
        }

        $stream = $this->getInputStream();

        $intro = fgetc($stream);
        if ($intro === false) {
            if ($this->isKeyDebugEnabled()) {
                $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
            }
            return [];
        }

        $rawBytes .= $intro;

        // Application cursor mode (common in some terminals)
        if ($intro === 'O') {
            $code = fgetc($stream);
            if ($code === false) {
                if ($this->isKeyDebugEnabled()) {
                    $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
                }
                return [];
            }

            $rawBytes .= $code;

            if ($this->isKeyDebugEnabled()) {
                $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
            }

            return match ($code) {
                'A' => ['up'],
                'B' => ['down'],
                'C' => ['right'],
                'D' => ['left'],
                'H' => ['home'],
                'F' => ['end'],
                default => [],
            };
        }

        // CSI sequences
        if ($intro !== '[') {
            if ($this->isKeyDebugEnabled()) {
                $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
            }
            return [];
        }

        $sequence = '';
        for ($i = 0; $i < 8; $i++) {
            $c = fgetc($stream);
            if ($c === false) {
                break;
            }

            $sequence .= $c;

            // Final byte for CSI is typically in @A-Z[\]^_`a-z{|}~
            if (preg_match('/[@-~]/', $c) === 1) {
                break;
            }
        }

        $rawBytes .= $sequence;

        if ($this->isKeyDebugEnabled()) {
            $this->lastKeyDebug = $this->formatKeyDebug($rawBytes);
        }

        if ($sequence === '') {
            return [];
        }

        $final = substr($sequence, -1);

        // Arrow keys (including CSI with modifiers, e.g. "1;5A")
        if (in_array($final, ['A', 'B', 'C', 'D'], true)) {
            return match ($final) {
                'A' => ['up'],
                'B' => ['down'],
                'C' => ['right'],
                'D' => ['left'],
                default => ['interrupt'],
            };
        }

        // Home/End variants
        if ($final === 'H') {
            return ['home'];
        }
        if ($final === 'F') {
            return ['end'];
        }

        // Tilde-terminated keys like "3~" (delete), "1~"/"7~" (home), "4~"/"8~" (end)
        if ($final === '~') {
            $num = rtrim($sequence, '~');
            return match ($num) {
                '3' => ['delete'],
                '1', '7' => ['home'],
                '4', '8' => ['end'],
                '5' => ['page-up'],
                '6' => ['page-down'],
                default => [],
            };
        }

        return [];
    }

    protected static function applyLineEditorAction(array $state, array $action): array
    {
        $buffer = (string) ($state['buffer'] ?? '');
        $cursor = (int) ($state['cursor'] ?? mb_strlen($buffer));

        if ($cursor < 0) {
            $cursor = 0;
        }
        if ($cursor > mb_strlen($buffer)) {
            $cursor = mb_strlen($buffer);
        }

        $type = $action[0] ?? '';

        if ($type === 'char') {
            $char = (string) ($action[1] ?? '');
            if ($char !== '' && $char !== "\x1B") {
                $buffer = mb_substr($buffer, 0, $cursor) . $char . mb_substr($buffer, $cursor);
                $cursor += mb_strlen($char);
            }
        } elseif ($type === 'left') {
            $cursor = max(0, $cursor - 1);
        } elseif ($type === 'right') {
            $cursor = min(mb_strlen($buffer), $cursor + 1);
        } elseif ($type === 'home') {
            $cursor = 0;
        } elseif ($type === 'end') {
            $cursor = mb_strlen($buffer);
        } elseif ($type === 'backspace') {
            if ($cursor > 0) {
                $buffer = mb_substr($buffer, 0, $cursor - 1) . mb_substr($buffer, $cursor);
                $cursor -= 1;
            }
        } elseif ($type === 'delete') {
            if ($cursor < mb_strlen($buffer)) {
                $buffer = mb_substr($buffer, 0, $cursor) . mb_substr($buffer, $cursor + 1);
            }
        }

        return ['buffer' => $buffer, 'cursor' => $cursor];
    }

    protected function renderLineEditorState(int $cursorY, int $cursorX, array $state): void
    {
        $buffer = (string) ($state['buffer'] ?? '');
        $cursor = (int) ($state['cursor'] ?? mb_strlen($buffer));

        echo "\e[{$cursorY};{$cursorX}H\e[K";
        echo $buffer;
        echo "\e[{$cursorY};" . ($cursorX + $cursor) . "H";
    }

    protected static function moveGridSelection(
        int $selectedIndex,
        string $direction,
        int $rows,
        int $columns,
        int $count
    ): int {
        if ($count <= 0 || $rows <= 0 || $columns <= 0) {
            return 0;
        }

        $selectedIndex = max(0, min($count - 1, $selectedIndex));

        $row = $selectedIndex % $rows;
        $col = (int) floor($selectedIndex / $rows);

        if ($direction === 'up') {
            $row = max(0, $row - 1);
        } elseif ($direction === 'down') {
            $row = min($rows - 1, $row + 1);
        } elseif ($direction === 'left') {
            $col = max(0, $col - 1);
        } elseif ($direction === 'right') {
            $col = min($columns - 1, $col + 1);
        }

        $candidate = ($col * $rows) + $row;
        if ($candidate < $count) {
            return $candidate;
        }

        while ($row > 0) {
            $row--;
            $candidate = ($col * $rows) + $row;
            if ($candidate < $count) {
                return $candidate;
            }
        }

        return $selectedIndex;
    }

    protected static function resolveDefaultSelectionIndex(array $keys, string $default): int
    {
        if ($default === '') {
            return 0;
        }

        foreach ($keys as $index => $key) {
            if ((string) $key === (string) $default) {
                return $index;
            }
        }

        return 0;
    }

    protected static function shouldAutoCommitChoice(string $buffer, array $options): bool
    {
        $buffer = strtolower(trim($buffer));
        if ($buffer === '' || !array_key_exists($buffer, $options)) {
            return false;
        }

        foreach (array_keys($options) as $key) {
            $key = strtolower((string) $key);
            if ($key !== $buffer && str_starts_with($key, $buffer)) {
                return false;
            }
        }

        return true;
    }

    protected function readLineWithEditableDefault(string $default, int $cursorY, int $cursorX): string
    {
        $rawState = $this->enableRawInput();

        $stream = $this->getInputStream();

        try {
            $state = ['buffer' => $default, 'cursor' => mb_strlen($default)];
            $this->renderLineEditorState($cursorY, $cursorX, $state);

            while (true) {
                if ($this->interrupted) {
                    return '';
                }

                $char = fgetc($stream);
                if ($char === false) {
                    return (string) ($state['buffer'] ?? '');
                }

                $action = $this->parseTerminalKeySequence($char);
                if ($action === []) {
                    continue;
                }

                $actionType = (string) ($action[0] ?? '');

                if ($actionType === 'enter') {
                    return (string) ($state['buffer'] ?? '');
                }

                if ($actionType === 'page-up') {
                    $this->scrollLog('up');
                    $this->renderLineEditorState($cursorY, $cursorX, $state);
                    continue;
                }

                if ($actionType === 'page-down') {
                    $this->scrollLog('down');
                    $this->renderLineEditorState($cursorY, $cursorX, $state);
                    continue;
                }

                $state = self::applyLineEditorAction($state, $action);
                $this->renderLineEditorState($cursorY, $cursorX, $state);
            }
        } finally {
            $this->restoreRawInput($rawState);
        }
    }

    protected function enableRawInput(): ?string
    {
        if (!function_exists('shell_exec') || $this->plainMode || app()->runningUnitTests()) {
            return null;
        }

        // Check if /dev/tty is actually accessible and a TTY
        if (!@is_readable('/dev/tty') || !@stream_isatty(fopen('/dev/tty', 'r'))) {
            return null;
        }

        $state = (string) @shell_exec('stty -g < /dev/tty 2>/dev/null');
        if (trim($state) === '') {
            return null;
        }

        @shell_exec('stty -icanon -echo min 1 time 0 < /dev/tty 2>/dev/null');

        $stream = $this->getInputStream();
        if (function_exists('stream_set_blocking') && is_resource($stream)) {
            @stream_set_blocking($stream, true);
        }

        return trim($state);
    }

    protected function restoreRawInput(?string $state): void
    {
        if ($state === null || !function_exists('shell_exec')) {
            return;
        }

        @shell_exec('stty ' . escapeshellarg($state) . ' < /dev/tty');
    }

    protected function drainPendingInput(mixed $stream): void
    {
        if (!is_resource($stream) || !function_exists('stream_select')) {
            return;
        }

        $read = [$stream];
        $write = $except = [];
        while (@stream_select($read, $write, $except, 0, 0) > 0) {
            fgetc($stream);
            $read = [$stream];
            $write = $except = [];
        }
    }

    protected function selectWithArrowKeys(string $question, array $options, string $default = '', ?callable $onSelectionChange = null): string
    {
        $keys = array_keys($options);
        if (count($keys) === 0) {
            return '';
        }

        $selectedIndex = self::resolveDefaultSelectionIndex($keys, $default);

        $numericBuffer = '';
        $lastNotifiedKey = null;
        $rawState = $this->enableRawInput();

        $stream = $this->getInputStream();

        try {
            while (true) {
                if ($this->interrupted) {
                    $this->promptLines = [];
                    $this->renderFull();
                    return false;
                }

                $desiredColumns = 3;
                $availableWidth = max(20, $this->width - 8);
                $maxItemLen = 0;
                foreach ($options as $key => $val) {
                    $candidate = '(' . $key . ') ' . $val;
                    $maxItemLen = max($maxItemLen, mb_strlen($candidate));
                }
                $idealColWidth = min($availableWidth, max(10, $maxItemLen + 2));
                $maxColumnsThatFit = (int) max(1, floor($availableWidth / $idealColWidth));
                $columns = min(max(1, $desiredColumns), min(4, $maxColumnsThatFit));
                $rows = (int) ceil(count($keys) / $columns);

                $selectedKey = (string) ($keys[$selectedIndex] ?? '');

                if ($onSelectionChange !== null && $selectedKey !== $lastNotifiedKey) {
                    $lastNotifiedKey = $selectedKey;
                    $onSelectionChange($selectedKey);
                }

                $lines = [
                    "\e[1;33m{$question}\e[0m",
                    'Use arrows + Enter (Up/Down/Left/Right), or type option key',
                ];

                $gridOptions = [];
                foreach ($keys as $idx => $key) {
                    $label = (string) ($options[$key] ?? '');
                    if ((string) $key === $selectedKey) {
                        $gridOptions[$key] = "\e[7m{$label}\e[0m";
                    } else {
                        $gridOptions[$key] = $label;
                    }
                }

                $lines = array_merge($lines, $this->formatOptionsAsColumns($gridOptions, $columns));

                if ($numericBuffer !== '') {
                    $lines[] = "Typed: {$numericBuffer}";
                }

                if ($this->isKeyDebugEnabled()) {
                    $lines[] = 'KeyDebug: ON (set IMPORT_UI_KEY_DEBUG=1)';
                    if ($this->lastKeyDebug !== null) {
                        $lines[] = $this->lastKeyDebug;
                    }
                }

                $this->promptLines = $lines;
                $this->renderFull();

                $char = fgetc($stream);
                if ($char === false || $char === '') {
                    if (feof($stream)) {
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

                $action = $this->parseTerminalKeySequence($char);
                $actionType = (string) ($action[0] ?? '');
                if ($actionType === 'up' || $actionType === 'down' || $actionType === 'left' || $actionType === 'right') {
                    $selectedIndex = self::moveGridSelection(
                        $selectedIndex,
                        $actionType,
                        max(1, $rows),
                        max(1, $columns),
                        count($keys)
                    );
                    $numericBuffer = '';
                    continue;
                }

                if ($actionType === 'page-up') {
                    $this->scrollLog('up');
                    continue;
                }

                if ($actionType === 'page-down') {
                    $this->scrollLog('down');
                    continue;
                }

                if (ctype_digit($char) || ctype_alpha($char)) {
                    $numericBuffer .= $char;

                    $choice = strtolower(trim($numericBuffer));
                    if ($this->isQuitInput($choice)) {
                        $this->promptLines = [];
                        $this->renderFull();
                        return 'q';
                    }

                    if (self::shouldAutoCommitChoice($choice, $options)) {
                        $this->drainPendingInput($stream);
                        $this->promptLines = [];
                        $this->renderFull();
                        return $choice;
                    }

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
        $layout = $this->computeLayout();
        $y = $layout['progressY'];
        $percent = $this->progressTotal > 0 ? round(($this->progressCurrent / $this->progressTotal) * 100) : 0;
        $barWidth = $this->width - 30;
        $filledWidth = (int) ($barWidth * ($percent / 100));
        $bar = str_repeat("█", $filledWidth) . str_repeat("░", $barWidth - $filledWidth);

        $progressText = "\e[{$y};4H\e[1;33mProgress:\e[0m [\e[32m{$bar}\e[0m] " .
            "{$percent}% ({$this->progressCurrent}/{$this->progressTotal})";
        $this->screen->write($progressText);
    }

    protected function drawBookDetails(): void
    {
        $layout = $this->computeLayout();
        $y = $layout['bookDetailsY'];
        $fixedHeight = $layout['bookDetailsHeight'];

        if (empty($this->currentBook)) {
            $this->drawBox(2, $y, $this->width - 2, $fixedHeight, " Current Book Details ", "green");
            $this->bookDetailsEndY = $y + $fixedHeight;
            $this->screen->write("\e[" . ($y + 2) . ";10HNo book currently processing...");
            return;
        }

        $seriesNumber = $this->currentBook['series_number'] ?? null;
        $seriesSuffix = '';
        $hasNumericSeries = is_int($seriesNumber) ||
            is_float($seriesNumber) ||
            (is_string($seriesNumber) && is_numeric($seriesNumber));
        if ($hasNumericSeries) {
            $seriesNumber = str_contains((string) $seriesNumber, '.') ? (float) $seriesNumber : (int) $seriesNumber;
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
        if ($description !== '' && mb_strlen($description) > 140) {
            $description = mb_substr($description, 0, 140) . '...';
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
            'Directory' => $directoryLabel,
            'Path' => $sourcePathLabel,
            'Desc' => $descriptionLabel,
        ];

        // Only add Cover URL if it's not from embedded data
        $isEmbeddedCover = strpos($coverUrl, sys_get_temp_dir()) === 0 && strpos($coverUrl, 'embedded_cover_') !== false;
        if (!$isEmbeddedCover && $coverUrl !== '') {
            $fields['Cover URL'] = $coverUrlLabel;
        }

        $detailsHeight = $fixedHeight;
        $this->drawBox(2, $y, $this->width - 2, $detailsHeight, " Current Book Details ", "green");
        $this->bookDetailsEndY = $y + $detailsHeight;

        $row = $y + 2;
        $maxRows = ($y + $detailsHeight) - 2;
        foreach ($fields as $label => $value) {
            if ($row > $maxRows) {
                break;
            }

            $displayValue = $this->stringifyForDisplay($value);

            // Truncate long values to fit on one line
            if (mb_strlen($displayValue) > $valueMaxWidth) {
                $displayValue = mb_substr($displayValue, 0, $valueMaxWidth - 3) . '...';
            }

            $labelText = str_pad($label . ':', $labelWidth);
            $this->screen->write("\e[{$row};{$labelX}H\e[1;36m{$labelText}\e[0m");
            $this->screen->write("\e[{$row};{$valueX}H" . $displayValue);
            $row++;
        }
    }

    protected function drawLogs(): void
    {
        $layout = $this->computeLayout();
        $y = $layout['logY'];
        $h = $layout['logHeight'];
        $maxLogs = $layout['maxLogs'];

        $totalLogs = count($this->logs);
        $maxOffset = max(0, $totalLogs - $maxLogs);
        $this->logScrollOffset = min($this->logScrollOffset, $maxOffset);
        $offset = $this->logScrollOffset;

        $title = $offset > 0 ? " Activity Log [PgDn↓ — {$offset} lines below] " : " Activity Log ";

        $this->drawBox(2, $y, $this->width - 2, $h, $title, "yellow");

        $displayLogs = $offset === 0 ? array_slice($this->logs, -$maxLogs) : array_slice($this->logs, -($maxLogs + $offset), $maxLogs);

        $row = $y + 1;
        foreach ($displayLogs as $log) {
            $this->screen->write("\e[{$row};4H" . mb_substr($log, 0, $this->width - 6));
            $row++;
        }
    }

    protected function scrollLog(string $direction): void
    {
        $layout = $this->computeLayout();
        $maxLogs = $layout['maxLogs'];
        $maxOffset = max(0, count($this->logs) - $maxLogs);

        if ($direction === 'up') {
            $this->logScrollOffset = min($maxOffset, $this->logScrollOffset + $maxLogs);
        } else {
            $this->logScrollOffset = max(0, $this->logScrollOffset - $maxLogs);
        }

        $this->renderFull();
    }

    protected function drawFooter(): void
    {
        $layout = $this->computeLayout();
        $separatorY = $layout['separatorY'];

        $this->screen->write("\e[{$separatorY};2H" . str_repeat("─", $this->width - 2));
    }

    protected function drawPrompt(): void
    {
        if (!$this->screen) {
            return;
        }

        $layout = $this->computeLayout();
        $startY = $layout['menuStartY'];
        $promptHeight = $layout['menuHeight'];
        $endY = $startY + $promptHeight - 1;

        // Clear the prompt area
        for ($y = $startY; $y <= $endY; $y++) {
            $this->screen->write("\e[{$y};2H" . str_repeat(' ', $this->width - 4));
        }

        // Display prompt lines from the beginning (not the end) to show titles
        $lines = array_slice($this->promptLines, 0, $promptHeight);
        $row = $startY;
        foreach ($lines as $line) {
            if ($row > $endY) {
                break;
            }
            $this->screen->write("\e[{$row};4H" . mb_substr($line, 0, $this->width - 6));
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
        $visibleLen = mb_strlen($this->stripAnsi($value));
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

        if (mb_strlen($value) <= $maxWidth) {
            return $value;
        }

        if ($maxWidth <= 1) {
            return mb_substr($value, 0, $maxWidth);
        }

        return mb_substr($value, 0, $maxWidth - 1) . '…';
    }

    protected function formatOptionsAsColumns(array $options, int $columns = 3): array
    {
        $availableWidth = max(20, $this->width - 8);
        $columns = max(1, min($columns, 4));

        $maxItemLen = 0;
        foreach ($options as $key => $val) {
            $candidate = '(' . $key . ') ' . $val;
            $maxItemLen = max($maxItemLen, mb_strlen($candidate));
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

                $maxWidth = max($maxWidth, mb_strlen($this->stripAnsi($items[$idx])));
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
        $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        if ($this->plainMode) {
            echo $message . PHP_EOL;
            return;
        }
        $this->logs[] = $message;
        $this->pollScrollKeys();
        $this->renderFull();
    }

    protected function pollScrollKeys(): void
    {
        if (!$this->terminalSupportsArrowInput()) {
            return;
        }

        $stream = $this->getInputStream();
        if (!is_resource($stream)) {
            return;
        }

        // Must enable raw mode BEFORE stream_select. In canonical (cooked) mode
        // the kernel buffers ESC sequences until a newline arrives, so stream_select
        // would see zero bytes and return immediately — swallowing the PageUp/PageDown.
        $rawState = $this->enableRawInput();
        try {
            $read = [$stream];
            $write = $except = [];
            if (@stream_select($read, $write, $except, 0, 0) <= 0) {
                return;
            }

            while (true) {
                $read = [$stream];
                $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 0) <= 0) {
                    break;
                }

                $char = fgetc($stream);
                if ($char === false) {
                    break;
                }

                $action = $this->parseTerminalKeySequence($char);
                $actionType = $action[0] ?? '';

                if ($actionType === 'page-up') {
                    $layout = $this->computeLayout();
                    $maxLogs = $layout['maxLogs'];
                    $maxOffset = max(0, count($this->logs) - $maxLogs);
                    $this->logScrollOffset = min($maxOffset, $this->logScrollOffset + $maxLogs);
                } elseif ($actionType === 'page-down') {
                    $maxLogs = $this->computeLayout()['maxLogs'];
                    $this->logScrollOffset = max(0, $this->logScrollOffset - $maxLogs);
                }
                // Other keys consumed here would otherwise accumulate as stale
                // input that confuses the next prompt.
            }
        } finally {
            $this->restoreRawInput($rawState);
        }
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

        // Clear render state to force re-render for new book
        $this->renderedCoverUrl = null;
        $this->renderedCoverSignature = null;

        $this->renderFull();
    }

    protected function cacheCoverForCurrentBook(): void
    {
        $coverUrl = $this->stringifyForDisplay($this->currentBook['cover_url'] ?? null);
        if ($coverUrl === '') {
            $this->cleanupCoverTempFile();
            return;
        }

        // For local temp files (like embedded covers), always re-cache to ensure freshness
        $isTempFile = strpos($coverUrl, sys_get_temp_dir()) === 0;
        if ($isTempFile) {
            $this->cleanupCoverTempFile();
            $this->cachedCoverUrl = $coverUrl;
            $this->cacheCoverFromUrl($coverUrl);
            return;
        }

        // Check if we have a valid cached cover for this URL
        if (
            $coverUrl === $this->cachedCoverUrl
            && $this->cachedCoverTempFile
            && file_exists($this->cachedCoverTempFile)
        ) {
            return;
        }

        $this->cleanupCoverTempFile();
        $this->cachedCoverUrl = $coverUrl;
        $this->cacheCoverFromUrl($coverUrl);
    }

    private function cacheCoverFromUrl(string $coverUrl): void
    {
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

    protected function buildPromptLabel(string $question, string $default): string
    {
        $label = $question;

        if ($default !== '') {
            $defaultDisplay = $this->stringifyForDisplay($default);
            if ($defaultDisplay !== '') {
                $label .= ' [' . $defaultDisplay . ']';
            }
        }

        return $label . ':';
    }

    public function ask(string $question, string $default = '', bool $clearPrompt = true): string
    {
        if (empty($this->promptLines)) {
            $promptLabel = $this->buildPromptLabel($question, $default);
            $this->promptLines = ["\e[1;33m{$promptLabel}\e[0m"];
        }
        $this->renderFull();

        // Dynamic cursor position at bottom of menu area
        $layout = $this->computeLayout();
        $cursorX = 7;
        $cursorY = $layout['menuStartY'] + $layout['menuHeight'] - 2;

        // Show cursor for interactive input
        echo "\e[?25h\e[{$cursorY};{$cursorX}H";

        while (true) {
            echo "\e[{$cursorY};{$cursorX}H\e[K";

            if ($default !== '' && $this->terminalSupportsArrowInput()) {
                $input = $this->readLineWithEditableDefault($default, $cursorY, $cursorX);
            } elseif (extension_loaded('readline')) {
                $input = $default !== '' ? $this->readLineWithPrefill($default) : (string) readline('');
            } else {
                $input = trim((string) fgets(STDIN));
            }

            if ($this->interrupted) {
                if ($clearPrompt) {
                    $this->promptLines = [];
                    $this->renderFull();
                }
                return '';
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
            $choice = $this->selectWithArrowKeys($question, $options, $default);
            if ($this->interrupted) {
                return '';
            }

            return $choice;
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

    /**
     * Like select(), but updates the inline cover preview as the user navigates between options.
     *
     * @param array<array-key, string> $options         Key => label map
     * @param array<array-key, string|null> $coverPathByKey  Key => local file path (or URL) for the cover preview
     */
    public function selectCoverWithPreview(string $question, array $options, string $default, array $coverPathByKey): string
    {
        if (!$this->terminalSupportsArrowInput()) {
            return $this->select($question, $options, $default);
        }

        $savedBook = $this->currentBook;

        $choice = $this->selectWithArrowKeys(
            $question,
            $options,
            $default,
            function (string $key) use ($coverPathByKey, $savedBook): void {
                $path = $coverPathByKey[$key] ?? null;
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
            }
        );

        // Restore original book context (the caller will setCurrentBook once confirmed)
        $this->currentBook = $savedBook;
        $this->cleanupCoverTempFile();

        return $choice;
    }

    public function render(): void
    {
        if (!$this->screen) {
            return;
        }

        echo "\e[H";
        $output = $this->screen->output();
        echo rtrim($output, "\n");

        // Always force re-render for embedded covers to ensure they update properly
        $coverUrl = $this->stringifyForDisplay($this->currentBook['cover_url'] ?? null);
        $isTempFile = $coverUrl && strpos($coverUrl, sys_get_temp_dir()) === 0;
        $forceRender = $isTempFile;

        $this->renderCoverInline($forceRender);

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

        // Only clear if the cover URL is actually different (not just force render)
        $shouldClear = ($this->renderedCoverUrl !== null && $this->renderedCoverUrl !== $this->cachedCoverUrl);

        if ($shouldClear) {
            $this->clearInlineCoverRendering();
        }

        $this->renderedCoverUrl = $this->cachedCoverUrl;
        $this->renderedCoverSignature = $signature;

        $kittenPath = '/usr/bin/kitten';
        if (!file_exists($kittenPath) || !is_executable($kittenPath)) {
            return;
        }

        // Place the cover image inside the Book Details panel (top-right)
        $x = max(4, $this->width - ($this->inlineCoverCols + $this->inlineCoverPadding + 2));
        $y = 4; // Moved up 3 rows
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

    protected function clearInlineCoverRendering(): void
    {
        if (!$this->terminalSupportsKitty()) {
            return;
        }

        $kittenPath = '/usr/bin/kitten';
        if (!file_exists($kittenPath) || !is_executable($kittenPath)) {
            return;
        }

        // Use the standard icat clear command
        @system($kittenPath . ' icat --clear 2>/dev/null');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $defaultStr = $default ? 'Y/n' : 'y/N';
        $response = $this->ask("{$question} [{$defaultStr}]", $default ? 'y' : 'n');
        $response = strtolower(trim($response));
        if ($response === '') {
            return $default;
        }
        return $response === 'y' || $response === 'yes';
    }

    public function progress(string $label, iterable $items, callable $callback): void
    {
        $itemsArray = is_array($items) ? $items : iterator_to_array($items);
        $total = count($itemsArray);

        $this->logMessage($label);
        foreach ($itemsArray as $index => $item) {
            $this->updateProgress($index + 1, $total);
            $callback($index, $item);
        }
    }

    public function spin(string $message, callable $callback): mixed
    {
        $this->logMessage("{$message}...");
        $result = $callback();
        $this->logMessage("{$message}... done");
        return $result;
    }

    public function info(string $message): void
    {
        $this->logMessage($message);
    }

    public function warning(string $message): void
    {
        $this->logMessage("⚠️  {$message}");
    }

    public function error(string $message): void
    {
        $this->logMessage("❌ {$message}");
    }

    public function table(array $headers, array $rows): void
    {
        if ($this->plainMode) {
            $colWidths = [];
            foreach ($headers as $i => $header) {
                $colWidths[$i] = mb_strlen((string) $header);
            }
            foreach ($rows as $row) {
                foreach ($row as $i => $cell) {
                    $colWidths[$i] = max($colWidths[$i] ?? 0, mb_strlen((string) $cell));
                }
            }

            $line = '';
            foreach ($headers as $i => $header) {
                $line .= mb_str_pad((string) $header, $colWidths[$i] + 2);
            }
            echo $line . PHP_EOL;
            echo str_repeat('-', mb_strlen($line)) . PHP_EOL;

            foreach ($rows as $row) {
                $line = '';
                foreach ($row as $i => $cell) {
                    $line .= mb_str_pad((string) $cell, $colWidths[$i] + 2);
                }
                echo $line . PHP_EOL;
            }
            return;
        }

        $lines = [];
        $colWidths = [];
        foreach ($headers as $i => $header) {
            $colWidths[$i] = mb_strlen((string) $header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $colWidths[$i] = max($colWidths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $headerLine = '';
        foreach ($headers as $i => $header) {
            $headerLine .= mb_str_pad((string) $header, $colWidths[$i] + 2);
        }
        $lines[] = "\e[1;36m" . $headerLine . "\e[0m";
        $lines[] = str_repeat('-', mb_strlen($headerLine));

        foreach ($rows as $row) {
            $line = '';
            foreach ($row as $i => $cell) {
                $line .= mb_str_pad((string) $cell, $colWidths[$i] + 2);
            }
            $lines[] = $line;
        }

        $this->promptLines = $lines;
        $this->renderFull();
    }
}
