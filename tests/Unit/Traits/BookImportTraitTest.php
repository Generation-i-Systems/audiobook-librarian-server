<?php

namespace Tests\Unit\Traits;

use App\Traits\BookImportTrait;
use PHPUnit\Framework\TestCase;

class BookImportTraitTest extends TestCase
{
    use BookImportTrait;

    public function test_title_needs_review_returns_null_for_normal_title()
    {
        $this->assertNull($this->titleNeedsReview(
            'The Hobbit',
            'Middle Earth',
            'J.R.R. Tolkien',
            '/Fantasy/J.R.R. Tolkien/Middle Earth/The Hobbit'
        ));
    }

    public function test_title_needs_review_series_equals_author()
    {
        $reasons = $this->titleNeedsReview(
            'Some Book',
            'J.K. Rowling',
            'J.K. Rowling',
            '/Fantasy/J.K. Rowling/J.K. Rowling/Some Book'
        );
        $this->assertIsArray($reasons);
        $this->assertContains(
            'Series name is same as author: J.K. Rowling, J.K. Rowling',
            $reasons
        );
    }

    public function test_title_needs_review_title_equals_series()
    {
        $reasons = $this->titleNeedsReview(
            'Stormlight Archive',
            'Stormlight Archive',
            'Brandon Sanderson',
            '/Fantasy/Brandon Sanderson/Stormlight Archive/Stormlight Archive'
        );
        $this->assertIsArray($reasons);
        $this->assertContains(
            'Title is the same as series name: Stormlight Archive, Stormlight Archive',
            $reasons
        );
    }

    public function test_title_needs_review_title_not_in_path()
    {
        $reasons = $this->titleNeedsReview(
            'Odd Title',
            'Some Series',
            'Author',
            '/fantasy/author/some series/another title'
        );
        $this->assertIsArray($reasons);
        $this->assertContains(
            'Title is not a substring of path: Odd Title, /fantasy/author/some series/another title',
            $reasons
        );
    }

    public function test_title_needs_review_numbers_at_ends_no_series()
    {
        $reasons = $this->titleNeedsReview('123 The Book', null, 'Author', '/fantasy/author/123 The Book');
        $this->assertIsArray($reasons);
        $this->assertContains(
            'Title has numbers at beginning or end: 123 The Book',
            $reasons
        );

        $reasons2 = $this->titleNeedsReview(
            'The Book 456',
            null,
            'Author',
            '/fantasy/author/The Book 456'
        );
        $this->assertIsArray($reasons2);
        $this->assertContains(
            'Title has numbers at beginning or end: The Book 456',
            $reasons2
        );
    }
}
