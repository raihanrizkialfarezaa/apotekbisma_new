# PROSEDUR RE-INPUT PEMBELIAN PASCA CUT-OFF (RINGKAS)

## Aturan Wajib

- Jangan reset database.
- Jangan migrate:fresh.
- Selalu mulai dari dry-run.
- Untuk import darurat jan-feb via JSON executable, gunakan --preserve-subtotal agar subtotal sumber tetap dipakai apa adanya.

## Command

- stock:import-post-cutoff-purchases
- Implementasi: app/Console/Commands/ImportPostCutoffPurchasesFromMarkdown.php

## Dinamis & Berulang

- Fitur sudah dinamis untuk periode berjalan ke depan.
- Ulang eksekusi kapan pun dengan menggeser --until (misal akhir bulan terbaru).
- Jika --file tidak diisi, command auto-discovery file markdown.

Default discovery:

- folder: root project
- pattern: INPUT_FAKTUR_PEMBELIAN\*.md

## Template Input Baru (Disarankan)

- Gunakan template standar: TEMPLATE_FORMAT_INPUT_FAKTUR_PEMBELIAN_POST_CUTOFF.md
- Untuk penambahan pembelian baru atau pelengkapan missing date, ikuti format section/tabel di template agar parsing tetap konsisten.

## Opsi Penting

- --file: file markdown manual (bisa banyak)
- --file-dir: folder sumber markdown
- --file-glob: pattern file markdown (bisa koma)
- --preserve-subtotal: gunakan subtotal dari input apa adanya (tanpa dipaksa harga_beli x jumlah)
- --alias: file alias aktif
- --alias-template: generate template alias unresolved
- --alias-suggestions: generate kandidat mapping
- --alias-autofill: isi template dengan kandidat confidence tinggi
- --force-map-all-products: paksa auto-map semua produk unresolved (mode deadline)
- --force-map-min-score: batas skor minimum untuk mode force map (default: 50)
- --apply: tulis ke database

## Alur Normal (Aman)

1. Dry-run periode target.
2. Review report JSON.
3. Jika ada mapping gagal, gunakan alias/suggestions.
4. Ulang dry-run sampai issue siap.
5. Apply.

## Alur Deadline (Tanpa Input Manual Admin)

1. Jalankan dry-run dengan --force-map-all-products.
2. Pastikan issue produk = 0 (yang boleh tersisa hanya missing_invoice_date).
3. Simpan hasil mapping auto ke file alias permanen.
4. Ulang dry-run pakai --alias (tanpa force) untuk validasi repeatable.
5. Apply.

## Contoh Eksekusi

Dry-run dinamis (1 Jan 2026 sampai akhir Mei 2026):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-05-31 23:59:59"

Dry-run dengan discovery custom:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-05-31 23:59:59" --file-dir="storage/app/post-cutoff" --file-glob="INPUT_FAKTUR_PEMBELIAN*.md,INPUT_FAKTUR_TAMBAHAN*.md"

Dry-run mode deadline auto-map:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --force-map-all-products --force-map-min-score=50

Dry-run validasi ulang pakai alias auto:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias="storage/app/product_alias_auto_deadline.json"

Apply:

php artisan stock:import-post-cutoff-purchases --apply --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias="storage/app/product_alias_auto_deadline.json"

## Alur Darurat JSON Executable (Jan-Feb)

Gunakan file final executable agar tidak tergantung parser tabel markdown:

1. Dry-run:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="DATA_INPUT_JANUARI_EXECUTABLE.json" --file="DATA_INPUT_FEBRUARI_EXECUTABLE.json" --preserve-subtotal --report="post_cutoff_purchase_reinput_report_janfeb_json_dryrun.json"

2. Apply:

php artisan stock:import-post-cutoff-purchases --apply --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="DATA_INPUT_JANUARI_EXECUTABLE.json" --file="DATA_INPUT_FEBRUARI_EXECUTABLE.json" --preserve-subtotal --report="post_cutoff_purchase_reinput_report_janfeb_json_apply.json"

3. Post-check idempotent:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="DATA_INPUT_JANUARI_EXECUTABLE.json" --file="DATA_INPUT_FEBRUARI_EXECUTABLE.json" --preserve-subtotal --report="post_cutoff_purchase_reinput_report_janfeb_json_postcheck.json"

## Format Alias JSON

Contoh:

{
"Lactacyd Liq Baby 60ml": 123,
"Dexteem Plus Tab 100's": "DEXTEEM PLUS"
}

Aturan:

- Key: nama produk dari markdown.
- Value: id_produk (angka) atau nama produk DB (string).

## Keamanan & Integritas

