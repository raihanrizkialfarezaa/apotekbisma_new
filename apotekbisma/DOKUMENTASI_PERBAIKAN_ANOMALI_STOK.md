# DOKUMENTASI PERBAIKAN ANOMALI STOK - APOTEK BISMA

**Tanggal Perbaikan:** 2 Januari 2026  
**Masalah:** Diskrepansi perhitungan stok akhir pasca transaksi penjualan setelah Stock Opname

---

## RINGKASAN MASALAH

Klien melaporkan bahwa stok produk terpotong lebih banyak dari seharusnya setelah transaksi penjualan. Contoh kasus:
- **Amoxicillin:** Stok awal 150, dijual 30 (3x10), seharusnya sisa 120, namun sistem menampilkan 118 (selisih 2).
- Masalah juga terjadi pada: Asam Mefenamat, Cetirizin, Dextem

---

## AKAR MASALAH YANG DITEMUKAN

### 1. Double Recalculation pada Model RekamanStok
**Lokasi:** `app/Models/RekamanStok.php` - method `boot()` baris 26-42

**Masalah:**
- Hook `static::created()` memanggil `self::recalculateStock()` setiap kali rekaman dibuat
- Jika ada rekaman yang lebih baru secara kronologis, recalculation terjadi
- Ini menyebabkan cascading recalculation yang dapat mengubah nilai stok

### 2. Duplikat Rekaman Stok per Transaksi
**Lokasi:** `app/Http/Controllers/PenjualanDetailController.php`

**Masalah:**
- Ketika produk yang sama ditambahkan ke keranjang beberapa kali, rekaman stok baru dibuat setiap kali
- Seharusnya rekaman stok existing di-update, bukan membuat yang baru
- Ini menyebabkan stok terpotong multiple kali

### 3. normalizeNegativeStock() di Constructor
**Lokasi:** `PenjualanDetailController` dan `PembelianDetailController` constructor

**Masalah:**
- Setiap kali controller diinisialisasi, semua produk dengan stok < 0 di-update jadi 0
- Ini adalah overhead yang tidak perlu dan dapat menyebabkan race condition

### 4. Stock Opname Tidak Sinkron dengan Recalculation
**Lokasi:** `app/Http/Controllers/ProdukController.php` - method `updateStokManual()`

**Masalah:**
- Setelah stock opname, rekaman stok dibuat dengan `stok_awal` dari rekaman terakhir
- Ketika transaksi penjualan berikutnya dibuat, `recalculateStock()` dipanggil dan dapat mengubah chain

---

## PERBAIKAN YANG DILAKUKAN

### 1. Perbaikan Model RekamanStok (`app/Models/RekamanStok.php`)

**Perubahan:**
- **Hapus hook `static::created()`** yang menyebabkan cascading recalculation
- Tambahkan flag `$preventRecalculation` untuk mencegah recursive calls
- Pertahankan validasi di hook `creating` dan `updating` untuk auto-correct kalkulasi
- Optimasi method `recalculateStock()` untuk menggunakan batch update
- Tambahkan method `getCalculatedStock()` dan `verifyIntegrity()` untuk debugging

### 2. Perbaikan PenjualanDetailController (`app/Http/Controllers/PenjualanDetailController.php`)

**Perubahan:**
- **Hapus `normalizeNegativeStock()` dari constructor** - tidak diperlukan karena mutator sudah handle
- **Cek existing detail sebelum insert** - jika produk sudah ada di keranjang, update jumlahnya
- **Update existing rekaman stok** - daripada buat baru, update rekaman yang sudah ada
- **Gunakan DB::table()** untuk operasi yang tidak butuh hooks
- **Recalculate setelah commit** - bukan di dalam transaksi
- **Method terpisah `createRekamanStokPenjualan()`** untuk membuat rekaman yang konsisten

### 3. Perbaikan PembelianDetailController (`app/Http/Controllers/PembelianDetailController.php`)

**Perubahan:**
- **Hapus `normalizeNegativeStock()` dari constructor**
- **Cek existing detail dan rekaman** sebelum insert baru
- **Update existing rekaman** saat jumlah berubah
- **Safe recalculation** setelah commit transaksi

### 4. Perbaikan ProdukController (`app/Http/Controllers/ProdukController.php`)

