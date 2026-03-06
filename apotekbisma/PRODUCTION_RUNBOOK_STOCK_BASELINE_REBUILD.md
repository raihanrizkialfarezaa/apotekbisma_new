# Production Runbook: Baseline Rebuild Stok (Shared Hosting)

Dokumen ini adalah panduan eksekusi production yang aman, terukur, dan audit-friendly untuk flow:

- baseline rebuild command `stock:baseline-rebuild`
- safe apply terlebih dahulu (tanpa include negative events)
- remediation bertahap untuk produk exception negative-event

Panduan ini ditulis untuk server Linux shared hosting dengan akses SSH/Terminal.

---

## 1) Tujuan dan prinsip eksekusi

Tujuan:

1. Menyamakan stok produk berdasarkan baseline 31 Des 2025 + event valid pasca cutoff.
2. Meminimalkan risiko dengan strategi safe-mode dulu, lalu exception handling bertahap.
3. Menjaga audit trail (report JSON + log command + backup).

Prinsip wajib:

- Selalu mulai dari dry-run.
- Jangan jalankan `--include-negative-events` secara global.
- Exception negative-event harus one-by-one per produk.
- Selalu ada backup sebelum apply.

---

## 2) Pre-flight checklist (wajib)

Jalankan dari root project Laravel di server production.

## 2.1 Validasi command tersedia

```bash
php artisan help stock:baseline-rebuild
```

Pastikan opsi ini muncul:

- `--apply`
- `--csv`
- `--cutoff`
- `--until`
- `--include-negative-events`
- `--product`

## 2.2 Validasi file konfigurasi & env

Pastikan file ini sudah ikut deploy:

- `app/Console/Commands/RebuildStockFromBaseline.php`
- `config/stock.php`

Cek env terkait:

```bash
grep -E '^STOCK_BASELINE_(CSV|CUTOFF)=' .env
grep -E '^ENABLE_(DESTRUCTIVE_STOCK_TOOLS|LEGACY_STOCK_SYNC)=' .env
```

Nilai yang disarankan:

- `ENABLE_DESTRUCTIVE_STOCK_TOOLS=false`
- `ENABLE_LEGACY_STOCK_SYNC=false`

## 2.3 Letakkan file baseline CSV

Pastikan file baseline ada di path yang sama seperti konfigurasi.

Default path dari config:

- `REKAMAN STOK FINAL 31 DESEMBER 2025.csv`

Jika Anda ingin path custom, gunakan opsi command `--csv=/path/file.csv`.

## 2.4 Siapkan folder log operasi

```bash
mkdir -p storage/logs/stock_ops
```

---

## 3) Backup wajib sebelum apply

## 3.1 Backup database

Contoh (sesuaikan host, user, db):

```bash
mysqldump -h DB_HOST -u DB_USER -p DB_NAME > storage/logs/stock_ops/db_backup_before_rebuild_$(date +%Y%m%d_%H%M%S).sql
```

Jika `mysqldump` tidak tersedia di shared hosting, lakukan export full DB dari phpMyAdmin sebelum lanjut.

## 3.2 Backup file penting aplikasi

```bash
tar -czf storage/logs/stock_ops/app_backup_before_rebuild_$(date +%Y%m%d_%H%M%S).tar.gz app config composer.json artisan
```

---

## 4) Maintenance window (disarankan kuat)

Aktifkan maintenance mode agar tidak ada traffic write selama apply.

```bash
php artisan down --refresh=15
```

Clear cache konfigurasi sebelum run:

```bash
php artisan optimize:clear
```

---

## 5) DRY-RUN global (kanonik)

Run dry-run tanpa apply:

```bash
php artisan stock:baseline-rebuild | tee storage/logs/stock_ops/dryrun_global_$(date +%Y%m%d_%H%M%S).log
```

Command ini akan menghasilkan file report:

- `baseline_rebuild_report_YYYYMMDD_HHMMSS.json`

---

## 6) Validasi report dry-run

Buat helper checker agar pembacaan report konsisten:

```bash
cat > storage/logs/stock_ops/check_report.php <<'PHP'
<?php
$files = glob('baseline_rebuild_report_*.json');
if (!$files) { echo "NO_REPORT\n"; exit(1); }
usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));
$f = $files[0];
$j = json_decode(file_get_contents($f), true);
if (!is_array($j)) { echo "INVALID_JSON\n"; exit(1); }

$summary = $j['summary'] ?? [];
$skipped = $j['skipped_negative_event_products'] ?? [];

$abs = 0;
foreach ($skipped as $p) {
    $abs += abs((int)($p['delta_stok'] ?? 0));
}

echo "report={$f}\n";
echo "mode=" . ($summary['mode'] ?? '-') . "\n";
echo "processed_products=" . ($summary['processed_products'] ?? '-') . "\n";
echo "products_stock_changed=" . ($summary['products_stock_changed'] ?? '-') . "\n";
echo "total_abs_delta_stock=" . ($summary['total_abs_delta_stock'] ?? '-') . "\n";
echo "products_with_negative_event=" . ($summary['products_with_negative_event'] ?? '-') . "\n";
echo "products_skipped_because_negative_event=" . ($summary['products_skipped_because_negative_event'] ?? '-') . "\n";
echo "has_skipped_key=" . (isset($j['skipped_negative_event_products']) ? 'yes' : 'no') . "\n";
echo "skipped_count=" . count($skipped) . "\n";
echo "computed_abs_from_skipped={$abs}\n";

echo "--SKIPPED_PRIORITY--\n";
usort($skipped, fn($a,$b) => abs((int)$b['delta_stok']) <=> abs((int)$a['delta_stok']));
foreach ($skipped as $p) {
    echo ($p['id_produk'] ?? '-') . "|" . ($p['nama_produk'] ?? '-') . "|delta=" . ($p['delta_stok'] ?? 0) . "|neg=" . ($p['negative_events_detected'] ?? 0) . "\n";
}
PHP

php storage/logs/stock_ops/check_report.php | tee storage/logs/stock_ops/check_report_$(date +%Y%m%d_%H%M%S).log
```

