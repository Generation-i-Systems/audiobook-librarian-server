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
        $app = require __DIR__ . '/../bootstrap/app.php';

        // Ensure the application is actually an Application instance
        if (!$app instanceof Application) {
            throw new \RuntimeException('Bootstrap file did not return an Application instance. Got: ' . get_class($app));
        }

        // Set the application as the facade application
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);

        // Bootstrap the application (load service providers, etc.)
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        return $app;
    }
}
