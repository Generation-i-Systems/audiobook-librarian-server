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
                // Set the filename format to ensure rotation happens daily
                $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
                
                // Set the date format for the filename to ensure daily rotation
                $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
                
                // Set the maximum number of log files to keep (14 days)
                $handler->setMaxFiles(14);
            }
        }
    }
}
