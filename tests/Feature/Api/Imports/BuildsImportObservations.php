<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Enums\PermissionKey;
use App\Models\Author;
use App\Models\Book;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared setup and request helpers for imports.v1 feature tests.
 */
trait BuildsImportObservations
{
    protected const DRAFTS_URL = '/api/v1/imports/drafts';

    protected string $booksRoot = '';

    protected function setUpImportDrafts(): void
    {
        config(['import_drafts.enabled' => true, 'import_drafts.enrichment.enabled' => false]);
        $this->grantImportPermission($this->user);

        $this->booksRoot = sys_get_temp_dir() . '/import-drafts-test-books-' . Str::random(8);
        File::makeDirectory($this->booksRoot, 0755, true);
        config([
            'filesystems.disks.books.root' => $this->booksRoot,
            'app.book_root' => $this->booksRoot,
            // The library-profile middleware re-applies the profile's book root on each request.
            'library_profiles.profiles.main.book_storage_path' => $this->booksRoot,
        ]);
    }

    protected function tearDownImportDrafts(): void
    {
        if ($this->booksRoot !== '' && File::isDirectory($this->booksRoot)) {
            File::deleteDirectory($this->booksRoot);
        }
    }

    protected function grantImportPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => PermissionKey::IMPORT_BOOKS->value],
            ['label' => PermissionKey::IMPORT_BOOKS->label()]
        );
        $user->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * @param array<string, mixed> $observation
     */
    protected function postDraft(array $observation): TestResponse
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(self::DRAFTS_URL, $observation);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $artifacts
     * @param array<int, string> $warnings
     * @param array<int, string> $requested
     * @return array<string, mixed>
     */
    protected function observation(
        string $displayName,
        array $files,
        array $artifacts = [],
        array $warnings = [],
        array $requested = ['tag_normalization', 'duplicate_check', 'path_recommendation'],
    ): array {
        return ImportObservationFixtures::observation($displayName, $files, $artifacts, $warnings, $requested);
    }

    /**
     * @param array<string, array<int, string>> $rawTags
     * @param array<int, string> $mediaWarnings
     * @return array<string, mixed>
     */
    protected function audioFile(string $fileId, string $relativePath, array $rawTags = [], array $mediaWarnings = []): array
    {
        return ImportObservationFixtures::audioFile($fileId, $relativePath, $rawTags, $mediaWarnings);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function nfoFileAndArtifact(string $text): array
    {
        return ImportObservationFixtures::nfoFileAndArtifact($text);
    }

    /**
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    protected function dustRoadObservation(array $warnings = []): array
    {
        return ImportObservationFixtures::dustRoadObservation($warnings);
    }

    protected function createLibraryBook(string $title, string $author, string $directoryPath): Book
    {
        $book = Book::factory()->create([
            'title' => $title,
            'directory_path' => $directoryPath,
            'isbn' => null,
        ]);
        $book->authors()->attach(Author::query()->firstOrCreate(['name' => $author])->id);

        return $book;
    }

    protected function createLibraryDirectory(string $relativeDirectory, bool $withAudio): void
    {
        $directory = $this->booksRoot . '/' . $relativeDirectory;
        File::makeDirectory($directory, 0755, true);
        File::put($directory . '/' . ($withAudio ? '01.mp3' : 'notes.txt'), 'x');
    }

    /**
     * Creates a draft and returns its interpreted JSON (the sync test queue runs interpretation inline).
     *
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    protected function createInterpretedDraft(array $observation): array
    {
        $response = $this->postDraft($observation)->assertCreated();

        return (array) $response->json('draft');
    }
}
