<?php

namespace Tests\Unit\Services;

use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Tests\TestCase;

class BookImportServiceParentDirectoryManualOverridesTest extends TestCase
{
    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            app(GenreMappingService::class),
            app(SourceTrashService::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editMetadataFieldsRecordsManualEditsForParentDirectory(): void
    {
        $metadata = [
            'title' => 'Book A',
            'author' => ['Brandon Sanderson'],
            'genre' => 'Fantasy',
            'series' => 'Mistborn',
        ];
        $audiobook = ['path' => '/downloads/Mistborn Series/Book A'];

        $this->service->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $default,
            fn ($question, $options, $default) => $default,
            fn ($metadata, $keys) => null,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy'],
            function (): void {
            },
            fn ($metadata) => $metadata,
            true
        );

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $overrides = $property->getValue($this->service);

        $this->assertSame(
            [
                'author' => ['Brandon Sanderson'],
                'genre' => ['Fantasy'],
                'series' => 'Mistborn',
            ],
            $overrides['/downloads/Mistborn Series']
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyParentDirectoryManualOverridesFillsSiblingBookFromRecordedEdits(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $property->setValue($this->service, [
            '/downloads/Mistborn Series' => [
                'author' => ['Brandon Sanderson'],
                'genre' => 'Fantasy',
                'series' => 'Mistborn',
            ],
        ]);

        $method = $reflection->getMethod('applyParentDirectoryManualOverrides');
        $method->setAccessible(true);

        $audiobook = ['path' => '/downloads/Mistborn Series/Book B'];
        $aiMetadata = [
            'title' => 'Book B',
            'author' => ['Unknown Author'],
            'genre' => 'Other',
        ];

        $result = $method->invoke($this->service, $audiobook, $aiMetadata, function (): void {
        });

        $this->assertSame(['Brandon Sanderson'], $result['author']);
        $this->assertSame('Fantasy', $result['genre']);
        $this->assertSame('Mistborn', $result['series']);
        $this->assertSame('Book B', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyParentDirectoryManualOverridesLeavesMetadataUntouchedForUnrelatedDirectory(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $property->setValue($this->service, [
            '/downloads/Mistborn Series' => ['genre' => 'Fantasy'],
        ]);

        $method = $reflection->getMethod('applyParentDirectoryManualOverrides');
        $method->setAccessible(true);

        $audiobook = ['path' => '/downloads/Some Other Book'];
        $aiMetadata = ['title' => 'Some Other Book', 'genre' => 'Other'];

        $result = $method->invoke($this->service, $audiobook, $aiMetadata, function (): void {
        });

        $this->assertSame('Other', $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editMetadataFieldsDoesNotRecordOverridesForGenericContainerDirectory(): void
    {
        $metadata = [
            'title' => 'Book A',
            'author' => ['Brandon Sanderson'],
            'genre' => 'Fantasy',
            'series' => 'Mistborn',
        ];
        $audiobook = ['path' => '/media/downloads/Book A'];

        $this->service->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $default,
            fn ($question, $options, $default) => $default,
            fn ($metadata, $keys) => null,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy'],
            function (): void {
            },
            fn ($metadata) => $metadata,
            true
        );

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $overrides = $property->getValue($this->service);

        $this->assertArrayNotHasKey('/media/downloads', $overrides);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyParentDirectoryManualOverridesIgnoresRecordedOverridesForGenericContainerDirectory(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        // Simulate a pre-existing generic-directory entry (e.g. from before this guard existed).
        $property->setValue($this->service, [
            '/media/downloads' => [
                'author' => ['Brandon Sanderson'],
                'genre' => 'Fantasy',
                'series' => 'Mistborn',
            ],
        ]);

        $method = $reflection->getMethod('applyParentDirectoryManualOverrides');
        $method->setAccessible(true);

        $audiobook = ['path' => '/media/downloads/Unrelated Book'];
        $aiMetadata = [
            'title' => 'Unrelated Book',
            'author' => ['Unknown Author'],
            'genre' => 'Other',
        ];

        $result = $method->invoke($this->service, $audiobook, $aiMetadata, function (): void {
        });

        $this->assertSame(['Unknown Author'], $result['author']);
        $this->assertSame('Other', $result['genre']);
        $this->assertArrayNotHasKey('series', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editMetadataFieldsRecordsTitleSeriesSwapAsPatternHintAnchoredOnFolderName(): void
    {
        $metadata = [
            'title' => 'The Forgotten Five',
            'author' => ['Some Author'],
            'series' => 'Rebel Undercover',
        ];
        $audiobook = ['path' => '/downloads/Some Series/3 Rebel Undercover'];

        $choices = ['s', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $this->service->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $default,
            $selectCallback,
            fn ($metadata, $keys) => null,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy'],
            function (): void {
            },
            fn ($metadata) => $metadata
        );

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $overrides = $property->getValue($this->service);

        $this->assertCount(1, $overrides['/downloads/Some Series']['_pattern_hints']);
        $hint = $overrides['/downloads/Some Series']['_pattern_hints'][0];
        $this->assertStringContainsString('Folder "3 Rebel Undercover"', $hint);
        $this->assertStringContainsString(
            'Title: AI guessed "The Forgotten Five", correct is "Rebel Undercover"',
            $hint
        );
        $this->assertStringContainsString(
            'Series: AI guessed "Rebel Undercover", correct is "The Forgotten Five"',
            $hint
        );
        $this->assertStringContainsString('Title and Series were swapped', $hint);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getParentDirectoryPatternHintsReturnsRecordedHints(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $property->setValue($this->service, [
            '/downloads/Some Series' => [
                '_pattern_hints' => ['Title and Series were swapped for a previous book here.'],
            ],
        ]);

        $method = $reflection->getMethod('getParentDirectoryPatternHints');
        $method->setAccessible(true);

        $hints = $method->invoke($this->service, ['path' => '/downloads/Some Series/Book B']);

        $this->assertSame(['Title and Series were swapped for a previous book here.'], $hints);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getParentDirectoryPatternHintsReturnsEmptyForGenericContainerDirectory(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $property->setValue($this->service, [
            '/media/downloads' => [
                '_pattern_hints' => ['Should never be surfaced.'],
            ],
        ]);

        $method = $reflection->getMethod('getParentDirectoryPatternHints');
        $method->setAccessible(true);

        $hints = $method->invoke($this->service, ['path' => '/media/downloads/Book A']);

        $this->assertSame([], $hints);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyParentDirectoryManualOverridesDoesNotLeakPatternHintsIntoMetadata(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $property->setValue($this->service, [
            '/downloads/Mistborn Series' => [
                'genre' => 'Fantasy',
                '_pattern_hints' => ['Should never be surfaced as a metadata field.'],
            ],
        ]);

        $method = $reflection->getMethod('applyParentDirectoryManualOverrides');
        $method->setAccessible(true);

        $audiobook = ['path' => '/downloads/Mistborn Series/Book B'];
        $aiMetadata = ['title' => 'Book B', 'genre' => 'Other'];

        $result = $method->invoke($this->service, $audiobook, $aiMetadata, function (): void {
        });

        $this->assertSame('Fantasy', $result['genre']);
        $this->assertArrayNotHasKey('_pattern_hints', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editingAnyFieldRecordsAFolderNameAnchoredPatternHint(): void
    {
        $metadata = [
            'title' => 'Book A',
            'author' => ['Old Narrator Value'],
            'narrator' => ['Wrong Narrator'],
            'series' => 'Some Series',
        ];
        $audiobook = ['path' => '/downloads/Some Series/Book A [narrated wrong]'];

        // '3' = edit narrator, then done.
        $choices = ['3', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $this->service->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $question === 'Narrator(s) (comma-separated)' ? 'Correct Narrator' : $default,
            $selectCallback,
            fn ($metadata, $keys) => null,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy'],
            function (): void {
            },
            fn ($metadata) => $metadata
        );

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $overrides = $property->getValue($this->service);

        $this->assertCount(1, $overrides['/downloads/Some Series']['_pattern_hints']);
        $hint = $overrides['/downloads/Some Series']['_pattern_hints'][0];
        $this->assertStringContainsString('Folder "Book A [narrated wrong]"', $hint);
        $this->assertStringContainsString('Narrator: AI guessed "Wrong Narrator", correct is "Correct Narrator"', $hint);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editingAFieldWithNoActualChangeRecordsNoPatternHint(): void
    {
        $metadata = [
            'title' => 'Book A',
            'author' => ['Some Author'],
            'series' => 'Some Series',
        ];
        $audiobook = ['path' => '/downloads/Some Series/Book A'];

        // '3' = edit narrator, then done; the callback just returns the (empty) default.
        $choices = ['3', '9'];
        $selectCallback = function (string $question, array $options, string $default) use (&$choices): string {
            return array_shift($choices) ?? $default;
        };

        $this->service->editMetadataFields(
            $metadata,
            $audiobook,
            fn ($question, $default) => $default,
            $selectCallback,
            fn ($metadata, $keys) => null,
            function (array &$metadata): void {
            },
            fn () => ['Fantasy'],
            function (): void {
            },
            fn ($metadata) => $metadata
        );

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $overrides = $property->getValue($this->service);

        $this->assertArrayNotHasKey('_pattern_hints', $overrides['/downloads/Some Series'] ?? []);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function patternHintsAreDeduplicatedPerDirectory(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('addParentDirectoryPatternHint');
        $method->setAccessible(true);

        $method->invoke($this->service, '/downloads/Some Series', 'same hint');
        $method->invoke($this->service, '/downloads/Some Series', 'same hint');

        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $hints = $property->getValue($this->service)['/downloads/Some Series']['_pattern_hints'];

        $this->assertSame(['same hint'], $hints);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function patternHintsAreCappedPerDirectoryKeepingTheMostRecent(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('addParentDirectoryPatternHint');
        $method->setAccessible(true);

        for ($i = 0; $i < 12; $i++) {
            $method->invoke($this->service, '/downloads/Some Series', "hint {$i}");
        }

        $property = $reflection->getProperty('parentDirectoryManualOverrides');
        $property->setAccessible(true);
        $hints = $property->getValue($this->service)['/downloads/Some Series']['_pattern_hints'];

        $this->assertSame(['hint 4', 'hint 5', 'hint 6', 'hint 7', 'hint 8', 'hint 9', 'hint 10', 'hint 11'], $hints);
    }
}
