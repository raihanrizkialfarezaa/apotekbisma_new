<?php

use App\Http\Controllers\PenjualanController;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$selectedDate = (string) ($argv[1] ?? '2026-04-21');
$browserNowIso = (string) ($argv[2] ?? '2026-04-21T11:19:49+07:00');
$timezoneOffsetMinutes = intval($argv[3] ?? -420);
$hiddenWaktu = (string) ($argv[4] ?? '');

$controller = app(PenjualanController::class);
$resolveSubmittedMethod = new ReflectionMethod(PenjualanController::class, 'resolveSubmittedPenjualanWaktu');
$resolveSubmittedMethod->setAccessible(true);
$assertMethod = new ReflectionMethod(PenjualanController::class, 'assertFinalPenjualanWaktuAllowed');
$assertMethod->setAccessible(true);

$request = Request::create('/transaksi/simpan', 'POST', [
    'waktu_tanggal' => $selectedDate,
    'waktu' => $hiddenWaktu,
    'browser_now_iso' => $browserNowIso,
    'browser_timezone_offset_minutes' => $timezoneOffsetMinutes,
]);

$browserNow = Carbon::parse($browserNowIso)->setTimezone(config('app.timezone'));
$resolvedWaktu = $resolveSubmittedMethod->invoke($controller, $request, $browserNow);

$validation = 'LOLOS';
$validationMessage = 'Tanggal dan waktu submit dianggap valid.';

try {
    $assertMethod->invoke($controller, $resolvedWaktu, $browserNow);
} catch (Throwable $throwable) {
    $validation = 'GAGAL';
    $validationMessage = $throwable->getMessage();
}

fwrite(STDOUT, "Analisis submit penjualan date-only\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");
fwrite(STDOUT, "Tanggal terpilih      : {$selectedDate}\n");
fwrite(STDOUT, "Browser now ISO       : {$browserNowIso}\n");
fwrite(STDOUT, "Offset browser menit  : {$timezoneOffsetMinutes}\n");
fwrite(STDOUT, "Hidden waktu submit   : " . ($hiddenWaktu !== '' ? $hiddenWaktu : '(kosong)') . "\n");
fwrite(STDOUT, "Resolved waktu akhir  : {$resolvedWaktu}\n");
fwrite(STDOUT, "Validasi masa depan   : {$validation}\n");
fwrite(STDOUT, "Pesan                 : {$validationMessage}\n");