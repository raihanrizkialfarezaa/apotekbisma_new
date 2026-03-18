# Spesifikasi Final JSON v2 Pembelian Massal (Strict)

Dokumen ini adalah kontrak data final untuk import pembelian massal.
Mode strict berarti data wajib lolos semua validasi format, relasi, dan konsistensi hitungan.

## 1) File Kontrak

- Schema validasi: JSON_V2_PEMBELIAN_MASSAL_STRICT.schema.json
- Contoh payload siap pakai: CONTOH_JSON_V2_PEMBELIAN_MASSAL.json

## 2) Struktur Root

Field root yang wajib:

- schema_version: string, wajib, harus 2.0
- ppn_persen: number, wajib, harus 11
- purchases: array object, wajib, minimal 1 transaksi

Field root opsional:

- source: string, maks 100
- generated_at: string format YYYY-MM-DD HH:mm:ss

## 3) Struktur Header Per Transaksi

Field wajib:

- nomor_faktur: string, unique antar transaksi, maks 255
- id_supplier: integer >= 1
- tanggal_waktu_faktur: string, format YYYY-MM-DD HH:mm:ss
- diskon: integer 0..100
- detail: array minimal 1 item

Field opsional:

- tanggal_waktu_obat_datang: string format YYYY-MM-DD HH:mm:ss, default mengikuti tanggal_waktu_faktur
- total: integer >= 0
- bayar: integer >= 0

## 4) Struktur Detail Per Item

Field wajib:

- no: integer >= 1
- harga_beli: integer >= 1
- jumlah: integer >= 1

Aturan identitas produk (wajib salah satu ada):

- id_produk
- kode_produk
- nama_produk

Field opsional:

- harga_jual: integer >= 0
- expired_date: string YYYY-MM-DD
- batch: string maks 255
- subtotal: integer >= 1

## 5) Aturan Bisnis Strict (Hard Fail)

Jika salah satu aturan berikut gagal, transaksi harus ditolak.

1. nomor_faktur tidak boleh duplikat dengan data pembelian existing.
2. Semua detail harus resolve ke produk valid.
3. no di dalam detail harus unik per transaksi.
4. harga_beli pada detail diperlakukan sebagai line total (bukan harga satuan).
5. subtotal jika diisi harus sama persis dengan harga_beli.
6. total jika diisi harus sama dengan jumlah semua harga_beli detail.
7. bayar jika diisi harus sama dengan hasil perhitungan final.
8. diskon wajib 0..100.
9. jumlah wajib > 0.
10. Semua angka harus integer rupiah (tanpa desimal).

## 6) Rumus Final Wajib

Per transaksi:

- total_derived = SUM(detail.harga_beli)
- diskon_nominal = ROUND(total_derived \* diskon / 100)
- dpp = total_derived - diskon_nominal
- ppn_nominal = ROUND(dpp \* 11 / 100)
- bayar_derived = dpp + ppn_nominal

Catatan:

- Nilai bayar dari payload tidak boleh dipakai langsung.
- Importer wajib hitung ulang bayar_derived di backend.

## 7) Mapping Ke Tabel (Ringkas)

Header pembelian:

- nomor_faktur -> pembelian.no_faktur
- id_supplier -> pembelian.id_supplier
- tanggal_waktu_faktur -> pembelian.waktu
- tanggal_waktu_obat_datang -> pembelian.waktu_datang
- total_derived -> pembelian.total_harga
- diskon -> pembelian.diskon
- bayar_derived -> pembelian.bayar
- total_item -> SUM(detail.jumlah)

Detail pembelian:

- id_produk hasil mapping -> pembelian_detail.id_produk
- harga_beli -> pembelian_detail.harga_beli
- jumlah -> pembelian_detail.jumlah
- subtotal -> pembelian_detail.subtotal (isi dengan harga_beli)

Update master produk (jika field tersedia):

- harga_jual -> produk.harga_jual
- expired_date -> produk.expired_date
- batch -> produk.batch

## 8) Catatan Implementasi Importer

1. Validasi payload terhadap file schema dulu.
2. Jalankan validasi bisnis strict (cross-field) setelah schema pass.
3. Untuk akurasi sistem saat ini, gunakan harga_beli sebagai line total.
4. Gunakan transaksi database per nomor_faktur.
5. Jika insert langsung, jalankan sinkronisasi stok setelah import.

## 9) Contoh Siap Pakai

Gunakan file CONTOH_JSON_V2_PEMBELIAN_MASSAL.json sebagai template awal.

Angka contoh JM1-2601-00495 di file tersebut sudah mengikuti rumus:

- total = 1.097.873
- diskon = 0
- ppn 11 persen = 120.766
- bayar = 1.218.639
