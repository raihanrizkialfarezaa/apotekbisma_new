$ErrorActionPreference = 'Stop'

# Production stock fix runner (PowerShell)
# Urutan command dibuat sama persis dengan run_stock_fix_production.sh dan PRODUCTION_RUN_COMMANDS_SHORT.md

$rootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $rootDir

if (-not (Test-Path "artisan")) {
    Write-Error "File artisan tidak ditemukan. Jalankan script dari root project Laravel."
    exit 1
}

if (-not (Test-Path "storage/logs")) {
    New-Item -ItemType Directory -Path "storage/logs" -Force | Out-Null
}

function Log-Step([string]$message) {
    Write-Host ""
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $message"
}

function Get-LatestReport {
    $files = Get-ChildItem -Path . -Filter "baseline_rebuild_report_*.json" -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending
    if ($files -and $files.Count -gt 0) {
        return $files[0].Name
    }
    return $null
}

function Resolve-SqlDumpPath {
    if ($env:MARIADB_DUMP_PATH -and (Test-Path $env:MARIADB_DUMP_PATH)) {
        return $env:MARIADB_DUMP_PATH
    }

    if ($env:MYSQLDUMP_PATH -and (Test-Path $env:MYSQLDUMP_PATH)) {
        return $env:MYSQLDUMP_PATH
    }

    $cmd = Get-Command mariadb-dump.exe -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source) {
        return $cmd.Source
    }

    $cmd = Get-Command mysqldump.exe -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source) {
        return $cmd.Source
    }

    $candidates = @(
        'C:\laragon\bin\mysql\*\bin\mariadb-dump.exe',
        'C:\laragon\bin\mysql\*\bin\mysqldump.exe',
        'C:\Program Files\MySQL\*\bin\mariadb-dump.exe',
        'C:\Program Files\MySQL\*\bin\mysqldump.exe',
        'C:\xampp\mysql\bin\mariadb-dump.exe',
        'C:\xampp\mysql\bin\mysqldump.exe'
    )

    foreach ($pattern in $candidates) {
        $found = Get-ChildItem -Path $pattern -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending |
            Select-Object -First 1
        if ($found) {
            return $found.FullName
        }
    }

    return $null
}

function Get-DbConfigFromLaravel {
    $tmpPath = "tmp_read_db_config.php"
    $tmpPhp = @'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$cfg = (array) config('database.connections.mysql', []);
echo json_encode([
  'host' => (string)($cfg['host'] ?? ''),
  'port' => (string)($cfg['port'] ?? '3306'),
  'database' => (string)($cfg['database'] ?? ''),
  'username' => (string)($cfg['username'] ?? ''),
  'password' => (string)($cfg['password'] ?? '')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
'@

    $tmpPhp | Set-Content $tmpPath -Encoding UTF8
    $json = $null

    try {
        $json = php $tmpPath 2>$null
    } catch {
        $json = $null
    } finally {
        if (Test-Path $tmpPath) {
            Remove-Item $tmpPath -Force
        }
    }

    if (-not [string]::IsNullOrWhiteSpace($json)) {
        try {
            return $json | ConvertFrom-Json
        } catch {
            return $null
        }
    }

    return $null
}

function Get-EnvValue([string]$key, [string]$default = '') {
    if (-not (Test-Path '.env')) {
        return $default
    }

    $line = Get-Content .env | Where-Object { $_ -match ("^\s*" + [regex]::Escape($key) + "\s*=") } | Select-Object -First 1
    if (-not $line) {
        return $default
    }

    $value = [regex]::Replace($line, "^\s*" + [regex]::Escape($key) + "\s*=\s*", '')
    $value = $value.Trim()

    if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
        if ($value.Length -ge 2) {
            $value = $value.Substring(1, $value.Length - 2)
        }
    }

    return $value
}

Log-Step "STEP 1/8 - Pre-check command"
php artisan help stock:baseline-rebuild

Log-Step "STEP 2/8 - Clear cache"
php artisan optimize:clear

Log-Step "STEP 3/8 - Backup DB (minimal)"
if ($env:SKIP_BACKUP -eq '1') {
    Write-Warning "Backup dilewati karena SKIP_BACKUP=1"
}
else {
    $dbSource = '.env'
    $dbConfig = Get-DbConfigFromLaravel

    if ($dbConfig) {
        $dbHost = [string]$dbConfig.host
        $dbPort = [string]$dbConfig.port
        $dbName = [string]$dbConfig.database
        $dbUser = [string]$dbConfig.username
        $dbPass = [string]$dbConfig.password
        $dbSource = 'laravel-config'
    }
    else {
        $dbHost = Get-EnvValue 'DB_HOST'
        $dbPort = Get-EnvValue 'DB_PORT' '3306'
        $dbName = Get-EnvValue 'DB_DATABASE'
        $dbUser = Get-EnvValue 'DB_USERNAME'
        $dbPass = Get-EnvValue 'DB_PASSWORD'
    }

    if ([string]::IsNullOrWhiteSpace($dbPort)) {
        $dbPort = '3306'
    }

    if ([string]::IsNullOrWhiteSpace($dbHost) -or [string]::IsNullOrWhiteSpace($dbName) -or [string]::IsNullOrWhiteSpace($dbUser)) {
        Write-Error "DB_HOST/DB_DATABASE/DB_USERNAME tidak valid di .env"
        exit 1
    }

    $sqlDumpPath = Resolve-SqlDumpPath
    if (-not $sqlDumpPath) {
        Write-Error "mariadb-dump/mysqldump tidak ditemukan. Tambahkan ke PATH atau set `$env:MARIADB_DUMP_PATH / `$env:MYSQLDUMP_PATH. Alternatif sementara: set `$env:SKIP_BACKUP='1' untuk lanjut tanpa backup."
        exit 1
    }

    Write-Host "[INFO] Sumber konfigurasi DB: $dbSource"
    Write-Host "[INFO] Menggunakan dump binary: $sqlDumpPath"

    $backupFile = "storage/logs/db_backup_before_stock_fix_$(Get-Date -Format 'yyyyMMdd_HHmmss').sql"

    if ([string]::IsNullOrWhiteSpace($dbPass)) {
        & $sqlDumpPath -h $dbHost -P $dbPort -u $dbUser $dbName | Out-File -Encoding utf8 $backupFile
    }
    else {
        & $sqlDumpPath -h $dbHost -P $dbPort -u $dbUser "-p$dbPass" $dbName | Out-File -Encoding utf8 $backupFile
    }

    Write-Host "[OK] Backup tersimpan: $backupFile"
}

Log-Step "STEP 4/8 - Dry-run awal (tanpa ubah DB)"
php artisan stock:baseline-rebuild
$reportDryrunAwal = Get-LatestReport
Write-Host "[INFO] Report dry-run awal: $reportDryrunAwal"

Log-Step "STEP 5/8 - Apply safe global (skip negative-event)"
php artisan stock:baseline-rebuild --apply
$reportApplySafe = Get-LatestReport
Write-Host "[INFO] Report apply safe: $reportApplySafe"

Log-Step "STEP 6/8 - Verifikasi post-apply safe"
php artisan stock:baseline-rebuild
$reportPostSafe = Get-LatestReport
Write-Host "[INFO] Report post-apply safe: $reportPostSafe"

Log-Step "STEP 6b - Cek report terbaru + daftar skipped"
$tmpCheck = @'
<?php
$files = glob('baseline_rebuild_report_*.json');
if (!$files) { echo "NO_REPORT\n"; exit(1); }
usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));
$f = $files[0];
$j = json_decode(file_get_contents($f), true);