**Perubahan:**
- **Method `updateStokManual()` menggunakan transaction dengan lock**
- **Gunakan DB::table()** untuk menghindari Eloquent boot hooks
- **Method terpisah `createStockOpnameRecord()`** untuk membuat rekaman stock opname
- **Recalculate setelah commit** untuk memastikan konsistensi

---

## SCRIPT MAINTENANCE

### 1. Script Repair (`repair_stock_sync.php`)

**Fungsi:**
- Menghapus rekaman stok duplikat
- Memperbaiki mismatch antara detail transaksi dengan rekaman stok
- Recalculate semua stok produk
- Sinkronisasi stok produk dengan rekaman terakhir

**Cara Jalankan:**
```bash
php repair_stock_sync.php
```

### 2. Script Stress Test (`stress_test_stock.php`)

**Fungsi:**
- Validasi integritas database
- Validasi kalkulasi stok konsisten
- Simulasi transaksi penjualan
- Cek duplikat rekaman
- Validasi sinkronisasi stok
- Validasi formula kalkulasi
- Test concurrency (lock)

**Cara Jalankan:**
```bash
php stress_test_stock.php
```

### 3. Script Diagnosa (`diagnose_stock_anomaly.php`)

**Fungsi:**
- Analisis mendalam untuk mencari anomali stok
- Deteksi duplikat dan mismatch
- Mengidentifikasi produk dengan masalah

---

## PREVENTIVE MEASURES

### 1. Konsistensi Rekaman Stok

Setiap rekaman stok HARUS memenuhi formula:
```
stok_sisa = stok_awal + stok_masuk - stok_keluar
```

Model RekamanStok sudah auto-correct jika formula tidak sesuai.

### 2. Satu Rekaman per Transaksi per Produk

Untuk setiap kombinasi `(id_penjualan, id_produk)` atau `(id_pembelian, id_produk)`, HANYA boleh ada SATU rekaman stok.

### 3. Lock untuk Operasi Stok

Semua operasi yang mengubah stok HARUS menggunakan `lockForUpdate()` untuk mencegah race condition.

### 4. Recalculation Setelah Commit

`RekamanStok::recalculateStock()` HARUS dipanggil SETELAH transaksi di-commit, bukan di dalam transaksi.

---

## CARA VERIFIKASI PERBAIKAN

1. **Jalankan stress test:**
   ```bash
   php stress_test_stock.php
   ```
   Semua test harus PASS.

2. **Cek integritas produk:**
   ```php
   $integrity = RekamanStok::verifyIntegrity($productId);
   // $integrity['valid'] harus true
   ```

3. **Bandingkan stok produk dengan rekaman terakhir:**
   ```sql
   SELECT p.nama_produk, p.stok as stok_produk, 
          (SELECT stok_sisa FROM rekaman_stoks 
           WHERE id_produk = p.id_produk 
           ORDER BY waktu DESC, id_rekaman_stok DESC 
           LIMIT 1) as stok_rekaman
   FROM produk p
   HAVING stok_produk != stok_rekaman;
   ```

---

## MONITORING & LOGGING

Perbaikan ini menambahkan logging untuk:
- Stock changes yang besar (> 100 unit) → `Log::warning()`
- Auto-correction pada rekaman stok → `Log::warning()`
- Error pada recalculation → `Log::warning()`
- Error pada sinkronisasi → `Log::error()`

---

## CATATAN PENTING

1. **SEBELUM menggunakan sistem**, jalankan `repair_stock_sync.php` untuk memperbaiki data historis

2. **Jika terjadi anomali lagi**, jalankan `diagnose_stock_anomaly.php` terlebih dahulu untuk identifikasi masalah

3. **Setelah stock opname manual**, sistem akan otomatis membuat rekaman dengan keterangan "Stock Opname"

4. **Jangan edit database langsung** - selalu gunakan interface sistem atau script yang disediakan

---

## SUPPORT

Jika masih ditemukan masalah:
1. Cek log Laravel di `storage/logs/laravel.log`
2. Jalankan `diagnose_stock_anomaly.php`
3. Screenshot error dan kirim ke developer

---

**Dokumentasi dibuat oleh:** AI Assistant  
**Tanggal:** 2 Januari 2026
