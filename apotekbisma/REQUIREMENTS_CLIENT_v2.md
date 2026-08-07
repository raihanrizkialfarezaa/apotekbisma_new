# SPESIFIKASI KEBUTUHAN FITUR (CLIENT REQUIREMENTS)

> Dokumen ini berisi daftar permintaan fitur dari client untuk pengembangan sistem Apotek Bisma.
> Disusun ulang dari notasi mentah ke format spesifikasi teknis yang jelas dan tidak ambigu.

---

## 1. MULTI-PAYMENT CASH & CASHLESS

### 1.1. Kondisi Saat Ini

Saat ini sistem hanya mendukung satu metode pembayaran: **Cash (tunai)** dengan alur:
- Admin menghitung total belanja
- Customer membayar dengan uang tunai
- Admin menghitung kembalian secara manual
- Admin menyelesaikan transaksi dan mencetak struk

### 1.2. Metode Pembayaran Baru yang Diminta

Client meminta penambahan **2 opsi pembayaran cashless** sebagai berikut:

#### A. Midtrans Payment Gateway (Dynamic QR — Fast Track)

| Atribut | Spesifikasi |
|---------|-------------|
| **Jenis** | Payment gateway pihak ketiga (Midtrans) |
| **Mekanisme** | Dynamic QR — sistem generate QR unik per transaksi |
| **Kecepatan** | Cepat — pembayaran terverifikasi otomatis via callback/server-to-server notification |
| **Biaya Admin** | Ada (potongan merchant fee ~0.7% per transaksi) — dicatat sebagai biaya admin sistem |
| **Auto-Withdrawal** | Settlement otomatis ke rekening merchant (fitur gateway, tidak perlu di-build) |
| **Flow** | POS hitung total → request snap token ke Midtrans → tampilkan QR → customer scan & bayar → Midtrans kirim notifikasi → sistem update status jadi success → cetak struk |

#### B. QRIS Statis (GoPay — Manual Track)

| Atribut | Spesifikasi |
|---------|-------------|
| **Jenis** | QRIS statis milik merchant (satu QR tetap untuk semua transaksi) |
| **Mekanisme** | Customer scan QR → transfer manual via GoPay/QRIS apps |
| **Maksimum Nominal** | Rp 499.000 per transaksi (untuk menghindari transaction/fee) |
| **Biaya Admin** | Rp 0 (nol rupiah) |
| **Kecepatan** | Lambat — membutuhkan verifikasi manual oleh admin |
| **Flow** | POS hitung total (max 499k) → customer scan QR → transfer → admin cek GoPay merchant apps → konfirmasi manual di POS → selesaikan transaksi → cetak struk |
| **Catatan** | Risiko waktu tunggu lebih lama karena admin harus menunggu dan memverifikasi transfer masuk sebelum menyelesaikan transaksi |

### 1.3. Persyaratan Teknis

1. Kolom baru di tabel `penjualan`:
   - `metode_bayar` — enum: `'cash'`, `'midtrans'`, `'qris_statis'`
   - `payment_ref` — varchar, nullable (menyimpan transaction ID dari gateway)
   - `payment_status` — enum: `'pending'`, `'success'`, `'failed'`
   - `biaya_admin` — integer, default 0 (mencatat fee transaksi)

2. Validasi:
   - QRIS Statis: tolak transaksi dengan nominal > Rp 499.000
   - Midtrans: validasi signature callback dari Midtrans sebelum update status

3. Transaksi dengan `payment_status = 'pending'` harus muncul di daftar transaksi yang memerlukan konfirmasi admin.

---

## 2. SELF-ORDER KIOSK & AI CHATBOT ASSISTANT

### 2.1. Konsep Dua Arah (Two-Way Order Flow)

Sistem harus mendukung **2 jalur order yang paralel**:

| Jalur | Pelaku | Perangkat | Deskripsi |
|-------|--------|-----------|-----------|
| **A. Admin POS** (eksisting) | Admin toko | PC/Desktop kasir | Flow eksisting — admin yang menginput dan memproses pesanan customer |
| **B. Self-Order Kiosk** (baru) | Customer mandiri | Tablet khusus di toko | Customer dapat menjelajah produk, melihat stok, memilih, dan melakukan checkout mandiri |

