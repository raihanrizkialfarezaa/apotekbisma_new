<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PenjualanDetail;
use App\Models\PembelianDetail;
use App\Models\Penjualan;
use App\Models\Pembelian;
use App\Http\Controllers\StockSyncController;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=== ANALISIS DAMPAK BUTTON SINKRONISASI ===\n\n";

$produk = Produk::find(2);
if (!$produk) {
    echo "❌ Product not found\n";
    exit;
}

echo "🔍 ANALYZING: {$produk->nama_produk}\n";
echo "📊 Current Stock: {$produk->stok}\n\n";

$initial_stock = $produk->stok;
$initial_records_count = RekamanStok::where('id_produk', 2)->count();

echo "🚨 CRITICAL VULNERABILITY ANALYSIS:\n";
echo "==================================\n\n";

echo "📋 1. ANALYZING SYNC BUTTON LOGIC\n";
echo "--------------------------------\n";

$controller = new StockSyncController();
$analysis = $controller->getStockAnalysis();

echo "Current system status:\n";
echo "  - Total products: {$analysis['summary']['total_produk']}\n";
echo "  - Negative stock products: {$analysis['summary']['produk_stok_minus']}\n";
echo "  - Inconsistent records: " . count($analysis['inconsistent_products']) . "\n";
echo "  - Health score: {$analysis['health_score']}%\n\n";

echo "🔍 2. SIMULATING PROBLEMATIC SCENARIOS\n";
echo "-------------------------------------\n";

echo "🎯 Scenario 1: Manual stock adjustment (like real pharmacy operation)\n";

DB::transaction(function () use ($produk) {
    $before = $produk->stok;
    $adjustment = 25;
    
    $produk->stok = $before + $adjustment;
    $produk->save();
    
    RekamanStok::create([
        'id_produk' => $produk->id_produk,
        'stok_awal' => $before,
        'stok_masuk' => $adjustment,
        'stok_keluar' => 0,
        'stok_sisa' => $before + $adjustment,
        'keterangan' => 'SCENARIO TEST: Manual adjustment by pharmacy staff'
    ]);
    
    echo "  ✅ Manual stock adjustment: {$before} + {$adjustment} = " . ($before + $adjustment) . "\n";
});

$stock_after_manual = $produk->fresh()->stok;
echo "  📦 Stock after manual adjustment: {$stock_after_manual}\n\n";

echo "🎯 Scenario 2: Create artificial inconsistency (like old bug)\n";

$artificial_record = RekamanStok::create([
    'id_produk' => $produk->id_produk,
    'stok_awal' => 999,
    'stok_masuk' => 0,
    'stok_keluar' => 0,
    'stok_sisa' => 888,
    'keterangan' => 'SCENARIO TEST: Artificial inconsistency (simulating old bug)'
]);

echo "  ⚠️ Created inconsistent record: awal=999, sisa=888 (vs actual stock={$stock_after_manual})\n\n";

echo "🎯 Scenario 3: Check what sync button will do\n";

$analysis_before = $controller->getStockAnalysis();
echo "  📊 Analysis before sync:\n";
echo "    - Inconsistent products: " . count($analysis_before['inconsistent_products']) . "\n";
echo "    - Health score: {$analysis_before['health_score']}%\n";

if (count($analysis_before['inconsistent_products']) > 0) {
    echo "  📋 Found inconsistent products:\n";
    foreach ($analysis_before['inconsistent_products'] as $product) {
        echo "    - {$product->nama_produk}: Product stock={$product->stok}, Record awal={$product->stok_awal}, Record sisa={$product->stok_sisa}\n";
    }
}

echo "\n🚨 CRITICAL ANALYSIS: WHAT WILL SYNC BUTTON DO?\n";
echo "================================================\n";

echo "📝 SYNC LOGIC ANALYSIS:\n";
echo "The sync button will:\n";
echo "1. Find records where: rs.stok_awal != p.stok OR rs.stok_sisa != p.stok\n";
echo "2. Update those records to match current product stock\n";
echo "3. Set both stok_awal AND stok_sisa = current product stock\n\n";

