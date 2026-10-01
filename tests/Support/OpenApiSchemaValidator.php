<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal OpenAPI 3.0 schema validator for contract tests: $ref, type, nullable, enum,
 * required, properties, items, allOf/anyOf/oneOf and the string formats we document.
 * It reports problems as "path: message" strings; an empty list means the value conforms.
 */
class OpenApiSchemaValidator
{
    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(private readonly array $spec)
    {
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $schema = $this->resolve($schema);

        if ($value === null) {
            return ($schema['nullable'] ?? false) === true || !isset($schema['type']) && !isset($schema['anyOf'])
                ? []
                : ["{$path}: null is not allowed"];
        }

        $errors = [];
        foreach ($schema['allOf'] ?? [] as $sub) {
            $errors = array_merge($errors, $this->validate($value, $sub, $path));
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (!isset($schema[$key])) {
                continue;
            }
            $matched = false;
            foreach ($schema[$key] as $sub) {
                if ($this->validate($value, $sub, $path) === []) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors[] = "{$path}: does not match any of {$key}";
            }
        }

        if (isset($schema['type'])) {
            $typeError = $this->checkType($value, (string) $schema['type'], $path);
            if ($typeError !== null) {
                return array_merge($errors, [$typeError]);
            }
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: " . json_encode($value) . ' is not one of ' . json_encode($schema['enum']);
        }
        if (($schema['format'] ?? null) === 'date-time' && is_string($value) && strtotime($value) === false) {
            $errors[] = "{$path}: {$value} is not a date-time";
        }
        if (($schema['format'] ?? null) === 'email' && is_string($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "{$path}: {$value} is not an email";
        }

        if (is_array($value) && ($schema['type'] ?? null) === 'object') {
            foreach ($schema['required'] ?? [] as $name) {
                if (!array_key_exists($name, $value)) {
                    $errors[] = "{$path}: missing required property {$name}";
                }
            }
            foreach ($schema['properties'] ?? [] as $name => $sub) {
                if (array_key_exists($name, $value)) {
                    $errors = array_merge($errors, $this->validate($value[$name], $sub, "{$path}.{$name}"));
                }
            }
            if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                foreach (array_diff_key($value, $schema['properties'] ?? []) as $name => $item) {
                    $errors = array_merge($errors, $this->validate($item, $schema['additionalProperties'], "{$path}.{$name}"));
                }
            }
        }
        if (is_array($value) && ($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = array_merge($errors, $this->validate($item, $schema['items'], "{$path}[{$i}]"));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $target = $this->spec;
            foreach (explode('/', substr((string) $node['$ref'], 2)) as $segment) {
                $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? null;
                if ($target === null) {
                    throw new \RuntimeException('Unresolvable $ref ' . $node['$ref']);
                }
            }
            $node = $target;
        }

        return $node;
    }

    private function checkType(mixed $value, string $type, string $path): ?string
    {
        $ok = match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => true,
        };

        return $ok ? null : "{$path}: expected {$type}, got " . get_debug_type($value);
    }
}
