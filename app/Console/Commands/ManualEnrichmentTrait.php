<?php

namespace App\Console\Commands;

trait ManualEnrichmentTrait
{
    /**
     * Manually request enrichment and compare field-by-field with current metadata
     */
    protected function manualEnrichmentWithComparison(array $metadata, array $audiobook): array
    {
        $this->info('🔍 Requesting enrichment from external sources...');

        // Force enrichment even if we have critical fields
        $enrichedData = $this->enrichWithExternalData($metadata, ['force' => true]);

        if (empty($enrichedData) || (count($enrichedData) === 1 && isset($enrichedData['_enrichment_results']))) {
            $this->warn('⚠️  No enrichment data found from external sources');
            return $metadata;
        }

        $this->info('✅ Enrichment data retrieved. Comparing fields...');

        // Fields to compare
        $fieldsToCompare = [
            'title' => 'Title',
            'author' => 'Author(s)',
            'narrator' => 'Narrator(s)',
            'description' => 'Description',
            'series' => 'Series',
            'series_number' => 'Series Number',
            'publisher' => 'Publisher',
            'genre' => 'Genre',
            'year' => 'Year',
            'cover_url' => 'Cover Image',
        ];

        foreach ($fieldsToCompare as $field => $label) {
            $currentValue = $metadata[$field] ?? null;
            $newValue = $enrichedData[$field] ?? null;

            // Skip if no new value
            if ($newValue === null || $newValue === '') {
                continue;
            }

            // Skip if values are identical
            if ($this->valuesAreEqual($currentValue, $newValue)) {
                continue;
            }

            // Display comparison
            $this->newLine();
            $this->line("📋 Field: {$label}");
            $this->line('─────────────────────────────────────────');

            if ($field === 'cover_url') {
                $this->compareAndSelectCover($metadata, $currentValue, $newValue);
            } else {
                $this->compareAndSelectField($metadata, $field, $label, $currentValue, $newValue);
            }
        }

        $this->info('✅ Enrichment comparison complete');
        return $metadata;
    }

    protected function valuesAreEqual($value1, $value2): bool
    {
        if ($value1 === $value2) {
            return true;
        }

        // Handle array comparison
        if (is_array($value1) && is_array($value2)) {
            sort($value1);
            sort($value2);
            return $value1 === $value2;
        }

        // Handle string/array comparison
        if (is_array($value1) && is_string($value2)) {
            return implode(', ', $value1) === $value2;
        }
        if (is_string($value1) && is_array($value2)) {
            return $value1 === implode(', ', $value2);
        }

        return false;
    }

    protected function compareAndSelectField(array &$metadata, string $field, string $label, $currentValue, $newValue): void
    {
        $currentDisplay = $this->formatValueForDisplay($currentValue);
        $newDisplay = $this->formatValueForDisplay($newValue);

        $this->line("Current: {$currentDisplay}");
        $this->line("New:     {$newDisplay}");

        $options = [
            '1' => 'Keep current',
            '2' => 'Use new',
            '3' => 'Edit manually',
        ];

        $choice = $this->selectWithImmediateInterrupt("Choose value for {$label}", $options, '1');

        if ($choice === '2') {
            $metadata[$field] = $newValue;
            $this->info("✓ Updated {$label} to new value");
        } elseif ($choice === '3') {
            $defaultEdit = is_array($currentValue) ? implode(', ', $currentValue) : (string) $currentValue;
            $edited = $this->askInline("Enter {$label}", $defaultEdit);

            // Handle array fields
            if (in_array($field, ['author', 'narrator', 'genre'], true)) {
                $metadata[$field] = array_map('trim', explode(',', $edited));
            } else {
                $metadata[$field] = $edited;
            }
            $this->info("✓ Updated {$label} to custom value");
        } else {
            $this->info("✓ Keeping current {$label}");
        }
    }

