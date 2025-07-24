<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RepairBooksNoAudio extends Command
{
    protected $signature = 'books:repair-no-audio {parent_path?} {--delete : Delete books with no audio files} ' .
        '{--interactive : Interactively review each book with no audio files} {--no-backup : Skip automatic database backup}';

    protected $description = 'Find (and optionally delete) books whose directory contains no audio files (creates a database backup by default).';

    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before checking for books with no audio...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info('Scanning for books with no audio files...');
        //     $disk = Storage::disk('books');
        //     $parent = $this->argument('parent_path');
        //     $books = Book::query();
        //     if ($parent) {
        //         $books->where('directoryPath', 'like', $parent . '%');
        //     }
        //     if ($this->option('verbose')) {
        //         $sql = $books->toRawSql();
        //         $this->info('Raw SQL: ' . $sql);
        //     }
        //     $books = $books->get();
        //     $noAudioBooks = [];
        //     foreach ($books as $book) {
        //         $dir = $book->directoryPath;
        //         if ($this->option('verbose')) {
        //             $this->info("Checking book: {$book->title} ({$dir})");
        //         }
        //         if (!$dir || !$disk->exists($dir)) {
        //             $this->warn("Directory missing for book: {$book->title} ({$dir})");
        //             if ($this->option('interactive')) {
        //                 if ($this->confirm("Delete book DB entry for '{$book->title}' ({$book->id})?")) {
        //                     $book->delete();
        //                     $this->info("Deleted book DB entry: {$book->title} ({$book->id})");
        //                     continue;
        //                 }
        //             }
        //             $noAudioBooks[] = $book;
        //             continue;
        //         }
        //         $files = collect($disk->allFiles($dir));
        //         $audio = $files->filter(function ($file) {
        //             return preg_match('/\.(m4b|mp3|m4a|wav|flac|ogg|aac)$/i', $file);
        //         });
        //         if ($audio->isEmpty()) {
        //             $this->warn("NO AUDIO: {$book->title} ({$dir})");
        //             if ($this->option('delete')) {
        //                 $book->delete();
        //                 $this->info("Deleted book DB entry: {$book->title} ({$book->id})");
        //             } elseif ($this->option('interactive')) {
        //                 $this->info("Directory contents for {$dir}:");
        //                 $dirFiles = $disk->files($dir);
        //                 foreach ($dirFiles as $f) {
        //                     $this->line("  - " . basename($f));
        //                 }
        //                 $choice = $this->choice(
        //                     'Action?',
        //                     ['Accept', 'Delete Book', 'Edit'],
        //                     0
        //                 );
        //                 if ($choice === 'Delete Book') {
        //                     $book->delete();
        //                     $this->info("Deleted book DB entry: {$book->title} ({$book->id})");
        //                     continue;
        //                 } elseif ($choice === 'Edit') {
        //                     $absPath = $disk->path($dir);
        //                     $this->line("To edit, run: cd '" . $absPath . "'");
        //                 } else {
        //                     $this->info("Accepted book: {$book->title} ({$book->id})");
        //                     continue;
        //                 }
        //             }
        //             $noAudioBooks[] = $book;
        //         }
        //     }
        //     $this->info("Found " . count($noAudioBooks) . " books with no audio files.");
        //     if ($this->option('delete') && count($noAudioBooks)) {
        //         if ($this->confirm('Are you sure you want to delete these books from the database?')) {
        //             foreach ($noAudioBooks as $book) {
        //                 $this->info("Deleting book: {$book->title} ({$book->id})");
        //                 $book->delete();
        //             }
        //             $this->info('Deleted all books with no audio files.');
        //         } else {
        //             $this->info('Aborted deletion.');
        //         }
        //     }
    }
}
