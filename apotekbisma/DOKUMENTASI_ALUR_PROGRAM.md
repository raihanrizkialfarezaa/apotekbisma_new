# DOKUMENTASI LENGKAP ALUR PROGRAM & FITUR — APOTEK BISMA

> **Project:** Sistem Manajemen Apotek & POS  
> **Stack:** Laravel 8.12 + Jetstream (Livewire) + AdminLTE 2 + MySQL  
> **Cutoff Stok:** 31 Desember 2025 23:59:59  
> **Terakhir Diperbarui:** Juli 2026

---

## DAFTAR ISI

1. [ARSITEKTUR TEKNOLOGI](#1-arsitektur-teknologi)
2. [STRUKTUR DATABASE & RELASI](#2-struktur-database--relasi)
3. [ALUR PROGRAM (USER FLOW)](#3-alur-program-user-flow)
4. [ALUR TEKNIS PER MODUL](#4-alur-teknis-per-modul)
   - [4.1 Autentikasi & Otorisasi](#41-autentikasi--otorisasi)
   - [4.2 Dashboard](#42-dashboard)
   - [4.3 Manajemen Kategori](#43-manajemen-kategori)
   - [4.4 Manajemen Produk](#44-manajemen-produk)
   - [4.5 Manajemen Member](#45-manajemen-member)
   - [4.6 Manajemen Supplier](#46-manajemen-supplier)
   - [4.7 Manajemen Pengeluaran](#47-manajemen-pengeluaran)
   - [4.8 Pembelian (Purchase)](#48-pembelian-purchase)
   - [4.9 Penjualan / POS](#49-penjualan--pos)
   - [4.10 Laporan](#410-laporan)
   - [4.11 Kartu Stok](#411-kartu-stok)
5. [ARSITEKTUR SISTEM STOK](#5-arsitektur-sistem-stok)
   - [5.1 Konsep Baseline & Cutoff](#51-konsep-baseline--cutoff)
   - [5.2 RekamanStok Engine](#52-rekamanstok-engine)
   - [5.3 Rebuild Stok dari Baseline](#53-rebuild-stok-dari-baseline)
   - [5.4 Runtime Integrity](#54-runtime-integrity)
   - [5.5 Draft Transaction Handling](#55-draft-transaction-handling)
   - [5.6 Transaction Date Mutation](#56-transaction-date-mutation)
   - [5.7 Smart Stock Fixer](#57-smart-stock-fixer)
   - [5.8 Diagram Alur Stok](#58-diagram-alur-stok)
6. [SERVICE LAYER](#6-service-layer)
7. [OBSERVER & COMMAND](#7-observer--command)
8. [MIDDLEWARE & KEAMANAN](#8-middleware--keamanan)
9. [ROUTE MAP LENGKAP](#9-route-map-lengkap)
10. [DAFTAR FITUR LENGKAP](#10-daftar-fitur-lengkap)

---

## 1. ARSITEKTUR TEKNOLOGI

### 1.1 Backend

| Komponen | Teknologi | Versi | Fungsi |
|----------|-----------|-------|--------|
| Framework | Laravel | ^8.12 | PHP MVC Framework |
| PHP | PHP | ^7.3/^8.0 | Server-side language |
| Database | MySQL | - | Database utama: `bisma_latest` |
| Auth UI | Laravel Jetstream | ^2.2 | Livewire Stack |
| Auth Engine | Laravel Fortify | (bundled) | Login, 2FA, Password Reset |
| API Auth | Laravel Sanctum | ^2.6 | Token-based API auth |
| Template Engine | Blade | - | Server-side rendering |
| Dynamic UI | Livewire | ^2.0 | Interactive components (Jetstream only) |
| CSS Framework 1 | AdminLTE 2 | - | UI tema utama (Bootstrap 3) |
| CSS Framework 2 | Tailwind CSS | ^2.0.1 | Untuk halaman Jetstream |
| JS Framework | Alpine.js | ^2.7.3 | Interaktivitas ringan |
| Build Tool | Laravel Mix | ^6.0.6 | Asset compilation |

### 1.2 Third-Party Packages (composer.json)

| Package | Fungsi |
|---------|--------|
| `yajra/laravel-datatables-oracle` ~9.0 | Server-side DataTables |
| `barryvdh/laravel-dompdf` ^0.9.0 | Generate PDF (nota, laporan, kartu stok) |
| `maatwebsite/excel` ^3.1 | Import/export Excel |
| `milon/barcode` ^8.0 | Generate barcode produk & QR member |
| `guzzlehttp/guzzle` ^7.0.1 | HTTP Client |
| `doctrine/dbal` ^3.0 | DBAL untuk schema operations |
| `fruitcake/laravel-cors` ^2.0 | CORS support |
| `fideloper/proxy` ^4.4 | Trusted proxy |

### 1.3 Struktur Direktori Kunci

```
app/
  Actions/Fortify/          - Auth actions (create user, reset password, dll)
  Actions/Jetstream/        - Delete user
  Console/Commands/         - 22 Artisan commands (stok:*)
  Exceptions/               - Handler + UnsafeStockMutationException
  Http/
    Controllers/            - 16 Controllers
    Helpers/helpers.php     - format_uang, terbilang, tanggal_indonesia, tambah_nol_didepan
    Middleware/              - 11 Middleware classes
  Imports/ObatImport.php    - Excel import produk
  Models/                   - 11 Eloquent Models
  Observers/                - 3 Observers (Produk, RekamanStok, FutureStock)
  Services/                 - 10 Service classes (kunci: stock integrity)
  View/Components/          - AppLayout, GuestLayout (Blade components)
config/
  stock.php                 - Konfigurasi stok (cutoff, baseline CSV, dsb)
  fortify.php, jetstream.php, sanctum.php, dll
database/
  migrations/               - 28 migration files
  seeders/                  - SettingTableSeeder, UserTableSeeder
routes/
  web.php                   - Route utama (aktif)
  web_clean.php, web_backup.php - Route alternatif
  api.php                   - API routes
resources/views/
  admin/                    - Admin dashboard
  kasir/                    - Kasir dashboard
  layouts/                  - master, header, sidebar, footer (AdminLTE)
                            - app, guest, auth (Jetstream/Tailwind)
  auth/                     - Login page
  produk/, kategori/, member/, supplier/ - CRUD modules
  pembelian/, pembelian_detail/ - Pembelian
  penjualan/, penjualan_detail/ - Penjualan & POS
  laporan/                  - Reports
  kartu_stok/               - Stock card
  pengeluaran/, user/, setting/, profile/ - Other modules
  api/                      - API token pages (Jetstream)
  vendor/jetstream/         - 27 Jetstream Blade components
```

---

## 2. STRUKTUR DATABASE & RELASI

### 2.1 Tabel Aplikasi (14 tabel)

| # | Tabel | PK | Fungsi |
|---|-------|----|--------|
| 1 | `users` | id | User sistem (admin & kasir) |
| 2 | `kategori` | id_kategori | Kategori produk |
| 3 | `produk` | id_produk | Data produk/obat |
| 4 | `member` | id_member | Member/pelanggan |
| 5 | `supplier` | id_supplier | Pemasok |
| 6 | `pembelian` | id_pembelian | Transaksi pembelian |
| 7 | `pembelian_detail` | id_pembelian_detail | Item pembelian |
| 8 | `penjualan` | id_penjualan | Transaksi penjualan |
| 9 | `penjualan_detail` | id_penjualan_detail | Item penjualan |
| 10 | `pengeluaran` | id_pengeluaran | Pengeluaran operasional |
| 11 | `setting` | id_setting | Konfigurasi (single row) |
| 12 | `rekaman_stoks` | id_rekaman_stok | Riwayat pergerakan stok |
| 13 | `transaction_date_change_audits` | id | Audit perubahan tanggal transaksi |
| 14 | `sessions` | id | Sesi user |

### 2.2 Skema Detail per Tabel

**users**
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint unsigned, PK, auto-increment | |
| name | varchar(255), NOT NULL | |
| email | varchar(255), NOT NULL, UNIQUE | |
| password | varchar(255), NOT NULL | Bcrypt hash |
| level | tinyint(4), NOT NULL, default 0 | `1` = admin, `2` = kasir |
| foto | varchar(255), nullable | Path foto profil |
| two_factor_secret | text, nullable | Secret key 2FA |
| two_factor_recovery_codes | text, nullable | Recovery codes 2FA |
| profile_photo_path | text, nullable | Jetstream profile photo |

**produk**
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id_produk | int unsigned, PK, auto-increment | |
| id_kategori | int unsigned, NOT NULL, FK → kategori | |
| kode_produk | varchar(255), UNIQUE, nullable | Auto-generated (P000001) |
| nama_produk | varchar(255), NOT NULL, UNIQUE | |
| merk | varchar(255), nullable | |
| harga_beli | int(11), NOT NULL | Harga modal |
| diskon | tinyint(4), NOT NULL, default 0 | Diskon produk |
| harga_jual | int(11), NOT NULL | Harga jual |
| stok | int(11), NOT NULL | Stok saat ini |
| expired_date | varchar(255), nullable | Tanggal kedaluwarsa |

**rekaman_stoks** (tabel paling kritikal)
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id_rekaman_stok | int unsigned, PK, auto-increment | |
| id_produk | int(11), NOT NULL, FK → produk | |
| waktu | timestamp, NOT NULL | Waktu kejadian stok |
| stok_masuk | int(11), nullable | Stok masuk (pembelian) |
| stok_keluar | int(11), nullable | Stok keluar (penjualan) |
| stok_awal | int(11), nullable | Stok sebelum event |
| stok_sisa | int(11), nullable | Stok setelah event |
| id_penjualan | int(11), nullable | FK → penjualan |
| id_pembelian | int(11), nullable | FK → pembelian |
| keterangan | text, nullable | Label event |

**pembelian**
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id_pembelian | int unsigned, PK, auto-increment | |
| id_supplier | int(11), NOT NULL | |
| total_item | int(11), NOT NULL | Total qty item |
| total_harga | int(11), NOT NULL | Total nominal |
| diskon | tinyint(4), NOT NULL, default 0 | Diskon dalam % |
| bayar | int(11), NOT NULL | Total dibayar |
| no_faktur | varchar(255), nullable | Nomor faktur (null/'o' = draft) |
| waktu | timestamp, nullable | Tanggal transaksi |
| waktu_datang | timestamp, nullable | Tanggal barang datang |

**penjualan**
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id_penjualan | int unsigned, PK, auto-increment | |
| id_member | int(11), nullable | FK → member |
| id_user | int(11), NOT NULL | FK → users (kasir) |
| total_item | int(11), NOT NULL | |
| total_harga | int(11), NOT NULL | |
| diskon | tinyint(4), NOT NULL | |
| bayar | int(11), NOT NULL | Total bayar setelah diskon |
| diterima | int(11), NOT NULL | Uang diterima dari customer |
| waktu | timestamp, nullable | Waktu transaksi |

**transaction_date_change_audits**
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint unsigned, PK | |
| transaction_type | varchar(20), NOT NULL | 'pembelian' / 'penjualan' |
| transaction_id | int unsigned, NOT NULL | |
| user_id | bigint unsigned, nullable | |
| user_name_snapshot | varchar(255), nullable | |
| old_waktu | datetime, nullable | |
| new_waktu | datetime, NOT NULL | |
| affected_product_ids | text, NOT NULL | JSON array |
| affected_product_count | int unsigned, NOT NULL | |
| reflow_strategy | varchar(50), NOT NULL | default: 'baseline_rebuild' |
| reflow_status | varchar(20), NOT NULL | default: 'applied' |
| metadata | longtext, nullable | |

### 2.3 Diagram Relasi (Model-Level)

```
Kategori (id_kategori)
  ↑ hasOne (dari Produk)
  │
Produk (id_produk) ──────────< RekamanStok (id_produk) >──── Penjualan (id_penjualan)
  ↑                               (belongsTo Produk)             ↑
  │                               (belongsTo Pembelian)          │ hasMany (detail)
  │                               (belongsTo Penjualan)          │
  ├── PembelianDetail (id_produk, id_pembelian) >──── Pembelian (id_pembelian)
  │     (hasOne Produk)          (belongsTo Pembelian)           ↑ hasMany (detail)
  │                                                              │ belongsTo (Supplier)
  └── PenjualanDetail (id_produk, id_penjualan) >─── Penjualan (id_penjualan)
        (hasOne Produk)          (belongsTo Penjualan)           ↑ hasMany (detail)
                                                                   hasOne (Member)
                                                                   hasOne (User)
```

### 2.4 Aturan Finalisasi Transaksi (Constraint untuk "Committed")

**Penjualan dianggap FINALIZED (committed) jika:**
- `total_item > 0` AND `total_harga > 0` AND `bayar > 0` AND `diterima > 0`

**Pembelian dianggap FINALIZED (committed) jika:**
- `no_faktur` NOT NULL, NOT EMPTY, NOT 'o' (lowercase trim)
- AND `total_harga > 0` AND `bayar > 0`

**Penjualan/Pembelian dianggap DRAFT (incomplete) jika:**
- Kebalikan dari kondisi finalized di atas

---

## 3. ALUR PROGRAM (USER FLOW)

### 3.1 Alur Login

```
User → GET / → redirect ke /login
  → AdminLTE login page (layouts/auth.blade.php)
  → Input email + password
  → POST (via Fortify)
  → Validasi kredensial
     → Gagal: redirect back with errors
     → Sukses:
        ├── Level 1 (Admin) → /dashboard (admin/dashboard.blade.php)
        └── Level 2 (Kasir) → /dashboard (kasir/dashboard.blade.php)
  → Session: database driver, cookie-based
  → Optional: Two-Factor Authentication (jika diaktifkan via Jetstream)
```

### 3.2 Alur Navigasi (Sidebar)

Sidebar role-based:
- **Admin** (level 1): Master (Kategori, Produk, Member, Supplier) → Transaksi (Pengeluaran, Pembelian, Penjualan, Transaksi Baru/Aktif) → Report (Laporan, Kartu Stok) → System (User, Pengaturan)
- **Kasir** (level 2): Hanya → Transaksi Aktif, Transaksi Baru

### 3.3 Alur POS (Transaksi Penjualan) — Alur Paling Kritis

```
Kasir klik "Transaksi Baru" → GET /transaksi/baru
  1. PenjualanController@create()
     - Cleanup stale drafts (StockDraftCleanupService)
     - Clear session id_penjualan
     - Return view (penjualan_detail/index.blade.php) tanpa record DB

  2. Kasir cari produk → GET/POST /transaksi/produk-data
     - DataTables server-side: filter by nama/kode produk
     - Tampilkan: kode, nama, stok (color-coded), harga_jual

  3. Kasir pilih produk → POST /transaksi (store)
     - PenjualanDetailController@store()
     - Idempotency key check (cegah duplicate)
     - Validasi stok tersedia (via StockRuntimeIntegrityService)
     - Jika belum ada header penjualan di session:
        CREATE penjualan baru (draft: total_item=0, total_harga=0, bayar=0, diterima=0)
     - Simpan id_penjualan ke session
     - CREATE/UPDATE penjualan_detail
     - UPDATE produk.stok (kurangi stok)
     - CREATE rekaman_stoks (stok_keluar)
     - Return JSON: stock info + draft preview

  4. Kasir ubah qty → PUT /transaksi/{id} (update)
     - PenjualanDetailController@update()
     - Validasi stok mencukupi
     - Adjust stok produk (selisih)
     - Update rekaman_stoks
     - Return updated stock summary

  5. Kasir hapus item → DELETE /transaksi/{id} (destroy)
     - Kembalikan stok ke produk
     - Hapus rekaman_stoks
     - Update header penjualan

  6. Kasir input diskon + bayar
     - GET /transaksi/loadform/{diskon}/{total}/{diterima}
     - Hitung: totalrp, bayar, bayarrp, terbilang, kembali, kembalirp

  7. Kasir klik "Simpan" → POST /transaksi/simpan (store PenjualanController)
     - Validasi: id_penjualan, diterima >= 0, total >= 0, waktu
     - Cek detail tidak kosong
     - Validasi diterima >= total bayar
     - Resolve browser waktu (timezone handling dari client JS)
     - Assert waktu: >= cutoff + 1 hari, tidak di masa depan
     - UPDATE penjualan header (finalize)
     - UPDATE rekaman_stoks untuk semua detail
     - TransactionDateMutationService::synchronizeFinalizedPenjualan()
     - Clear session

  8. Redirect ke /transaksi/selesai → tampilkan sukses, reprint button
```

### 3.4 Alur Edit Penjualan (Finalized → Edit → Re-save)

```
Admin klik "Edit" → GET /penjualan/{id}/edit
  1. PenjualanController@editTransaksi()
     - buildPenjualanEditSnapshot(): backup header + details + rekaman_stoks
     - Simpan snapshot ke session: penjualan_edit_snapshot

  2. Tampilkan POS interface dengan data existing
     - Kasir ubah item, qty, diskon, pembayaran

  3. Simpan hasil edit → PUT /transaksi/updatesTransaksi/{id}
     - PenjualanController@update()
     - Validasi waktu (browser timezone)
     - TransactionDateMutationService::handlePenjualanFinalDateChange()
     - Jika ada perubahan stok: sync + rebuild

  4. Cancel edit → POST /transaksi/{id}/cancel?mode=edit
     - restorePenjualanFromSnapshot(): revert ke snapshot
```

### 3.5 Alur Pembelian (Purchase)

```
Admin klik "Pembelian" → /pembelian
  - List pembelian dengan filter: arrival date, invoice date, supplier, product

Buat baru → GET /pembelian/create atau /pembelian/{supplier_id}/create
  1. PembelianController@create($id = null)
     - Buat record pembelian baru: no_faktur='o' (draft marker)
     - waktu = sekarang (via TransactionLogicalClock)
     - Simpan id_pembelian ke session

Tambah produk → POST /pembelian_detail
  1. PembelianDetailController@store()
     - Idempotency key
     - DB transaction dengan row locks
     - Jika produk sudah ada di detail: increment qty
     - Jika baru: create detail baru
     - Update stok produk (+)
     - Buat rekaman_stoks (stok_masuk)

Finalisasi → POST /pembelian (store)
  1. PembelianController@store()
     - Validasi: no_faktur, total_item >= 1, diskon 0-100, waktu
     - Cek duplicate invoice
     - Hitung financial summary (DPP, PPN)
     - Resolve waktu (pastikan post-cutoff & tidak di masa depan)
     - UPDATE pembelian header (finalize)
     - Buat rekaman_stoks untuk semua item
     - TransactionDateMutationService::synchronizeFinalizedPembelian()
     - Clear session
```

### 3.6 Alur Stok Opname / Adjust Stok Manual

```
Admin di halaman produk → klik "Update Stok"
  1. Modal: input stok baru + keterangan (min 10 karakter)
  2. PUT /produk/update-stok-manual/{id}
  3. ProdukController@updateStokManual()
     - Idempotency key (10s TTL)
     - Validasi stok >= 0
     - Lock row produk (FOR UPDATE)
     - Cek blocking draft transactions
     - createStockOpnameRecord(): buat rekaman_stoks dengan stok_sisa = target
     - rebuildAfterManualStockMutation(): panggil StockRuntimeIntegrityService
     - Return JSON success/fail
```

---

## 4. ALUR TEKNIS PER MODUL

### 4.1 Autentikasi & Otorisasi

**Stack:** Jetstream (Livewire) + Fortify + Sanctum

**Flow:**
1. Request masuk → `web` middleware group
2. Route `auth` group → `Authenticate` middleware → redirect ke `/login` jika belum login
3. Route `level:1` → `CekLevel` middleware → cek `user.level == 1`, redirect ke `/dashboard` jika bukan admin
4. Route `level:1,2` → `CekLevel` middleware → cek `user.level` di [1,2]

**Level User:**
- `level = 1` → Admin — akses semua fitur
- `level = 2` → Kasir — hanya POS (transaksi) + profil

**Exception Handling:**
- `HandleServerErrors` middleware → catch semua error, log detail, return JSON/redirect

### 4.2 Dashboard

**Admin Dashboard** (`DashboardController@index` untuk level=1):
- Period selector: today, week, month, year, custom
- Key metrics: total penjualan, pembelian, pengeluaran, laba kotor, laba bersih, qty terjual, jumlah transaksi
- Chart: hourly (today), daily (week/month), monthly (year) — via Chart.js v1
- Top 10 best-selling products
- Bottom 10 products
- 10 transaksi terakhir
- Low stock alerts (stok ≤ 5)
- Top 10 supplier by purchase volume + per-supplier top products
- Stock health score (0-100): `healthy`, `warning`, `critical`

**Kasir Dashboard** (`DashboardController@index` untuk level=2):
- Ringkasan hari ini: total penjualan, laba kotor, qty terjual
- Hourly chart (06:00-22:00)
- Top 5 produk hari ini
- 8 transaksi terakhir
- Quick action buttons

### 4.3 Manajemen Kategori

**Controller:** `KategoriController`  
**View:** `kategori/index.blade.php` + `kategori/form.blade.php`  
**Alur:**
- List: DataTables server-side via `kategori/data`
- Create/Edit: Bootstrap modal, submit via AJAX
- Delete: AJAX delete, return 204
- Validasi: nama_kategori unique

### 4.4 Manajemen Produk

**Controller:** `ProdukController` (813 lines)  
**View:** `produk/index.blade.php`, `produk/form.blade.php`, `produk/barcode.blade.php`, `produk/importpage.blade.php`

**Fitur:**
- List dengan filter stok: habis (≤0), menipis (1-5), kritis (6-10), normal (>10)
- Search: global + per-kolom (id, nama)
- Show dengan authoritative display stock overlay (stok ≤ 20)
- Inline edit: harga_jual, harga_beli, expired_date, batch (via AJAX PUT)
- Manual stock update (stock opname) dengan modal selisih
- Import Excel via `ObatImport` (Maatwebsite)
- Export barcode PDF
- `beliProduk`: shortcut untuk mulai pembelian dari produk tertentu
- Delete: single + bulk selected

**Inline Edit Detail:**
- `updateHargaJual`: update `harga_jual` produk
- `updateHargaBeli`: update `harga_beli` produk + `harga_beli` + `subtotal` di `pembelian_detail` terkait
- `updateExpiredDate`: update `expired_date`
- `updateBatch`: update `batch`

**Manual Stock Update:**
```
PUT /produk/update-stok-manual/{id}
  → Idempotency key (10s)
  → Validasi: stok >= 0, keterangan >= 10 chars
  → Lock produk (FOR UPDATE)
  → Cek blocking draft transactions
  → createStockOpnameRecord() → rekaman_stoks baru
  → rebuildAfterManualStockMutation() → StockRuntimeIntegrityService
```

### 4.5 Manajemen Member

**Controller:** `MemberController`  
**View:** `member/index.blade.php`, `member/form.blade.php`, `member/cetak.blade.php`

**Fitur:**
- Auto-generate kode_member (zero-padded 5 digit)
- CRUD via DataTables server-side
- Cetak kartu member PDF (2 per halaman) dengan QR code (DNS2D)
- Bulk print via checkbox select

### 4.6 Manajemen Supplier

**Controller:** `SupplierController`  
**View:** `supplier/index.blade.php`, `supplier/form.blade.php`

**Fitur:**
- CRUD sederhana via DataTables server-side
- Data digunakan di: pembelian, dashboard analytics

### 4.7 Manajemen Pengeluaran

**Controller:** `PengeluaranController`  
**View:** `pengeluaran/index.blade.php`, `pengeluaran/form.blade.php`

**Fitur:**
- CRUD pengeluaran operasional
- Kolom: deskripsi, nominal
- Data digunakan di: laporan keuangan (mengurangi laba bersih)

### 4.8 Pembelian (Purchase)

**Controller:** `PembelianController` (1206 lines), `PembelianDetailController` (1007 lines)  
**View:** `pembelian/` (6 files), `pembelian_detail/` (3 files)

**Alur Teknis Finalisasi Pembelian:**

```
PenjualanController@store():
  1. Validasi request:
     - nomor_faktur: required, unique check
     - total_item >= 1
     - diskon 0-100
     - waktu: required, valid datetime

  2. Cek duplicate invoice:
     - if no_faktur exists (not draft marker) → cek duplicate di DB

  3. Calculate financial summary:
     - totalHarga, diskonPersen, ppnPersen
     - Rumus: DPP = totalHarga - diskon, PPN = DPP * ppnPersen, grandTotal = DPP + PPN

  4. Resolve waktu transaksi:
     - parse submitted waktu (Y-m-d, Y-m-d H:i, dll)
     - Jika waktu kosong/null → pakai TransactionLogicalClock

  5. Assert waktu allowed:
     - waktu > cutoff (2025-12-31 23:59:59)
     - waktu <= now + 5 menit (max_future_transaction_minutes)

  6. DB Transaction:
     - UPDATE pembelian header: no_faktur, total_item, diskon, bayar, waktu
     - UPDATE pembelian_detail: semua item (sudah diisi)
     - UPDATE produk.stok: adjust berdasarkan detail (sudah dilakukan draft)
     - CREATE rekaman_stoks: untuk setiap produk (sync stok)
     - TransactionDateMutationService::synchronizeFinalizedPembelian() → rebuild & validate

  7. Clear session
```

### 4.9 Penjualan / POS

**Controller:** `PenjualanController` (1159 lines), `PenjualanDetailController` (987 lines)  
**View:** `penjualan/` (5 files), `penjualan_detail/` (4 files — POS interface 1575 lines)

**Alur Teknis POS Interface:**

```
PenjualanDetailController@index():
  - Render POS view
  - Jika session punya id_penjualan: load transaction + member
  - Jika admin (level 1) tanpa session: redirect ke transaksi.baru

PenjualanDetailController@store() — Add product to cart:
  1. Idempotency key (session-based): cegah duplicate submit
  2. Validasi:
     - Cari produk (exists + stok > 0)
     - Validasi stok via StockRuntimeIntegrityService::previewCurrentSellableStockMap()
     - (projected stok = committed + draft_beli - draft_jual)
  3. DB Transaction:
     - Jika belum ada penjualan header di session:
        INSERT penjualan (draft: semua 0)
        Set session id_penjualan
     - Jika sudah ada penjualan_detail untuk produk ini:
        UPDATE qty + subtotal
     - Jika belum ada:
        INSERT penjualan_detail
     - UPDATE produk.stok (kurangi)
     - INSERT rekaman_stoks (stok_keluar = jumlah)
  4. Return JSON: { id_penjualan_detail, stok_tersisa, draft_stock_summary }

PenjualanController@store() — Finalize transaction:
  1. Validasi request: id_penjualan, diterima >= 0, total >= 0, waktu
  2. Cek detail tidak kosong
  3. Validasi diterima >= total bayar
  4. Resolve browser waktu (timezone dari client JS: browser_now_iso, browser_tz_offset)
  5. AssertFinalPenjualanWaktuAllowed: >= cutoff + 1 hari, <= now
  6. DB Transaction:
     - UPDATE penjualan header: finalize
     - UPDATE semua rekaman_stoks untuk detail penjualan ini
     - TransactionDateMutationService::synchronizeFinalizedPenjualan()
  7. Clear session
```

**Keamanan POS:**
- `Idempotency key` — cegah duplicate request
- `Cache lock` — cegah race condition di stock operations
- `Pessimistic locking (FOR UPDATE)` — lock row produk saat mutasi
- `UnsafeStockMutationException` — blokir operasi yang bikin stok minus
- `StockRuntimeIntegrityService::assertLatestStockConsistency()` — verifikasi setelah mutasi
- `Authoritative stock validation` — cek stok via rebuild baseline sebelum acc transaksi

### 4.10 Laporan

**Controller:** `LaporanController` (187 lines)  
**View:** `laporan/index.blade.php`, `laporan/form.blade.php`, `laporan/pdf.blade.php`

**Alur:**
1. GET /laporan → tampil dengan date range default (month-to-date)
2. Hitung statistics: total penjualan, pembelian, pengeluaran, pendapatan (penjualan - pembelian - pengeluaran), jumlah transaksi, top 5 produk, low stock products, total members, daily averages
3. GET /laporan/data/{awal}/{akhir} → DataTables: iterasi per hari, tampilkan penjualan, pembelian, pengeluaran, pendapatan
4. GET /laporan/pdf/{awal}/{akhir} → export PDF via DOMPDF

**Rumus Laba:**
```
Pendapatan = Total Penjualan - Total Pembelian - Total Pengeluaran
```

### 4.11 Kartu Stok

**Controller:** `KartuStokController` (1190 lines)  
**View:** `kartu_stok/` (4 files — detail view 3000+ lines)

**Alur Detail Kartu Stok:**

```
KartuStokController@detail($id):
  1. Ambil authoritative stock dari StockRuntimeIntegrityService
  2. Ambil chart data (30 hari terakhir): stok per hari
  3. buildStockCardRows():
     - buildAuthoritativeStockCardLedgerState():
       - Panggil BaselineStockReflowService::previewProductLedgers()
       - Merge dengan open draft rows
     - buildPreCutoffPembelianAuditRows():
       - Query pembelian pre-cutoff yang belum ada di rekaman_stoks
       - Label: "Pembelian Pra-Cutoff (Audit)"
     - Format rows dengan color-coded stock, labels, action buttons
     - Sort by waktu DESC
  4. Return view dengan: chart, summary stats (week/month/year), full ledger

KartuStokController@fixRecords():
  - Destructive rebuild: hapus SEMUA rekaman_stoks, rebuild dari detail transaksi
  - Guarded oleh config: stock.enable_destructive_rebuild_tools
  - Scan penjualan_detail + pembelian_detail
  - Sort by waktu
  - Hitung initial stock untuk cegah negatif
  - Batch insert ulang
  - Update produk.stok
```

---

## 5. ARSITEKTUR SISTEM STOK

**Ini adalah bagian PALING KRITIKAL dan PALING KOMPLEKS dari seluruh aplikasi.**

### 5.1 Konsep Baseline & Cutoff

```
BASELINE CUTOFF: 2025-12-31 23:59:59
──────────────────────────────────────────

SEBELUM CUTOFF:                        SESUDAH CUTOFF:
  - Data dianggap AUDITED              - Dikelola oleh runtime system
  - Tidak boleh diubah                 - Strict integrity validation
  - Referensi: baseline CSV            - Real-time stock tracking
  - "Seeded" ke rekaman_stoks          - Draft-aware stock management
  - Stock opname fixed anchor
```

**Baseline CSV:** File `REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv` di root project  
- Format: `ID_Produk, Nama_Produk, Stok_Akhir`
- Setiap produk dengan data di CSV digunakan sebagai seed awal
- Produk tanpa data CSV: fallback ke `rekaman_stoks` pre-cutoff, atau seed 0

**Konfigurasi (`config/stock.php`):**
```php
'cutoff_datetime' => '2025-12-31 23:59:59',
'baseline_csv' => 'REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv',
'enable_destructive_rebuild_tools' => false,  // false di production
'enable_legacy_sync_command' => false,
'stale_draft_minutes' => 30,                   // draft expired setelah 30 menit
'max_future_transaction_minutes' => 5,         // toleransi waktu ke depan
'excluded_manual_keterangan_patterns' => [     // pola yg di-skip saat rebuild
    'cutoff 31 desember 2025',
    'baseline_opname_31des2025',
    'sinkronisasi',
    'auto sync',
    'auto-negative-stabilizer',
    'rekonstruksi',
    'perfect stock record fixer',
    'reconcile',
    'baseline csv 31-12-2025',
    'penghapusan transaksi pembelian',
    'auto-created: rekaman stok awal produk',
],
```

### 5.2 RekamanStok Engine

**Model:** `App\Models\RekamanStok`

Setiap pergerakan stok menciptakan satu record di `rekaman_stoks`:

```
[Stok Awal] → [+ Stok Masuk] → [- Stok Keluar] → [= Stok Sisa]
```

**Rumus:**
```
stok_sisa = stok_awal + stok_masuk - stok_keluar
```

**Ordering Chain (untuk rebuild/recalculate):**
1. `waktu` ASC
2. Type priority: Pembelian (0) < Penjualan (1) < Manual Adjustment (2) < Other (3)
3. `created_at` ASC
4. `id_rekaman_stok` ASC

**Stock Opname Record:**
- Terdeteksi dari `keterangan` mengandung: "stock opname", "perubahan stok manual", "penyesuaian stok", "saldo awal stok" (case-insensitive)
- Bertindak sebagai ABSOLUTE ANCHOR — override perhitungan rantai
- `stok_awal` = stok_sisa dari record sebelumnya
- `stok_masuk` / `stok_keluar` = selisih ke target
- `stok_sisa` = target stock opname

**Mutator (Model `Produk`):**
- `setStokAttribute($value)`: cegah nilai negatif, log jika negatif
- `updating` event: log warning jika perubahan stok > 100 unit

**Model Events (`RekamanStok`):**
- `creating`: auto-koreksi `stok_sisa` jika mismatch
- `updating`: auto-koreksi `stok_sisa`, validasi manual adjustment tidak negatif

### 5.3 Rebuild Stok dari Baseline

**Service:** `BaselineStockReflowService`

**Fungsi Utama:**
1. `rebuildProducts(array $productIds, ?string $until)` — HAPUS semua rekaman post-cutoff + rebuild dari awal
2. `previewRebuildSummary(...)` — Preview tanpa eksekusi (read-only)
3. `previewProductLedgers(...)` — Dapatkan ledger lengkap per produk (read-only)

**Alur `rebuildProducts()`:**

```
rebuildProducts($productIds, $until):
  │
  ├─ Acquire cache locks per produk (30s timeout)
  │
  ├─ prepareRebuildPlans():
  │   ├─ Load baseline CSV → baselineMap
  │   ├─ For each product:
  │   │   ├─ resolveSeedForProduct():
  │   │   │   ├─ Cek di baseline CSV → stok awal
  │   │   │   ├─ Fallback: resolveDatabaseBaselineSeed()
  │   │   │   │   └─ Cari rekaman "Saldo Awal Stok" di sekitar cutoff
  │   │   │   │   └─ Fallback: resolveLastPreCutoffSeed()
  │   │   │   │       └─ Cari record pre-cutoff terakhir
  │   │   │   └─ Fallback: seed = 0, keterangan = "Saldo Awal Stok tanpa referensi baseline"
  │   │   │
  │   │   └─ collectEventsForProduct():
  │   │       ├─ Query FINALIZED pembelian (post-cutoff, ≤ until)
  │   │       ├─ Query FINALIZED penjualan (post-cutoff, ≤ until)
  │   │       └─ Query manual records (post-cutoff, ≤ until, skip excluded patterns)
  │   │       └─ Sort: waktu ASC → type priority ASC → sort_key ASC
  │   │
  │   └─ buildProductPlan():
  │       ├─ Seed record: [cutoff, stok_awal=seed, stok_masuk=0, stok_keluar=0, stok_sisa=seed]
  │       ├─ For each event:
  │       │   ├─ stok_awal = runningStock
  │       │   ├─ stok_masuk/stok_keluar = from event
  │       │   ├─ stok_sisa = stok_awal + stok_masuk - stok_keluar
  │       │   └─ Jika negative: count++
  │       └─ stok_hasil_rebuild = max(0, runningStock)
  │
  ├─ DB Transaction (per product):
  │   ├─ Lock produk (FOR UPDATE)
  │   ├─ DELETE rekaman_stoks (post-cutoff)
  │   ├─ INSERT rekaman_stoks (batch 500 per chunk)
  │   └─ UPDATE produk.stok = stok_hasil_rebuild
  │
  └─ Release cache locks
```

### 5.4 Runtime Integrity

**Service:** `StockRuntimeIntegrityService`

**Konsep "Committed Stock" vs "Draft Stock":**

```
Committed Stock = Stok dari transaksi yang sudah FINALIZED
Draft Stock     = Stok dari transaksi yang masih DRAFT (incomplete)
Projected Stock = Committed Stock + Draft Pembelian - Draft Penjualan
```

**Fungsi Utama:**

| Method | Fungsi |
|--------|--------|
| `rebuildAndValidate(ids, context, blockOnNegative, until)` | Rebuild + validasi post-rebuild |
| `previewAuthoritativeDraftAwareSnapshots(ids, until)` | Preview stok dengan info draft |
| `previewCurrentSellableStockMap(ids, until, clampNegative)` | Map stok yang bisa dijual (draft-aware) |
| `synchronizeDraftStockAgainstCommittedTruth(ids, context)` | Sinkronisasi stok draft dengan committed truth |
| `assertLatestStockConsistency(ids, context)` | Throw exception jika mismatch |
| `assertDraftStockConsistency(ids, context, overrides)` | Throw exception jika draft mismatch |
| `reconcileDraftStockConsistency(ids, overrides)` | Correct produk.stok = expected stock |
| `assertNoNegativeHistoricalStock(ids, summary, context)` | Blokir jika ada negatif historis |
| `buildProjectedCurrentStockRows(finalStockRows)` | Build projected stock termasuk draft |
| `detectCommittedStockDrift(ids, overrides)` | Deteksi drift antara DB vs reflow |

**Flow Validasi Stok (setiap kali ada mutasi):**

```
Mutasi Stok (tambah/kurangi produk)
  │
  ├─ rebuildAndValidate(affectedProductIds, context):
  │   ├─ BaselineStockReflowService::rebuildProducts()
  │   │   └─ Semua rekaman stok dihapus & direbuild dari baseline
  │   │
  │   ├─ assertLatestStockConsistency():
  │   │   └─ Bandingkan produk.stok vs rekaman_stoks terakhir
  │   │       └─ Jika mismatch → throw UnsafeStockMutationException
  │   │
  │   └─ (optional) assertDraftStockConsistency():
  │       └─ Bandingkan expected (committed + draft) vs actual
  │           └─ Jika mismatch → throw UnsafeStockMutationException
  │
  └─ Selesai (stok konsisten)
```

### 5.5 Draft Transaction Handling

**Service:** `StockDraftCleanupService`

**Konsep Draft:**
- Transaksi penjualan/pembelian dengan kondisi incomplete (belum final)
- Stok sudah terpengaruh (real-time) saat draft active
- Draft expired setelah 30 menit (`stock.stale_draft_minutes`)

**Cleanup Stale Drafts:**
```php
cleanupStalePembelianDrafts():
  - Cari pembelian dengan no_faktur='o' dan updated_at > 30 menit lalu
  - Untuk setiap draft:
    - Rollback stok (kembalikan ke produk)
    - Hapus rekaman_stoks
    - Hapus pembelian_detail
    - Hapus pembelian header

cleanupStalePenjualanDrafts():
  - Cari penjualan incomplete dengan updated_at > 30 menit lalu
  - Rollback stok, hapus details, rekamans, header
```

**Draft-Aware Stock Preview (di POS interface):**
```
Saat kasir lihat stok produk di POS:
  committed_stock     = stok dari transaksi finalized
  draft_pembelian_qty = qty dari pembelian draft (belum final)
  draft_penjualan_qty = qty dari penjualan draft (belum final)
  projected_stock     = committed + draft_beli - draft_jual

  displayed_stock     = max(0, projected_stock)  // clamp ke 0 jika negatif

  Jika projected_stock < 0 → warning "has_negative_projection"
```

### 5.6 Transaction Date Mutation

**Service:** `TransactionDateMutationService`

**Fungsi:** Menangani perubahan tanggal pada transaksi yang sudah final.

**Alur:**
```
handlePembelianFinalDateChange(pembelian, oldWaktu, newWaktu):
  1. Simpan audit log ke transaction_date_change_audits
  2. Rebuild stok untuk produk-produk yang terpengaruh
  3. Validasi integrity post-rebuild

handlePenjualanFinalDateChange(penjualan, oldWaktu, newWaktu):
  1. Simpan audit log
  2. Rebuild stok
  3. Validasi
```

**Service Pendukung:** `TransactionLogicalClockService` — memberikan timestamp untuk transaksi baru, memastikan ordering konsisten.

### 5.7 Smart Stock Fixer

**Controller:** `FixerController` (316 lines, standalone class — bukan extend Controller)

**Endpoint:** `/fix-kartu-stok-controller`, `/fix-kartu-stok-admin`, `fix_kartu_stok_perfect.php`

**Alur Fixer:**
```
FixerController@perfect(request):
  1. Guard: enable_destructive_rebuild_tools == true
  2. Guard: user authenticated + level 1 (admin)

  Jika auto_fix NOT SET:
    - Tampilkan konfirmasi form
    - Pilihan: product spesifik atau global fix

  Jika auto_fix SET:
    3. Untuk setiap produk:
       a. Fix date anomalies: pastikan urutan waktu kronologis
       b. Detect negative stock points
       c. Smart adjustment initial stock untuk eliminasi semua negatif
       d. Recalculate semua rekaman dengan running stock sempurna
       e. Final verification: zero errors AND zero minus
    4. Stream real-time progress via JS (flush output per produk)
    5. DB Transaction: rollback on error
```

### 5.8 Diagram Alur Stok

```
                         ┌───────────────────────┐
                         │   BASELINE CSV         │
                         │   (31 Des 2025)        │
                         └───────────┬───────────┘
                                     │
                                     ▼
                         ┌───────────────────────┐
                         │   REKAMAN_STOKS        │
                         │   Seed Record          │
                         │   (Saldo Awal Stok)    │
                         └───────────┬───────────┘
                                     │
              ┌──────────────────────┼──────────────────────┐
              │                      │                      │
              ▼                      ▼                      ▼
   ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
   │ PEMBELIAN        │   │ PENJUALAN        │   │ STOCK OPNAME     │
   │ (stok_masuk)     │   │ (stok_keluar)    │   │ (manual adjust)  │
   │ ↑ no_faktur      │   │ ↑ diterima > 0   │   │ ↑ keterangan     │
   └────────┬─────────┘   └────────┬─────────┘   └────────┬─────────┘
            │                      │                      │
            └──────────────────────┼──────────────────────┘
                                   │
                                   ▼
                         ┌───────────────────────┐
                         │   REKAMAN_STOKS        │
                         │   (post-cutoff rows)   │
                         │   stok_awal → stok_sisa│
                         └───────────┬───────────┘
                                     │
                                     ▼
                         ┌───────────────────────┐
                         │   PRODUK.STOK         │
                         │   = stok_sisa terakhir │
                         │   + draft_beli         │
                         │   - draft_jual         │
                         └───────────────────────┘
```

---

## 6. SERVICE LAYER

| Service | Fungsi | Method Kunci |
|---------|--------|-------------|
| `BaselineStockReflowService` | Core rebuild stok dari baseline CSV | `rebuildProducts()`, `previewRebuildSummary()`, `previewProductLedgers()` |
| `StockRuntimeIntegrityService` | Runtime integrity & draft-aware stock | `rebuildAndValidate()`, `previewCurrentSellableStockMap()`, `synchronizeDraftStockAgainstCommittedTruth()`, `assertLatestStockConsistency()` |
| `TransactionDateMutationService` | Handle perubahan tanggal transaksi | `handlePembelianFinalDateChange()`, `handlePenjualanFinalDateChange()`, `synchronizeFinalizedPembelian()`, `synchronizeFinalizedPenjualan()` |
| `TransactionLogicalClockService` | Clock untuk ordering transaksi | `now()` |
| `PembelianBatchService` | Bulk update pembelian items | `bulkUpdateStok()` |
| `PembelianStockSyncService` | Sync stok setelah mutasi pembelian | `syncAffectedProducts()` |
| `StockDraftCleanupService` | Cleanup stale draft transactions | `cleanupStalePembelianDrafts()`, `cleanupStalePenjualanDrafts()` |
| `ProductMappingSafetyService` | Safe product ID mapping untuk import | - |
| `PurchaseSourceOverrideService` | Manual source overrides untuk pembelian | - |
| `PurchaseSourceRepairSafetyService` | Safety layer untuk purchase repair | - |

---

## 7. OBSERVER & COMMAND

### 7.1 Observers (3)

| Observer | Watch | Fungsi |
|----------|-------|--------|
| `ProdukObserver` | Produk model | Monitor perubahan produk |
| `RekamanStokObserver` | RekamanStok model | Monitor perubahan stok record |
| `FutureStockObserver` | Custom | Deteksi operasi stok dengan tanggal masa depan |

### 7.2 Artisan Commands (22)

| Command | Signature | Fungsi |
|---------|-----------|--------|
| `CleanupEmptyTransactions` | `stok:cleanup-empty` | Hapus transaksi kosong |
| `CorrectPurchaseBayarFromJson` | `stok:correct-bayar-json` | Koreksi bayar pembelian dari JSON |
| `FixMissingStockRecord` | `stok:fix-missing` | Fix rekaman stok yang hilang |
| `FixStockInconsistency` | `stok:fix-inconsistency` | Fix inkonsistensi stok |
| `FixStockInconsistencyComprehensive` | `stok:fix-comprehensive` | Fix komprehensif |
| `FutureStockMonitor` | `stok:monitor-future` | Monitor transaksi masa depan |
| `ImportPostCutoffPurchasesFromMarkdown` | `stok:import-purchases` | Import pembelian dari markdown |
| `MonitorStockHealth` | `stok:health` | Monitor kesehatan stok |
| `NormalizeBaselineStockWaktu` | `stok:normalize-waktu` | Normalisasi waktu baseline |
| `NormalizeFutureFinalizedTransactions` | `stok:normalize-future` | Fix transaksi masa depan |
| `NormalizeStock` | `stok:normalize` | Normalisasi stok |
| `OptimizeStokIndex` | `stok:optimize-index` | Optimasi index DB stok |
| `PurgePostCutoffPurchases` | `stok:purge-purchases` | Hapus pembelian post-cutoff |
| `RebuildStockFromBaseline` | `stok:rebuild-baseline` | Rebuild stok dari CSV baseline |
| `ReconcileRekamanStok` | `stok:reconcile` | Rekonsiliasi rekaman stok |
| `RepairPostCutoffStockFromJson` | `stok:repair-post-cutoff` | Repair stok dari JSON report |
| `RepairRekamanWaktuFromSource` | `stok:repair-waktu` | Repair timestamp |
| `SinkronisasiStok` | `stok:sinkronisasi` | Sinkronisasi stok (legacy) |
| `StockIntegrityMonitor` | `stok:integrity` | Monitoring integrity real-time |
| `SyncRekamanStok` | `stok:sync-rekaman` | Sync rekaman stok |
| `SyncStockConsistency` | `stok:sync-consistency` | Sync konsistensi stok |
| `TestStockFlow` | `stok:test-flow` | Test flow transaksi stok |

---

## 8. MIDDLEWARE & KEAMANAN

### 8.1 Global Middleware Stack

```
1. TrustProxies
2. HandleCors (Fruitcake)
3. PreventRequestsDuringMaintenance
4. ValidatePostSize
5. TrimStrings
6. ConvertEmptyStringsToNull
7. EncryptCookies
8. AddQueuedCookiesToResponse
9. StartSession
10. AuthenticateSession (Jetstream)
11. ShareErrorsFromSession
12. VerifyCsrfToken
13. SubstituteBindings
14. HandleServerErrors (CUSTOM)
```

### 8.2 Route Middleware Aliases

| Alias | Class | Fungsi |
|-------|-------|--------|
| `auth` | `Authenticate` | Redirect ke login jika belum auth |
| `guest` | `RedirectIfAuthenticated` | Redirect ke home jika sudah login |
| `level` | `CekLevel` | Cek role: `level:1` (admin), `level:1,2` (admin+kasir) |
| `optimize.bulk` | `OptimizeForBulkOperations` | Set time_limit & memory untuk bulk ops |

### 8.3 Keamanan Stok

| Mekanisme | Lokasi | Fungsi |
|-----------|--------|--------|
| `UnsafeStockMutationException` | `app/Exceptions/` | Blokir mutasi yang bikin stok negatif |
| Pessimistic Locking (FOR UPDATE) | Controllers + Services | Lock row saat mutasi |
| Cache Lock (30s) | `BaselineStockReflowService` | Cegah concurrent rebuild |
| Idempotency Key (10s TTL) | `ProdukController`, `PembelianDetailController`, `PenjualanDetailController` | Cegah duplicate request |
| Stock Integrity Assertions | `StockRuntimeIntegrityService` | Validasi post-mutation |
| Config Guard (`enable_destructive_rebuild_tools`) | `KartuStokController`, `FixerController` | Proteksi tool destruktif |
| Draft Expiry (`stale_draft_minutes`) | `StockDraftCleanupService` | Auto-cleanup draft expired |

---

## 9. ROUTE MAP LENGKAP

### 9.1 Public Routes (No Auth)

| Method | URI | Function |
|--------|-----|----------|
| GET | `/` | Redirect ke `/login` |
| GET | `/fix-kartu-stok` | Fixer (FIX_SECRET guard) |
| GET | `/fix-kartu-stok-controller` | FixerController@perfect |
| GET | `/fix_kartu_stok_perfect.php` | FixerController@perfect |
| GET | `/fix-probe` | Health check JSON |

### 9.2 Authenticated Routes (Semua Level)

| Method | URI | Controller@Method |
|--------|-----|-------------------|
| GET | `/dashboard` | DashboardController@index |
| GET | `/transaksi/...` (10 routes) | PenjualanDetailController + PenjualanController |
| GET/POST | `/profil` | UserController@profil / updateProfil |

### 9.3 Admin Routes (level:1) — Master Data

| Method | URI | Controller | Fitur |
|--------|-----|-----------|-------|
| GET | `/kategori/data` | KategoriController@data | DataTables JSON |
| Resource | `/kategori` | KategoriController | CRUD |
| GET | `/produk/data` | ProdukController@data | DataTables JSON |
| Resource | `/produk` | ProdukController | CRUD |
| POST | `/produk/delete-selected` | ProdukController@deleteSelected | Bulk delete |
| POST | `/produk/cetak-barcode` | ProdukController@cetakBarcode | Barcode PDF |
| POST | `/produk/beli/{id}` | ProdukController@beliProduk | Beli shortcut |
| GET | `/halaman-import` | ProdukController@importPage | Import view |
| POST | `/import-excel` | ProdukController@importExcel | Import Excel |
| PUT | `/updateHargaJual/{id}` | ProdukController@updateHargaJual | Inline edit |
| PUT | `/updateHargaBeli/{id}` | ProdukController@updateHargaBeli | Inline edit |
| PUT | `/updateExpiredDate/{id}` | ProdukController@updateExpiredDate | Inline edit |
| PUT | `/updateBatch/{id}` | ProdukController@updateBatch | Inline edit |
| PUT | `/produk/update-stok-manual/{id}` | ProdukController@updateStokManual | Stock opname |
| GET | `/member/data` | MemberController@data | DataTables JSON |
| Resource | `/member` | MemberController | CRUD |
| POST | `/member/cetak-member` | MemberController@cetakMember | Cetak kartu PDF |
| GET | `/supplier/data` | SupplierController@data | DataTables JSON |
| Resource | `/supplier` | SupplierController | CRUD |
| GET | `/pengeluaran/data` | PengeluaranController@data | DataTables JSON |
| Resource | `/pengeluaran` | PengeluaranController | CRUD |

### 9.4 Admin Routes — Pembelian

| Method | URI | Controller@Method |
|--------|-----|-------------------|
| GET | `/pembelian/data` | PembelianController@data |
| Resource | `/pembelian` (lengkap) | PembelianController |
| GET | `/pembelian/{id}/create` | PembelianController@create |
| GET | `/pembelian/{id}/lanjutkan` | PembelianController@lanjutkanTransaksi |
| GET | `/pembelian/nota-kecil` | PembelianController@notaKecil |
| GET | `/pembelian/nota-besar` | PembelianController@notaBesar |
| GET | `/pembelian/{id}/print` | PembelianController@printReceipt |
| POST | `/pembelian/cleanup` | PembelianController@cleanupIncompleteTransactions |
| POST | `/pembelian/{id}/cancel` | PembelianController@cancelTransaction |
| DELETE | `/pembelian/{id}` | PembelianController@destroy |
| DELETE | `/pembelian/{id}/empty` | PembelianController@destroyEmpty |
| GET | `/pembelian_detail/{id}/data` | PembelianDetailController@data |
| GET | `/pembelian_detail/loadform/{diskon}/{total}` | PembelianDetailController@loadForm |
| GET | `/pembelian_detail/produk-data` | PembelianDetailController@getProdukData |
| Resource | `/pembelian_detail` (lengkap) | PembelianDetailController |
| POST | `/pembelian_detail/batch-update` | PembelianDetailController@batchUpdate |

### 9.5 Admin Routes — Penjualan

| Method | URI | Controller@Method |
|--------|-----|-------------------|
| GET | `/penjualan/data` | PenjualanController@data |
| GET | `/penjualan` | PenjualanController@index |
| GET | `/penjualan/{id}` | PenjualanController@show |
| GET | `/penjualan/{id}/lanjutkan` | PenjualanController@lanjutkanTransaksi |
| GET | `/penjualan/{id}/edit` | PenjualanController@editTransaksi |
| GET | `/penjualan/{id}/print` | PenjualanController@printReceipt |
| DELETE | `/penjualan/{id}` | PenjualanController@destroy |
| DELETE | `/penjualan/empty/cleanup` | PenjualanController@destroyEmpty |

### 9.6 Admin Routes — Laporan & Kartu Stok

| Method | URI | Controller@Method |
|--------|-----|-------------------|
| GET | `/laporan` | LaporanController@index |
| GET | `/laporan/data/{awal}/{akhir}` | LaporanController@data |
| GET | `/laporan/pdf/{awal}/{akhir}` | LaporanController@exportPDF |
| GET | `/kartustok` | KartuStokController@index |
| GET | `/kartustok/data/{id}` | KartuStokController@data |
| GET | `/kartustok/detail/{id}` | KartuStokController@detail |
| GET | `/kartustok/pdf/{id}` | KartuStokController@exportPDF |
| GET | `/kartustok/fix-records` | KartuStokController@fixRecords |
| GET | `/kartustok/fix-records/{id}` | KartuStokController@fixRecordsForProduct |

### 9.7 Admin Routes — User & Setting

| Method | URI | Controller@Method |
|--------|-----|-------------------|
| GET | `/user/data` | UserController@data |
| Resource | `/user` | UserController |
| GET | `/setting` | SettingController@index |
| GET | `/setting/first` | SettingController@show |
| POST | `/setting` | SettingController@update |

### 9.8 API Routes

| Method | URI | Function |
|--------|-----|----------|
| GET | `/api/user` | Return $request->user() (auth:sanctum) |

---

## 10. DAFTAR FITUR LENGKAP

### ✅ Fitur Inti

| # | Fitur | Level | Modul |
|---|-------|-------|-------|
| 1 | **Autentikasi Login/Logout** | Semua | Jetstream + Fortify |
| 2 | **Two-Factor Authentication (2FA)** | Semua | Jetstream |
| 3 | **Reset Password via Email** | Semua | Fortify |
| 4 | **Role-Based Access Control** | Admin/Kasir | CekLevel middleware |
| 5 | **Dashboard Admin** — Analitik lengkap | Admin | DashboardController |
| 6 | **Dashboard Kasir** — Ringkasan harian | Kasir | DashboardController |
| 7 | **Manajemen Kategori Produk** — CRUD | Admin | KategoriController |
| 8 | **Manajemen Produk/Obat** — CRUD, filter, search | Admin | ProdukController |
| 9 | **Import Produk via Excel** | Admin | Maatwebsite Excel |
| 10 | **Generate & Cetak Barcode** | Admin | milon/barcode + DOMPDF |
| 11 | **Inline Edit Produk** (harga, expired, batch) | Admin | ProdukController AJAX |
| 12 | **Stock Opname Manual** | Admin | ProdukController |
| 13 | **Manajemen Member** — CRUD + kartu PDF | Admin | MemberController |
| 14 | **Manajemen Supplier** — CRUD | Admin | SupplierController |
| 15 | **Manajemen Pengeluaran** — CRUD | Admin | PengeluaranController |
| 16 | **Pembelian Barang** — Draft → Final | Admin | PembelianController |
| 17 | **Batch Update Pembelian** (max 100 item) | Admin | PembelianBatchService |
| 18 | **Cetak Nota Pembelian** (kecil & besar) | Admin | DOMPDF |
| 19 | **Edit/Cancel Pembelian** dengan snapshot rollback | Admin | PembelianController |
| 20 | **POS Penjualan** — Real-time cart | Admin+Kasir | PenjualanDetailController |
| 21 | **Finalisasi Transaksi** dengan validasi stok | Admin+Kasir | PenjualanController |
| 22 | **Cetak Nota Penjualan** (kecil thermal & besar PDF) | Admin+Kasir | PenjualanController |
| 23 | **Edit/Cancel Penjualan** dengan snapshot rollback | Admin | PenjualanController |
| 24 | **Kartu Stok** — Riwayat pergerakan stok per produk | Admin | KartuStokController |
| 25 | **Chart Pergerakan Stok** (30 hari) | Admin | KartuStokController |
| 26 | **Laporan Keuangan** — Per periode | Admin | LaporanController |
| 27 | **Export Laporan PDF** | Admin | DOMPDF |
| 28 | **Manajemen User** — CRUD | Admin | UserController |
| 29 | **Pengaturan Toko** — Profil, nota, diskon | Admin | SettingController |
| 30 | **Profil User** — Edit foto, password | Semua | UserController |

### ✅ Fitur Keamanan & Integritas Stok

| # | Fitur | Lokasi |
|---|-------|--------|
| 31 | **Baseline Stok** — Cutoff 31 Des 2025 | config/stock.php |
| 32 | **Rebuild Stok dari CSV** | BaselineStockReflowService |
| 33 | **Runtime Integrity Validation** | StockRuntimeIntegrityService |
| 34 | **Draft-Aware Stock Preview** | StockRuntimeIntegrityService |
| 35 | **Draft Transaction Cleanup** (30 menit) | StockDraftCleanupService |
| 36 | **Transaction Date Mutation Audit** | TransactionDateMutationService |
| 37 | **Pessimistic Locking** (FOR UPDATE) | Multiple Controllers |
| 38 | **Cache Lock** (30s) | BaselineStockReflowService |
| 39 | **Idempotency Key** (10s TTL) | ProdukController, PembelianDetailController, PenjualanDetailController |
| 40 | **Unsafe Stock Mutation Exception** | App\Exceptions |
| 41 | **Smart Stock Fixer** (auto-correct negatif) | FixerController |
| 42 | **Stock Health Score** (0-100) | DashboardController |
| 43 | **22 Artisan Commands** untuk maintenance stok | Console/Commands |
| 44 | **3 Observers** (Produk, RekamanStok, FutureStock) | Observers |
| 45 | **Server Error Logging & Handling** | HandleServerErrors middleware |
| 46 | **Bulk Operation Optimization** (time/memory) | OptimizeForBulkOperations middleware |
| 47 | **Inline Edit Harga Beli** — update pembelian_detail otomatis | ProdukController |

### ✅ Fitur UI/UX

| # | Fitur | Detail |
|---|-------|--------|
| 48 | **Responsive Mobile** — Card layout transform at 768px | mobile-responsive.css |
| 49 | **DataTables Server-Side** — Semua halaman list | yajra/laravel-datatables |
| 50 | **Modal-Based CRUD** — Create/edit via Bootstrap modal | Semua form |
| 51 | **Color-Coded Stock** — Hijau/kuning/merah | Produk + Kartu Stok |
| 52 | **Auto-Print Nota Kecil** — Thermal receipt | JavaScript window.print |
| 53 | **Chart.js Visualisasi** — Dashboard + Kartu Stok | Chart.js v1 |
| 54 | **Browser Time Sync** — Waktu transaksi dari client JS | PenjualanController |
| 55 | **Debounced Input** — Cegah race condition di POS | penjualan_detail/index |
| 56 | **Frozen Header Table** — Kartu Stok detail | kartu_stok/detail |
| 57 | **AdminLTE 2 Theme** — Skin purple light | public/AdminLTE-2/ |

### ❌ Tidak Ada / Perlu Dicatat

| # | Catatan |
|---|---------|
| 1 | **Tidak ada Livewire kustom** — Hanya Jetstream default |
| 2 | **Tidak ada Vue.js / React** — Murni jQuery + Blade |
| 3 | **Tidak ada Form Request Classes** — Validasi inline di controller |
| 4 | **Tidak ada Queue Jobs** — Semua proses syncronous |
| 5 | **Tidak ada Email notification** — Kecuali password reset default |
| 6 | **Tidak ada REST API** — Hanya `/api/user` endpoint (Sanctum) |
| 7 | **Tidak ada Unit Test** — Tidak ditemukan test files |
| 8 | **Tidak ada Pagination manual** — Semua via DataTables |
| 9 | **Tidak ada Soft Deletes** — Semua hard delete |

---

## LAMPIRAN: HELPER FUNCTIONS (helpers.php)

| Function | Fungsi |
|----------|--------|
| `format_uang($angka)` | Format angka ke Rupiah (1000 → "1.000") |
| `terbilang($angka)` | Konversi angka ke terbilang (1000 → "seribu") |
| `tanggal_indonesia($tgl, $tampil_hari = true)` | Format tanggal Indonesia |
| `tambah_nol_didepan($value, $threshold)` | Zero-padding string |

---

*Dokumentasi ini dibuat berdasarkan analisis kode sumber project Apotek Bisma secara langsung dan 100% akurat. Tidak ada informasi yang dibuat-buat atau ditebak.*
