# TEMPLATE FORMAT INPUT FAKTUR PEMBELIAN POST CUTOFF

Dokumen ini adalah template standar untuk input faktur pembelian baru agar konsisten dengan struktur file Januari-Februari yang saat ini diproses command importer.

## 1) Struktur dokumen global (wajib)

1. Baris judul file:

# INPUT FAKTUR PEMBELIAN [NAMA BULAN / PERIODE]

2. Status dokumen (opsional tapi disarankan):

Status: In progress

3. Setiap faktur/halaman harus dimulai dengan header section:

### HALAMAN [NOMOR/HALAMAN]: [NAMA SUPPLIER] (Faktur: [NO_FAKTUR])

4. Gunakan pemisah antar section:

---

## 2) Aturan wajib per section (agar tidak error)

1. Header section HARUS diawali persis dengan teks: ### HALAMAN
2. No faktur wajib ada dan jelas. Paling aman ditaruh di header dalam tanda kurung dengan label Faktur.
3. Tanggal wajib ada. Paling aman gunakan format:
    - **Tanggal: DD/MM/YYYY**
    - atau **Tanggal: DD NamaBulan YYYY** (contoh: 04 Februari 2026)
4. Harus ada tabel markdown dengan kolom yang memuat minimal:
    - Nama produk
    - Qty
5. Semua baris item harus berada di dalam tabel markdown (jangan campur format bebas).
6. Jangan buat sel tabel multi-baris.
7. Jika 1 faktur terdiri dari beberapa section, nomor faktur harus sama persis di semua section.

## 3) Template table yang direkomendasikan

Pakai template ini untuk konsistensi maksimal:

| No  | Nama Produk   | Batch/Exp  | Qty                         | Harga Satuan | Diskon    | Potongan      | Netto Total |
| --- | ------------- | ---------- | --------------------------- | ------------ | --------- | ------------- | ----------- |
| -   | [Nama Produk] | [Batch-ED] | [Angka Qty atau Qty + Unit] | Rp [Harga]   | [Diskon%] | Rp [Potongan] | Rp [Netto]  |

Catatan:

- Qty boleh seperti 3, 3 Box, 6 Btl, 1 Dus/10.
- Harga boleh format Rp 12.345 atau Rp 12.345,50.
- Jika Potongan tidak ada, isi Rp 0.

## 4) Template blok section (copy-paste)

### HALAMAN [N]: [NAMA SUPPLIER] (Faktur: [NO_FAKTUR])

**Tanggal: [DD/MM/YYYY] | Metode: [CASH/KREDIT]**

| No  | Nama Produk     | Batch/Exp  | Qty   | Harga Satuan | Diskon    | Potongan      | Netto Total |
| --- | --------------- | ---------- | ----- | ------------ | --------- | ------------- | ----------- |
| -   | [Nama Produk 1] | [Batch/ED] | [Qty] | Rp [Harga]   | [Diskon%] | Rp [Potongan] | Rp [Netto]  |
| -   | [Nama Produk 2] | [Batch/ED] | [Qty] | Rp [Harga]   | [Diskon%] | Rp [Potongan] | Rp [Netto]  |

**Keuangan:**

- Total I: Rp [Total]
- Ext. Disc: Rp [Disc]
- **TOTAL NETTO + PPN: Rp [Grand Total]**

---

## 5) Template lanjutan halaman faktur yang sama

### HALAMAN [N+1]: [NAMA SUPPLIER] (Faktur: [NO_FAKTUR] - Halaman 2)

**Lanjutan dari Halaman [N]**

| No  | Nama Produk   | Batch/Exp  | Qty   | Harga Satuan | Diskon    | Potongan      | Netto Total |
| --- | ------------- | ---------- | ----- | ------------ | --------- | ------------- | ----------- |
| -   | [Nama Produk] | [Batch/ED] | [Qty] | Rp [Harga]   | [Diskon%] | Rp [Potongan] | Rp [Netto]  |

**Keuangan (Keseluruhan Hal [N] & [N+1]):**

- **TOTAL TAGIHAN FINAL: Rp [Grand Total]**

---

## 6) Template faktur konsolidasi (opsional, didukung)

### HALAMAN [A] & [B]: [NAMA SUPPLIER] (Faktur Konsolidasi: [NO_FAKTUR])

**Tanggal: [DD NamaBulan YYYY]**

| No  | Nama Produk   | Batch   | ED   | Qty          | Harga Satuan | Diskon    | Netto Total |
| --- | ------------- | ------- | ---- | ------------ | ------------ | --------- | ----------- |
| -   | [Nama Produk] | [Batch] | [ED] | [Qty + Unit] | Rp [Harga]   | [Diskon%] | Rp [Netto]  |

**Keuangan:**

- **TOTAL TAGIHAN FINAL: Rp [Grand Total]**

---

## 7) Batasan agar tetap sinkron dengan parser saat ini

1. Jangan ubah prefix header section selain ### HALAMAN.
2. Jangan hapus label Tanggal.
3. Jangan menaruh nomor faktur hanya di footer; taruh di header section.
4. Jangan gunakan tabel tanpa kolom Nama Produk dan Qty.
5. Hindari duplikasi baris item yang sama persis dalam faktur yang sama.
6. Untuk item tanpa Batch/ED, isi - (strip), jangan kosong acak.

## 8) Checklist sebelum commit data markdown baru

1. Setiap section punya nomor faktur.
2. Setiap section punya tanggal valid.
3. Semua item ada di tabel markdown yang rapi.
4. Header kolom konsisten.
5. Nominal harga/netto berupa angka yang bisa diparse.
6. Sudah dry-run import dan cek report JSON.

## 9) Validasi cepat (wajib)

Jalankan dry-run dulu sebelum apply:

php artisan stock:import-post-cutoff-purchases --from="[YYYY-MM-DD 00:00:00]" --until="[YYYY-MM-DD 23:59:59]" --file="[NAMA_FILE_MD]"

Jika semua aman, lanjutkan alur normal sesuai SOP utama.
