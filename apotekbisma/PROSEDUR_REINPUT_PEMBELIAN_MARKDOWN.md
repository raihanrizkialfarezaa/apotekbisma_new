# PROSEDUR RE-INPUT PEMBELIAN PASCA BASELINE CUT-OFF (TANPA RESET DB)

Dokumen ini khusus untuk re-input faktur pembelian pasca cut-off baseline menggunakan data markdown validasi admin.

Prinsip utama:

- Jangan reset database.
- Jangan jalankan migrate:fresh.
- Command ini aman dipakai dalam mode dry-run dulu.
- Apply hanya saat issue sudah dipahami dan disetujui.

## Command Baru

Command artisan:

- stock:import-post-cutoff-purchases

Lokasi implementasi:

- app/Console/Commands/ImportPostCutoffPurchasesFromMarkdown.php

## Alur Aman (Disarankan)

1. Jalankan dry-run untuk rentang post-cutoff yang diinginkan (dinamis).
2. Review report JSON hasil dry-run.
3. Jika ada produk tidak termapping, generate alias template.
4. Opsional: generate alias suggestions untuk mempercepat review mapping.
5. Isi/finalisasi alias JSON dengan id_produk atau nama produk DB yang benar.
6. Ulangi dry-run dengan alias sampai hasil sesuai.
7. Jalankan apply.

## Contoh Eksekusi

Dry-run dinamis dari 1 Januari 2026 sampai akhir bulan berjalan:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00"

Dry-run periode tertentu (contoh: Januari-Mei 2026):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-05-31 23:59:59"

Dry-run dengan file discovery otomatis (default):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-05-31 23:59:59"

Default pattern yang dicari bila --file tidak diisi:

INPUT_FAKTUR_PEMBELIAN\*.md (di root project)

Dry-run dengan direktori + glob khusus (misal arsip bulanan):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-05-31 23:59:59" --file-dir="storage/app/post*cutoff" --file-glob="INPUT_FAKTUR_PEMBELIAN*_.md,INPUT*FAKTUR_TAMBAHAN*_.md"

Dry-run manual file tertentu (override discovery otomatis):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59"

Dry-run + generate template alias unresolved:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias-template="storage/app/product_alias_template.json"

Dry-run + generate kandidat alias (untuk review admin):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias-suggestions="storage/app/product_alias_suggestions.json"

Dry-run + generate template alias dengan autofill kandidat confidence tinggi (tetap wajib review):

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias-template="storage/app/product_alias_template.json" --alias-suggestions="storage/app/product_alias_suggestions.json" --alias-autofill

Dry-run dengan alias produk:

php artisan stock:import-post-cutoff-purchases --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias="storage/app/product_alias_template.json"

Apply setelah valid:

php artisan stock:import-post-cutoff-purchases --apply --from="2026-01-01 00:00:00" --until="2026-02-28 23:59:59" --alias="storage/app/product_alias_template.json"

Penting saat iterasi berulang:

- Jangan gunakan path file yang sama untuk --alias dan --alias-template.
- Gunakan file terpisah agar alias aktif tidak tertimpa (command akan skip tulis template jika path sama).

## Format File Alias JSON

Contoh:

{
"Postinor 2 Tab": "POSTINOR",
"Lactacyd Liq Baby 60ml": 123,
"Dexteem Plus Tab 100's": "DEXTEEM PLUS"
}

Aturan:

- Key: nama produk persis dari markdown.
- Value bisa:
    - id_produk (angka)
    - nama produk yang ada di tabel produk (string)

Catatan alias suggestion:

- File suggestion berisi kandidat mapping + score + gap + flag autofill_safe.
- Gunakan sebagai bahan review, bukan bukti final kebenaran mapping.

## Perilaku Keamanan Command

- Default mode adalah dry-run, tidak ada perubahan DB.
- Insert bersifat idempotent berbasis no_faktur + signature detail.
- Jika no_faktur sudah ada tapi detail berbeda, command stop (conflict) untuk mencegah duplikasi salah.
- Saat apply, seluruh operasi dibungkus transaction. Jika gagal, rollback otomatis.
- Setelah insert, command memanggil reflow stok hanya untuk produk terdampak.

## Catatan Operasional

- Gunakan command ini saat traffic rendah.
- Simpan report JSON setiap eksekusi untuk audit.
- Jika masih ada issue mapping produk, jangan paksa apply tanpa verifikasi admin.
- Untuk penggunaan berulang jangka panjang, cukup geser parameter --until sesuai periode terbaru (misal akhir bulan berjalan) tanpa perlu reset DB.
