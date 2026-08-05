<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A snapshot of book metadata exactly as the user confirmed it (or as auto-mode locked
 * it in), taken at the single moment confirmation happens. Once built, it cannot be
 * changed — there is no setter, no mutation method, nothing to call. Every persistence
 * step downstream of confirmation reads from this object, never from a plain mutable
 * array, so there is no code path capable of altering confirmed data on the way to the
 * database — the only way to change what gets persisted is to build a new snapshot,
 * which means going back through confirmation.
 */
final class ConfirmedBookMetadata
{
    private function __construct(private readonly array $data)
    {
    }

    public static function fromConfirmed(array $metadata): self
    {
        return new self($metadata);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null && $this->data[$key] !== '';
    }

    /**
     * Read-only escape hatch for code that still needs the whole payload (e.g. passing
     * through to a callback or a log line). Returns a copy — mutating it has no effect
     * on this snapshot or anything derived from it.
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
