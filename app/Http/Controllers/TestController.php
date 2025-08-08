<?php

namespace App\Http\Controllers;

class TestController extends Controller
{
    public function memoryTest()
    {
        $debugLog = storage_path('logs/memory_debug.log');
        $logData = date('Y-m-d H:i:s') . " - TestController - " .
                   "Memory: " . number_format(memory_get_usage()) .
                   " - Limit: " . ini_get('memory_limit') . "\n";
        file_put_contents($debugLog, $logData, FILE_APPEND);

        return response()->json([
            'status' => 'success',
            'memory_usage' => number_format(memory_get_usage()),
            'memory_limit' => ini_get('memory_limit'),
            'peak_memory' => number_format(memory_get_peak_usage())
        ]);
    }
}
