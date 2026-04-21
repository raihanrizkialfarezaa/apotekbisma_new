<?php

use App\Http\Controllers\PenjualanController;
use App\Services\TransactionDateMutationService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$customCutoff = trim((string) ($argv[1] ?? ''));
if ($customCutoff !== '') {
    Config::set('stock.cutoff_datetime', $customCutoff);
}

$controller = app(PenjualanController::class);
$mutationService = app(TransactionDateMutationService::class);

$controllerFloorMethod = new ReflectionMethod(PenjualanController::class, 'resolveMinimumAllowedFinalPenjualanWaktu');
$controllerFloorMethod->setAccessible(true);

$controllerAssertMethod = new ReflectionMethod(PenjualanController::class, 'assertFinalPenjualanWaktuAllowed');
$controllerAssertMethod->setAccessible(true);

$serviceFloorMethod = new ReflectionMethod(TransactionDateMutationService::class, 'resolveMinimumAllowedFinalTransactionWaktu');
$serviceFloorMethod->setAccessible(true);

$serviceAssertMethod = new ReflectionMethod(TransactionDateMutationService::class, 'assertTransactionFinalWaktuAllowed');
$serviceAssertMethod->setAccessible(true);

$configuredCutoff = (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
$controllerFloor = (string) $controllerFloorMethod->invoke($controller);
$serviceFloor = (string) $serviceFloorMethod->invoke($mutationService);
$referenceNow = Carbon::parse('2026-04-21 11:30:14', config('app.timezone'));

$candidates = [
    Carbon::parse($controllerFloor, config('app.timezone'))->copy()->subSecond()->format('Y-m-d H:i:s'),
    $controllerFloor,
    Carbon::parse($controllerFloor, config('app.timezone'))->copy()->addHours(12)->format('Y-m-d H:i:s'),
];

$analyze = function (callable $callback): array {
    try {
        $callback();

        return [
            'status' => 'LOLOS',
            'message' => '-',
        ];
    } catch (Throwable $throwable) {
        return [
            'status' => 'GAGAL',
            'message' => $throwable->getMessage(),
        ];
    }
};

fwrite(STDOUT, "Analisis floor backdate transaksi final\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");
fwrite(STDOUT, "Cutoff config             : {$configuredCutoff}\n");
fwrite(STDOUT, "Floor controller          : {$controllerFloor}\n");
fwrite(STDOUT, "Floor service             : {$serviceFloor}\n");
fwrite(STDOUT, "Reference now             : " . $referenceNow->format('Y-m-d H:i:s') . "\n");

foreach ($candidates as $candidate) {
    $controllerResult = $analyze(function () use ($controllerAssertMethod, $controller, $candidate, $referenceNow) {
        $controllerAssertMethod->invoke($controller, $candidate, $referenceNow);
    });

    $serviceResult = $analyze(function () use ($serviceAssertMethod, $mutationService, $candidate) {
        $serviceAssertMethod->invoke($mutationService, $candidate);
    });

    fwrite(STDOUT, str_repeat('-', 72) . "\n");
    fwrite(STDOUT, "Candidate                 : {$candidate}\n");
    fwrite(STDOUT, "Controller validation     : {$controllerResult['status']}\n");
    fwrite(STDOUT, "Controller message        : {$controllerResult['message']}\n");
    fwrite(STDOUT, "Service validation        : {$serviceResult['status']}\n");
    fwrite(STDOUT, "Service message           : {$serviceResult['message']}\n");
}