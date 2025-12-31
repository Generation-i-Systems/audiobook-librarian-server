<?php

declare(strict_types=1);

namespace Tests\Web\Unit\Views;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class AdminBooksPaginationViewTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function itRendersSingleRowPaginationWithFirstPreviousNextLast(): void
    {
        $paginator = new LengthAwarePaginator(
            range(1, 20),
            10293,
            20,
            10,
            [
                'path' => '/admin/books',
                'query' => ['search' => 'test', 'sort' => 'title_asc'],
            ]
        );

        $paginator->onEachSide(2);

        $html = $paginator->links('pagination.admin-books')->toHtml();

        $this->assertStringContainsString('Showing', $html);
        $this->assertStringContainsString('of 10293 results', $html);
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('Next', $html);
        $this->assertStringContainsString('Last', $html);

        // Reduced window: show 1,2,...,8,9,10,11,12,...,514,515 for page 10 of 515
        $this->assertStringContainsString('>1<', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('>8<', $html);
        $this->assertStringContainsString('>9<', $html);
        $this->assertStringContainsString('>10<', $html);
        $this->assertStringContainsString('>11<', $html);
        $this->assertStringContainsString('>12<', $html);
        $this->assertStringContainsString('>514<', $html);
        $this->assertStringContainsString('>515<', $html);
        $this->assertStringContainsString('...', $html);

        $this->assertStringNotContainsString('sm:hidden', $html);
        $this->assertStringContainsString('page-link', $html);
    }
}