### 2.2. Fitur Self-Order Kiosk

#### 2.2.1. Mode Eksplorasi Produk

Tampilan tablet-friendly dengan layout seperti **ESB (Electronic Self-service Board)** yang umum di merchant resto modern:

- **Grid card produk** — menampilkan gambar, nama, harga, indikator stok
- **Navigasi kategori obat** — sorting berdasarkan kategori
- **Filter pencarian:**
  - Berdasarkan nama obat
  - Berdasarkan harga (range min-max)
  - Berdasarkan penyakit / indikasi (terintegrasi dengan AI — lihat section 2.3)
  - Berdasarkan kategori (analgesik, antibiotik, vitamin, dll)
- **Informasi stok real-time** — menampilkan ketersediaan stok terkini

#### 2.2.2. Keranjang Belanja & Checkout

- **Add to cart** — customer dapat menambahkan produk ke keranjang
- **Review cart** — lihat daftar, ubah quantity, hapus item
- **Pilih metode pembayaran** (saat checkout):
  - **Cash** → notifikasi ke admin, admin selesaikan pembayaran tunai
  - **Midtrans Dynamic QR** → proses otomatis, sama seperti section 1.2.A
  - **QRIS Statis** → konfirmasi admin, sama seperti section 1.2.B
- **Persetujuan customer** — item hanya masuk ke keranjang setelah konfirmasi customer (bukan otomatis add)

### 2.3. AI Chatbot Assistant (via Google Gemini API)

#### 2.3.1. Fungsi Umum

Chatbot cerdas yang berperan sebagai **asisten digital apoteker** dengan kemampuan:

1. **Memproses pertanyaan natural language** dari customer seputar:
   - Nama obat dan kegunaan
   - Kandungan obat
   - Indikasi penyakit
   - Alternatif obat
   - Dosis umum
2. **Menelusuri internet** untuk mendapatkan informasi terkini terkait obat/penyakit
3. **Melakukan mapping** antara informasi dari internet dengan data produk yang tersedia di database apotek
4. **Memberikan rekomendasi produk** yang relevan dalam bentuk **shortcut/interactive card** yang bisa langsung di-add ke keranjang

#### 2.3.2. Alur Teknis AI Chatbot

```
Step 1: INPUT
  User memilih shortcut (misal: "Obat Batuk Berdahak")
  ATAU user mengetik pertanyaan manual (misal: "obat untuk batuk berdahak apa saja?")

Step 2: INTERNET SEARCH (AI)
  Sistem mengirim prompt ke Gemini API untuk mencari informasi terkini di internet
  tentang obat-obat untuk kondisi yang ditanyakan.
  
  Contoh output AI:
    "Berdasarkan informasi terkini, obat batuk berdahak yang umum adalah:
     Ultraflu, Paratusin, Neozep, dan lain-lain."

Step 3: SQL QUERY (Non-Destruktif — AI ke Database)
  AI menyusun query SQL SELECT (READ-ONLY) untuk mencocokkan informasi dari
  internet dengan data produk di database apotek.
  
  Contoh query yang dihasilkan AI:
    SELECT * FROM produk WHERE nama_produk ILIKE '%ultraflu%'
    SELECT * FROM produk WHERE nama_produk ILIKE '%paratusin%'
    SELECT * FROM produk WHERE nama_produk ILIKE '%neozep%'

  KETENTUAN QUERY:
  - Hanya SELECT — TIDAK BOLEH INSERT, UPDATE, DELETE, DROP, ALTER
  - Hanya kolom yang di-whitelist (id_produk, nama_produk, harga_jual, stok, kategori)
  - WHERE clause terbatas pada: LIKE, ILIKE, IN, BETWEEN, AND, OR, =
  - Tidak boleh JOIN, subquery, UNION, aggregasi kompleks
  - Limit maksimal hasil: 50 baris

Step 4: MAPPING & FALLBACK
  AI memetakan hasil query dari database:
  
  Jika produk DITEMUKAN:
    → AI menampilkan product card shortcut
    → Card bisa di-tap untuk lihat detail
    → Bisa langsung Add to Cart (dengan persetujuan customer)
  
  Jika produk TIDAK DITEMUKAN:
    → AI memberikan fallback message yang informatif
    → Contoh: "Maaf, untuk [nama obat] saat ini belum tersedia di apotek kami.
      Namun kami punya alternatif: [daftar produk serupa]."
    → Tetap menampilkan rekomendasi alternatif jika ada

Step 5: PERSETUJUAN CUSTOMER
  Customer melihat rekomendasi AI → memilih → tap "Tambah ke Keranjang"
  → produk masuk ke cart → lanjut checkout seperti flow normal (section 2.2.2)
```

