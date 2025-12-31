<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class CoverImageAnalysisService
{
    /**
     * Analyze an image to determine if it's a low-quality text-on-white cover
     *
     * A cover is considered low quality if it's approximately 80% white with black text
     * and minimal grey (only for antialiasing).
     *
     * @param string $imagePath Absolute path to the image file
     * @return bool True if the image is a text-only white background cover
     */
    public function isTextOnWhiteCover(string $imagePath): bool
    {
        if (!file_exists($imagePath)) {
            Log::warning('CoverImageAnalysisService: Image file does not exist', [
                'path' => $imagePath,
            ]);
            return false;
        }

        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($imagePath);

            $width = $image->width();
            $height = $image->height();

            // Sample pixels in a grid pattern for performance
            $sampleRate = 10; // Sample every 10th pixel
            $whitePixels = 0;
            $blackPixels = 0;
            $greyPixels = 0;
            $totalSampled = 0;

            // Define thresholds
            $whiteThreshold = 240; // RGB values above this are considered white
            $blackThreshold = 40;  // RGB values below this are considered black

            for ($y = 0; $y < $height; $y += $sampleRate) {
                for ($x = 0; $x < $width; $x += $sampleRate) {
                    $totalSampled++;
                    $color = $image->pickColor($x, $y);

                    $r = $color->red()->toInt();
                    $g = $color->green()->toInt();
                    $b = $color->blue()->toInt();

                    // Calculate brightness
                    $brightness = ($r + $g + $b) / 3;

                    if ($brightness >= $whiteThreshold) {
                        $whitePixels++;
                    } elseif ($brightness <= $blackThreshold) {
                        $blackPixels++;
                    } else {
                        $greyPixels++;
                    }
                }
            }

            if ($totalSampled === 0) {
                return false;
            }

            $whitePercentage = ($whitePixels / $totalSampled) * 100;
            $blackPercentage = ($blackPixels / $totalSampled) * 100;
            $greyPercentage = ($greyPixels / $totalSampled) * 100;

            Log::debug('CoverImageAnalysisService: Image analysis results', [
                'path' => $imagePath,
                'whitePercentage' => round($whitePercentage, 2),
                'blackPercentage' => round($blackPercentage, 2),
                'greyPercentage' => round($greyPercentage, 2),
            ]);

            // Check if image matches text-on-white criteria:
            // - At least 75% white (being slightly conservative)
            // - Some black text (at least 3%)
            // - Limited grey (less than 20% to account for antialiasing)
            $isTextOnWhite = $whitePercentage >= 75
                && $blackPercentage >= 3
                && $greyPercentage < 20;

            if ($isTextOnWhite) {
                Log::info('CoverImageAnalysisService: Detected text-on-white cover', [
                    'path' => $imagePath,
                    'whitePercentage' => round($whitePercentage, 2),
                    'blackPercentage' => round($blackPercentage, 2),
                    'greyPercentage' => round($greyPercentage, 2),
                ]);
            }

            return $isTextOnWhite;
        } catch (\Exception $e) {
            Log::error('CoverImageAnalysisService: Error analyzing image', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get detailed analysis statistics for an image
     *
     * @param string $imagePath Absolute path to the image file
     * @return array Analysis statistics including color percentages
     */
    public function getAnalysisStats(string $imagePath): array
    {
        $stats = [
            'success' => false,
            'whitePercentage' => 0,
            'blackPercentage' => 0,
            'greyPercentage' => 0,
            'otherPercentage' => 0,
            'isTextOnWhite' => false,
            'error' => null,
        ];

        if (!file_exists($imagePath)) {
            $stats['error'] = 'Image file does not exist';
            return $stats;
        }

        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($imagePath);

            $width = $image->width();
            $height = $image->height();

            $sampleRate = 10;
            $whitePixels = 0;
            $blackPixels = 0;
            $greyPixels = 0;
            $colorPixels = 0;
            $totalSampled = 0;

            $whiteThreshold = 240;
            $blackThreshold = 40;

            for ($y = 0; $y < $height; $y += $sampleRate) {
                for ($x = 0; $x < $width; $x += $sampleRate) {
                    $totalSampled++;
                    $color = $image->pickColor($x, $y);

                    $r = $color->red()->toInt();
                    $g = $color->green()->toInt();
                    $b = $color->blue()->toInt();

                    $brightness = ($r + $g + $b) / 3;

                    // Check if it's a saturated color (not greyscale)
                    $maxChannel = max($r, $g, $b);
                    $minChannel = min($r, $g, $b);
                    $saturation = $maxChannel > 0 ? ($maxChannel - $minChannel) / $maxChannel : 0;

                    if ($brightness >= $whiteThreshold) {
                        $whitePixels++;
                    } elseif ($brightness <= $blackThreshold) {
                        $blackPixels++;
                    } elseif ($saturation > 0.1) {
                        // Has color saturation
                        $colorPixels++;
                    } else {
                        $greyPixels++;
                    }
                }
            }

            if ($totalSampled > 0) {
                $stats['whitePercentage'] = round(($whitePixels / $totalSampled) * 100, 2);
                $stats['blackPercentage'] = round(($blackPixels / $totalSampled) * 100, 2);
                $stats['greyPercentage'] = round(($greyPixels / $totalSampled) * 100, 2);
                $stats['otherPercentage'] = round(($colorPixels / $totalSampled) * 100, 2);
                $stats['success'] = true;
                $stats['isTextOnWhite'] = $this->isTextOnWhiteCover($imagePath);
            }
        } catch (\Exception $e) {
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }
}
