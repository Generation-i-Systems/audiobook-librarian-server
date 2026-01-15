<?php

namespace App\Contracts\AI;

class AIResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $content = null,
        public readonly ?array $data = null,
        public readonly ?string $error = null,
        public readonly array $usage = [],
        public readonly array $metadata = []
    ) {
    }

    public static function success(string $content, array $usage = [], array $metadata = []): self
    {
        return new self(
            success: true,
            content: $content,
            usage: $usage,
            metadata: $metadata
        );
    }

    public static function successWithData(array $data, array $usage = [], array $metadata = []): self
    {
        return new self(
            success: true,
            data: $data,
            usage: $usage,
            metadata: $metadata
        );
    }

    public static function failure(string $error, array $metadata = []): self
    {
        return new self(
            success: false,
            error: $error,
            metadata: $metadata
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getCost(): float
    {
        return $this->usage['cost'] ?? 0.0;
    }
}
