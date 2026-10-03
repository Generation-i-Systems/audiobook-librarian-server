<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Services\BookImportService;

/**
 * Decides what counts as a book, from a client-supplied names-and-sizes listing, with the same rules
 * book:import uses on a real filesystem (scanForAudiobooks, processSpecificPaths, container and multi-disc
 * folders, multi-book archives). The client reports what is on disk; it never decides book boundaries.
 *
 * Stateless: nothing is stored and no file is read.
 */
class ImportBookDiscovery
{
    /** Extensions scanForAudiobooks, processAudiobookDirectory and processSingleAudioFile look for. */
    private const SCAN_AUDIO = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];

    /** Extensions directoryHasAudioFiles accepts when deciding whether a sub-folder is a book. */
    private const FOLDER_AUDIO = ['mp3', 'm4a', 'm4b', 'm4p', 'mp4', 'aac', 'ogg', 'oga', 'wav', 'flac', 'wma'];

    private const EXTRAS_PATTERN = '/^(extras?|artwork|scans?|covers?|sample)$/i';

    private const CD_PATTERN = '/^(cd|disc|disk)[\s_-]*(\d+)$/i';

    private const COMPANIONS = [
        'metadata.json', 'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp',
        'folder.jpg', 'folder.jpeg', 'folder.png', 'folder.webp',
    ];

    /** A book (or single file) must be larger than this, as in the old importer. */
    private const MIN_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly BookImportService $import)
    {
    }

    /**
     * @param array<string, mixed> $input mode (paths|scan), selections, entries, optional force_include,
     *                                    overrides [{path, action: single|split}], tags [{path, album, title}]
     * @return array<string, mixed>
     */
    public function discover(array $input): array
    {
        $listing = new ImportListing((array) ($input['entries'] ?? []));
        $selections = array_values(array_filter(
            array_map(static fn (mixed $s): string => ImportListing::normalize((string) $s), (array) ($input['selections'] ?? [])),
            static fn (string $s): bool => $s !== ''
        ));
        $overrides = [];
        foreach ((array) ($input['overrides'] ?? []) as $override) {
            $overrides[ImportListing::normalize((string) $override['path'])] = (string) $override['action'];
        }
        $skipped = [];
        $found = ($input['mode'] ?? 'paths') === 'scan' ? $this->scan($listing, $selections, $skipped) : $this->specificPaths($listing, $selections, (bool) ($input['force_include'] ?? false), $overrides, $skipped);
        $tags = [];
        foreach ((array) ($input['tags'] ?? []) as $tag) {
            $tags[ImportListing::normalize((string) $tag['path'])] = [
                'album' => isset($tag['album']) ? trim((string) $tag['album']) : null,
                'title' => isset($tag['title']) ? trim((string) $tag['title']) : null,
            ];
        }

        $books = [];
        $evidence = [];
        foreach ($found as $book) {
            foreach ($this->split($listing, $book, $overrides[$book['path']] ?? null, $tags, $evidence) as $part) {
                $books[] = $this->present($part);
            }
        }

        return [
            'mode' => ($input['mode'] ?? 'paths') === 'scan' ? 'scan' : 'paths',
            'books' => $books,
            'evidence_needed' => array_values($evidence),
            'skipped' => $skipped,
        ];
    }

    /**
     * processSpecificPaths: a file is its own book; a directory is split into its sub-folders only when it is a
     * container (more than one sub-folder holds audio and it is not a multi-disc book), else it is one book.
     *
     * @param array<int, string> $selections
     * @param array<int, array<string, string>> $skipped
     * @return array<int, array<string, mixed>>
     */
    private function specificPaths(ImportListing $listing, array $selections, bool $force, array $overrides, array &$skipped): array
    {
        $books = [];
        $processed = [];
        foreach ($selections as $path) {
            if (!$listing->exists($path)) {
                $skipped[] = ['path' => $path, 'reason' => 'not_found'];
                continue;
            }
            if ($listing->isFile($path)) {
                if (!in_array(self::extension($path), self::SCAN_AUDIO, true)) {
                    $skipped[] = ['path' => $path, 'reason' => 'non_audio'];
                } elseif (!$force && $listing->bytes($path) < self::MIN_BYTES) {
                    $skipped[] = ['path' => $path, 'reason' => 'too_small'];
                } else {
                    $books[] = [
                        'path' => $path,
                        'name' => pathinfo($path, PATHINFO_FILENAME),
                        'files' => [$path],
                        'total_size' => $listing->bytes($path),
                        'kind' => 'single_file',
                    ];
                }
                continue;
            }
            if (in_array($path, $processed, true)) {
                continue;
            }
            if (($overrides[$path] ?? null) !== 'single' && $this->isContainer($listing, $path)) {
                $foundBooks = false;
                foreach ($listing->directories($path) as $sub) {
                    if (preg_match(self::EXTRAS_PATTERN, basename($sub)) || !$this->hasAudio($listing, $sub)) {
                        continue;
                    }
                    $book = $this->directoryBook($listing, $sub, 'container_child');
                    if ($book !== null) {
                        $books[] = $book;
                        $processed[] = $sub;
                        $foundBooks = true;
                    }
                }
                if ($foundBooks) {
                    $processed[] = $path;
                }
            }
            if (!in_array($path, $processed, true)) {
                $book = $this->directoryBook($listing, $path, 'book');
                if ($book !== null) {
                    $books[] = $book;
                    $processed[] = $path;
                } else {
                    $skipped[] = ['path' => $path, 'reason' => $this->audioIn($listing, $path) === [] ? 'no_audio' : 'too_small'];
                }
            }
        }

        return $books;
    }

    /**
     * scanForAudiobooks: every folder that directly holds audio is a candidate, multi-disc folders merge into
     * their parent, a folder that contains another candidate is a container, and the scan root itself is never a book.
     *
     * @param array<int, string> $directories
     * @param array<int, array<string, string>> $skipped
     * @return array<int, array<string, mixed>>
     */
    private function scan(ImportListing $listing, array $directories, array &$skipped): array
    {
        $books = [];
        foreach ($directories as $directory) {
            if (!$listing->isDir($directory)) {
                $skipped[] = ['path' => $directory, 'reason' => $listing->exists($directory) ? 'not_a_folder' : 'not_found'];
                continue;
            }
            $potential = [];
            foreach ($listing->descendants($directory) as $path) {
                if ($listing->isFile($path) && in_array(self::extension($path), self::SCAN_AUDIO, true)) {
                    $bookDir = ImportListing::parent($path);
                    $potential[$bookDir] ??= ['path' => $bookDir, 'name' => basename($bookDir), 'files' => [], 'total_size' => 0];
                    $potential[$bookDir]['files'][] = $path;
                    $potential[$bookDir]['total_size'] += $listing->bytes($path);
                }
            }
            $potential = $this->import->groupCdDirectories($potential);

            $paths = array_keys($potential);
            foreach ($paths as $parentPath) {
                foreach ($paths as $childPath) {
                    if ($parentPath !== $childPath && str_starts_with((string) $childPath, $parentPath . '/')) {
                        unset($potential[$parentPath]);
                        break;
                    }
                }
            }

            foreach ($potential as $data) {
                if (count($data['files']) >= 1 && $data['total_size'] > self::MIN_BYTES) {
                    if (in_array($data['path'], $directories, true)) {
                        continue;
                    }
                    $books[] = [
                        'path' => $data['path'],
                        'name' => $data['name'],
                        'files' => $data['files'],
                        'total_size' => $data['total_size'],
                        'kind' => isset($data['cd_count']) ? 'multi_disc' : 'book',
                    ];
                }
            }
        }

        return $books;
    }

    /**
     * Multi-book archives. A folder named like "Series [2-4]" or "Series (2-4)" splits by the numbers in its file
     * names; a flat folder of unrelated titles splits per file once the tags confirm they are not parts of one book.
     * "single" keeps the folder as one book (Merge into Parent Book); "split" forces one book per file.
     *
     * @param array<string, mixed> $book
     * @param array<string, array{album: ?string, title: ?string}> $tags
     * @param array<mixed> $evidence
     * @return array<int, array<string, mixed>>
     */
    private function split(ImportListing $listing, array $book, ?string $override, array $tags, array &$evidence): array
    {
        if ($override === 'single') {
            return [$book];
        }

        $files = $book['files'];
        $flat = false;
        if ($override === 'split') {
            $info = ['series_name' => $book['name'], 'start_number' => 1, 'end_number' => count($files), 'numbers' => range(1, max(1, count($files)))];
            $flat = true;
        } else {
            $info = $this->import->detectMultiBookPattern($book['name']);
            if ($info === null && $this->looksLikeFlatArchive($book, $tags, $evidence)) {
                $info = ['series_name' => $book['name'], 'start_number' => 1, 'end_number' => count($files), 'numbers' => range(1, count($files))];
                $flat = true;
            }
        }
        if ($info === null) {
            return [$book];
        }

        $groups = $flat ? $this->import->convertFlatFilesToSplitGroups($files) : $this->import->analyzeMultiBookFiles(['files' => $files], $info);

        if (count($groups) < 2) {
            $book['kind'] = 'multi_book_combined';
            $book['split'] = ['series_name' => $info['series_name'], 'numbers' => $info['numbers'], 'flat' => $flat];

            return [$book];
        }

        $parts = [];
        foreach ($groups as $number => $fileInfos) {
            if ($fileInfos === []) {
                continue;
            }
            $partFiles = array_map(static fn (array $fileInfo): string => $fileInfo['file'], $fileInfos);
            $parts[] = [
                'path' => $book['path'],
                'name' => $fileInfos[0]['title'],
                'files' => array_merge($partFiles, $this->companions($listing, $partFiles[0], $partFiles)),
                'total_size' => array_sum(array_map(fn (string $f): int => $listing->bytes($f), $partFiles)),
                'kind' => 'multi_book_part',
                'parent_path' => $book['path'],
                'split' => [
                    'series_name' => $info['series_name'],
                    'number' => is_int($number) ? $number : null,
                    'title' => $fileInfos[0]['title'],
                    'flat' => $flat,
                ],
            ];
        }

        return $parts;
    }

    /**
     * detectFlatArchive on names, then the album/title tags the old importer read from up to three files. Tags
     * the client has not sent yet are requested in [evidence] and the folder stays one book until they arrive.
     *
     * @param array<string, mixed> $book
     * @param array<string, array{album: ?string, title: ?string}> $tags
     * @param array<mixed> $evidence
     */
    private function looksLikeFlatArchive(array $book, array $tags, array &$evidence): bool
    {
        $files = $book['files'];
        if (count($files) < 2) {
            return false;
        }

        $parts = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (
                preg_match('/(cd|disc|disk|part)\s*[-_.]?\s*\d+/i', $name)
                || preg_match('/track\s*[-_.]?\s*\d+/i', $name)
                || preg_match('/(chapter|ch\.?)\s*[-_.]?\s*\d+/i', $name)
                || preg_match('/^(\d{1,3})\s*[-_.\s]/i', $name)
            ) {
                $parts++;
            }
        }
        if ($parts > count($files) / 2) {
            return false;
        }
        if ($this->import->filesShareCommonPrefix($files)) {
            return false;
        }

        $indices = [0];
        if (count($files) > 2) {
            $indices[] = (int) floor(count($files) / 2);
        }
        $indices[] = count($files) - 1;
        $indices = array_values(array_unique($indices));

        $missing = [];
        $albums = [];
        $titles = [];
        foreach ($indices as $index) {
            $file = $files[$index];
            if (!array_key_exists($file, $tags)) {
                $missing[] = $file;
                continue;
            }
            if (($tags[$file]['album'] ?? '') !== '') {
                $albums[] = (string) $tags[$file]['album'];
            }
            if (($tags[$file]['title'] ?? '') !== '') {
                $titles[] = (string) $tags[$file]['title'];
            }
        }
        if ($missing !== []) {
            $evidence[$book['path']] = ['path' => $book['path'], 'files' => $missing, 'fields' => ['album', 'title']];

            return false;
        }

        if (count($albums) >= 2 && count(array_unique(array_map('strtolower', $albums))) === 1) {
            return false;
        }
        $partTitles = 0;
        foreach ($titles as $title) {
            if (
                preg_match('/(chapter|ch\.?|track|part|cd|disc|disk)\s*[-_.]?\s*\d+/i', $title)
                || preg_match('/^\d+[\s\-_]/', $title)
                || is_numeric($title)
            ) {
                $partTitles++;
            }
        }
        if (count($titles) > 0 && $partTitles >= count($titles) / 2) {
            return false;
        }

        return true;
    }

    /**
     * findCompanionFiles: metadata.json and a cover image beside a split part's first file travel with it.
     *
     * @param array<int, string> $exclude
     * @return array<int, string>
     */
    private function companions(ImportListing $listing, string $reference, array $exclude): array
    {
        $directory = ImportListing::parent($reference);
        $found = [];
        foreach (self::COMPANIONS as $name) {
            $path = $directory === '' ? $name : $directory . '/' . $name;
            if ($listing->isFile($path) && !in_array($path, $exclude, true)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /** isContainerDirectory: not a multi-disc book, and more than one sub-folder (ignoring extras) holds audio. */
    private function isContainer(ImportListing $listing, string $path): bool
    {
        foreach ($listing->directories($path) as $dir) {
            if (preg_match(self::CD_PATTERN, basename($dir))) {
                return false;
            }
        }
        $count = 0;
        foreach ($listing->directories($path) as $dir) {
            if (preg_match(self::EXTRAS_PATTERN, basename($dir))) {
                continue;
            }
            if ($this->hasAudio($listing, $dir)) {
                $count++;
            }
        }

        return $count > 1;
    }

    /** directoryHasAudioFiles: any non-empty audio file at any depth. */
    private function hasAudio(ImportListing $listing, string $directory): bool
    {
        foreach ($listing->descendants($directory) as $path) {
            if ($listing->isFile($path) && in_array(self::extension($path), self::FOLDER_AUDIO, true) && $listing->bytes($path) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function audioIn(ImportListing $listing, string $directory): array
    {
        return array_values(array_filter(
            $listing->descendants($directory),
            fn (string $p): bool => $listing->isFile($p) && in_array(self::extension($p), self::SCAN_AUDIO, true)
        ));
    }

    /**
     * processAudiobookDirectory: all audio under the folder, at any depth, is one book (over the size floor).
     *
     * @return array<string, mixed>|null
     */
    private function directoryBook(ImportListing $listing, string $directory, string $kind): ?array
    {
        $files = $this->audioIn($listing, $directory);
        $total = array_sum(array_map(fn (string $f): int => $listing->bytes($f), $files));
        if (count($files) < 1 || $total <= self::MIN_BYTES) {
            return null;
        }

        return ['path' => $directory, 'name' => basename($directory), 'files' => $files, 'total_size' => $total, 'kind' => $kind];
    }

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>
     */
    private function present(array $book): array
    {
        $files = array_map(static fn (string $path): array => ['path' => $path], $book['files']);
        $out = [
            'id' => substr(sha1($book['kind'] . '|' . $book['path'] . '|' . ($book['files'][0] ?? '')), 0, 16),
            'relative_path' => $book['path'],
            'name' => $book['name'],
            'kind' => $book['kind'],
            'files' => $files,
            'total_bytes' => (int) $book['total_size'],
            'warnings' => [],
        ];
        foreach (['parent_path', 'split'] as $key) {
            if (isset($book[$key])) {
                $out[$key] = $book[$key];
            }
        }

        return $out;
    }

    private static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
