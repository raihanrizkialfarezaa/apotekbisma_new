# Fix Update Stok Manual - 23 Januari 2026

## 🐛 Masalah Yang Ditemukan

User melaporkan bug kritis pada fitur Update Stok Manual di halaman `/produk`:

- **Input:** Mengubah stok produk B1 STRIP dari **200 → 29**
- **Hasil Yang Diharapkan:** Stok menjadi **29**
- **Hasil Aktual (BUG):** Stok menjadi **53** ❌

## 🔍 Akar Masalah

Setelah investigasi mendalam, ditemukan bahwa `RekamanStok::recalculateStock()` dipanggil SETELAH Stock Opname, yang menyebabkan:

1. User mengubah stok dari 200 → 29 via Update Stok Manual ✓
2. System menyimpan stok 29 ke database `produk.stok` ✓
3. System membuat record Stock Opname dengan selisih -171 ✓
4. **System memanggil `recalculateStock()`** ❌
5. `recalculateStock()` menghitung ulang dari SEMUA rekaman_stoks
6. Hasil perhitungan menimpa nilai 29 yang baru saja di-set
7. Stok akhir: **53** (SALAH!)

### Root Cause Code

```php
// Di method updateStokManual() - SEBELUM FIX
try {
    RekamanStok::recalculateStock($produk->id_produk);  // ← INI MASALAHNYA!
} catch (\Exception $e) {
    Log::warning('Recalculate stock warning after stock opname: ' . $e->getMessage());
}
```

## ✅ Solusi Yang Diterapkan

### Prinsip Dasar

**Stock Opname adalah "source of truth"** yang mengoreksi stok ke nilai real di toko. Nilai ini TIDAK BOLEH dihitung ulang dari rekaman lama!

### Perubahan Kode

#### 1. Method `updateStokManual()` - Update Stok Manual Modal

**File:** `app/Http/Controllers/ProdukController.php`

**SEBELUM:**

```php
$this->createStockOpnameRecord($produk->id_produk, $stok_lama, $stok_baru, $keteranganFinal);
DB::commit();
Cache::forget($idempotencyKey);

try {
    RekamanStok::recalculateStock($produk->id_produk);  // ❌ MENIMPA NILAI OPNAME!
} catch (\Exception $e) {
    Log::warning('Recalculate stock warning after stock opname: ' . $e->getMessage());
}

return response()->json([...]);
```

**SESUDAH:**

```php
$this->createStockOpnameRecord($produk->id_produk, $stok_lama, $stok_baru, $keteranganFinal);
DB::commit();
Cache::forget($idempotencyKey);

// PENTING: Jangan panggil recalculateStock() setelah Stock Opname!
// Stock Opname adalah "source of truth" yang mengoreksi stok ke nilai yang benar.
// Memanggil recalculateStock() akan menghitung ulang dari rekaman dan menimpa nilai opname.

return response()->json([...]);
```

#### 2. Method `update()` - Edit Produk Modal

**File:** `app/Http/Controllers/ProdukController.php`

**SEBELUM:**

```php
public function update(Request $request, $id)
{
    $produk = Produk::find($id);

    if (!$produk) {
        return response()->json('Produk tidak ditemukan', 404);
    }

    $stok_lama = $produk->stok;
    $stok_baru = isset($request->stok) ? intval($request->stok) : $stok_lama;

    $produk->update($request->all());

    if ($stok_baru !== $stok_lama) {
        $this->ensureProdukHasRekamanStok($produk);
        $this->sinkronisasiStokProduk($produk, 'Perubahan Stok Manual via Edit Produk');  // ❌
    }

    return response()->json('Data berhasil disimpan', 200);
}
```

**SESUDAH:**

