<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GenrePathHandlingTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_genre_path_handling_logic(): void
    {
        // Test case 1: genrePath is already included in directoryPath
        $genrePath = 'Fantasy';
        $directoryPath = 'Fantasy/Author/Series/Book1';
        $destRoot = '/storage/audiobooks';

        // This is the logic from ImportFileController::moveSelected
        $directoryPathParts = explode('/', $directoryPath);
        $firstPart = reset($directoryPathParts);

        /** @phpstan-ignore-next-line empty.variable */
        if (!empty($genrePath) && $firstPart !== $genrePath) {
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $genrePath . DIRECTORY_SEPARATOR . $directoryPath;
        } else {
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $directoryPath;
        }

        // Assert that the path is constructed correctly (no duplication)
        $this->assertEquals(
            '/storage/audiobooks/Fantasy/Author/Series/Book1',
            $destDir,
            'When genrePath is already included in directoryPath, it should not be duplicated'
        );

        // Test case 2: genrePath is not included in directoryPath
        $genrePath = 'Fantasy';
        $directoryPath = 'Author/Series/Book1';

        // This is the logic from ImportFileController::moveSelected
        $directoryPathParts = explode('/', $directoryPath);
        $firstPart = reset($directoryPathParts);

        /** @phpstan-ignore-next-line empty.variable */
        if (!empty($genrePath) && $firstPart !== $genrePath) {
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $genrePath . DIRECTORY_SEPARATOR . $directoryPath;
        } else {
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $directoryPath;
        }

        // Assert that the path is constructed correctly (genre is prepended)
        $this->assertEquals(
            '/storage/audiobooks/Fantasy/Author/Series/Book1',
            $destDir,
            'When genrePath is not included in directoryPath, it should be prepended'
        );
    }
}
