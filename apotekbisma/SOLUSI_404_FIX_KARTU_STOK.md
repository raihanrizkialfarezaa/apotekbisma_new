# Solusi: Error 404 pada fix_kartu_stok_perfect.php di Shared Hosting

## Masalah
File `fix_kartu_stok_perfect.php` berfungsi dengan baik di localhost namun menghasilkan error **404 Not Found** saat diakses di shared hosting melalui URL:
```
https://apotikbisma.viviashop.com/fix_kartu_stok_perfect.php
```

## Penyebab
File tersebut berada di folder `/public` dan menggunakan path relatif untuk memuat Laravel (`__DIR__.'/../vendor/autoload.php'`). Hal ini menyebabkan konflik dengan routing `.htaccess` di shared hosting, yang berbeda dengan environment development menggunakan `php artisan serve`.

## Solusi yang Diterapkan

### 1. **Pemindahan File Utama ke Root Directory**
File-file berikut dipindahkan dari `/public` ke root directory project:
- `fix_kartu_stok_perfect.php`
- `fix_kartu_stok_robust.php`
- `fix_kartu_stok_ultimate.php`

### 2. **Update Path Bootstrap Laravel**
Path dalam file yang dipindahkan diupdate dari:
```php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
```

Menjadi:
```php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
```

### 3. **Pembuatan Bridge File di /public**
File bridge dibuat di folder `/public` yang berfungsi sebagai penghubung:

**File: `/public/fix_kartu_stok_perfect.php`**
```php
<?php
// Bridge file untuk menjalankan fix_kartu_stok_perfect.php dari public webroot
// Menukar direktori kerja ke root project sehingga require relatif di script utuh bekerja
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../fix_kartu_stok_perfect.php';
```

Bridge file yang sama dibuat untuk:
- `/public/fix_kartu_stok_robust.php`
- `/public/fix_kartu_stok_ultimate.php`

## Mengapa Solusi Ini Bekerja?

### Pattern yang Terbukti
Solusi ini mengikuti pattern yang sudah terbukti bekerja pada file `perbaiki_rekaman_stok.php` yang sudah ada di repository.

### Keuntungan
1. ✅ **Kompatibel dengan localhost** - Tetap bisa dijalankan dengan `php artisan serve`
2. ✅ **Kompatibel dengan shared hosting** - Bridge file menangani routing dengan benar
3. ✅ **Tidak mengubah logika bisnis** - Hanya mengubah struktur file dan path
4. ✅ **Konsisten dengan codebase** - Menggunakan pattern yang sudah ada
5. ✅ **URL tetap sama** - User tetap mengakses melalui `/fix_kartu_stok_perfect.php`

## Cara Kerja

### Di Localhost (php artisan serve)
1. Browser request → `/fix_kartu_stok_perfect.php`
2. Laravel server menangani → `/public/fix_kartu_stok_perfect.php`
3. Bridge file mengubah working directory → ke root project
4. Require file utama → `/fix_kartu_stok_perfect.php`
5. Laravel bootstrap berjalan dengan path yang benar

### Di Shared Hosting
1. Browser request → `https://apotikbisma.viviashop.com/fix_kartu_stok_perfect.php`
2. `.htaccess` redirect → ke `/public/fix_kartu_stok_perfect.php`
3. Bridge file mengubah working directory → ke root project
4. Require file utama → `/fix_kartu_stok_perfect.php`
5. Laravel bootstrap berjalan dengan path yang benar

## Pengujian

Untuk memastikan file bekerja dengan baik, jalankan syntax check:
```bash
php -l public/fix_kartu_stok_perfect.php
php -l fix_kartu_stok_perfect.php
```

Semua file telah divalidasi dan tidak ada syntax error.

## Deployment ke Shared Hosting

Setelah melakukan pull/merge dari repository:
1. Pastikan semua file ter-upload ke shared hosting
2. File structure harus seperti ini:
   ```
   /
   ├── fix_kartu_stok_perfect.php     (file utama)
   ├── fix_kartu_stok_robust.php      (file utama)
   ├── fix_kartu_stok_ultimate.php    (file utama)
   └── public/
       ├── fix_kartu_stok_perfect.php   (bridge file)
       ├── fix_kartu_stok_robust.php    (bridge file)
       └── fix_kartu_stok_ultimate.php  (bridge file)
   ```
3. Akses URL seperti biasa: `https://apotikbisma.viviashop.com/fix_kartu_stok_perfect.php`

## Catatan Tambahan

### Bolehkah File PHP di /public?
Ya, file PHP boleh ditempatkan di folder `/public`, tapi dengan menggunakan **bridge file pattern** seperti yang diterapkan pada solusi ini. Ini adalah best practice untuk:
- Menjaga keamanan (file utama tidak langsung accessible)
- Kompatibilitas dengan berbagai environment
- Konsistensi dengan struktur Laravel

### Alternative Solution (Tidak Direkomendasikan)
Anda juga bisa membuat Laravel route untuk file-file ini, tapi solusi bridge file lebih sederhana dan tidak memerlukan perubahan pada routing atau controller.

## Referensi
Pattern ini sudah digunakan pada file berikut di repository:
- `/public/perbaiki_rekaman_stok.php` → bridge untuk `/perbaiki_rekaman_stok.php`