- Dry-run tidak mengubah DB.
- Insert idempotent berbasis no_faktur + signature detail.
- Jika no_faktur sama tapi detail beda: dianggap conflict (tidak dipaksa insert).
- Apply dibungkus transaction (gagal => rollback).
- Reflow stok hanya untuk produk terdampak.
- Jika --alias dan --alias-template menunjuk file yang sama, penulisan template otomatis di-skip agar alias aktif tidak tertimpa.

## Catatan Sinkronisasi CRUD UI

- Sinkronisasi stok pembelian UI kini terpusat untuk mutasi detail/batch/hapus/cancel draft pada produk terdampak.
- Untuk transaksi finalized pasca cut-off, sistem memprioritaskan reflow baseline per produk terdampak agar kartu stok tetap konsisten.
- Jika reflow gagal, sistem fallback ke recalculate berurutan per produk agar operasi tetap berlanjut dengan aman.

## Catatan Operasional Shared Hosting

- Tetap kompatibel di shared hosting, namun hindari operasi besar di jam sibuk karena batas CPU/memori/timeout.
- Untuk import besar lintas banyak faktur/produk, prioritaskan eksekusi via CLI/cron agar lebih stabil dibanding request web biasa.
- Jika ada timeout di web, ulangi via dry-run + apply bertahap per periode lebih kecil.

## Catatan Audit

- Simpan report JSON setiap run.
- Missing invoice date tetap harus diselesaikan agar invoice tersebut bisa ikut insert.

## Eksekusi Aktual Jan-Feb (17-03-2026)

Command yang dipakai:

1. Cek file input:

Get-ChildItem -Path . -File -Filter "INPUT_FAKTUR_PEMBELIAN\*.md" | Select-Object -ExpandProperty Name

2. Dry-run validasi:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="INPUT_FAKTUR_PEMBELIAN_JANUARI.md" --file="INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md" --alias="storage/app/product_alias_auto_deadline.json"

3. Apply robust (parsial terkontrol karena masih ada missing_invoice_date):

php artisan stock:import-post-cutoff-purchases --apply --allow-partial --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="INPUT_FAKTUR_PEMBELIAN_JANUARI.md" --file="INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md" --alias="storage/app/product_alias_auto_deadline.json"

Ringkasan hasil run:

- Invoice inserted: 71
- Detail inserted: 479
- Produk terdampak: 342
- Issue tersisa: 10 (type: missing_invoice_date)
- Report apply: post_cutoff_purchase_reinput_report_20260317_051303.json

## Eksekusi Lanjutan Perbaikan Februari (17-03-2026)

Tujuan:

- Menormalkan data faktur Februari yang sempat terbaca missing date/produk tidak terpetakan.

Command yang dipakai:

1. Dry-run verifikasi setelah perbaikan section Februari:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="INPUT_FAKTUR_PEMBELIAN_JANUARI.md" --file="INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md" --alias="storage/app/product_alias_auto_deadline.json"

2. Siapkan alias gabungan untuk produk panjang/no-retur (file baru):

storage/app/product_alias_auto_deadline_plus_feb.json

3. Dry-run final (konfigurasi yang dipakai untuk apply):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="INPUT_FAKTUR_PEMBELIAN_JANUARI.md" --file="INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md" --alias="storage/app/product_alias_auto_deadline_plus_feb.json" --force-map-all-products --force-map-min-score=50 --report="post_cutoff_purchase_reinput_report_force_map_aliasplus_janfeb_20260317_dryrun.json"

4. Apply final:

php artisan stock:import-post-cutoff-purchases --apply --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="INPUT_FAKTUR_PEMBELIAN_JANUARI.md" --file="INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md" --alias="storage/app/product_alias_auto_deadline_plus_feb.json" --force-map-all-products --force-map-min-score=50 --report="post_cutoff_purchase_reinput_report_force_map_aliasplus_janfeb_20260317_apply.json"

5. Post-check idempotent:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --file="INPUT_FAKTUR_PEMBELIAN_JANUARI.md" --file="INPUT_FAKTUR_PEMBELIAN_FEBRUARI.md" --alias="storage/app/product_alias_auto_deadline_plus_feb.json" --force-map-all-products --force-map-min-score=50 --report="post_cutoff_purchase_reinput_report_force_map_aliasplus_janfeb_20260317_postcheck.json"

Ringkasan hasil:

- Dry-run final sebelum apply: Insertable 11, Existing Same 70, Issues 0.
- Apply final: Invoice inserted 11, Detail inserted 93, Produk terdampak 85.
- Post-check: Insertable 0, Existing Same 81, Issues 0.
