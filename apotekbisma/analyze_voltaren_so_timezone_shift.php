<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$productId = 862;
$cutoff = '2025-12-31 23:59:59';

function output(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function headline(string $title): void
{
    output();
    output('=== ' . $title . ' ===');
}

$dbName = DB::connection()->getDatabaseName();

$sessionInfo = DB::selectOne(
    "SELECT @@session.time_zone AS session_tz, @@global.time_zone AS global_tz, NOW() AS now_session, UTC_TIMESTAMP() AS now_utc"
);

$manualSoAnyTimezone = DB::table('rekaman_stoks')
    ->where('id_produk', $productId)
    ->whereNull('id_penjualan')
    ->whereNull('id_pembelian')
    ->where('keterangan', 'Perubahan Stok Manual: SO')
    ->orderBy('id_rekaman_stok', 'asc')
    ->get([
        'id_rekaman_stok',
        'waktu',
        'created_at',
        'updated_at',
        'stok_awal',
        'stok_masuk',
        'stok_keluar',
        'stok_sisa',
        'keterangan',
    ]);

$timestampDiagnostics = DB::select(
    "SELECT 
        id_rekaman_stok,
        waktu,
        UNIX_TIMESTAMP(waktu) AS waktu_epoch,
        FROM_UNIXTIME(UNIX_TIMESTAMP(waktu)) AS waktu_from_epoch,
        ? AS cutoff_literal,
        UNIX_TIMESTAMP(?) AS cutoff_epoch,
        CASE WHEN waktu > ? THEN 'YES' ELSE 'NO' END AS passes_cutoff_now,
        CASE WHEN UNIX_TIMESTAMP(waktu) > UNIX_TIMESTAMP(?) THEN 'YES' ELSE 'NO' END AS passes_cutoff_epoch
     FROM rekaman_stoks
     WHERE id_produk = ?
       AND id_penjualan IS NULL
       AND id_pembelian IS NULL
       AND (keterangan LIKE '%Saldo Awal Stok%' OR keterangan = 'Perubahan Stok Manual: SO')
     ORDER BY id_rekaman_stok ASC",
    [$cutoff, $cutoff, $cutoff, $cutoff, $productId]
);

output('Database: ' . $dbName);
headline('MySQL Session Info');
output('session time_zone: ' . (string) ($sessionInfo->session_tz ?? '-'));
output('global time_zone: ' . (string) ($sessionInfo->global_tz ?? '-'));
output('NOW(): ' . (string) ($sessionInfo->now_session ?? '-'));
output('UTC_TIMESTAMP(): ' . (string) ($sessionInfo->now_utc ?? '-'));

headline('Manual SO Records By Keterangan');
if ($manualSoAnyTimezone->isEmpty()) {
    output('No record with keterangan = Perubahan Stok Manual: SO exists in this DB/session.');
} else {
    foreach ($manualSoAnyTimezone as $row) {
        output(sprintf(
            'id=%d | waktu=%s | created_at=%s | updated_at=%s | awal=%d | +%d | -%d | sisa=%d | %s',
            (int) $row->id_rekaman_stok,
            (string) $row->waktu,
            (string) $row->created_at,
            (string) $row->updated_at,
            (int) $row->stok_awal,
            (int) $row->stok_masuk,
            (int) $row->stok_keluar,
            (int) $row->stok_sisa,
            (string) $row->keterangan
        ));
    }
}

headline('TIMESTAMP Diagnostics Around Cutoff');
foreach ($timestampDiagnostics as $row) {
    output(sprintf(
        'id=%d | waktu=%s | epoch=%s | from_epoch=%s | passes_literal=%s | passes_epoch=%s | cutoff=%s | cutoff_epoch=%s',
        (int) $row->id_rekaman_stok,
        (string) $row->waktu,
        (string) $row->waktu_epoch,
        (string) $row->waktu_from_epoch,
        (string) $row->passes_cutoff_now,
        (string) $row->passes_cutoff_epoch,
        (string) $row->cutoff_literal,
        (string) $row->cutoff_epoch
    ));
}

headline('Interpretation');
output('If production shows every waktu about 7 hours earlier than local, the same TIMESTAMP values are being read under a different MySQL session timezone.');
output('In that case, a manual SO inserted as 2026-01-01 00:15:37 Asia/Jakarta can be read as 2025-12-31 17:15:37 in UTC and fail the query waktu > 2025-12-31 23:59:59.');
