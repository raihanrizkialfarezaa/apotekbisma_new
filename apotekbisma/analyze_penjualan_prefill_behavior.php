<?php

use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$browserNowInput = (string) ($argv[1] ?? '2026-04-21 11:07:40');
$serverWaktuInput = (string) ($argv[2] ?? '2026-04-19 03:34:08');
$preserveServerTime = filter_var($argv[3] ?? '0', FILTER_VALIDATE_BOOLEAN);
$restoredBrowserValue = trim((string) ($argv[4] ?? ''));

$browserNow = Carbon::parse($browserNowInput, config('app.timezone'));
$serverWaktu = Carbon::parse($serverWaktuInput, config('app.timezone'));

$selected = $preserveServerTime ? $serverWaktu->copy() : $browserNow->copy();
$reloadStrategy = $preserveServerTime
	? 'pertahankan waktu server untuk edit transaksi lama'
	: 'paksa autofill ulang ke browser now untuk draft aktif';

fwrite(STDOUT, "Analisis prefill waktu transaksi penjualan\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");
fwrite(STDOUT, "Browser now          : {$browserNow->format('Y-m-d H:i:s')}\n");
fwrite(STDOUT, "Waktu draft/server   : {$serverWaktu->format('Y-m-d H:i:s')}\n");
fwrite(STDOUT, "Nilai restore browser: " . ($restoredBrowserValue !== '' ? $restoredBrowserValue : '(kosong)') . "\n");
fwrite(STDOUT, "Preserve server time : " . ($preserveServerTime ? 'YA' : 'TIDAK') . "\n");
fwrite(STDOUT, "Strategi reload      : {$reloadStrategy}\n");
fwrite(STDOUT, "Prefill terpilih     : {$selected->format('Y-m-d H:i:s')}\n");
fwrite(STDOUT, "Mode                 : " . ($preserveServerTime ? 'EDIT TRANSAKSI LAMA' : 'DRAFT AKTIF / AUTOFILL NOW') . "\n");