<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AdminBooksPaginationViewTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function itRendersSingleRowPaginationWithFirstPreviousNextLast(): void
    {
        $paginator = new LengthAwarePaginator(
            range(1, 10),
            100,
            10,
            5,
            [
                'path' => '/admin/books',
                'query' => ['search' => 'test', 'sort' => 'title_asc'],
            ]
        );

        $html = $paginator->links('pagination.admin-books')->toHtml();

        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('Next', $html);
        $this->assertStringContainsString('Last', $html);

        $this->assertStringNotContainsString('sm:hidden', $html);
        $this->assertStringContainsString('page-link', $html);
    }
}
