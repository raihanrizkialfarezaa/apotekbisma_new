<?php

// Monitor log files
$logFiles = [
    'Web Sync Log' => storage_path('logs/web-sync.log'),
    'Command Sync Log' => storage_path('logs/command-sync.log'),
    'Laravel Log' => storage_path('logs/laravel.log')
];

echo "=== LOG MONITOR ===\n";
echo "Press Ctrl+C to exit\n\n";

while (true) {
    system('cls'); // Clear screen on Windows
    echo "=== LOG MONITOR - " . date('Y-m-d H:i:s') . " ===\n\n";
    
    foreach ($logFiles as $name => $file) {
        echo "--- $name ---\n";
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (!empty(trim($content))) {
                // Show last 10 lines
                $lines = explode("\n", $content);
                $lastLines = array_slice($lines, -10);
                echo implode("\n", $lastLines) . "\n";
            } else {
                echo "No content\n";
            }
        } else {
            echo "File not found\n";
        }
        echo "\n";
    }
    
    sleep(2);
}
