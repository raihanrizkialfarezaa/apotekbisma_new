#!/usr/bin/env bash
set -euo pipefail

# Production stock fix runner
# Urutan command dibuat sama dengan PRODUCTION_RUN_COMMANDS_SHORT.md

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [[ ! -f artisan ]]; then
  echo "[ERROR] File artisan tidak ditemukan. Jalankan script dari root project Laravel."
  exit 1
fi

mkdir -p storage/logs

log() {
  echo
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

latest_report() {
  ls -1t baseline_rebuild_report_*.json 2>/dev/null | head -n 1 || true
}

log "STEP 1/8 - Pre-check command"
php artisan help stock:baseline-rebuild

log "STEP 2/8 - Clear cache"
php artisan optimize:clear

log "STEP 3/8 - Backup DB (minimal)"
if [[ "${SKIP_BACKUP:-0}" == "1" ]]; then
  echo "[WARN] Backup dilewati karena SKIP_BACKUP=1"
else
  DB_HOST="$(grep -E '^DB_HOST=' .env | head -n1 | cut -d'=' -f2- | tr -d '\r' | sed 's/^"//;s/"$//')"
  DB_PORT="$(grep -E '^DB_PORT=' .env | head -n1 | cut -d'=' -f2- | tr -d '\r' | sed 's/^"//;s/"$//')"
  DB_DATABASE="$(grep -E '^DB_DATABASE=' .env | head -n1 | cut -d'=' -f2- | tr -d '\r' | sed 's/^"//;s/"$//')"
  DB_USERNAME="$(grep -E '^DB_USERNAME=' .env | head -n1 | cut -d'=' -f2- | tr -d '\r' | sed 's/^"//;s/"$//')"
  DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | head -n1 | cut -d'=' -f2- | tr -d '\r' | sed 's/^"//;s/"$//')"

  if [[ -z "$DB_HOST" || -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
    echo "[ERROR] DB_HOST/DB_DATABASE/DB_USERNAME tidak valid di .env"
    exit 1
  fi

  BACKUP_FILE="storage/logs/db_backup_before_stock_fix_$(date +%Y%m%d_%H%M%S).sql"
  if [[ -n "$DB_PASSWORD" ]]; then
    mysqldump -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > "$BACKUP_FILE"
  else
    mysqldump -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" > "$BACKUP_FILE"
  fi
  echo "[OK] Backup tersimpan: $BACKUP_FILE"
fi

log "STEP 4/8 - Dry-run awal (tanpa ubah DB)"
php artisan stock:baseline-rebuild
REPORT_DRYRUN_AWAL="$(latest_report)"
echo "[INFO] Report dry-run awal: ${REPORT_DRYRUN_AWAL:-N/A}"

log "STEP 5/8 - Apply safe global (skip negative-event)"
php artisan stock:baseline-rebuild --apply
REPORT_APPLY_SAFE="$(latest_report)"
echo "[INFO] Report apply safe: ${REPORT_APPLY_SAFE:-N/A}"

log "STEP 6/8 - Verifikasi post-apply safe"
php artisan stock:baseline-rebuild
REPORT_POST_SAFE="$(latest_report)"
echo "[INFO] Report post-apply safe: ${REPORT_POST_SAFE:-N/A}"

log "STEP 6b - Cek report terbaru + daftar skipped"
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

log "STEP 7/8 - Apply exception negative-event (one-by-one)"
# Urutan sama persis dengan hasil run terbaru yang telah dieksekusi sebelumnya
EXCEPTION_IDS=(63 23 994 860 293 323 410 676 356 473 175 727 42)
for id in "${EXCEPTION_IDS[@]}"; do
  echo "[RUN] id_produk=${id}"
  php artisan stock:baseline-rebuild --apply --include-negative-events --product="$id"
done

log "STEP 8/8 - Final verification"
php artisan stock:baseline-rebuild
REPORT_FINAL="$(latest_report)"
echo "[INFO] Report final: ${REPORT_FINAL:-N/A}"

if [[ -n "$REPORT_FINAL" ]]; then
  FINAL_CHANGED="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo (int)($j["summary"]["products_stock_changed"] ?? -1);' "$REPORT_FINAL")"
  FINAL_SKIPPED="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo (int)($j["summary"]["products_skipped_because_negative_event"] ?? -1);' "$REPORT_FINAL")"
  echo "[RESULT] products_stock_changed=${FINAL_CHANGED}; products_skipped_because_negative_event=${FINAL_SKIPPED}"

  if [[ "$FINAL_CHANGED" != "0" || "$FINAL_SKIPPED" != "0" ]]; then
    echo "[WARN] Final belum 0. Perlu review manual sebelum dinyatakan selesai penuh."
    exit 2
  fi
fi

echo
echo "[DONE] Flow stock fix selesai tanpa mismatch urutan command."
