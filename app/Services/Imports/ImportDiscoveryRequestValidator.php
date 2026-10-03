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
            'entries.*.path' => 'required|string|max:1024',
            'entries.*.kind' => 'required|string|in:file,dir',
            'entries.*.bytes' => 'sometimes|integer|min:0',
            'overrides' => 'sometimes|array|max:' . $maxSelections,
            'overrides.*.path' => 'required|string|max:1024',
            'overrides.*.action' => 'required|string|in:single,split',
            'tags' => 'sometimes|array|max:' . $maxTags,
            'tags.*.path' => 'required|string|max:1024',
            'tags.*.album' => 'sometimes|nullable|string|max:500',
            'tags.*.title' => 'sometimes|nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            throw ImportApiException::validation('The folder listing was not valid.', $validator->errors()->toArray());
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
}
