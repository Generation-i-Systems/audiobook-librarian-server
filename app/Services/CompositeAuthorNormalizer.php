<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Author;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompositeAuthorNormalizer
{
    /**
     * @return array{action: 'split'|'deleted'|'skipped', names: array<int, string>}
     */
    public function normalize(Author $author): array
    {
        $activeBookIds = $author->books()->pluck('books.id')->map(fn (int $id): int => $id)->all();

        if ($activeBookIds === []) {
            $author->delete();

            Log::info('Deleted composite author without active books', [
                'author_id' => $author->id,
                'author_name' => $author->name,
            ]);

            return ['action' => 'deleted', 'names' => []];
        }

        $names = $this->splitNames($author->name);
        if ($names === null) {
            return ['action' => 'skipped', 'names' => []];
        }

        DB::transaction(function () use ($author, $activeBookIds, $names): void {
            $replacementIds = [];
            foreach ($names as $name) {
                $replacement = Author::withTrashed()->where('name', $name)->first();
                if ($replacement === null) {
                    $replacement = Author::query()->create(['name' => $name]);
                } elseif ($replacement->trashed()) {
                    $replacement->restore();
                }

                $replacementIds[] = $replacement->id;
            }

            foreach ($replacementIds as $replacementId) {
                Author::query()->findOrFail($replacementId)->books()->syncWithoutDetaching($activeBookIds);
            }

            foreach ($activeBookIds as $bookId) {
                $linkedCount = Author::query()
                    ->whereIn('id', $replacementIds)
                    ->whereHas('books', fn ($query) => $query->whereKey($bookId))
                    ->count();
                if ($linkedCount !== count($replacementIds)) {
                    throw new \RuntimeException("Could not preserve every author link for book {$bookId}.");
                }
            }

            $author->delete();
        });

        Log::info('Split composite author', [
            'author_id' => $author->id,
            'author_name' => $author->name,
            'replacement_names' => $names,
            'active_book_ids' => $activeBookIds,
        ]);

        return ['action' => 'split', 'names' => $names];
    }

    /**
     * @return array<int, string>|null
     */
    private function splitNames(string $name): ?array
    {
        $normalizedName = mb_strtolower(trim($name));
        $ambiguousNames = [
            'bamboo kingdom #6: fire and as',
            'critical role and various',
            'institute for economics and peace',
        ];

        if (
            in_array($normalizedName, $ambiguousNames, true)
            || str_contains($normalizedName, 'arrangement with')
            || str_contains($name, '(')
            || str_contains($name, ')')
        ) {
            return null;
        }

        $protectedPhrase = 'Authors and Dragons';
        $placeholder = '__AUTHORS_AND_DRAGONS__';
        $value = str_ireplace($protectedPhrase, $placeholder, $name);
        $parts = preg_split('/\s*(?:,|&|\band\b|\bwith\b)\s*/iu', $value);
        if ($parts === false) {
            return null;
        }

        $excludedCreditPattern = '/[-—].*\\b(?:editor|translator|contributor|foreword|forward)\\b/iu';
        $retainedCreditSuffixPattern = '/\\s*[-—]\\s*(?:introduction(?:\\s+by)?|adaptation)\\b.*$/iu';
        $names = array_values(array_unique(array_filter(array_map(
            fn (string $part): string => trim((string) preg_replace(
                $retainedCreditSuffixPattern,
                '',
                str_ireplace($placeholder, $protectedPhrase, $part)
            )),
            array_filter($parts, fn (string $part): bool => preg_match($excludedCreditPattern, $part) !== 1)
        ))));

        return $names !== [] ? $names : null;
    }
}
