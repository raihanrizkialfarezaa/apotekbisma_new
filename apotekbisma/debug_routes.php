<?php

use Illuminate\Http\Request;

Route::post('/debug-sync', function(Request $request) {
    $logFile = storage_path('logs/debug-sync.log');
    $timestamp = now()->format('Y-m-d H:i:s');
    
    $logEntry = "[$timestamp] Web sync called from IP: " . $request->ip() . "\n";
    $logEntry .= "[$timestamp] Request data: " . json_encode($request->all()) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    try {
        $controller = new \App\Http\Controllers\StockSyncController();
        $response = $controller->performSync($request);
        
        $logEntry = "[$timestamp] Sync completed successfully\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        return $response;
    } catch (\Exception $e) {
        $logEntry = "[$timestamp] Sync failed: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        return response()->json([
            'success' => false,
            'message' => 'Debug sync failed: ' . $e->getMessage()
        ], 500);
    }
});

Route::get('/debug-sync-log', function() {
    $logFile = storage_path('logs/debug-sync.log');
    if (file_exists($logFile)) {
        return '<pre>' . file_get_contents($logFile) . '</pre>';
    }
    return 'No debug log found';
});
