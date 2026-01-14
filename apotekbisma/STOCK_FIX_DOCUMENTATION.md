# DOKUMENTASI PERBAIKAN SISTEM STOK ROBUST

## Tanggal: 14 Januari 2026

## MASALAH YANG DITEMUKAN

### 1. Duplikat Record Penjualan
- Ditemukan **19 transaksi penjualan** yang memiliki record duplikat di `rekaman_stoks`
- Total **22 record berlebih** yang menyebabkan pengurangan stok berlebihan
- Contoh: `id_penjualan: 131` muncul 4x, `id_penjualan: 89` muncul 3x

### 2. Race Condition pada Timestamp
- Ketika 2 transaksi terjadi di detik yang sama, urutan tidak konsisten
- Transaksi penjualan bisa tercatat sebelum pembelian meskipun pembelian di-insert duluan
- Menyebabkan `stok_awal` menggunakan nilai yang salah

### 3. Tidak Ada Perlindungan Idempotency
- AJAX request yang dikirim berkali-kali (double-click, network retry) menyebabkan duplikat
- Tidak ada mekanisme untuk mendeteksi dan menolak request yang sudah diproses

### 4. Stok Tidak Sinkron
- `produk.stok` tidak selalu sinkron dengan `stok_sisa` terakhir di `rekaman_stoks`
- Contoh: Produk 48 memiliki `stok = -2` tapi kalkulasi seharusnya `28`

---

## PERBAIKAN YANG DILAKUKAN

### 1. PenjualanDetailController.php (Rewrite Total)

**Perubahan Utama:**
- ✅ **Idempotency Protection**: Menggunakan `Cache::has()` untuk menolak request duplikat dalam 10 detik
- ✅ **Microsecond Timestamp**: Menggunakan `Carbon::now()->format('Y-m-d H:i:s.u')` untuk ordering yang presisi
- ✅ **Atomic Lock**: Menggunakan `lockForUpdate()` pada semua query `rekaman_stoks`
- ✅ **Distributed Lock**: Menggunakan `Cache::lock()` untuk mencegah race condition pada recalculation
- ✅ **Removed Eloquent Hooks**: Menggunakan `DB::table()` untuk bypass model hooks yang menyebabkan overhead

**Fungsi Baru:**
- `atomicRecalculateAndSync($produkId)` - Recalculate dengan distributed lock

### 2. PembelianDetailController.php (Rewrite Total)

**Perubahan Sama dengan PenjualanDetailController:**
- ✅ Idempotency Protection
- ✅ Microsecond Timestamp
- ✅ Atomic Lock pada rekaman_stoks
- ✅ Distributed Lock untuk recalculation
- ✅ Direct DB queries

### 3. ProdukController.php (Enhanced)

**Perubahan:**
- ✅ Added `Cache` import
- ✅ Idempotency pada `updateStokManual()`
- ✅ Microsecond timestamp pada `createStockOpnameRecord()`

### 4. RekamanStok.php Model (Enhanced)

**Perubahan Utama:**
- ✅ **Distributed Lock**: `recalculateStock()` sekarang menggunakan `Cache::lock()` untuk mencegah concurrent execution
- ✅ **Logging Improvements**: Log ketika record di-fix atau sync dilakukan
- ✅ **New Methods:**
  - `cleanupDuplicates($productId)` - Hapus duplikat record untuk produk tertentu
  - `fullRepair($productId)` - Cleanup + recalculate + verify dalam satu panggilan
  - Enhanced `verifyIntegrity()` dengan chain error detection

---

## CARA PENGGUNAAN

### 1. Jalankan Stock Opname Fix (Untuk Data Existing)
```bash
php complete_stock_opname_fix_v3.php --execute
```

### 2. Verifikasi Integritas Setelah Fix
```bash
php verify_stock_integrity.php
```

### 3. Repair Produk Spesifik
```php
use App\Models\RekamanStok;

// Repair produk ID 48
$result = RekamanStok::fullRepair(48);
print_r($result);
```

### 4. Cleanup Semua Duplikat
```php
use App\Models\Produk;
use App\Models\RekamanStok;

foreach(Produk::all() as $produk) {
    RekamanStok::cleanupDuplicates($produk->id_produk);
}
```

