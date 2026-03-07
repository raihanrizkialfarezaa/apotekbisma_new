#!/usr/bin/env bash
set -euo pipefail

# Production stock fix runner (Shell)
# Urutan command dibuat sama persis dengan run_stock_fix_production.ps1 dan PRODUCTION_RUN_COMMANDS_SHORT.md

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [[ ! -f artisan ]]; then
  echo "[ERROR] File artisan tidak ditemukan. Jalankan script dari root project Laravel."
  exit 1
fi

mkdir -p storage/logs

RUNNER_SKIP_BACKUP="${SKIP_BACKUP:-0}"
RUNNER_ALLOW_BACKUP_FAIL="${ALLOW_BACKUP_FAIL:-0}"

print_runner_usage() {
  cat <<'TXT'
Usage:
  ./run_stock_fix_production.sh [options]

Options:
  --skip-backup         Lewati STEP 3 backup DB
  --allow-backup-fail   Jika backup gagal, lanjutkan ke step berikutnya
  --help-runner         Tampilkan bantuan runner ini

Env alternatif:
  SKIP_BACKUP=1
  ALLOW_BACKUP_FAIL=1
TXT
}

for arg in "$@"; do
  case "$arg" in
    --skip-backup)
      RUNNER_SKIP_BACKUP="1"
      ;;
    --allow-backup-fail)
      RUNNER_ALLOW_BACKUP_FAIL="1"
      ;;
    --help-runner)
      print_runner_usage
      exit 0
      ;;
  esac
done