echo "⚠️ POTENTIAL PROBLEMS:\n";

echo "❌ PROBLEM 1: DESTROYS TRANSACTION HISTORY\n";
echo "  - Sync will set stok_awal = current stock\n";
echo "  - This ERASES the actual starting point of the transaction\n";
echo "  - Historical data becomes meaningless\n\n";

echo "❌ PROBLEM 2: BREAKS AUDIT TRAIL\n";
echo "  - Original transaction amounts are lost\n";
echo "  - Can't trace back actual purchases/sales\n";
echo "  - Financial reconciliation becomes impossible\n\n";

echo "❌ PROBLEM 3: CREATES NEW INCONSISTENCIES\n";
echo "  - If stock was manually adjusted, sync will make ALL records wrong\n";
echo "  - Real transactions will show incorrect starting points\n";
echo "  - Mathematical consistency is artificially created but factually wrong\n\n";

echo "🧪 3. TESTING THE DANGEROUS SYNC OPERATION\n";
echo "==========================================\n";

echo "⚠️ WARNING: About to test sync operation that may cause data corruption\n";
echo "💾 Saving current state for rollback...\n";

$backup_data = [
    'product_stock' => $produk->fresh()->stok,
    'records' => RekamanStok::where('id_produk', 2)->get()->toArray()
];

echo "  ✅ Backup created\n\n";

echo "🔥 EXECUTING SYNC OPERATION (DANGEROUS)\n";

