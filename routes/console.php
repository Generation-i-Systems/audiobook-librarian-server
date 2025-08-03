<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bkmv {source} {destination} {--dry-run} {--force} {--import}', function () {
    $this->call('books:move', [
        'source' => $this->argument('source'),
        'destination' => $this->argument('destination'),
        '--dry-run' => $this->option('dry-run'),
        '--force' => $this->option('force'),
        '--import' => $this->option('import'),
    ]);
})->purpose('Move a book directory (alias for books:move)');
