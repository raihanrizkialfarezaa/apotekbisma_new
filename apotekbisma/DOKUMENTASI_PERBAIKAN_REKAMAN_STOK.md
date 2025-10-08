# DOKUMENTASI PERBAIKAN REKAMAN STOK

## 📋 RINGKASAN

Script ini dirancang khusus untuk memperbaiki perhitungan rekaman stok yang tidak konsisten atau salah kalkulasi. Script akan menghitung ulang `stok_awal` dan `stok_sisa` secara berurutan tanpa mengubah data stok realtime pada tabel produk.

## 🎯 TUJUAN

Menyelesaikan masalah pada data historis kartu stok dimana:

-   Stok akhir tidak sesuai dengan perhitungan (stok_awal + stok_masuk - stok_keluar)
-   Stok akhir bertambah padahal seharusnya berkurang (atau sebaliknya)
-   Inkonsistensi perhitungan antara rekaman stok berurutan

## 🔍 CONTOH MASALAH

**Sebelum Perbaikan:**

```
No. 24: Stok Akhir = 70
No. 18: Stok Keluar = 25, Stok Akhir = 110 ❌ (Harusnya 45)
```

**Setelah Perbaikan:**

```
No. 24: Stok Akhir = 70
No. 18: Stok Keluar = 25, Stok Akhir = 45 ✅ (70 - 25 = 45)
```

## ⚙️ CARA KERJA

### Algoritma Perbaikan:

1. **Pengambilan Data**

    - Ambil semua produk yang memiliki rekaman stok
    - Urutkan rekaman berdasarkan waktu (ASC) dan ID (ASC)

2. **Perhitungan Ulang**

    - Untuk setiap produk:
        - Rekaman pertama: stok_awal tetap (dari data awal produk)
        - Rekaman berikutnya:
            ```
            stok_awal = stok_sisa rekaman sebelumnya
            stok_sisa = stok_awal + stok_masuk - stok_keluar
            ```

3. **Update Database**

    - Update hanya jika ada perbedaan nilai
    - Gunakan transaction untuk keamanan
    - Commit jika semua berhasil, rollback jika error

4. **Verifikasi**
    - Cek apakah masih ada inkonsistensi
    - Tampilkan laporan detail

## 🚀 CARA PENGGUNAAN

### Metode 1: Via Web Browser (RECOMMENDED untuk Shared Hosting)

1. **Dari Halaman Kartu Stok Index:**

    - Login sebagai Administrator
    - Buka menu "Kartu Stok"
    - Klik tombol "**🔧 Perbaiki Semua Rekaman Stok**" di pojok kanan atas

2. **Dari Halaman Detail Kartu Stok:**

    - Buka detail kartu stok produk mana saja
    - Klik tombol "**🔧 Perbaiki Rekaman Stok**" di bagian header tabel

3. **Proses:**
    - Konfirmasi proses perbaikan
    - Tunggu hingga proses selesai (beberapa detik hingga menit tergantung jumlah data)
    - Lihat laporan hasil perbaikan
    - Klik tombol "Kembali"
    - Refresh halaman kartu stok untuk melihat hasil

### Metode 2: Via Terminal (Jika memungkinkan)

```bash
cd c:\laragon\www\apotekbisma\apotekbisma
php perbaiki_rekaman_stok.php
```

### Metode 3: Via Browser Direct Access

Akses langsung URL:

```
http://127.0.0.1:8000/perbaiki_rekaman_stok.php
```

## 📊 OUTPUT YANG DIHASILKAN

Script akan menampilkan:

### FASE 1: ANALISIS DATA

-   Total Produk
-   Total Rekaman Stok

### FASE 2: PROSES PERBAIKAN

-   Progress bar real-time
-   Jumlah produk yang diproses

### FASE 3: HASIL PERBAIKAN

-   Produk yang diperbaiki
-   Rekaman yang diupdate
-   Inkonsistensi yang ditemukan

### FASE 4: VERIFIKASI

-   Cek inkonsistensi yang tersisa
-   Tabel detail jika masih ada masalah
-   Status akhir (SEMPURNA atau masih ada issue)

## ⚠️ PENTING - YANG TIDAK DIUBAH

Script ini **HANYA** memperbaiki data di tabel `rekaman_stoks`, yaitu:

-   ✅ `stok_awal`
-   ✅ `stok_sisa`
-   ✅ `updated_at`

Yang **TIDAK DIUBAH**:

-   ❌ `stok` pada tabel `produk` (stok realtime)
-   ❌ `stok_masuk` pada rekaman_stoks
-   ❌ `stok_keluar` pada rekaman_stoks
-   ❌ `waktu` pada rekaman_stoks
-   ❌ Data transaksi pembelian/penjualan
-   ❌ Data detail pembelian/penjualan

