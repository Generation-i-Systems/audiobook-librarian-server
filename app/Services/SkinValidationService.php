<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use ZipArchive;

class SkinValidationService
{
    private const MAX_FILE_SIZE = 52428800; // 50MB in bytes

    private const VALID_ELEMENT_TYPES = [
        'cover-image',
        'text',
        'button',
        'progress-bar',
        'image',
        'visualizer',
        'container',
        'rectangle',
    ];

    private const VALID_ANCHORS = [
        'top-left',
        'top-center',
        'top-right',
        'center-left',
        'center',
        'center-right',
        'bottom-left',
        'bottom-center',
        'bottom-right',
    ];

    public function validateZipStructure(UploadedFile $file): array
    {
        $errors = [];

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = 'File size exceeds maximum allowed size of 50MB';

            return $errors;
        }

        $zip = new ZipArchive();
        $res = $zip->open($file->getRealPath());

        if ($res !== true) {
            $errors[] = 'Failed to open ZIP file';

            return $errors;
        }

        $hasManifest = false;
        $hasPreview = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (str_contains($filename, '..')) {
                $errors[] = 'ZIP file contains path traversal attempts';
                $zip->close();

                return $errors;
            }

            if (str_ends_with($filename, 'manifest.json')) {
                $hasManifest = true;
            }
            if (str_ends_with($filename, 'preview.png')) {
                $hasPreview = true;
            }
        }

        if (! $hasManifest) {
            $errors[] = 'ZIP file must contain manifest.json';
        }

        if (! $hasPreview) {
            $errors[] = 'ZIP file must contain preview.png';
        }

        $zip->close();

        return $errors;
    }

    public function validateManifest(array $manifest): array
    {
        $errors = [];

        if (! isset($manifest['version'])) {
            $errors[] = 'Manifest must contain version field';
        }

        if (! isset($manifest['skinName'])) {
            $errors[] = 'Manifest must contain skinName field';
        }

        if (! isset($manifest['layout'])) {
            $errors[] = 'Manifest must contain layout field';

            return $errors;
        }

        $layout = $manifest['layout'];
        $hasPortrait = isset($layout['portrait']);
        $hasLandscape = isset($layout['landscape']);

        if (! $hasPortrait && ! $hasLandscape) {
            $errors[] = 'Layout must contain at least portrait or landscape orientation';
        }

        foreach (['portrait', 'landscape'] as $orientation) {
            if (isset($layout[$orientation])) {
                $orientationErrors = $this->validateLayoutOrientation($layout[$orientation], $orientation);
                $errors = array_merge($errors, $orientationErrors);
            }
        }

        return $errors;
    }

    private function validateLayoutOrientation(array $orientation, string $orientationName): array
    {
        $errors = [];
        $elementIds = [];

        if (! isset($orientation['elements']) || ! is_array($orientation['elements'])) {
            return $errors;
        }

        foreach ($orientation['elements'] as $index => $element) {
            if (! isset($element['id'])) {
                $errors[] = "Element at index {$index} in {$orientationName} must have an id";

                continue;
            }

            $elementId = $element['id'];

            if (in_array($elementId, $elementIds, true)) {
                $errors[] = "Duplicate element ID '{$elementId}' in {$orientationName}";
            }
            $elementIds[] = $elementId;

            if (isset($element['type']) && ! in_array($element['type'], self::VALID_ELEMENT_TYPES, true)) {
                $errors[] = "Invalid element type '{$element['type']}' for element '{$elementId}' in {$orientationName}";
            }

            if (isset($element['anchor']) && ! in_array($element['anchor'], self::VALID_ANCHORS, true)) {
                $errors[] = "Invalid anchor '{$element['anchor']}' for element '{$elementId}' in {$orientationName}";
            }
        }

        return $errors;
    }

    public function extractPreview(string $zipPath): ?string
    {
        $zip = new ZipArchive();
        $res = $zip->open($zipPath);

        if ($res !== true) {
            return null;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with($filename, 'preview.png')) {
                $content = $zip->getFromIndex($i);
                $zip->close();

                return $content;
            }
        }

        $zip->close();

        return null;
    }

    public function extractManifest(string $zipPath): ?array
    {
        $zip = new ZipArchive();
        $res = $zip->open($zipPath);

        if ($res !== true) {
            return null;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_ends_with($filename, 'manifest.json')) {
                $content = $zip->getFromIndex($i);
                $zip->close();

                $manifest = json_decode($content, true);

                return is_array($manifest) ? $manifest : null;
            }
        }

        $zip->close();

        return null;
    }
}
