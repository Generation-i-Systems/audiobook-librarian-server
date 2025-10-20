<?php

// Script to check General Fiction books against OpenAudible metadata
// and identify which should be moved to correct genres

$booksJsonPath = '/media/lyra_data1/audiobooks/OpenAudible/books.json';
$booksToCheck = [
    'Achilles',
    'A Medicine for Melancholy and Other Stories',
    'A Graveyard for Lunatics',
    'A Knot in the Grain - And Other Stories',
    'Age of Swords',
    'Age of Legend',
    'Ancient Debts',
    'Alethea',
    'America',
    'Anne of Green Gables',
];

if (!file_exists($booksJsonPath)) {
    die("books.json not found at {$booksJsonPath}\n");
}

$booksData = json_decode(file_get_contents($booksJsonPath), true);

echo "Checking books against OpenAudible metadata...\n\n";

foreach ($booksToCheck as $bookTitle) {
    $found = false;
    foreach ($booksData as $book) {
        // Check if title matches (allowing for variations)
        if (
            stripos($book['title'], $bookTitle) !== false ||
            stripos($bookTitle, $book['title']) !== false ||
            ($book['title_short'] ?? '') === $bookTitle
        ) {
            $found = true;
            $genre = $book['genre'] ?? 'UNKNOWN';

            echo "Title: {$bookTitle}\n";
            echo "  OpenAudible Title: {$book['title']}\n";
            echo "  Genre: {$genre}\n";

            // Parse hierarchical genre
            $genreParts = explode(':', $genre);
            $rootCategory = trim($genreParts[0]);
            $specificGenre = trim(end($genreParts));

            echo "  Root Category: {$rootCategory}\n";
            echo "  Specific Genre: {$specificGenre}\n";

            // Determine if it should be in General Fiction
            $shouldBeGenFic = in_array($rootCategory, [
                'Literature & Fiction',
                'Comedy & Humor'
            ]) || $specificGenre === 'Fiction';

            if (!$shouldBeGenFic) {
                echo "  ⚠️  SHOULD BE MOVED!\n";
            } else {
                echo "  ✓ Correctly in General Fiction\n";
            }

            echo "\n";
            break;
        }
    }

    if (!$found) {
        echo "Title: {$bookTitle}\n";
        echo "  ❌ NOT FOUND in OpenAudible books.json\n\n";
    }
}
