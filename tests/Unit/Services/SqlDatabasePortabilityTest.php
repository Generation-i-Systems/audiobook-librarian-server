<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Tests\TestCase;

class SqlDatabasePortabilityTest extends TestCase
{
    public function testDocumentStoreUsesConfiguredSqlConnectionWithoutASecondDriverSetting(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertNull(config('documentstore.driver'));
        $this->assertInstanceOf(DocumentStoreServiceInterface::class, app(DocumentStoreServiceInterface::class));
        $this->assertSame('SqlDatabaseService', class_basename(app(DocumentStoreServiceInterface::class)));
    }
}
