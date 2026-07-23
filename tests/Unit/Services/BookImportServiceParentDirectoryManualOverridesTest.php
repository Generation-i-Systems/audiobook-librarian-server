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
                'genre' => 'Fantasy',
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
}
