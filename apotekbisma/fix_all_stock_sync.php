<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n";
echo "=============================================================\n";
echo "  FIX SINKRONISASI STOK MASSAL\n";
echo "=============================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Load report
$reportFile = null;
$files = glob('stock_sync_report_*.json');
if (count($files) > 0) {
    rsort($files);
    $reportFile = $files[0];
}

if (!$reportFile || !file_exists($reportFile)) {
    echo "❌ Report file tidak ditemukan!\n";
    echo "   Jalankan dulu: php check_all_stock_sync.php\n\n";
    exit(1);
}

$report = json_decode(file_get_contents($reportFile), true);
$tidakSinkron = $report['detail'];

echo "Loaded report: {$reportFile}\n";
echo "Produk tidak sinkron: " . count($tidakSinkron) . "\n\n";

if (count($tidakSinkron) == 0) {
    echo "✅ Tidak ada produk yang perlu diperbaiki!\n\n";
    exit(0);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "PREVIEW FIX:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "Total produk yang akan diupdate: " . count($tidakSinkron) . "\n\n";

// Show top 20 examples
echo "Contoh 20 produk pertama yang akan diupdate:\n\n";
$preview = array_slice($tidakSinkron, 0, 20);
foreach ($preview as $idx => $item) {
    echo sprintf(
        "%3d. %-35s : %4d → %4d (selisih: %s)\n",
        $idx + 1,
        strlen($item['nama_produk']) > 35 ? substr($item['nama_produk'], 0, 32) . '...' : $item['nama_produk'],
        $item['stok_produk'],
        $item['stok_rekaman'],
        $item['selisih'] > 0 ? "+{$item['selisih']}" : $item['selisih']
    );
}

if (count($tidakSinkron) > 20) {
    echo "\n... dan " . (count($tidakSinkron) - 20) . " produk lainnya\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "KONFIRMASI:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "Apakah Anda yakin ingin update stok " . count($tidakSinkron) . " produk?\n";
echo "Stok akan diupdate sesuai dengan rekaman_stoks terakhir.\n\n";
echo "Ketik 'YA SAYA YAKIN' untuk melanjutkan: ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if ($line !== 'YA SAYA YAKIN') {
    echo "\n❌ Fix dibatalkan.\n\n";
    exit(0);
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "EXECUTING MASS FIX...\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$success = 0;
$failed = 0;
$errors = [];

foreach ($tidakSinkron as $idx => $item) {
    try {
        DB::beginTransaction();
        
        $updated = DB::table('produk')
            ->where('id_produk', $item['id_produk'])
            ->update(['stok' => $item['stok_rekaman']]);
        
        if ($updated) {
            $success++;
            if ($success % 50 == 0) {
                echo "Progress: {$success}/" . count($tidakSinkron) . " produk...\n";
            }
        } else {
            $failed++;
            $errors[] = [
                'id' => $item['id_produk'],
                'nama' => $item['nama_produk'],
                'error' => 'Update returned 0 rows'
            ];
        }
        
        DB::commit();
        
    } catch (\Exception $e) {
        DB::rollBack();
        $failed++;
        $errors[] = [
            'id' => $item['id_produk'],
            'nama' => $item['nama_produk'],
            'error' => $e->getMessage()
        ];
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "HASIL FIX:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "✅ Berhasil : {$success} produk\n";
echo "❌ Gagal    : {$failed} produk\n\n";

if ($failed > 0) {
    echo "Error details:\n";
    foreach ($errors as $err) {
        echo "  - ID {$err['id']} ({$err['nama']}): {$err['error']}\n";
    }
    echo "\n";
}

// Verify
echo "═══════════════════════════════════════════════════════════\n";
echo "VERIFIKASI:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$sampleIds = array_slice(array_column($tidakSinkron, 'id_produk'), 0, 5);
echo "Mengecek 5 produk sample...\n\n";

foreach ($sampleIds as $id) {
    $produk = DB::table('produk')->where('id_produk', $id)->first();
    $rekaman = DB::table('rekaman_stoks')
        ->where('id_produk', $id)
        ->orderBy('waktu', 'desc')
        ->orderBy('id_rekaman_stok', 'desc')
        ->first();
    
    $status = $produk->stok == $rekaman->stok_sisa ? '✅' : '❌';
    echo sprintf(
        "%s ID %d (%s): produk.stok=%d, rekaman.stok=%d\n",
        $status,
        $id,
        $produk->nama_produk,
        $produk->stok,
        $rekaman->stok_sisa
    );
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "SUMMARY:\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$stillNotSync = DB::select("
    SELECT COUNT(*) as total
    FROM produk p
    INNER JOIN (
        SELECT id_produk, stok_sisa, ROW_NUMBER() OVER (PARTITION BY id_produk ORDER BY waktu DESC, id_rekaman_stok DESC) as rn
        FROM rekaman_stoks
    ) r ON p.id_produk = r.id_produk AND r.rn = 1
    WHERE p.stok != r.stok_sisa
");

$masihTidakSinkron = $stillNotSync[0]->total;

if ($masihTidakSinkron == 0) {
    echo "🎉 SEMUA PRODUK SUDAH SINKRON!\n";
    echo "   Tidak ada lagi masalah sinkronisasi stok.\n\n";
} else {
    echo "⚠️  Masih ada {$masihTidakSinkron} produk yang tidak sinkron.\n";
    echo "   Jalankan check_all_stock_sync.php untuk cek detail.\n\n";
}

// Save log
$logFile = 'fix_stock_sync_' . date('Ymd_His') . '.log';
$logContent = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_fixed' => count($tidakSinkron),
    'success' => $success,
    'failed' => $failed,
    'errors' => $errors,
    'still_not_sync' => $masihTidakSinkron
];

file_put_contents($logFile, json_encode($logContent, JSON_PRETTY_PRINT));
echo "📄 Log disimpan ke: {$logFile}\n\n";

echo "=============================================================\n";
echo "Fix selesai: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n\n";