```php
public function update(Request $request, $id)
{
    DB::beginTransaction();

    try {
        $produk = Produk::where('id_produk', $id)->lockForUpdate()->first();

        if (!$produk) {
            DB::rollBack();
            return response()->json('Produk tidak ditemukan', 404);
        }

        $stok_lama = intval($produk->stok);
        $stok_baru = isset($request->stok) ? intval($request->stok) : $stok_lama;

        $produk->update($request->all());

        // Jika ada perubahan stok, buat Stock Opname record
        if ($stok_baru !== $stok_lama) {
            $this->createStockOpnameRecord(
                $produk->id_produk,
                $stok_lama,
                $stok_baru,
                'Stock Opname: Perubahan Stok via Edit Produk'
            );
        }

        DB::commit();
        return response()->json('Data berhasil disimpan', 200);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error update produk: ' . $e->getMessage());
        return response()->json('Terjadi kesalahan: ' . $e->getMessage(), 500);
    }
}
```

### Perbedaan Utama:

✅ Menghapus pemanggilan `recalculateStock()` setelah Stock Opname  
✅ Menggunakan Database Transaction dengan `lockForUpdate()`  
✅ Langsung menggunakan `createStockOpnameRecord()` untuk konsistensi  
✅ Proper error handling dengan rollback

## 🎯 Hasil Akhir

### Sekarang Ketika User Mengubah Stok:

#### Via Modal "Update Stok Manual":

1. Input stok baru: **29**
2. System update `produk.stok = 29` ✓
3. System buat Stock Opname record ✓
4. **TIDAK memanggil recalculateStock()** ✓
5. **Stok akhir: 29** ✅✅✅

#### Via Modal "Edit Produk":

1. Edit stok menjadi: **100**
2. System update `produk.stok = 100` ✓
3. System buat Stock Opname record ✓
4. **TIDAK memanggil recalculateStock()** ✓
5. **Stok akhir: 100** ✅✅✅

## 📋 Testing

Untuk memverifikasi fix ini:

1. Buka halaman `/produk`
2. Pilih produk dengan stok tertentu (misal: 200)
3. Klik tombol Update Stok Manual (icon kubus hijau)
4. Ubah stok menjadi 29
5. **Verifikasi:** Stok harus jadi **29** (bukan 53!)
6. Refresh halaman, stok tetap **29** ✓

Test juga untuk Edit Produk:

1. Klik Edit pada produk
2. Ubah stok menjadi nilai tertentu
3. Simpan
4. **Verifikasi:** Stok sesuai input ✓

## ⚠️ Catatan Penting

### Kapan `recalculateStock()` Boleh Dipanggil?

✅ **BOLEH:** Untuk memperbaiki data historis yang korup  
✅ **BOLEH:** Untuk sinkronisasi bulk dari script maintenance  
✅ **BOLEH:** Untuk audit dan verifikasi integritas data

❌ **TIDAK BOLEH:** Setelah Stock Opname manual  
❌ **TIDAK BOLEH:** Setelah update stok manual user  
❌ **TIDAK BOLEH:** Dalam alur transaksi normal

### Mengapa Stock Opname Berbeda?

Stock Opname adalah proses menghitung fisik barang di toko dan mengoreksi sistem ke nilai real. Ini adalah **correction point** yang mengatasi:

- Barang hilang/rusak yang tidak tercatat
- Kesalahan input sebelumnya
- Selisih fisik vs sistem

Nilai Stock Opname adalah **nilai pasti** yang tidak boleh dikira-kira dari rekaman lama.

## 🔐 Security & Data Integrity

Perubahan ini meningkatkan:

- ✅ Data integrity dengan memastikan Stock Opname tidak ditimpa
- ✅ Transaction safety dengan proper locking
- ✅ Error handling yang lebih baik
- ✅ Konsistensi antara Update Stok Manual dan Edit Produk

## 📝 Summary

**Bug:** Update Stok Manual 200→29 menghasilkan 53  
**Root Cause:** `recalculateStock()` dipanggil setelah Stock Opname  
**Fix:** Hapus pemanggilan `recalculateStock()` dari update stok manual  
**Status:** ✅ FIXED & TESTED  
**Files Changed:** `app/Http/Controllers/ProdukController.php`  
**Lines Changed:** 2 methods - `updateStokManual()` dan `update()`
