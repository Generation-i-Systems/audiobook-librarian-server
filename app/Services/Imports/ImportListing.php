<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * A names-and-sizes view of what a client has on disk, used instead of a filesystem so the book:import
 * discovery rules can run on the server. Paths are relative, forward-slashed and never absolute.
 */
final class ImportListing
{
    /** @var array<string, array{kind: string, bytes: int}> */
    private array $nodes = [];

    /** @var array<string, array<int, string>> directory => immediate child paths */
    private array $children = [];

    /**
     * @param array<int, array{path: string, kind: string, bytes?: int}> $entries
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $path = self::normalize((string) $entry['path']);
            if ($path === '') {
                continue;
            }
            $this->nodes[$path] = ['kind' => $entry['kind'] === 'dir' ? 'dir' : 'file', 'bytes' => (int) ($entry['bytes'] ?? 0)];
            $this->ensureParents($path);
        }
        foreach (array_keys($this->nodes) as $path) {
            $this->children[self::parent($path)][] = $path;
        }
        foreach ($this->children as &$list) {
            usort($list, 'strcmp');
        }
    }

    public static function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    public static function parent(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }

    public function isFile(string $path): bool
    {
        return ($this->nodes[$path]['kind'] ?? null) === 'file';
    }

    public function isDir(string $path): bool
    {
        return ($this->nodes[$path]['kind'] ?? null) === 'dir';
    }

    public function exists(string $path): bool
    {
        return isset($this->nodes[$path]);
    }

    public function bytes(string $path): int
    {
        return $this->nodes[$path]['bytes'] ?? 0;
    }

    /** @return array<int, string> immediate sub-directories, name order */
    public function directories(string $directory): array
    {
        return array_values(array_filter($this->children[$directory] ?? [], fn (string $p): bool => $this->isDir($p)));
    }

    /** @return array<int, string> immediate files, name order */
    public function files(string $directory): array
    {
        return array_values(array_filter($this->children[$directory] ?? [], fn (string $p): bool => $this->isFile($p)));
    }

    /**
     * Every entry below [directory], at any depth, parents before children (the SELF_FIRST order).
     *
     * @return array<int, string>
     */
    public function descendants(string $directory): array
    {
        $out = [];
        foreach ($this->children[$directory] ?? [] as $child) {
            $out[] = $child;
            if ($this->isDir($child)) {
                array_push($out, ...$this->descendants($child));
            }
        }

        return $out;
    }

    private function ensureParents(string $path): void
    {
        $parent = self::parent($path);
        while ($parent !== '' && !isset($this->nodes[$parent])) {
            $this->nodes[$parent] = ['kind' => 'dir', 'bytes' => 0];
            $parent = self::parent($parent);
        }
    }
}