log() {
  echo
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

latest_report() {
  ls -1t baseline_rebuild_report_*.json 2>/dev/null | head -n 1 || true
}

resolve_sql_dump_path() {
  if [[ -n "${MARIADB_DUMP_PATH:-}" && -x "${MARIADB_DUMP_PATH}" ]]; then
    echo "${MARIADB_DUMP_PATH}"
    return 0
  fi

  if [[ -n "${MYSQLDUMP_PATH:-}" && -x "${MYSQLDUMP_PATH}" ]]; then
    echo "${MYSQLDUMP_PATH}"
    return 0
  fi

  if command -v mariadb-dump >/dev/null 2>&1; then
    command -v mariadb-dump
    return 0
  fi

  if command -v mysqldump >/dev/null 2>&1; then
    command -v mysqldump
    return 0
  fi

  local candidates=(
    '/usr/bin/mariadb-dump'
    '/usr/local/bin/mariadb-dump'
    '/usr/bin/mysqldump'
    '/usr/local/bin/mysqldump'
    '/c/laragon/bin/mysql/*/bin/mariadb-dump.exe'
    '/c/laragon/bin/mysql/*/bin/mysqldump.exe'
    '/c/Program Files/MySQL/*/bin/mariadb-dump.exe'
    '/c/Program Files/MySQL/*/bin/mysqldump.exe'
    '/c/xampp/mysql/bin/mariadb-dump.exe'
    '/c/xampp/mysql/bin/mysqldump.exe'
  )

  local pattern
  local found
  for pattern in "${candidates[@]}"; do
    found="$(compgen -G "$pattern" | sort -r | head -n 1 || true)"
    if [[ -n "$found" ]]; then
      echo "$found"
      return 0
    fi
  done

  return 1
}

read_db_config_from_laravel() {
  php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$cfg = (array) config("database.connections.mysql", []);
$values = [
  (string)($cfg["host"] ?? ""),
  (string)($cfg["port"] ?? "3306"),
  (string)($cfg["database"] ?? ""),
  (string)($cfg["username"] ?? ""),
  (string)($cfg["password"] ?? ""),
  (string)($cfg["url"] ?? ""),
  (string)($cfg["unix_socket"] ?? "")
];
foreach ($values as $v) {
  echo base64_encode($v), PHP_EOL;
}
' 2>/dev/null || true
}

read_db_config_from_env() {
  local extract_value
  extract_value() {
    local key="$1"
    local line
    line="$(grep -E "^${key}[[:space:]]*=" .env 2>/dev/null | head -n 1 | tr -d '\r' || true)"
    line="$(printf '%s' "$line" | sed -E "s/^${key}[[:space:]]*=[[:space:]]*//")"

    if [[ "$line" =~ ^".*"$ || "$line" =~ ^\'.*\'$ ]]; then
      line="${line:1:${#line}-2}"
    fi

    printf '%s' "$line" | base64 | tr -d '\n'
    printf '\n'
  }

  extract_value "DB_HOST"
  extract_value "DB_PORT"
  extract_value "DB_DATABASE"
  extract_value "DB_USERNAME"
  extract_value "DB_PASSWORD"
  extract_value "DATABASE_URL"
  extract_value "DB_SOCKET"
}

decode_b64() {
  local value="${1:-}"
  if [[ -z "$value" ]]; then
    printf ''
    return 0
  fi

  if printf '%s' "$value" | base64 --decode >/dev/null 2>&1; then
    printf '%s' "$value" | base64 --decode 2>/dev/null || true
  else
    printf '%s' "$value" | base64 -d 2>/dev/null || true
  fi
}

prompt_secret_if_empty() {
  local current_value="${1:-}"
  local prompt_text="${2:-Masukkan nilai rahasia: }"

  if [[ -n "$current_value" ]]; then
    printf '%s' "$current_value"
    return 0
  fi

  if [[ -t 0 ]]; then
    local input_value
    read -r -s -p "$prompt_text" input_value
    echo
    printf '%s' "$input_value"
    return 0
  fi

  printf ''
}

run_db_dump() {
  local dump_bin="$1"
  local backup_file="$2"
  local err_file="$3"
  local db_host="$4"
  local db_port="$5"
  local db_name="$6"
  local db_user="$7"
  local db_pass="$8"
  local db_socket="$9"

  if [[ -n "$db_socket" ]]; then
    if [[ -n "$db_pass" ]]; then
      MYSQL_PWD="$db_pass" "$dump_bin" --protocol=SOCKET --socket="$db_socket" -u "$db_user" "$db_name" > "$backup_file" 2>"$err_file"
    else
      "$dump_bin" --protocol=SOCKET --socket="$db_socket" -u "$db_user" "$db_name" > "$backup_file" 2>"$err_file"
    fi
  else
    if [[ -n "$db_pass" ]]; then
      MYSQL_PWD="$db_pass" "$dump_bin" -h "$db_host" -P "$db_port" -u "$db_user" "$db_name" > "$backup_file" 2>"$err_file"
    else
      "$dump_bin" -h "$db_host" -P "$db_port" -u "$db_user" "$db_name" > "$backup_file" 2>"$err_file"
    fi
  fi
}

parse_database_url_to_b64() {
  local db_url="${1:-}"
  if [[ -z "$db_url" ]]; then
    return 0
  fi

  php -r '
$url = $argv[1] ?? "";
$parts = parse_url($url);
if (!$parts) { exit(0); }
$path = isset($parts["path"]) ? ltrim((string)$parts["path"], "/") : "";
$values = [
  (string)($parts["host"] ?? ""),
  (string)($parts["port"] ?? "3306"),
  (string)$path,
  isset($parts["user"]) ? urldecode((string)$parts["user"]) : "",
  isset($parts["pass"]) ? urldecode((string)$parts["pass"]) : ""
];
foreach ($values as $v) {
  echo base64_encode($v), PHP_EOL;
}
' "$db_url" 2>/dev/null || true
}

log "STEP 1/8 - Pre-check command"
php artisan help stock:baseline-rebuild

log "STEP 2/8 - Clear cache"
php artisan optimize:clear

log "STEP 3/8 - Backup DB (disabled on hosting)"
echo "[INFO] Backup dilewati permanen untuk flow hosting."

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
