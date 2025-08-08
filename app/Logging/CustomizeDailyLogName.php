<?php

namespace App\Logging;

use Monolog\Handler\RotatingFileHandler;

class CustomizeDailyLogName
{
    /**
     * Customize the given logger instance.
     *
     * @param  \Illuminate\Log\Logger  $logger
     * @return void
     */
    public function __invoke($logger)
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof RotatingFileHandler) {
                // Use reflection to set the filename format if the method exists
                if (method_exists($handler, 'setFilenameFormat')) {
                    $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
                }
            }
        }
    }
}