echo "report={$f}\n";
echo "changed=" . ($j['summary']['products_stock_changed'] ?? '-') . "\n";
echo "abs_delta=" . ($j['summary']['total_abs_delta_stock'] ?? '-') . "\n";
echo "skipped=" . ($j['summary']['products_skipped_because_negative_event'] ?? '-') . "\n";

$skipped = $j['skipped_negative_event_products'] ?? [];
usort($skipped, fn($a,$b) => abs((int)$b['delta_stok']) <=> abs((int)$a['delta_stok']));
echo "--SKIPPED_PRIORITY--\n";
foreach ($skipped as $p) {
    echo ($p['id_produk'] ?? '-') . "|" . ($p['nama_produk'] ?? '-') . "|delta=" . ($p['delta_stok'] ?? 0) . "|neg=" . ($p['negative_events_detected'] ?? 0) . "\n";
}
'@

$tmpCheckPath = "tmp_check_report.php"
$tmpCheck | Set-Content $tmpCheckPath -Encoding UTF8
php $tmpCheckPath
Remove-Item $tmpCheckPath -Force

Log-Step "STEP 7/8 - Apply exception negative-event (one-by-one)"
# Urutan sama persis dengan hasil run terbaru yang telah dieksekusi sebelumnya
$exceptionIds = @(63, 23, 994, 860, 293, 323, 410, 676, 356, 473, 175, 727, 42)
foreach ($id in $exceptionIds) {
    Write-Host "[RUN] id_produk=$id"
    php artisan stock:baseline-rebuild --apply --include-negative-events --product=$id
}

Log-Step "STEP 8/8 - Final verification"
php artisan stock:baseline-rebuild
$reportFinal = Get-LatestReport
Write-Host "[INFO] Report final: $reportFinal"

if ($reportFinal) {
    $json = Get-Content $reportFinal -Raw | ConvertFrom-Json
    $finalChanged = [int]$json.summary.products_stock_changed
    $finalSkipped = [int]$json.summary.products_skipped_because_negative_event

    Write-Host "[RESULT] products_stock_changed=$finalChanged; products_skipped_because_negative_event=$finalSkipped"

    if ($finalChanged -ne 0 -or $finalSkipped -ne 0) {
        Write-Warning "Final belum 0. Perlu review manual sebelum dinyatakan selesai penuh."
        exit 2
    }
}

Write-Host ""
Write-Host "[DONE] Flow stock fix selesai tanpa mismatch urutan command."