---

## PERUBAHAN TEKNIS DETAIL

### Idempotency Key Format
```
penjualan_store_{id_penjualan}_{id_produk}_{user_id}
pembelian_store_{id_pembelian}_{id_produk}_{user_id}
penjualan_update_{id}_{user_id}
pembelian_update_{id}_{user_id}
stok_manual_{id_produk}_{user_id}
```

### Distributed Lock Key Format
```
stock_recalc_{produk_id}        - Lock untuk recalculation per produk
stock_recalc_lock_{produk_id}   - Lock internal di model
```

### Timestamp Format
```
Y-m-d H:i:s.u  (microseconds)
Example: 2026-01-14 15:58:49.123456
```

### Ordering Priority di rekaman_stoks
```sql
ORDER BY waktu ASC, created_at ASC, id_rekaman_stok ASC
```

---

## MONITORING DAN MAINTENANCE

### Log yang Dihasilkan
Semua operasi stok sekarang menghasilkan log di Laravel log:
- `RekamanStok: Auto-correcting stok_sisa on create/update`
- `recalculateStock fixed records`
- `recalculateStock synced produk.stok`
- `cleanupDuplicates removed records`
- `Stock mismatch detected and auto-fixed`

### Cek Kesehatan Sistem
```bash
# Jalankan secara berkala (misalnya weekly)
php verify_stock_integrity.php
```

---

## PENCEGAHAN STOK MINUS (STRICT VALIDATION)

### Perubahan 14 Jan 2026 - Update 2

Semua kode yang sebelumnya **menyembunyikan** masalah stok minus dengan:
```php
if ($stok_baru < 0) $stok_baru = 0;  // SALAH - menyembunyikan masalah
```

Sekarang diganti dengan **MENOLAK** transaksi:
```php
if ($stok_baru < 0) {
    DB::rollBack();
    return response()->json(['error' => true, 'message' => 'Stok tidak mencukupi!'], 400);
}
```

### Lokasi yang Diperbaiki:

| File | Fungsi | Keterangan |
|------|--------|------------|
| `PenjualanDetailController.php` | `store()` | Penambahan produk ke keranjang |
| `PenjualanDetailController.php` | `update()` | Update jumlah item |
| `PembelianDetailController.php` | `update()` | Pengurangan jumlah pembelian |
| `PembelianDetailController.php` | `updateEdit()` | Edit jumlah pembelian |
| `PembelianDetailController.php` | `destroy()` | Hapus item pembelian |

### Skenario yang Sekarang Dicegah:

1. **Penjualan melebihi stok**: Stok = 2, mau jual 4 → **DITOLAK**
2. **Kurangi pembelian yang sudah terjual**: Beli 10, sudah jual 8, mau ubah pembelian jadi 5 → **DITOLAK**  
3. **Hapus pembelian yang sudah terjual**: Stok awal 10 dari pembelian, sudah terjual 8, mau hapus pembelian → **DITOLAK**

### Pesan Error yang Ditampilkan:

- `"Stok habis! Produk tidak dapat dijual karena stok saat ini: X"`
- `"Tidak dapat menambah produk! Stok tersedia: X"`
- `"Tidak dapat mengubah jumlah! Stok tersedia: X, dibutuhkan: Y"`
- `"Tidak dapat menghapus pembelian! ... Hasil akan minus. Produk mungkin sudah terjual."`

2. **Backup database** sebelum menjalankan script fix

3. **Monitor log** setelah deploy untuk memastikan tidak ada warning/error baru

4. **Cache driver** harus mendukung atomic locks (Redis/Memcached recommended, File cache juga bisa bekerja)

---

## FILES YANG DIMODIFIKASI

1. `app/Http/Controllers/PenjualanDetailController.php` - Rewrite total
2. `app/Http/Controllers/PembelianDetailController.php` - Rewrite total  
3. `app/Http/Controllers/ProdukController.php` - Enhanced
4. `app/Models/RekamanStok.php` - Enhanced dengan methods baru

## FILES BARU

1. `verify_stock_integrity.php` - Script verifikasi integritas
