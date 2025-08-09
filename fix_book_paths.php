<?php

use Illuminate\Support\Facades\DB;

echo "Starting book path cleanup...\n";

// First, fix books with full absolute paths
$bookStoragePath = '/media/lyra_data1/audiobooks/books/';
$absoluteCount = DB::table('books')
    ->where('directory_path', 'LIKE', $bookStoragePath . '%')
    ->count();

echo "Found {$absoluteCount} books with full absolute paths.\n";

if ($absoluteCount > 0) {
    echo "Fixing full absolute paths...\n";
    $affected = DB::update("
        UPDATE books 
        SET directory_path = TRIM(LEADING '/' FROM SUBSTRING(directory_path, ?))
        WHERE directory_path LIKE ?
    ", [strlen($bookStoragePath) + 1, $bookStoragePath . '%']);
    echo "Fixed {$affected} books with full absolute paths.\n";
}

// Second, fix books that start with leading slash
$leadingSlashCount = DB::table('books')
    ->where('directory_path', 'LIKE', '/%')
    ->where('directory_path', 'NOT LIKE', $bookStoragePath . '%')
    ->count();

echo "Found {$leadingSlashCount} books with leading slash paths.\n";

if ($leadingSlashCount > 0) {
    // Show examples
    echo "\nExample leading slash paths to be fixed:\n";
    $examples = DB::table('books')
        ->select('id', 'title', 'directory_path')
        ->where('directory_path', 'LIKE', '/%')
        ->where('directory_path', 'NOT LIKE', $bookStoragePath . '%')
        ->limit(5)
        ->get();

    foreach ($examples as $book) {
        $newPath = ltrim($book->directory_path, '/');
        echo "ID {$book->id}: '{$book->directory_path}' -> '{$newPath}'\n";
    }

    echo "\nFixing leading slash paths...\n";
    $affected = DB::update("
        UPDATE books 
        SET directory_path = TRIM(LEADING '/' FROM directory_path)
        WHERE directory_path LIKE '/%' 
        AND directory_path NOT LIKE ?
    ", [$bookStoragePath . '%']);
    echo "Fixed {$affected} books with leading slashes.\n";
}

// Final verification
echo "\nVerification:\n";
$totalBooks = DB::table('books')->count();
$absoluteRemaining = DB::table('books')->where('directory_path', 'LIKE', $bookStoragePath . '%')->count();
$leadingSlashRemaining = DB::table('books')->where('directory_path', 'LIKE', '/%')->count();
$cleanPaths = $totalBooks - $absoluteRemaining - $leadingSlashRemaining;

echo "Total books: {$totalBooks}\n";
echo "Books with clean relative paths: {$cleanPaths}\n";
echo "Books still with absolute paths: {$absoluteRemaining}\n";
echo "Books still with leading slashes: {$leadingSlashRemaining}\n";

if ($absoluteRemaining == 0 && $leadingSlashRemaining == 0) {
    echo "✅ All book paths are now clean relative paths!\n";
} else {
    echo "⚠️ Some books still need manual review.\n";
}

echo "\nDone!\n";
