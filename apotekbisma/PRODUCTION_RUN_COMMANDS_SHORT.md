# Production Run Commands (Short)

Panduan ini adalah versi singkat, fokus ke urutan command yang dieksekusi dari nol untuk flow fix stok baseline.

## Quick run via script (.sh)

Script otomatis dengan urutan yang sama persis tersedia di:

- `run_stock_fix_production.sh`

Jalankan:

```bash
chmod +x run_stock_fix_production.sh
./run_stock_fix_production.sh
```

Jika ingin skip backup DB (tidak disarankan), jalankan:

```bash
SKIP_BACKUP=1 ./run_stock_fix_production.sh
```

## Quick run via script (PowerShell / .ps1)

Script PowerShell dengan urutan yang sama persis tersedia di:

- `run_stock_fix_production.ps1`

Jalankan di PowerShell:

```powershell
Set-Location "c:\laragon\www\apotekbisma\apotekbisma"
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\run_stock_fix_production.ps1
```

Jika muncul error `mysqldump.exe tidak ditemukan`, set path sekali lalu jalankan ulang:

```powershell
$env:MYSQLDUMP_PATH = "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe"
.\run_stock_fix_production.ps1
```

Jika ingin skip backup DB (tidak disarankan):

```powershell
$env:SKIP_BACKUP = '1'
.\run_stock_fix_production.ps1
Remove-Item Env:SKIP_BACKUP
```

## 0) Masuk ke project

```bash
cd /path/to/apotekbisma
```

## 1) Pre-check + clear cache

```bash
php artisan help stock:baseline-rebuild
php artisan optimize:clear
```

## 2) Backup wajib (minimal DB)

```bash
mysqldump -h DB_HOST -u DB_USER -p DB_NAME > storage/logs/db_backup_before_stock_fix_$(date +%Y%m%d_%H%M%S).sql
```

## 3) Dry-run awal (tanpa ubah DB)

```bash
php artisan stock:baseline-rebuild
```

## 4) Apply safe global (skip negative-event)

```bash
php artisan stock:baseline-rebuild --apply
```

## 5) Verifikasi post-apply safe

```bash
php artisan stock:baseline-rebuild
```

## 6) Cek report terbaru + daftar skipped

```bash
cat > tmp_check_report.php <<'PHP'
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
PHP

php tmp_check_report.php
rm -f tmp_check_report.php
```

## 7) Apply exception negative-event (one-by-one)

Urutan sesuai hasil run terbaru yang kita kerjakan:

```text
63, 23, 994, 860, 293, 323, 410, 676, 356, 473, 175, 727, 42
```

Eksekusi (tetap satu per satu):

```bash
php artisan stock:baseline-rebuild --apply --include-negative-events --product=63
php artisan stock:baseline-rebuild --apply --include-negative-events --product=23
php artisan stock:baseline-rebuild --apply --include-negative-events --product=994
php artisan stock:baseline-rebuild --apply --include-negative-events --product=860
php artisan stock:baseline-rebuild --apply --include-negative-events --product=293
php artisan stock:baseline-rebuild --apply --include-negative-events --product=323
php artisan stock:baseline-rebuild --apply --include-negative-events --product=410
php artisan stock:baseline-rebuild --apply --include-negative-events --product=676
php artisan stock:baseline-rebuild --apply --include-negative-events --product=356
php artisan stock:baseline-rebuild --apply --include-negative-events --product=473
php artisan stock:baseline-rebuild --apply --include-negative-events --product=175
php artisan stock:baseline-rebuild --apply --include-negative-events --product=727
php artisan stock:baseline-rebuild --apply --include-negative-events --product=42
```

## 8) Final verification (harus 0)

```bash
php artisan stock:baseline-rebuild
```

Target final:

- `products_stock_changed = 0`
- `products_skipped_because_negative_event = 0`
