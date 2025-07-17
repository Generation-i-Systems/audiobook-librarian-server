<?php

/**
 * Script to rename files by replacing the FIRST Roman numeral found in the filename with its Arabic number equivalent.
 *
 * Usage:
 *   php scripts/rename_to_roman.php [directory] [file_glob] [--dryrun] [--verbose] [--pattern=REGEX] [--help]
 *
 * Options:
 *   --dryrun         Only print what would be renamed, do not rename files.
 *   --verbose        Print all actions (including skips).
 *   --pattern=REGEX  Regex pattern for replacement. Use {roman} and {arabic} as placeholders.
 *                    Example: --pattern='/^Book-([IVXLCDM]+)-(.+)\.txt$/i|Book-{arabic}-{2}.txt'
 *                    If --pattern is set, only files matching the pattern will be renamed.
 *                    Default: finds the FIRST Roman numeral in the filename, converts it to Arabic, and replaces the FIRST Arabic number in the filename with that value.
 *   --help           Show this help message and exit.
 *
 * You can specify a directory and an optional glob pattern (e.g. *.mp3) to restrict files considered.
 * Only files with both an Arabic number and a Roman numeral will be renamed by default, unless --pattern is set, in which case only files matching the pattern will be renamed.
 * Example: '01 - Canto X.mp3' will be renamed to '10 - Canto X.mp3'.
 */
function romanToInt($roman)
{
    $map = [
        'M' => 1000,
        'CM' => 900,
        'D' => 500,
        'CD' => 400,
        'C' => 100,
        'XC' => 90,
        'L' => 50,
        'XL' => 40,
        'X' => 10,
        'IX' => 9,
        'V' => 5,
        'IV' => 4,
        'I' => 1,
    ];
    $roman = strtoupper($roman);
    $i = 0;
    $result = 0;
    while ($i < strlen($roman)) {
        foreach ($map as $key => $value) {
            if (substr($roman, $i, strlen($key)) === $key) {
                $result += $value;
                $i += strlen($key);
                break;
            }
        }
    }

    return $result;
}

// Parse arguments
$options = [
    'dir' => getcwd(),
    'glob' => '*',
    'dryrun' => false,
    'verbose' => false,
    'pattern' => null,
    'digits' => null,
];
$showHelp = false;
$nonFlagArgs = [];
foreach ($argv as $arg) {
    if ($arg === $argv[0]) {
        continue;
    }
    if ($arg === '--dryrun' || $arg === '-n') {
        $options['dryrun'] = true;
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $options['verbose'] = true;
    } elseif (str_starts_with($arg, '--pattern=')) {
        $options['pattern'] = substr($arg, 10);
    } elseif (str_starts_with($arg, '--digits=')) {
        $digits = (int) substr($arg, 9);
        $options['digits'] = $digits > 0 ? $digits : null;
    } elseif (preg_match('/^--pattern=(.+)$/', $arg, $m)) {
        $options['pattern'] = $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        $showHelp = true;
    } elseif ($arg[0] !== '-') {
        $nonFlagArgs[] = $arg;
    }
}

if ($showHelp) {
    echo <<<'EOT'
Script to rename files by replacing the first Arabic number in the filename with the Arabic value of the first Roman numeral found.

Usage:
  php scripts/rename_to_roman.php [file_or_dir ...] [options]

Arguments:
  file_or_dir      One or more files and/or a single directory. If a directory is given, all files within it (recursively) are processed.

Options:
  --dryrun, -n         Only print what would be renamed, do not rename files.
  --verbose, -v        Print all actions (including skips).
  --pattern=REGEX      Regex pattern for replacement. Use {arabic} and {roman} as placeholders.
                       Example: --pattern='/^Book-([IVXLCDM]+)\.txt$/i|Book-{arabic}.txt'
                       If --pattern is set, only files matching the pattern will be renamed.
  --digits=N           Pad the Arabic number with leading zeros to N digits (e.g. --digits=3 => 001, 012, 123).
  --help, -h           Show this help message and exit.

Behavior:
  By default, replaces the first Arabic number at the start of the filename with the value of the first Roman numeral found.
  Only files with both an Arabic number and a Roman numeral will be renamed by default, unless --pattern is set, in which case only files matching the pattern will be renamed.
EOT;
    exit(0);
}