    protected function compareAndSelectCover(array &$metadata, $currentValue, $newValue): void
    {
        // Temporarily disable UI service to allow image display
        $originalUiService = $this->uiService ?? null;
        $this->uiService = null;

        // Display covers side-by-side
        $this->displayCoversSideBySide($currentValue, $newValue);

        // Restore UI service
        $this->uiService = $originalUiService;

        $options = [
            '1' => 'Keep current cover',
            '2' => 'Use new cover',
            '3' => 'Enter custom URL',
        ];

        $choice = $this->selectWithImmediateInterrupt('Choose cover', $options, '1');

        // Clear the displayed images
        $this->clearDisplayedImages();

        if ($choice === '2') {
            $metadata['cover_url'] = $newValue;
            // Clear embedded cover data if switching to external URL
            unset($metadata['cover_data']);
            unset($metadata['cover_is_local_file']);
            $this->info('✓ Updated to new cover');
        } elseif ($choice === '3') {
            $customUrl = $this->askInline('Enter cover URL', (string) ($currentValue ?? ''));
            if (!empty($customUrl)) {
                $metadata['cover_url'] = $customUrl;
                unset($metadata['cover_data']);
                unset($metadata['cover_is_local_file']);
                $this->info('✓ Updated to custom cover URL');
            }
        } else {
            $this->info('✓ Keeping current cover');
        }

        // Redisplay book details with selected cover
        $this->redisplayBookWithCover($metadata);
    }

    protected function formatValueForDisplay($value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        $str = (string) $value;
        if (strlen($str) > 100) {
            return substr($str, 0, 97) . '...';
        }

        return $str;
    }

    protected function displayCoversSideBySide($currentValue, $newValue): void
    {
        $this->line('\n📸 Cover Comparison:');
        $this->line('─────────────────────────────────────────');

        // Check terminal support
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';
        $kittySupport = $termEnv === 'xterm-kitty' ||
            $termEnv === 'xterm-ghostty' ||
            strpos($termEnv, 'kitty') !== false ||
            $termProgram === 'ghostty';

        if ($kittySupport && file_exists('/usr/bin/kitten') && is_executable('/usr/bin/kitten')) {
            // Download both images
            $currentFile = $this->downloadCoverToTemp($currentValue);
            $newFile = $this->downloadCoverToTemp($newValue);

            if ($currentFile || $newFile) {
                // Display images with limited size to prevent covering menu
                // Place them at different vertical positions to avoid stacking
                if ($currentFile) {
                    $this->line('CURRENT:');
                    // First image at row 0
                    passthru("kitten icat --align=left --place=20x20@0x1 --image-id=1 '$currentFile'");
                    // Add spacing after image (20 rows + 2 for labels)
                    for ($i = 0; $i < 22; $i++) {
                        $this->newLine();
                    }
                } else {
                    $this->line('CURRENT: (none)');
                    $this->newLine();
                }

                if ($newFile) {
                    $this->line('NEW:');
                    // Second image at row offset (after first image + spacing)
                    passthru("kitten icat --align=left --place=20x20@0x24 --image-id=2 '$newFile'");
                    // Add spacing after second image
                    for ($i = 0; $i < 22; $i++) {
                        $this->newLine();
                    }
                } else {
                    $this->line('NEW: (none)');
                    $this->newLine();
                }
            }

            // Store temp files for cleanup
            $this->tempCoverFiles = array_filter([$currentFile, $newFile]);
        } else {
            // Fallback: show URLs only
            $this->line('Current: ' . ($currentValue ?: '(none)'));
            $this->line('New:     ' . ($newValue ?: '(none)'));
        }

        $this->line('─────────────────────────────────────────\n');
    }

    protected function downloadCoverToTemp($url): ?string
    {
        if (empty($url)) {
            return null;
        }

        try {
            $imageData = @file_get_contents($url);
            if (!$imageData) {
                return null;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.jpg';
            file_put_contents($tempFile, $imageData);
            return $tempFile;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function clearDisplayedImages(): void
    {
        // Clear images from terminal using kitten icat --clear
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';
        $kittySupport = $termEnv === 'xterm-kitty' ||
            $termEnv === 'xterm-ghostty' ||
            strpos($termEnv, 'kitty') !== false ||
            $termProgram === 'ghostty';

        if ($kittySupport && file_exists('/usr/bin/kitten') && is_executable('/usr/bin/kitten')) {
            system('kitten icat --clear 2>/dev/null');
        }

        // Clean up temp files
        if (isset($this->tempCoverFiles)) {
            foreach ($this->tempCoverFiles as $file) {
                @unlink($file);
            }
            $this->tempCoverFiles = [];
        }
    }

    protected function redisplayBookWithCover(array $metadata): void
    {
        // Update the UI service with the new metadata to refresh the book display
        if (isset($this->uiService)) {
            $uiMetadata = $this->buildUiMetadata($metadata);
            $this->uiService->setCurrentBook($uiMetadata);
        }
    }
}
