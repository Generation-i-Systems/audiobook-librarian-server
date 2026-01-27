<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\AIBookProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ListMissingBookDirectories extends Command
{
    protected $signature = 'books:list-missing-directories
                            {--disk=books : Storage disk to check}
                            {--format=txt : Output format: txt|json}
                            {--output= : Optional file path to write results}
                            {--unreferenced : Scan disk and list directories that contain audio files but no DB book}
                            {--root= : Optional root path within the disk to limit scanning when using --unreferenced}
                            {--include-unreferenced : When in default mode, also compute and include unreferenced directories}
                            {--unreferenced-output= : Optional second file path to write unreferenced directories}
                            {--ai-suggest : Use AI to suggest mappings for unreferenced directories that likely correspond to DB books}
                            {--ai-output= : Optional file path to write AI suggestions}';

    protected $description = 'Generate a list of book directory_path values that do not exist on disk';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $format = strtolower((string) $this->option('format'));
        $outputPath = (string) ($this->option('output') ?? '');

        $disk = Storage::disk($diskName);

        $isUnreferencedMode = (bool) $this->option('unreferenced');

        if ($isUnreferencedMode) {
            $root = (string) ($this->option('root') ?? '');
            [$unreferenced, $meta] = $this->scanUnreferenced($diskName, $root);

            if ($format === 'json') {
                $payload = json_encode([
                    'disk' => $diskName,
                    'count' => count($unreferenced),
                    'directories' => $unreferenced,
                    'mode' => 'unreferenced',
                    'root' => $meta['root'],
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if ($outputPath !== '') {
                    file_put_contents($outputPath, $payload . PHP_EOL);
                    $this->info("Wrote JSON list to {$outputPath} ({$diskName})");
                } else {
                    $this->line($payload);
                }
            } else {
                $lines = implode(PHP_EOL, $unreferenced) . (count($unreferenced) ? PHP_EOL : '');
                if ($outputPath !== '') {
                    file_put_contents($outputPath, $lines);
                    $this->info("Wrote list to {$outputPath} ({$diskName})");
                } else {
                    foreach ($unreferenced as $p) {
                        $this->line($p);
                    }
                }
            }
            $this->info('Unreferenced directories: ' . count($unreferenced));
            return self::SUCCESS;
        }

        // Default mode: list missing directories referenced by DB
        $books = Book::query()
            ->whereNotNull('directory_path')
            ->pluck('directory_path', 'id');

        $missing = [];
        foreach ($books as $id => $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            if (!$disk->exists($path)) {
                $missing[] = $path;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing, SORT_NATURAL | SORT_FLAG_CASE);

        $includeUnref = (bool) $this->option('include-unreferenced');
        $unrefOut = (string) ($this->option('unreferenced-output') ?? '');
        $useAi = (bool) $this->option('ai-suggest');
        $aiOut = (string) ($this->option('ai-output') ?? '');

        $unrefBundle = null;
        if ($includeUnref || $useAi) {
            $root = (string) ($this->option('root') ?? '');
            [$unrefList, $meta] = $this->scanUnreferenced($diskName, $root);
            $unrefBundle = [
                'list' => $unrefList,
                'meta' => $meta,
            ];
            // Optional second file for unreferenced
            if ($unrefOut !== '') {
                if ($format === 'json') {
                    $payload = json_encode([
                        'disk' => $diskName,
                        'count' => count($unrefList),
                        'directories' => $unrefList,
                        'mode' => 'unreferenced',
                        'root' => $meta['root'],
                    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    file_put_contents($unrefOut, $payload . PHP_EOL);
                } else {
                    $lines = implode(PHP_EOL, $unrefList) . (count($unrefList) ? PHP_EOL : '');
                    file_put_contents($unrefOut, $lines);
                }
                $this->info("Wrote unreferenced list to {$unrefOut} ({$diskName})");
            }
        }

        // If requested, build AI suggestions comparing missing vs unreferenced
        $aiSuggestions = null;
        $aiRawText = null;
        if ($useAi) {
            $unrefList = $unrefBundle['list'];
            // Build concise prompt asking for structured JSON suggestions
            $prompt = "You are an assistant helping reconcile audiobook directories.\n" .
                "Given two lists: (1) DB-referenced directories that are missing on disk, and (2) on-disk unreferenced directories that contain audio files.\n" .
                "Propose matches where an unreferenced directory likely corresponds to a DB book directory, considering name similarity and series numbering.\n" .
                "Return ONLY JSON with the exact schema: {\"mappings\": [{\"db_directory\": string, \"unreferenced_directory\": string, \"confidence\": number, \"reason\": string}]}.\n\n" .
                "DB missing directories (" . count($missing) . "):\n- " . implode("\n- ", $missing) . "\n\n" .
                "Unreferenced directories (" . count($unrefList) . "):\n- " . implode("\n- ", $unrefList) . "\n";

            /** @var AIBookProcessor $ai */
            $ai = app(AIBookProcessor::class);
            $resp = $ai->complete($prompt);
            if ($resp['success'] ?? false) {
                $data = $resp['data'] ?? '';
                if (is_string($data)) {
                    $raw = $data;
                } elseif (is_array($data)) {
                    $raw = (string) ($data['text'] ?? $data['content'] ?? json_encode($data));
                } else {
                    $raw = (string) $data;
                }
                $aiRawText = $raw;
                $parsed = $this->parseAiJson($raw);
                $aiSuggestions = $parsed ?? ['raw' => $raw];
            } else {
                $aiSuggestions = ['error' => (string) ($resp['error'] ?? 'Unknown AI error')];
            }

            // Note: writing to --ai-output happens after base payload is built so we can merge suggestions into the same doc
        }

        if ($format === 'json') {
            $base = [
                'disk' => $diskName,
                'count' => count($missing),
                'paths' => $missing,
                'mode' => 'missing',
            ];
            if ($includeUnref) {
                $base['unreferenced'] = [
                    'count' => count($unrefBundle['list']),
                    'directories' => $unrefBundle['list'],
                    'root' => $unrefBundle['meta']['root'],
                ];
            }
            if ($useAi) {
                $base['ai_suggestions'] = $aiSuggestions;
            }
            $payload = json_encode($base, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($outputPath !== '') {
                file_put_contents($outputPath, $payload . PHP_EOL);
                $this->info("Wrote JSON list to {$outputPath} ({$diskName})");
            } else {
                $this->line($payload);
            }
            // If --ai-output specified, write ONLY raw AI text there
            if ($useAi && $aiOut !== '') {
                file_put_contents($aiOut, (string) $aiRawText . PHP_EOL);
                $this->info("Wrote raw AI response to {$aiOut} ({$diskName})");
            }
        } else {
            if ($outputPath !== '') {
                $content = implode(PHP_EOL, $missing) . (count($missing) ? PHP_EOL : '');
                if ($includeUnref) {
                    $content .= '# Unreferenced directories' . PHP_EOL;
                    $content .= implode(PHP_EOL, $unrefBundle['list']) . (count($unrefBundle['list']) ? PHP_EOL : '');
                }
                if ($useAi) {
                    $content .= '# AI suggestions' . PHP_EOL;
                    $content .= json_encode($aiSuggestions, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
                }
                file_put_contents($outputPath, $content);
                $this->info("Wrote list to {$outputPath} ({$diskName})");
            } else {
                foreach ($missing as $p) {
                    $this->line($p);
                }
                if ($includeUnref) {
                    $this->line('# Unreferenced directories');
                    foreach ($unrefBundle['list'] as $p) {
                        $this->line($p);
                    }
                }
                if ($useAi) {
                    $this->line('# AI suggestions');
                    $this->line(json_encode($aiSuggestions, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                }
            }

            // If --ai-output specified, write ONLY raw AI text there (txt mode as well)
            if ($useAi && $aiOut !== '') {
                file_put_contents($aiOut, (string) $aiRawText . PHP_EOL);
                $this->info("Wrote raw AI response to {$aiOut} ({$diskName})");
            }
        }

        $this->info('Missing directories: ' . count($missing));
        return self::SUCCESS;
    }

    /**
     * Attempt to parse AI JSON suggestions from raw text. Handles fenced code blocks and embedded objects.
     *
     * @param string $raw
     * @return array|null returns array with 'mappings' key on success; null otherwise
     */
    private function parseAiJson(string $raw): ?array
    {
        // Normalize common stray prefixes before decoding
        $normalized = ltrim($raw);
        // Case: starts with 'json' then newline, without fences
        if (preg_match('/^json\s*\R/i', $normalized)) {
            $normalized = preg_replace('/^json\s*\R/i', '', $normalized, 1) ?? $normalized;
        }
        // Case: starts with an opening fence ```json without a closing fence
        if (preg_match('/^```\s*json\s*\R/i', $normalized)) {
            $normalized = preg_replace('/^```\s*json\s*\R/i', '', $normalized, 1) ?? $normalized;
            // also drop any trailing ``` if present later
            $normalized = preg_replace('/\R?```\s*$/', '', $normalized) ?? $normalized;
        }

        $tryDecode = function (string $s): ?array {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded) && isset($decoded['mappings']) && is_array($decoded['mappings'])) {
                    return $decoded;
                }
                // If first decode returns a JSON string that itself contains JSON, decode again
                if (is_string($decoded)) {
                    $decoded2 = json_decode($decoded, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded2) && isset($decoded2['mappings']) && is_array($decoded2['mappings'])) {
                        return $decoded2;
                    }
                }
            }
            return null;
        };

        // 1) Direct decode
        if ($res = $tryDecode($normalized)) {
            return $res;
        }

        // 2) Strip common code fences and try
        $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($normalized));
        if (is_string($stripped) && $stripped !== $raw) {
            if ($res = $tryDecode(trim($stripped))) {
                return $res;
            }
        }

        // 3) Extract fenced block content (first occurrence)
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $normalized, $m)) {
            $candidate = trim($m[1]);
            if ($res = $tryDecode($candidate)) {
                return $res;
            }
        }

        // 4) Extract first top-level JSON object from the string (simple bounds)
        $start = strpos($normalized, '{');
        $end = strrpos($normalized, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($normalized, $start, $end - $start + 1);
            if ($res = $tryDecode($candidate)) {
                return $res;
            }
        }

        // 5) Brace-matching scan to collect object candidates and try each
        $len = strlen($normalized);
        $stack = [];
        $candidates = [];
        for ($i = 0; $i < $len; $i++) {
            $ch = $normalized[$i];
            if ($ch === '{') {
                $stack[] = $i;
            } elseif ($ch === '}' && !empty($stack)) {
                $startIdx = array_pop($stack);
                if (empty($stack)) {
                    $candidates[] = substr($normalized, $startIdx, $i - $startIdx + 1);
                }
            }
        }
        foreach ($candidates as $cand) {
            if ($res = $tryDecode($cand)) {
                return $res;
            }
        }

        return null;
    }

    /**
     * Scan disk for directories that contain audio files but are not referenced by any Book.directory_path.
     *
     * @param string $diskName
     * @param string $root
     * @return array{0: array<int,string>, 1: array{root:string}}
     */
    private function scanUnreferenced(string $diskName, string $root): array
    {
        $disk = Storage::disk($diskName);
        $extensions = (array) config('bookparser.extensions', ['mp3', 'm4b', 'm4a', 'mp4', 'ogg', 'flac', 'aac', 'wav']);
        $extMap = array_fill_keys(array_map('strtolower', $extensions), true);
        $excludePatterns = (array) config('bookparser.exclude_dirs', []);

        $dbDirs = Book::query()
            ->whereNotNull('directory_path')
            ->pluck('directory_path')
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->values()
            ->all();
        $dbDirSet = array_fill_keys($dbDirs, true);

        $start = trim((string) $root, '/');
        $queue = [$start];
        $seen = [];
        $candidateDirs = [];
        while ($queue) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            // files in current dir
            try {
                $files = $disk->files($current);
            } catch (\Throwable $e) {
                $files = [];
            }
            foreach ($files as $file) {
                $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                if ($ext === '' || !isset($extMap[$ext])) {
                    continue;
                }
                $dir = trim(str_replace('\\', '/', dirname($file)), '/');
                if ($dir === '.') {
                    $dir = '';
                }
                if ($dir !== '') {
                    $skip = false;
                    foreach ($excludePatterns as $pattern) {
                        if (@preg_match('/' . $pattern . '/i', $dir)) {
                            if (preg_match('/' . $pattern . '/i', $dir)) {
                                $skip = true;
                                break;
                            }
                        }
                    }
                    if ($skip) {
                        continue;
                    }
                }
                $candidateDirs[$dir] = true;
            }

            // subdirectories
            try {
                $subdirs = $disk->directories($current);
            } catch (\Throwable $e) {
                $subdirs = [];
            }
            foreach ($subdirs as $sub) {
                $normalized = trim(str_replace('\\', '/', $sub), '/');
                if ($normalized === $current) {
                    continue;
                }
                $skip = false;
                foreach ($excludePatterns as $pattern) {
                    if (@preg_match('/' . $pattern . '/i', $normalized)) {
                        if (preg_match('/' . $pattern . '/i', $normalized)) {
                            $skip = true;
                            break;
                        }
                    }
                }
                if (!$skip) {
                    $queue[] = $normalized;
                }
            }
        }

        $unreferenced = [];
        foreach (array_keys($candidateDirs) as $dir) {
            if (!isset($dbDirSet[$dir])) {
                $unreferenced[] = $dir;
            }
        }
        $unreferenced = array_values(array_unique($unreferenced));
        sort($unreferenced, SORT_NATURAL | SORT_FLAG_CASE);

        return [$unreferenced, ['root' => $root]];
    }
}
