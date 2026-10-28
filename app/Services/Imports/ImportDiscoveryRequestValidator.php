<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Support\Facades\Validator;

/**
 * Validates a discovery request: relative names and sizes only, bounded in count and length, never absolute
 * or escaping paths.
 */
class ImportDiscoveryRequestValidator
{
    public function __construct(private readonly ImportRelativePathNormalizer $paths)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $maxEntries = (int) config('import_drafts.discovery.max_entries');
        $maxSelections = (int) config('import_drafts.discovery.max_selections');
        $maxTags = (int) config('import_drafts.discovery.max_tags');

        $validator = Validator::make($payload, [
            'contract_version' => 'sometimes|string|in:' . config('import_drafts.contract_version'),
            'mode' => 'required|string|in:paths,scan',
            'force_include' => 'sometimes|boolean',
            'selections' => 'required|array|min:1|max:' . $maxSelections,
            'selections.*' => 'required|string|max:1024',
            'entries' => 'required|array|min:1|max:' . $maxEntries,
            'overrides' => 'sometimes|array|max:' . $maxSelections,
            'overrides.*.path' => 'required|string|max:1024',
            'overrides.*.action' => 'required|string|in:single,split',
            'tags' => 'sometimes|array|max:' . $maxTags,
            'tags.*.path' => 'required|string|max:1024',
            'tags.*.album' => 'sometimes|nullable|string|max:500',
            'tags.*.title' => 'sometimes|nullable|string|max:500',
        ]);
        $errors = $validator->fails() ? $validator->errors()->toArray() : [];
        if ($errors === []) {
            $errors = $this->entryErrors((array) $payload['entries']);
        }
        if ($errors !== []) {
            throw ImportApiException::validation('The folder listing was not valid.', $errors);
        }

        $data = $validator->validated();
        $data['selections'] = array_map(fn (string $p): string => $this->paths->normalize($p), $data['selections']);
        foreach ($data['entries'] as $index => $entry) {
            $data['entries'][$index]['path'] = $this->paths->normalize($entry['path']);
        }
        foreach (['overrides', 'tags'] as $key) {
            foreach ($data[$key] ?? [] as $index => $item) {
                $data[$key][$index]['path'] = $this->paths->normalize($item['path']);
            }
        }

        return $data;
    }

    /**
     * Checks each listed entry with plain comparisons: Laravel's wildcard rules take seconds on the tens of
     * thousands of entries a base directory sends.
     *
     * @param array<int|string, mixed> $entries
     * @return array<string, array<int, string>>
     */
    private function entryErrors(array $entries): array
    {
        $errors = [];
        foreach ($entries as $index => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $path = $entry['path'] ?? null;
            if (!is_string($path) || $path === '') {
                $errors["entries.$index.path"] = ['The path is required.'];
            } elseif (strlen($path) > 1024) {
                $errors["entries.$index.path"] = ['The path may not be greater than 1024 characters.'];
            }
            if (!in_array($entry['kind'] ?? null, ['file', 'dir'], true)) {
                $errors["entries.$index.kind"] = ['The kind must be file or dir.'];
            }
            if (array_key_exists('bytes', $entry) && (!is_int($entry['bytes']) || $entry['bytes'] < 0)) {
                $errors["entries.$index.bytes"] = ['The bytes must be a whole number of zero or more.'];
            }
        }

        return $errors;
    }
}
