<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== REAL TIME LOG MONITOR ===\n";
echo "Monitoring controller and Laravel logs...\n";
echo "Press Ctrl+C to stop\n";
echo "-----------------------------------\n";

$controllerLog = storage_path('logs/controller-sync.log');
$laravelLog = storage_path('logs/laravel.log');

// Get current file sizes
$lastControllerSize = file_exists($controllerLog) ? filesize($controllerLog) : 0;
$lastLaravelSize = file_exists($laravelLog) ? filesize($laravelLog) : 0;

while (true) {
    // Check controller log
    if (file_exists($controllerLog)) {
        $currentSize = filesize($controllerLog);
        if ($currentSize > $lastControllerSize) {
            echo "\n[" . date('H:i:s') . "] CONTROLLER LOG UPDATE:\n";
            $content = file_get_contents($controllerLog);
            $newContent = substr($content, $lastControllerSize);
            echo $newContent;
            $lastControllerSize = $currentSize;
        }
    }
    
    // Check Laravel log for errors
    if (file_exists($laravelLog)) {
        $currentSize = filesize($laravelLog);
        if ($currentSize > $lastLaravelSize) {
            $handle = fopen($laravelLog, 'r');
            fseek($handle, $lastLaravelSize);
            
            $hasRelevantLog = false;
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line && (
                    strpos($line, 'stock') !== false || 
                    strpos($line, 'sync') !== false || 
                    strpos($line, 'ERROR') !== false ||
                    strpos($line, 'Exception') !== false
                )) {
                    if (!$hasRelevantLog) {
                        echo "\n[" . date('H:i:s') . "] LARAVEL LOG UPDATE:\n";
                        $hasRelevantLog = true;
                    }
                    echo trim($line) . "\n";
                }
            }
            fclose($handle);
            $lastLaravelSize = $currentSize;
        }
    }
    
    sleep(1);
}
