<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NavigationGalleryLinksTest extends TestCase
{
    #[Test]
    public function skinAndThemeNavigationLinksPointDirectlyToTheWwwGallery(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertIsString($layout);
        $this->assertSame(2, substr_count($layout, 'href="https://www.ablibrarian.com/gallery/skins"'));
        $this->assertSame(2, substr_count($layout, 'href="https://www.ablibrarian.com/gallery/themes"'));
    }
}