// Collect files: accept any number of files or a single directory (recursively)
function collectFiles($args)
{
    $files = [];
    foreach ($args as $arg) {
        if (is_dir($arg)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($arg, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $files[] = $fileinfo->getPathname();
                }
            }
        } elseif (is_file($arg)) {
            $files[] = $arg;
        }
    }

    return $files;
}

// Filter out options from $argv
$inputFiles = [];
foreach ($argv as $arg) {
    if ($arg === $argv[0]) {
        continue;
    }
    if ($arg[0] === '-') {
        continue;
    }
    $inputFiles[] = $arg;
}
if (empty($inputFiles)) {
    fwrite(STDERR, "No input files or directories specified.\n");
    exit(1);
}
$files = collectFiles($inputFiles);
foreach ($files as $path) {
    if (!is_file($path)) {
        continue;
    }

    $file = basename($path);
    $newFile = null;

    if ($options['pattern']) {
        // Pattern format: 'regex|replacement' where replacement can use {roman}, {arabic}, {1}, {2}, ...
        $patternArg = $options['pattern'];
        // Remove outer quotes if present
        if (
            (str_starts_with($patternArg, "'") && str_ends_with($patternArg, "'")) ||
            (str_starts_with($patternArg, '"') && str_ends_with($patternArg, '"'))
        ) {
            $patternArg = substr($patternArg, 1, -1);
        }
        $parts = explode('|', $patternArg, 2);
        if (count($parts) === 2) {
            $regex = $parts[0];
            $replacement = $parts[1];
            if (preg_match($regex, $file, $matches)) {
                $roman = null;
                foreach ($matches as $match) {
                    if (preg_match('/^[IVXLCDM]+$/i', $match)) {
                        $roman = $match;
                        break;
                    }
                }
                if ($roman !== null) {
                    $arabic = romanToInt($roman);
                    if ($options['digits']) {
                        $arabic = str_pad((string) $arabic, $options['digits'], '0', STR_PAD_LEFT);
                    }
                    $replace = $replacement;
                    $replace = str_replace(['{roman}', '{arabic}'], [$roman, $arabic], $replace);
                    // Allow {1}, {2}, ... for group references
                    for ($i = 1; $i < count($matches); $i++) {
                        $replace = str_replace('{' . $i . '}', $matches[$i], $replace);
                    }
                    $newFile = preg_replace($regex, $replace, $file, 1);
                }
            }
        }
    } else {
        // Default: Replace the first Arabic number at the start of the base filename with the first Roman numeral's value
        if (preg_match('/\b([IVXLCDM]+)\b/i', $file, $romanMatch)) {
            $roman = $romanMatch[1];
            $arabic = romanToInt($roman);
            if ($options['digits']) {
                $arabic = str_pad((string) $arabic, $options['digits'], '0', STR_PAD_LEFT);
            }
            // Only replace at the start of the base filename (not the path)
            if (preg_match('/^\d+/', $file, $arabicMatch)) {
                $oldArabic = $arabicMatch[0];
                $newFile = preg_replace('/^' . preg_quote($oldArabic, '/') . '/', $arabic, $file, 1);
            }
        }
    }

    if ($newFile && $newFile !== $file) {
        $dirName = dirname($path);
        $newPath = $dirName . DIRECTORY_SEPARATOR . $newFile;
        if (file_exists($newPath)) {
            if ($options['verbose']) {
                echo "SKIP (target exists): $path -> $newPath\n";
            }

            continue;
        }
        if ($options['dryrun']) {
            echo "[DRYRUN] Would rename: $path -> $newPath\n";
        } else {
            rename($path, $newPath);
            echo "Renamed: $path -> $newPath\n";
        }
    } elseif ($options['verbose']) {
        echo "No change: $path\n";
    }
}
