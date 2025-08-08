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
                // Set the filename format to ensure rotation happens at midnight
                $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');

                // Force the log to rotate at midnight by setting the next rotation time
                $now = time();
                $midnight = strtotime('tomorrow midnight');
                $handler->setNextRotationTime($midnight);
            }
        }
    }
}