Kriteria lulus sebelum apply:

- report terbaca valid
- `has_skipped_key=yes`
- nilai summary masuk akal

---

## 7) APPLY fase-1 (safe mode, tanpa negative events)

Jalankan apply global aman:

```bash
php artisan stock:baseline-rebuild --apply | tee storage/logs/stock_ops/apply_safe_global_$(date +%Y%m%d_%H%M%S).log
```

Catatan penting:

- Command ini hanya apply produk yang `eligible_for_apply=true`.
- Produk dengan event negatif tetap diskip (sesuai safe policy).

---

## 8) Verifikasi pasca apply-safe

Jalankan dry-run lagi:

```bash
php artisan stock:baseline-rebuild | tee storage/logs/stock_ops/dryrun_post_apply_safe_$(date +%Y%m%d_%H%M%S).log
php storage/logs/stock_ops/check_report.php | tee storage/logs/stock_ops/check_post_apply_safe_$(date +%Y%m%d_%H%M%S).log
```

Ekspektasi normal:

- `products_stock_changed` tersisa hanya exception negative-event.
- `skipped_negative_event_products` berisi daftar exception kanonik.

---

## 9) APPLY fase-2 (exception negative-event, one-by-one)

Jangan global. Wajib per produk.

Template command satu produk:

```bash
php artisan stock:baseline-rebuild --apply --include-negative-events --product=ID_PRODUK | tee storage/logs/stock_ops/apply_negative_ID_PRODUK_$(date +%Y%m%d_%H%M%S).log
```

Urutan disarankan (dari delta terbesar):

```text
63, 23, 994, 860, 115, 293, 323, 410, 676, 778, 356, 473, 175, 108, 727, 42, 135
```

Contoh batch awal (top-5), tetap dieksekusi satu per satu:

```bash
php artisan stock:baseline-rebuild --apply --include-negative-events --product=63
php artisan stock:baseline-rebuild --apply --include-negative-events --product=23
php artisan stock:baseline-rebuild --apply --include-negative-events --product=994
php artisan stock:baseline-rebuild --apply --include-negative-events --product=860
php artisan stock:baseline-rebuild --apply --include-negative-events --product=115
```

Setelah tiap produk atau minimal tiap 3 produk, jalankan verifikasi cepat:

```bash
php artisan stock:baseline-rebuild --product=ID_PRODUK
```

Target verifikasi cepat:

- untuk ID tersebut, delta sudah 0 (atau turun sesuai ekspektasi analisis)

---

## 10) Final verification (wajib sebelum go-live)

Setelah semua exception selesai:

```bash
php artisan stock:baseline-rebuild | tee storage/logs/stock_ops/dryrun_final_$(date +%Y%m%d_%H%M%S).log
php storage/logs/stock_ops/check_report.php | tee storage/logs/stock_ops/check_final_$(date +%Y%m%d_%H%M%S).log
```

Kriteria sukses final:

- `products_stock_changed=0`
- `skipped_count=0`
- tidak ada error pada output command

---

## 11) Keluarkan maintenance mode

```bash
php artisan up
```

Lalu lakukan smoke test:

- buka dashboard
- cek kartu stok beberapa produk prioritas
- uji 1 transaksi pembelian dan 1 transaksi penjualan

---

## 12) Rollback plan (jika ada anomaly)

Jika ada hasil tidak sesuai ekspektasi:

1. Jangan lanjut apply berikutnya.
2. Aktifkan maintenance mode.
3. Restore database dari backup sebelum rebuild.
4. Jalankan `php artisan optimize:clear`.
5. Verifikasi data kembali normal.

Restore DB contoh:

```bash
mysql -h DB_HOST -u DB_USER -p DB_NAME < storage/logs/stock_ops/db_backup_before_rebuild_YYYYMMDD_HHMMSS.sql
```

---

## 13) Catatan operasional khusus shared hosting

- Jika command timeout via web terminal, jalankan lewat SSH murni.
- Hindari menjalankan command bersamaan di 2 terminal.
- Simpan semua log `tee` dan report JSON minimal 30 hari.
- Jangan hapus report JSON sebelum audit sign-off selesai.

---

## 14) Ringkasan eksekusi cepat (cheat sheet)

1. Backup DB + app.
2. `php artisan down`
3. `php artisan optimize:clear`
4. Dry-run global + cek report.
5. Apply safe global.
6. Dry-run post-safe + baca skipped list.
7. Apply exception per-produk (one-by-one).
8. Dry-run final harus 0 change.
9. `php artisan up`
