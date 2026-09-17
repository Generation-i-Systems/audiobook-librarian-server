<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\TestCase;

class BookIndexListDurationViewTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function itRendersDurationInTheAjaxListView(): void
    {
        $view = file_get_contents(resource_path('views/books/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString(
            "<td class=\"text-muted small\">\${book.duration && book.duration !== '00:00:00' ? book.duration : ''}</td>",
            $view
        );
    }
}
