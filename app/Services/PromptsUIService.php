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

    public function initialize(int $width, int $height): void
    {
        $this->width = max(40, $width);
        $this->height = max(20, $height);
    }

    /**
     * Strip invalid UTF-8 bytes so soloterm's grapheme splitter never crashes.
     */
    protected function clean(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    protected function cleanOptions(array $options): array
    {
        return array_map(fn ($v) => is_string($v) ? $this->clean($v) : $v, $options);
    }

    public function ask(string $question, string $default = ''): string
    {
        $question = $this->clean($question);
        $default  = $this->clean($default);

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
        $question = $this->clean($question);
        $options  = $this->cleanOptions($options);

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
        $question = $this->clean($question);
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
        $headers = $this->cleanOptions($headers);
        $rows    = array_map(fn ($row) => is_array($row) ? $this->cleanOptions($row) : $row, $rows);

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
        $message = $this->clean($message);
        if ($this->plainMode) {
            echo "[INFO] {$message}\n";
            return;
        }
        info($message);
    }

    public function warning(string $message): void
    {
        $message = $this->clean($message);
        if ($this->plainMode) {
            echo "[WARNING] {$message}\n";
            return;
        }
        warning($message);
    }

    public function error(string $message): void
    {
        $message = $this->clean($message);
        if ($this->plainMode) {
            echo "[ERROR] {$message}\n";
            return;
        }
        error($message);
    }

    public function logMessage(string $message): void
    {
        $message = $this->clean($message);
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
            return;
        }

        // Display cover if available
        $this->displayCurrentBookCover();

        // Display metadata table
        $this->displayCurrentBookTable();
    }

    protected function displayCurrentBookCover(): void
    {
        $url = $this->currentBook['cover_url'] ?? null;
        if (!$url) {
            return;
        }

        // Check if we have cover data embedded or local file
        if (!empty($this->currentBook['cover_data']) && isset($this->currentBook['cover_is_local_file'])) {
            // It's already a local path from buildUiMetadata
        }

        $this->displayCoverImage($url);
    }

    protected function displayCurrentBookTable(): void
    {
        $metadata = $this->currentBook;

        $arrayToString = function ($value) {
            if (is_array($value)) {
                $filtered = array_filter($value, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(', ', $filtered);
            }
            return (string)($value ?? 'N/A');
        };

        $formatAuthors = function ($authors) {
            if (is_array($authors)) {
                $filtered = array_filter($authors, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(' & ', $filtered);
            }
            return (string)($authors ?? 'N/A');
        };

        $displaySeries = '';
        if (!empty($metadata['series'])) {
            $seriesName = is_array($metadata['series']) ? implode(', ', $metadata['series']) : $metadata['series'];
            // Simple cleaning since we don't have the full service logic here
            $cleanedSeriesName = trim($seriesName);
            $displaySeries = $cleanedSeriesName . (!empty($metadata['series_number']) ? " #{$metadata['series_number']}" : '');
        }

        $headers = ['Field', 'Value'];
        $rows = [
            ['Title', $arrayToString($metadata['title'] ?? null)],
            ['Author', $formatAuthors($metadata['author'] ?? null)],
            ['Narrator', $arrayToString($metadata['narrator'] ?? null)],
            ['Series', $displaySeries],
            ['Genre', $arrayToString($metadata['genre'] ?? null)],
            ['Year', $metadata['year'] ?? 'N/A'],
            ['Publisher', $arrayToString($metadata['publisher'] ?? null)],
            ['Language', $metadata['language'] ?? 'N/A'],
            ['ISBN', $metadata['isbn'] ?? 'N/A'],
            ['Confidence', ($metadata['confidence'] ?? 0) . '%'],
        ];

        if (!empty($metadata['source_path'])) {
            $rows[] = ['Source Path', $metadata['source_path']];
        }

        if (!empty($metadata['directory_path'])) {
            $rows[] = ['Directory Path', $metadata['directory_path']];
        }

        if (!empty($metadata['description'])) {
            $description = $metadata['description'];
            if (strlen($description) > 80) {
                $description = substr($description, 0, 80) . '...';
            }
            $rows[] = ['Description', $description];
        }

        if (!empty($metadata['cover_source'])) {
            $rows[] = ['Cover Source', $metadata['cover_source']];
        }

        table($headers, $rows);
    }

    protected function displayCoverImage(string $imageUrl): void
    {
        $term = getenv('TERM_PROGRAM') ?: getenv('TERM');
        $termEnv = getenv('TERM') ?: '';
        $termProgram = getenv('TERM_PROGRAM') ?: '';

        $kittySupport = $termEnv === 'xterm-kitty' ||
            $termEnv === 'xterm-ghostty' ||
            strpos($termEnv, 'kitty') !== false ||
            $termProgram === 'ghostty';

        if ($kittySupport || in_array($term, ['Ghostty', 'iTerm.app', 'WezTerm'])) {
            try {
                $imageData = @file_get_contents($imageUrl);

                if ($imageData) {
                    info("📸 Cover Preview: {$imageUrl}");

                    if ($kittySupport) {
                        $this->displayKittyImage($imageData);
                    } elseif ($term === 'iTerm.app') {
                        $base64Image = base64_encode($imageData);
                        echo "\033]1337;File=inline=1;width=200px;height=150px:{$base64Image}\007\n";
                    }
                } else {
                    info("📸 Cover available: {$imageUrl}");
                }
            } catch (\Exception $e) {
                warning("📸 Cover available: {$imageUrl} (display error: {$e->getMessage()})");
            }
        } else {
            info("📸 Cover available: {$imageUrl}");
        }
    }

    protected function displayKittyImage(string $imageData): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.png';

        try {
            $tempOriginal = tempnam(sys_get_temp_dir(), 'orig_') . '.jpg';
            file_put_contents($tempOriginal, $imageData);

            $imageInfo = getimagesize($tempOriginal);
            if (!$imageInfo) {
                return;
            }
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            $maxWidth = 200;
            $scale = min($maxWidth / $width, $maxWidth / $height);
            $thumbWidth = (int) ($width * $scale);
            $thumbHeight = (int) ($height * $scale);

            $thumb = $this->createThumbnail($tempOriginal, $thumbWidth, $thumbHeight);
            if ($thumb) {
                imagepng($thumb, $tempFile);
                imagedestroy($thumb);

                if (file_exists('/usr/bin/kitten') && is_executable('/usr/bin/kitten')) {
                    system("kitten icat --align=left '$tempFile' 2>/dev/null");
                } else {
                    $base64Image = base64_encode(file_get_contents($tempFile));
                    fwrite(STDOUT, "\033_Ga=T,f=100;{$base64Image}\033\\");
                    echo "\n";
                }
            }

            @unlink($tempOriginal);
        } catch (\Exception $e) {
            // Silently fail image display
        } finally {
            @unlink($tempFile);
        }
    }

    protected function createThumbnail(string $imagePath, int $width, int $height)
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        $info = getimagesize($imagePath);
        if (!$info) {
            return null;
        }

        $mime = $info['mime'];
        $src = null;

        switch ($mime) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $src = imagecreatefrompng($imagePath);
                break;
            case 'image/webp':
                $src = imagecreatefromwebp($imagePath);
                break;
        }

        if (!$src) {
            return null;
        }

        $dst = imagecreatetruecolor($width, $height);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        imagedestroy($src);

        return $dst;
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
            info("Audiobook Librarian Import");
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
