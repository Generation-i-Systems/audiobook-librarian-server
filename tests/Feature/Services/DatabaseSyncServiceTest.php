<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\DatabaseSyncService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseSyncServiceTest extends TestCase
{
    protected DatabaseSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DatabaseSyncService();

        // Setup in-memory databases for testing
        // We need to define them in config dynamically if not present
        config(['database.connections.sqlite_prod' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]]);

        config(['database.connections.sqlite_devel' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]]);
    }

    protected function createSchema($connection)
    {
        $builder = $connection->getSchemaBuilder();

        $builder->create('authors', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $builder->create('books', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $builder->create('author_book', function ($table) {
            $table->id();
            $table->foreignId('book_id');
            $table->foreignId('author_id');
        });
    }

    #[Test]
    public function it_refuses_table_sync_without_confirmation()
    {
        $prod = DB::connection('sqlite_prod');
        $devel = DB::connection('sqlite_devel');

        $this->createSchema($prod);
        $this->createSchema($devel);

        // Seed Prod
        $prod->table('authors')->insert(['id' => 1, 'name' => 'Tolkien']);
        $prod->table('authors')->insert(['id' => 2, 'name' => 'Orwell']);

        // Sync
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicit destructive-operation confirmation');

        $this->service->syncTable('authors', $prod, $devel);
    }

    #[Test]
    public function it_syncs_a_table_completely_when_confirmed()
    {
        $prod = DB::connection('sqlite_prod');
        $devel = DB::connection('sqlite_devel');

        $this->createSchema($prod);
        $this->createSchema($devel);

        // Seed Prod
        $prod->table('authors')->insert(['id' => 1, 'name' => 'Tolkien']);
        $prod->table('authors')->insert(['id' => 2, 'name' => 'Orwell']);

        // Sync
        $count = $this->service->syncTable('authors', $prod, $devel, true);

        $this->assertEquals(2, $count);
        $this->assertEquals(2, $devel->table('authors')->count());
        $this->assertEquals('Tolkien', ((object) $devel->table('authors')->find(1))->name);
    }

    #[Test]
    public function it_syncs_a_book_and_links_authors_by_name()
    {
        $prod = DB::connection('sqlite_prod');
        $devel = DB::connection('sqlite_devel');

        $this->createSchema($prod);
        $this->createSchema($devel);

        // Seed Prod Book & Author
        $prod->table('authors')->insert(['id' => 10, 'name' => 'Rowling']); // ID 10
        $prod->table('books')->insert(['id' => 50, 'title' => 'Harry Potter']); // ID 50
        $prod->table('author_book')->insert(['book_id' => 50, 'author_id' => 10]);

        // Seed Devel (Existing Author with different ID)
        $devel->table('authors')->insert(['id' => 5, 'name' => 'Rowling']); // ID 5

        // Sync Book ID 50
        $this->service->syncBook(50, $prod, $devel);

        // Verify Book exists in Devel
        $this->assertNotNull($devel->table('books')->find(50));
        $this->assertEquals('Harry Potter', ((object) $devel->table('books')->find(50))->title);

        // Verify Author Linkage
        $pivot = $devel->table('author_book')->where('book_id', 50)->first();
        $this->assertNotNull($pivot);
        $this->assertEquals(5, $pivot->author_id); // Should link to existing ID 5, not 10
    }
}
