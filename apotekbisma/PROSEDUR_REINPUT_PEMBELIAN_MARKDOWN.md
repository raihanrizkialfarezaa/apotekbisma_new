# PROSEDUR RE-INPUT PEMBELIAN PASCA CUT-OFF (RINGKAS)

## Aturan Wajib

- Jangan reset database.
- Jangan migrate:fresh.
- Selalu mulai dari dry-run.

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

## Catatan Audit

- Simpan report JSON setiap run.
- Missing invoice date tetap harus diselesaikan agar invoice tersebut bisa ikut insert.
