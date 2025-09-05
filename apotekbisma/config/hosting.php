<?php

return [
    'hosting_mode' => env('HOSTING_MODE', false),
    
    'timeouts' => [
        'ajax_request' => env('AJAX_TIMEOUT', 30),
        'database_lock' => env('DB_LOCK_TIMEOUT', 10),
        'script_execution' => env('SCRIPT_TIMEOUT', 60),
    ],
    
    'database' => [
        'retry_attempts' => env('DB_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('DB_RETRY_DELAY', 1000), // milliseconds
        'max_batch_size' => env('DB_MAX_BATCH_SIZE', 10),
    ],
    
    'performance' => [
        'memory_limit' => env('MEMORY_LIMIT', '512M'),
        'enable_query_log' => env('ENABLE_QUERY_LOG', false),
        'optimize_for_hosting' => env('OPTIMIZE_FOR_HOSTING', true),
    ],
    
    'error_handling' => [
        'detailed_errors' => env('DETAILED_ERRORS', false),
        'log_slow_queries' => env('LOG_SLOW_QUERIES', true),
        'slow_query_threshold' => env('SLOW_QUERY_THRESHOLD', 2000), // milliseconds
    ]
];
