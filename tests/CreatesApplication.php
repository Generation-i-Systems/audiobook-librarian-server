<?php

namespace Tests;

use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        return require __DIR__ . '/../bootstrap/app.php';
    }
}
