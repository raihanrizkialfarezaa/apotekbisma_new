<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=================================================\n";
echo "       FIX TIMESTAMP COLLISIONS TOOL\n";
echo "=================================================\n\n";

// 1. Find Timestamps with Collisions
echo "Scanning for timestamp collisions...\n";

$collisions = DB::table('rekaman_stoks')
    ->select('waktu', DB::raw('count(*) as total'))
    ->groupBy('waktu')
    ->having('total', '>', 1)
    ->get();

$count = $collisions->count();
echo "Found $count unique timestamps with collisions.\n";

if ($count === 0) {
    echo "No collisions found. Exiting.\n";
    exit;
}

$totalUpdated = 0;

foreach ($collisions as $index => $collision) {
    $waktu = $collision->waktu;
    $total = $collision->total;
    
    // echo "Processing [$index/$count] $waktu ($total records)...\n";

    // Get records for this timestamp, ordered by ID (Creation Order)
    // We assume ID ASC = Chronological Order
    $records = DB::table('rekaman_stoks')
        ->where('waktu', $waktu)
        ->orderBy('id_rekaman_stok', 'asc')
        ->get();

    $baseTime = Carbon::parse($waktu);
    
    // Iterate and increment seconds
    foreach ($records as $i => $record) {
        if ($i === 0) continue; // Keep the first one as is (or should we shift all? Keep first is fine)

        // Add $i seconds to ensure uniqueness sorted by ID
        // Note: usage of copy() to current instance
        $newTime = $baseTime->copy()->addSeconds($i);
        
        DB::table('rekaman_stoks')
            ->where('id_rekaman_stok', $record->id_rekaman_stok)
            ->update([
                'waktu' => $newTime->format('Y-m-d H:i:s'),
                'updated_at' => now() // Mark that we touched it
            ]);
            
        $totalUpdated++;
    }
}

echo "\n-------------------------------------------------\n";
echo "DONE. Total records updated: $totalUpdated\n";
echo "Timestamps are now spread out chronologically based on ID.\n";
echo "Please verify the Stock Card UI.\n";
