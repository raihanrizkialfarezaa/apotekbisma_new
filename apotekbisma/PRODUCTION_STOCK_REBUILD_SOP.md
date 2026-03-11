# Production Stock Rebuild SOP

Dokumen ini mengikuti flow final yang dipakai dan tervalidasi di lingkungan kerja ini.

## Prasyarat

- Deploy file kode yang sudah diperbaiki.
- Jika database production masih berasal dari struktur lama, jalankan migration terlebih dahulu:

```powershell
php artisan migrate
```

- Upload file `REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv` ke root project.
- Pastikan `.env` production memiliki nilai ini:

```env
STOCK_BASELINE_CSV="REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv"
STOCK_BASELINE_CUTOFF="2025-12-31 23:59:59"
STOCK_STALE_DRAFT_MINUTES=30
```

- Backup database sebelum menjalankan apply.
- Jangan jalankan cleanup draft lama atau script ad hoc lain di root project.
- Jangan jalankan `migrate:fresh`.

## Flow Reset Pembelian Pasca-Cutoff

Pakai flow ini hanya jika domain pembelian pasca-cutoff memang diputuskan akan direset total, sementara penjualan dan event stok non-pembelian tetap dipertahankan. Tujuannya adalah menghapus semua pembelian dengan waktu efektif `> cutoff` lalu input ulang faktur asli yang sudah divalidasi admin dari awal cutoff sampai hari ini.

Urutan aman:

1. Backup database penuh.
2. Freeze input transaksi baru selama reset berjalan.
3. Jalankan dry-run purge pembelian pasca-cutoff.
4. Jalankan apply purge pembelian pasca-cutoff.
5. Input ulang seluruh faktur pembelian asli yang valid dari awal cutoff sampai hari ini.
6. Jalankan flow rebuild presisi untuk verifikasi akhir.

Dry-run:

```powershell
php artisan stock:purge-post-cutoff-purchases
```

Apply:

```powershell
php artisan stock:purge-post-cutoff-purchases --apply --force
```

Jika juga ingin menghapus audit perubahan tanggal pembelian yang terkait dengan data yang dipurge: (optional)

```powershell
php artisan stock:purge-post-cutoff-purchases --apply --force --delete-audits
```
One-line command:

```powershell
php artisan stock:purge-post-cutoff-purchases; php artisan stock:purge-post-cutoff-purchases --apply --force
```

Command ini:

- hanya menyentuh `pembelian`, `pembelian_detail`, dan `rekaman_stoks` yang terkait `id_pembelian`
- tidak menghapus transaksi penjualan
- tidak menghapus event manual stok yang tidak terkait `id_pembelian`
- merecalculate stok produk terdampak berdasarkan `id_produk` unik agar tidak double-hit karena duplicate id
- membuat report JSON `post_cutoff_purchase_purge_report_*.json` di root project

## Flow Rebuild Presisi

Flow ini sama persis dengan yang dipakai di sini: audit dulu, dry-run default, dry-run final dengan `--include-negative-events`, apply, lalu verify. Bedanya hanya `until` dibekukan sesuai waktu saat Anda menjalankan proses.

```powershell
$until = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

php artisan optimize:clear
php generate_negative_stock_audit_markdown.php --until="$until"
php artisan stock:baseline-rebuild --until="$until"
php artisan stock:baseline-rebuild --until="$until" --include-negative-events
php artisan stock:baseline-rebuild --apply --until="$until" --include-negative-events
php artisan stock:baseline-rebuild --until="$until" --include-negative-events
```

Versi dijadikan satu command:

```powershell
$until=(Get-Date -Format "yyyy-MM-dd HH:mm:ss"); php artisan optimize:clear; php generate_negative_stock_audit_markdown.php --until="$until"; php artisan stock:baseline-rebuild --until="$until"; php artisan stock:baseline-rebuild --until="$until" --include-negative-events; php artisan stock:baseline-rebuild --apply --until="$until" --include-negative-events; php artisan stock:baseline-rebuild --until="$until" --include-negative-events
```

Hasil verifikasi akhir harus menunjukkan:

- `products_stock_changed = 0`
- `total_abs_delta_stock = 0`

## Flow Forensik Replay

Pakai ini hanya jika ingin mengulang investigasi historis ke titik waktu tertentu, misalnya kasus yang sudah dikerjakan di sini sampai `2026-03-09 23:59:59`.

```powershell
$until = "2026-03-09 23:59:59"

php artisan optimize:clear
php generate_negative_stock_audit_markdown.php --until="$until"
php artisan stock:baseline-rebuild --until="$until"
php artisan stock:baseline-rebuild --until="$until" --include-negative-events
php artisan stock:baseline-rebuild --apply --until="$until" --include-negative-events
php artisan stock:baseline-rebuild --until="$until" --include-negative-events
```

Versi dijadikan satu command:

```powershell
$until="2026-03-09 23:59:59"; php artisan optimize:clear; php generate_negative_stock_audit_markdown.php --until="$until"; php artisan stock:baseline-rebuild --until="$until"; php artisan stock:baseline-rebuild --until="$until" --include-negative-events; php artisan stock:baseline-rebuild --apply --until="$until" --include-negative-events; php artisan stock:baseline-rebuild --until="$until" --include-negative-events
```

Jangan pakai `now()` yang berbeda-beda per command untuk flow `audit -> dry-run -> apply -> verify`, karena hasilnya bisa bergeser saat transaksi baru masuk.

## Checklist Hasil

- File audit markdown terbentuk untuk nilai `until` yang sama dengan proses rebuild.
- File report JSON dry-run, apply, dan verify tersimpan.
- Verifikasi akhir menunjukkan nol delta.
- Produk yang tidak ada di CSV baseline tetap diabaikan.
- Produk yang match CSV dan database terhitung ulang dari baseline CSV + event pasca-cutoff.

## Catatan Penting

- Flow ini menganggap data sebelum baseline tidak lagi dipakai sebagai sumber kebenaran.
- Source of truth awal adalah CSV baseline.
- Flow ini aman dipakai ulang, selama semua command dalam satu sesi memakai nilai `until` yang sama.
- Edit tanggal final transaksi pembelian/penjualan pasca-cutoff sekarang menyimpan metadata otomatis ke tabel `transaction_date_change_audits`; admin tidak perlu menulis alasan bebas teks.
- Edit tanggal final yang mencoba menyentuh cutoff atau memindahkan transaksi ke periode `<= 2025-12-31 23:59:59` akan diblokir untuk menjaga integritas baseline.
- Finalisasi transaksi baru yang diinput terlambat tetapi memakai tanggal kejadian pasca-cutoff juga akan memicu sinkronisasi historis otomatis untuk produk terdampak.
- Produk yang tidak ada di baseline CSV tidak diblokir; sistem akan memakai seed stok aman dari rekaman terakhir sebelum cutoff, atau `0` bila produk memang baru muncul pasca-cutoff.
