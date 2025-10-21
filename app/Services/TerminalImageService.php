<?php

namespace App\Services;

class TerminalImageService
{
    /**
     * Display cover image if terminal supports it (like Ghostty with Kitty protocol)
     */
    public function displayImage(string $imageUrl, ?callable $outputCallback = null, string $align = 'left', ?string $displayName = null, ?string $place = null): void
    {
        $output = $outputCallback ?? function ($message) {
            echo $message . "\n";
        };

        $term = getenv('TERM_PROGRAM') ?: getenv('TERM');
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';

        $kittySupport = $termEnv === 'xterm-kitty' ||
            $termEnv === 'xterm-ghostty' ||
            str_contains($termEnv, 'kitty') ||
            $termProgram === 'ghostty';

        if ($kittySupport || in_array($term, ['Ghostty', 'iTerm.app', 'WezTerm'])) {
            try {
                $downloadStartTime = microtime(true);
                $imageData = @file_get_contents($imageUrl);
                $downloadDuration = round((microtime(true) - $downloadStartTime) * 1000);

                if ($imageData) {
                    $displayText = $displayName ?? $imageUrl;
                    $output("\n📸 Cover Preview: {$displayText}");
                    if (getenv('VERBOSE_TIMING')) {
                        $output("  ⏱️  Cover download took: {$downloadDuration}ms");
                    }

                    $processStartTime = microtime(true);
                    if ($kittySupport) {
                        $this->displayKittyImage($imageData, $align, $place);
                    } elseif ($term === 'iTerm.app') {
                        $base64Image = base64_encode($imageData);
                        echo "\033]1337;File=inline=1;width=200px;height=150px:{$base64Image}\007";
                    }
                    $processDuration = round((microtime(true) - $processStartTime) * 1000);

                    if (getenv('VERBOSE_TIMING')) {
                        $output("  ⏱️  Image processing took: {$processDuration}ms");
                    }

                    $output("");
                } else {
                    $output("📸 Cover available: {$imageUrl}");
                }
            } catch (\Exception $e) {
                $output("📸 Cover available: {$imageUrl} (display error: {$e->getMessage()})");
            }
        } else {
            $output("📸 Cover available: {$imageUrl}");
        }
    }

    /**
     * Display image using Kitty graphics protocol or kitten icat
     */
    protected function displayKittyImage(string $imageData, string $align = 'left', ?string $place = null): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.png';

        try {
            $tempOriginal = tempnam(sys_get_temp_dir(), 'orig_') . '.jpg';
            file_put_contents($tempOriginal, $imageData);

            $imageInfo = getimagesize($tempOriginal);
            if ($imageInfo === false) {
                echo "  (Could not read image dimensions)\n";
                return;
            }
            $width = $imageInfo[0] ?? 200;
            $height = $imageInfo[1] ?? 300;

            $maxWidth = 200;
            $scale = min($maxWidth / $width, $maxWidth / $height);
            $thumbWidth = (int) ($width * $scale);
            $thumbHeight = (int) ($height * $scale);

            $thumb = $this->createThumbnail($tempOriginal, $thumbWidth, $thumbHeight);
            if ($thumb) {
                imagepng($thumb, $tempFile);
                imagedestroy($thumb);

                if (file_exists('/usr/bin/kitten') && is_executable('/usr/bin/kitten')) {
                    $cmd = "kitten icat";
                    if ($place) {
                        $cmd .= " --place='{$place}'";
                    } else {
                        $cmd .= " --align={$align}";
                    }
                    $cmd .= " '$tempFile'";
                    system($cmd);
                } else {
                    $base64Image = base64_encode(file_get_contents($tempFile));
                    fwrite(STDOUT, "\033_Ga=T,f=100;{$base64Image}\033\\");
                    echo "\n";
                }
            } else {
                echo "  (Could not create thumbnail)\n";
            }

            @unlink($tempOriginal);
        } catch (\Exception $e) {
            echo "  (Image display error: " . $e->getMessage() . ")\n";
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Create thumbnail image
     */
    protected function createThumbnail(string $imagePath, int $width, int $height)
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return null;
        }

        $mime = $imageInfo['mime'];

        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($imagePath),
            'image/png' => imagecreatefrompng($imagePath),
            'image/gif' => imagecreatefromgif($imagePath),
            default => null,
        };

        if (!$source) {
            return null;
        }

        $thumb = imagecreatetruecolor($width, $height);

        if ($mime === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        }

        imagecopyresampled(
            $thumb,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $imageInfo[0],
            $imageInfo[1]
        );

        imagedestroy($source);
        return $thumb;
    }

    /**
     * Check if current terminal supports image display
     */
    public function supportsImages(): bool
    {
        $term = getenv('TERM_PROGRAM') ?: getenv('TERM');
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';

        $kittySupport = $termEnv === 'xterm-kitty' ||
            $termEnv === 'xterm-ghostty' ||
            str_contains($termEnv, 'kitty') ||
            $termProgram === 'ghostty';

        return $kittySupport || in_array($term, ['Ghostty', 'iTerm.app', 'WezTerm']);
    }
}