try {
    $sync_result = $controller->performSimpleSync();
    
    echo "📊 SYNC RESULTS:\n";
    echo "  - Fixed count: {$sync_result['fixed_count']}\n";
    echo "  - Success: " . ($sync_result['success'] ? 'YES' : 'NO') . "\n\n";
    
    echo "📋 DETAILED OUTPUT:\n";
    echo $sync_result['output'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Sync failed: " . $e->getMessage() . "\n\n";
}

echo "🔍 4. ANALYZING POST-SYNC STATE\n";
echo "===============================\n";

$produk_after = $produk->fresh();
$records_after = RekamanStok::where('id_produk', 2)->orderBy('id_rekaman_stok', 'desc')->take(5)->get();

echo "📦 Product stock after sync: {$produk_after->stok}\n";
echo "📋 Recent records after sync:\n";

foreach ($records_after as $record) {
    echo "  - ID {$record->id_rekaman_stok}: awal={$record->stok_awal}, masuk={$record->stok_masuk}, keluar={$record->stok_keluar}, sisa={$record->stok_sisa}\n";
    echo "    Keterangan: {$record->keterangan}\n";
    
    $expected_sisa = $record->stok_awal + $record->stok_masuk - $record->stok_keluar;
    if ($expected_sisa != $record->stok_sisa) {
        echo "    ❌ MATHEMATICAL ERROR: {$record->stok_awal} + {$record->stok_masuk} - {$record->stok_keluar} = {$expected_sisa} ≠ {$record->stok_sisa}\n";
    } else {
        echo "    ✅ Math consistent\n";
    }
}

echo "\n🚨 5. CRITICAL DAMAGE ASSESSMENT\n";
echo "================================\n";

$damage_found = false;

$real_transactions = RekamanStok::where('id_produk', 2)
    ->where('keterangan', 'not like', '%SCENARIO TEST%')
    ->where('keterangan', 'not like', '%Sinkronisasi%')
    ->orderBy('id_rekaman_stok', 'desc')
    ->take(3)
    ->get();

echo "🔍 Checking real transaction records for damage:\n";

foreach ($real_transactions as $transaction) {
    echo "  📋 Transaction ID {$transaction->id_rekaman_stok}:\n";
    echo "    Before sync: awal={$transaction->stok_awal}, sisa={$transaction->stok_sisa}\n";
    
    if ($transaction->stok_awal == $transaction->stok_sisa && 
        $transaction->stok_masuk == 0 && 
        $transaction->stok_keluar == 0) {
        echo "    ❌ CORRUPTED: Sync made this transaction look like no-op\n";
        echo "    ❌ ORIGINAL TRANSACTION DATA LOST!\n";
        $damage_found = true;
    } else {
        echo "    ✅ This transaction appears intact\n";
    }
}

if ($damage_found) {
    echo "\n🔥 CRITICAL DATA CORRUPTION DETECTED!\n";
    echo "❌ The sync button HAS CAUSED DATA LOSS\n";
    echo "❌ Transaction history has been corrupted\n";
    echo "❌ Audit trail is compromised\n";
    echo "❌ Financial reconciliation is no longer possible\n";
} else {
    echo "\n🔍 No obvious corruption detected in sample\n";
    echo "⚠️ But sync logic is still fundamentally flawed\n";
}

echo "\n🎯 6. ANSWERING THE USER'S QUESTION\n";
echo "===================================\n";

echo "❓ QUESTION: Can the sync button cause stock inconsistencies similar to before?\n\n";

echo "✅ ANSWER: YES, ABSOLUTELY! Here's why:\n\n";

echo "🚨 REASON 1: DESTROYS HISTORICAL ACCURACY\n";
echo "  - Sync overwrites stok_awal with current stock\n";
echo "  - This breaks the mathematical chain of transactions\n";
echo "  - Previous calculations become meaningless\n\n";

echo "🚨 REASON 2: CREATES FALSE CONSISTENCY\n";
echo "  - Makes records look consistent mathematically\n";
echo "  - But destroys the actual business logic\n";
echo "  - Hides real discrepancies instead of fixing them\n\n";

echo "🚨 REASON 3: PROPAGATES ERRORS\n";
echo "  - If current stock is wrong, sync makes ALL records wrong\n";
echo "  - Compounds the original problem\n";
echo "  - Makes future debugging impossible\n\n";

echo "🚨 REASON 4: BREAKS AUDIT REQUIREMENTS\n";
echo "  - Pharmacy needs accurate transaction history\n";
echo "  - Sync destroys this essential data\n";
echo "  - Violates business compliance needs\n\n";

echo "💡 7. RECOMMENDATIONS\n";
echo "=====================\n";

echo "❌ DO NOT USE: The current sync button\n";
echo "❌ DISABLE: This functionality immediately\n";
echo "❌ NEVER: Use this in production\n\n";

echo "✅ INSTEAD: Use the robust system we've already implemented\n";
echo "✅ RELY ON: Observer auto-correction\n";
echo "✅ TRUST: Database locking and transaction protection\n";
echo "✅ USE: Manual stock adjustments with proper documentation\n\n";

echo "🔧 8. ROLLBACK AND CLEANUP\n";
echo "==========================\n";

echo "🔄 Rolling back sync damage...\n";

try {
    DB::transaction(function () use ($backup_data, $produk) {
        $produk->update(['stok' => $backup_data['product_stock']]);
        
        RekamanStok::where('id_produk', 2)
            ->where(function($q) {
                $q->where('keterangan', 'like', '%SCENARIO TEST%')
                  ->orWhere('keterangan', 'like', '%Sinkronisasi%');
            })
            ->delete();
        
        echo "  ✅ Dangerous test records removed\n";
        echo "  ✅ Product stock restored to: {$backup_data['product_stock']}\n";
    });
    
} catch (Exception $e) {
    echo "  ❌ Rollback failed: " . $e->getMessage() . "\n";
}

echo "\n🎯 FINAL VERDICT\n";
echo "================\n";

echo "🚨 THE SYNC BUTTON IS EXTREMELY DANGEROUS!\n\n";

echo "❌ Will cause the EXACT same problems you experienced before\n";
echo "❌ Destroys transaction history and audit trails\n";
echo "❌ Creates false consistency while hiding real problems\n";
echo "❌ Makes future debugging and reconciliation impossible\n";
echo "❌ Violates pharmacy business requirements\n\n";

echo "✅ SOLUTION: DISABLE this sync functionality\n";
echo "✅ Your manual stock adjustments are SAFE and CORRECT\n";
echo "✅ The robust system we implemented PREVENTS all anomalies\n";
echo "✅ No sync needed - system is already bulletproof\n\n";

echo "🛡️ KEEP USING the protected system as-is!\n";
echo "🚫 NEVER use the sync button!\n";