## 🔒 KEAMANAN

1. **Database Transaction**

    - Semua perubahan dibungkus dalam transaction
    - Jika terjadi error, semua perubahan di-rollback
    - Tidak ada partial update yang merusak data

2. **Skip Mutators**

    - Menggunakan `RekamanStok::$skipMutators = true`
    - Mencegah interferensi dari model mutators
    - Update langsung ke database dengan raw query

3. **Backup Recommended**
    - Meskipun aman, disarankan backup database sebelum menjalankan
    - Terutama untuk produksi dengan data kritikal

## 📈 PERFORMA

-   **Batch Processing**: Tidak digunakan karena perlu perhitungan berurutan
-   **Memory Efficient**: Proses per produk, tidak load semua data sekaligus
-   **Time Complexity**: O(n × m) dimana n = jumlah produk, m = rata-rata rekaman per produk
-   **Estimasi Waktu**:
    -   100 produk × 50 rekaman = ~5-10 detik
    -   500 produk × 100 rekaman = ~30-60 detik
    -   1000 produk × 200 rekaman = ~2-3 menit

## 🐛 TROUBLESHOOTING

### Masalah: Script timeout

**Solusi**:

-   Tingkatkan `max_execution_time` di php.ini
-   Atau akses via CLI terminal

### Masalah: Memory limit exceeded

**Solusi**:

-   Tingkatkan `memory_limit` di php.ini
-   Script sudah optimal, tidak load semua data sekaligus

### Masalah: Masih ada inkonsistensi setelah perbaikan

**Solusi**:

-   Cek log error di storage/logs/laravel.log
-   Pastikan tidak ada transaksi yang sedang berjalan
-   Jalankan ulang script perbaikan

### Masalah: Stok realtime tidak match dengan rekaman terakhir

**Catatan**:

-   Ini BUKAN masalah
-   Script memang tidak mengubah stok realtime
-   Stok realtime di tabel produk harus disesuaikan manual jika memang berbeda

## 📝 CHANGELOG

### Version 1.0 (10 Oktober 2025)

-   ✅ Implementasi awal script perbaikan
-   ✅ Perhitungan ulang stok_awal dan stok_sisa
-   ✅ Web interface dengan progress bar
-   ✅ Verifikasi otomatis hasil perbaikan
-   ✅ Transaction-safe dengan rollback
-   ✅ Integrasi dengan route dan controller
-   ✅ Tombol akses di halaman kartu stok

## 🎓 REKOMENDASI

1. **Jadwalkan Maintenance**

    - Jalankan saat traffic rendah
    - Backup database sebelum perbaikan
    - Siapkan waktu 5-10 menit untuk monitoring

2. **Verifikasi Manual**

    - Setelah perbaikan, cek beberapa produk secara acak
    - Pastikan perhitungan sudah benar
    - Bandingkan dengan data sebelumnya jika ada

3. **Dokumentasi**
    - Catat waktu perbaikan
    - Catat jumlah rekaman yang diperbaiki
    - Simpan laporan hasil perbaikan

## 📞 SUPPORT

Jika mengalami masalah atau butuh bantuan:

1. Cek file log: `storage/logs/laravel.log`
2. Screenshot error message
3. Catat langkah-langkah yang dilakukan
4. Hubungi developer dengan informasi lengkap

## ✅ CHECKLIST SEBELUM MENJALANKAN

-   [ ] Backup database
-   [ ] Pastikan tidak ada transaksi aktif
-   [ ] Cek disk space cukup
-   [ ] Pastikan tidak ada user lain yang sedang edit stok
-   [ ] Catat stok beberapa produk untuk verifikasi manual
-   [ ] Siapkan waktu untuk monitoring proses

## 🎯 HASIL YANG DIHARAPKAN

Setelah menjalankan script ini:

✅ Semua rekaman stok memiliki perhitungan yang konsisten
✅ stok_awal setiap rekaman = stok_sisa rekaman sebelumnya
✅ stok_sisa = stok_awal + stok_masuk - stok_keluar
✅ Tidak ada lagi anomali perhitungan
✅ Kartu stok menampilkan data yang akurat dan dapat dipercaya
✅ Laporan stok lebih reliable untuk decision making

---

**Catatan**: Script ini adalah solusi untuk memperbaiki data historis yang sudah rusak. Untuk mencegah masalah serupa di masa depan, pastikan sistem pencatatan stok baru sudah robust dan teruji dengan baik.
