<?php

// Temporary debug file - remove after debugging
echo "<h1>PHP Configuration</h1>";
echo "<p><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</p>";
echo "<p><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . "</p>";
echo "<p><strong>Current Memory Usage:</strong> " . number_format(memory_get_usage()) . " bytes</p>";
echo "<p><strong>Peak Memory Usage:</strong> " . number_format(memory_get_peak_usage()) . " bytes</p>";
echo "<p><strong>Memory Available:</strong> " . (ini_get('memory_limit') === '-1' ? 'Unlimited' : ini_get('memory_limit')) . "</p>";

echo "<h2>Full PHP Info:</h2>";
phpinfo();
