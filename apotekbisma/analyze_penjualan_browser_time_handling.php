<?php

use App\Http\Controllers\PenjualanController;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$localInput = (string) ($argv[1] ?? '2026-04-21T10:50:00');
$browserOffsetMinutes = intval($argv[2] ?? -120);
$fallbackServerWaktu = (string) ($argv[3] ?? '2026-04-21 15:42:40');
$browserNowLocal = (string) ($argv[4] ?? $localInput);

$controller = app(PenjualanController::class);
$resolveMethod = new ReflectionMethod(PenjualanController::class, 'resolveTransactionWaktu');
$resolveMethod->setAccessible(true);
$assertMethod = new ReflectionMethod(PenjualanController::class, 'assertFinalPenjualanWaktuAllowed');
$assertMethod->setAccessible(true);

$totalMinutes = -$browserOffsetMinutes;
$sign = $totalMinutes >= 0 ? '+' : '-';
$absoluteMinutes = abs($totalMinutes);
$timezoneOffset = sprintf('%s%02d:%02d', $sign, intdiv($absoluteMinutes, 60), $absoluteMinutes % 60);

$browserNow = Carbon::createFromFormat('Y-m-d\TH:i:s', $browserNowLocal, $timezoneOffset)
    ->setTimezone(config('app.timezone'));

$resolvedWaktu = $resolveMethod->invoke(
    $controller,
    $localInput,
    $fallbackServerWaktu,
    $browserOffsetMinutes
);

$validation = 'LOLOS';
$validationMessage = 'Tanggal transaksi dianggap valid terhadap referensi waktu browser.';

try {
    $assertMethod->invoke($controller, $resolvedWaktu, $browserNow);
} catch (Throwable $throwable) {
    $validation = 'GAGAL';
    $validationMessage = $throwable->getMessage();
}

fwrite(STDOUT, "Analisis parsing waktu browser penjualan\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");
fwrite(STDOUT, "Input lokal browser     : {$localInput}\n");
fwrite(STDOUT, "Browser now lokal       : {$browserNowLocal}\n");
fwrite(STDOUT, "Offset browser (menit)  : {$browserOffsetMinutes}\n");
fwrite(STDOUT, "Offset browser (tz)     : {$timezoneOffset}\n");
fwrite(STDOUT, "Fallback server waktu   : {$fallbackServerWaktu}\n");
fwrite(STDOUT, "Browser now (app tz)    : {$browserNow->format('Y-m-d H:i:s')}\n");
fwrite(STDOUT, "Resolved waktu simpan   : {$resolvedWaktu}\n");
fwrite(STDOUT, "Validasi masa depan     : {$validation}\n");
fwrite(STDOUT, "Pesan                   : {$validationMessage}\n");