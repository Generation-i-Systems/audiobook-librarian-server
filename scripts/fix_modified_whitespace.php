<?php

/**
 * This script fixes whitespace issues only in modified lines of tracked files.
 * It's designed to be safe and only modify lines that have actual changes.
 */

// Get list of modified files with line numbers
$output = [];
exec('git diff --unified=0', $output, $returnCode);

if ($returnCode !== 0) {
    die("Error: Failed to get git diff output\n");
}

$currentFile = '';
$modifiedLines = [];

// Parse git diff output to find modified files and lines
foreach ($output as $line) {
    // Check for file header
    if (strpos($line, 'diff --git') === 0) {
        // Extract filename
        $parts = explode(' ', $line);
        $currentFile = $parts[2] ?? '';
        $currentFile = ltrim($currentFile, 'a/');
        if (!isset($modifiedLines[$currentFile])) {
            $modifiedLines[$currentFile] = [];
        }
        continue;
    }

    // Check for line numbers in the diff
    if (strpos($line, '@@ -') === 0) {
        if (preg_match('/@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $matches)) {
            $startLine = (int)$matches[1];
            $modifiedLines[$currentFile][] = $startLine;
        }
    }
}

// Process each modified file
foreach ($modifiedLines as $file => $lines) {
    if (empty($lines) || !file_exists($file)) {
        continue;
    }

    $content = file($file);
    $originalContent = implode('', $content);
    $modified = false;

    // Process only the modified lines
    foreach ($lines as $lineNumber) {
        $lineIdx = $lineNumber - 1; // Convert to 0-based index
        if (!isset($content[$lineIdx])) {
            continue;
        }

        $originalLine = $content[$lineIdx];
        $modifiedLine = $originalLine;

        // Apply whitespace fixes only to modified lines
        $modifiedLine = preg_replace('/\s+!(?=\S)/', '!', $modifiedLine); // Fix space before !
        $modifiedLine = str_replace('! ', '!', $modifiedLine); // Fix space after !
        $modifiedLine = str_replace(' . ', '.', $modifiedLine); // Fix string concatenation
        $modifiedLine = str_replace('@inheritDoc', '{@inheritDoc}', $modifiedLine); // Fix docblock

        if ($modifiedLine !== $originalLine) {
            $content[$lineIdx] = $modifiedLine;
            $modified = true;
        }
    }

    // Save changes if any modifications were made
    if ($modified) {
        $newContent = implode('', $content);
        if ($newContent !== $originalContent) {
            file_put_contents($file, $newContent);
            echo "Fixed whitespace in modified lines of: $file\n";
        }
    }
}

echo "Whitespace fixes complete.\n";