#### 2.3.3. Karakteristik AI yang Diminta

| Karakteristik | Deskripsi |
|---------------|-----------|
| **Dinamis** | Mengikuti data terbaru di database secara real-time |
| **Terhubung Internet** | Bisa mencari informasi terkini dari web untuk referensi obat/penyakit |
| **Multi-topik** | Bisa menjawab ribuan kemungkinan pertanyaan tentang kandungan obat, indikasi penyakit, dosis, kontraindikasi |
| **Berperan sebagai Apoteker Digital** | Memberikan informasi yang akurat dan bertanggung jawab, setara dengan informasi yang bisa diberikan apoteker |
| **Non-Destruktif** | Hanya membaca database, tidak pernah mengubah data |
| **Shortcut Generator** | Output berupa interactive card yang bisa langsung di-klik/tap |

---

## 3. SINKRONISASI 100% ANTARA ADMIN POS & SELF-ORDER

### 3.1. Prinsip Dasar

Kedua jalur order (Admin POS dan Self-Order Kiosk) harus terintegrasi penuh ke dalam **satu sistem yang sama** sehingga:

> **Tidak ada perbedaan data** antara transaksi yang dibuat lewat admin POS vs transaksi yang dibuat lewat self-order kiosk.

### 3.2. Area yang Harus Sinkron

| Area | Keterangan Sinkronisasi |
|------|------------------------|
| **Stok Produk** | Stok berkurang secara real-time saat transaksi dari kedua jalur difinalisasi. Tidak boleh ada selisih. |
| **Rekaman Stok (Kartu Stok)** | Setiap pergerakan stok dari kedua jalur tercatat di `rekaman_stoks`. Kartu stok menampilkan riwayat lengkap tanpa membedakan source. |
| **Laporan Keuangan** | Pendapatan, laba kotor, laba bersih mencakup transaksi dari kedua jalur. Laporan bisa difilter per source jika diperlukan. |
| **Stock Opname** | Ketika admin melakukan stock opname (penyesuaian stok manual), perubahan berlaku untuk kedua jalur secara simultan. |
| **Pengadaan Stok (Pembelian)** | Ketika stok baru masuk via pembelian, stok yang tersedia bertambah dan bisa langsung dijual via kedua jalur. |
| **Trigger & Event** | Semua event (stok minimum, expired, notifikasi) dipicu dari data yang sama, tanpa duplikasi atau miss. |

### 3.3. Persyaratan Teknis

1. Self-Order harus menggunakan **tabel penjualan yang sama** dengan Admin POS (bukan tabel terpisah).
2. Satu kolom `source` di tabel `penjualan` untuk membedakan asal transaksi (`'admin_pos'` / `'self_order'`).
3. Semua service layer existing (`StockRuntimeIntegrityService`, `BaselineStockReflowService`, `TransactionDateMutationService`) harus tetap digunakan tanpa modifikasi berarti — self-order hanya memanggil endpoint/logic yang sama.
4. Stok mutasi dari self-order harus melalui **pessimistic locking** dan **idempotency key** yang sama seperti Admin POS.
5. Riwayat kartu stok dan laporan keuangan harus tetap akurat tanpa perlu rekonsiliasi manual antara kedua source.

---

> **Dokumen ini hanya berisi spesifikasi kebutuhan.** Rancangan teknis, estimasi, dan strategi implementasi dibahas terpisah.
