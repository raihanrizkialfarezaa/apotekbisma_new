# Analisis Kritis Draft Proposal Skripsi

## Ringkasan Eksekutif

Secara objektif, draft terbaru ini sudah jauh lebih *robust* dibanding versi sebelumnya—terutama pada definisi validitas/konsistensi, rancangan tiga kondisi, *fault injection*, *invariant*, serta metode statistik. Namun saya belum menyarankan membawanya besok tanpa beberapa penajaman penting.

**Penilaian saya:**

| Aspek | Skor |
|-------|------|
| Kematangan konsep arsitektur | 8.5/10 |
| Kekuatan metode eksperimen | 8/10 |
| Keterikatan dengan kondisi nyata Apotek Bisma | 6.5/10 |
| Kesiapan menghadapi pertanyaan kritis Pak Made | 7.5/10 |
| Kerapian dokumen | Substansinya baik, tetapi 23 halaman dan masih ada beberapa inkonsistensi teknis/format |

Dokumen yang saya telaah adalah [draft Word terbaru](sandbox:/workspace/scratch/f6d30b9158b6/upload/DRAFT KONSEP DAN PROPOSAL SKRIPSI FINAL GUIDEBOOK FIXED (3) - Revisi Statistik Operasional Final.docx), beserta dokumentasi sistem lama dan kebutuhan klien.

---

## Bagian yang Sudah Sangat Kuat

### 1. Posisi Penelitian Jauh Lebih Jelas

Dokumen sudah tidak sekadar mengatakan "membuat *microservices*", tetapi menjelaskan masalah yang diuji:

- *Concurrent update*
- *Dual-write*
- *Event* duplikat
- *Partial failure*
- Transaksi lintas *database*
- Pemulihan *Saga*
- Konsekuensi performa

Ini penting karena menjadikan topik sebagai penelitian arsitektur dan konsistensi data, bukan proyek migrasi *framework* biasa.

### 2. Perbedaan dengan Penelitian WMS Candra Sudah Cukup Defensif

Tabel perbandingan sudah berhasil menunjukkan bahwa penelitian WMS sebelumnya berfokus pada pemindahan proses pendukung ke *queue* dan evaluasi performa, sedangkan penelitian Anda berfokus pada:

- *Database per service*
- Transaksi lintas domain
- **OCC** (*Optimistic Concurrency Control*)
- **Transactional Outbox**
- *Durable idempotency*
- **Saga** dan *compensation*
- *Fault injection*
- *Invariant* dan *recovery*

Ini sudah menjadi jawaban yang cukup kuat jika Pak Made bertanya, "*Apa yang Anda lanjutkan dari penelitian sebelumnya?*"

### 3. Definisi Data Valid dan Konsisten Sudah Operasional

Bagian 6 sekarang tidak berhenti pada definisi teoritis. Sudah ada:

- Data valid untuk setiap *use case*
- Data konsisten untuk setiap *use case*
- Parameter kelulusan
- *Invariant* yang diperiksa
- Cara membuktikannya melalui rekonsiliasi *database*

Ini sesuai dengan catatan awal Pak Made mengenai indikator validitas, konsistensi, dan cara mengujinya.

### 4. Metode Statistik Sudah di Atas Rata-rata Proposal S1

Pemisahan antara:

- Metrik kontinu
- Metrik keselamatan bernilai nol
- Unit analisis per iterasi
- **Friedman**
- **Wilcoxon**
- *Effect size*
- **Benjamini–Hochberg**
- *Bootstrap* BCa
- *Exact binomial*

sudah sangat baik. Penjelasan 7.4.3 dan 7.4.4 juga sekarang jauh lebih mudah ditelusuri.

### 5. Batas Klaim Sudah Sehat

Pernyataan bahwa hasil penelitian dibatasi pada Apotek Bisma, pola transaksi yang diuji, dan konfigurasi terkontrol sudah tepat. Anda juga tidak mengklaim menemukan algoritma baru.

Ini membuat kontribusinya lebih realistis:

> Rancangan implementasi, artefak sistem, metode evaluasi, bukti eksperimen, dan analisis *trade-off* pada kasus nyata.

---

## Hal Paling Penting yang Masih Harus Diperbaiki

### 1. Masalah Nyata Apotek Bisma Belum Dibuktikan Cukup Kuat

**Ini merupakan celah terbesar.**

Dokumen menyatakan bahwa *monolith* Laravel 8 "mulai memiliki keterbatasan" dalam menangani transaksi konkuren dan asinkron. Namun belum ada bukti nyata berupa:

- Jumlah transaksi harian atau jam sibuk
- Jumlah kasir aktif bersamaan
- Apakah tiga cabang benar-benar mengakses stok yang sama
- Apakah pernah terjadi stok tidak sesuai
- Apakah *kiosk* sudah digunakan atau baru direncanakan
- Apakah Midtrans sudah berjalan atau masih kebutuhan baru
- Apakah pernah ada *webhook* ganda atau pembayaran menggantung

Tanpa data tersebut, Pak Made dapat bertanya:

> "Kalau belum pernah ada masalah seperti itu, mengapa harus *microservices*? Ini jangan-jangan *over-solution*?"

**Jawaban yang paling aman** bukan mengklaim bahwa *monolith* sekarang sudah gagal, melainkan:

> Sistem *monolith* saat ini masih dapat menjalankan fungsi dasar. Permasalahan penelitian muncul ketika sistem dikembangkan untuk mendukung tiga cabang, *kiosk*, dan pembayaran asinkron. Penelitian ini tidak berangkat dari asumsi bahwa *microservices* pasti lebih baik, tetapi mengevaluasi apakah pemisahan layanan tersebut layak dan mekanisme apa yang diperlukan agar migrasi tidak mengorbankan konsistensi data.

Kalimat "mulai memiliki keterbatasan" sebaiknya hanya dipertahankan jika ada bukti wawancara, *log*, atau data transaksi.

### 2. Topologi Persediaan Tiga Cabang Belum Dijelaskan

Dokumen menyebut tiga cabang dan satu gudang terpusat, tetapi belum menjelaskan secara konkret:

- Apakah stok dicatat per cabang
- Apakah gudang memiliki stok sendiri
- Apakah penjualan cabang hanya mengurangi stok cabang
- Bagaimana proses transfer gudang ke cabang
- Siapa pemilik data stok yang otoritatif
- Apakah satu produk memiliki `branch_id`, `location_id`, atau tabel saldo per lokasi

Padahal dokumentasi sistem lama masih menunjukkan satu kolom `produk.stok`. Belum terlihat struktur saldo stok per cabang/gudang.

Ini dapat menjadi pertanyaan tajam:

> "Kalau sistem lama hanya punya satu stok produk, aspek multi-cabangnya berada di mana?"

Anda perlu menjelaskan model minimal:

```
Saldo stok = (product_id, location_id, on_hand, reserved, version)
```

Dengan demikian, setiap cabang dan gudang mempunyai saldo masing-masing. *Restock* gudang ke cabang seharusnya diperlakukan sebagai transfer:

1. *Reserve* stok gudang
2. Catat pengiriman
3. Barang diterima cabang
4. Kurangi stok gudang
5. Tambah stok cabang
6. Lakukan kompensasi jika proses gagal

Tanpa penjelasan ini, label "multi-cabang" masih terasa sebagai latar cerita, belum masuk ke desain data.

### 3. Kondisi A, B, dan C Tidak Semuanya Bisa Menerima *Fault* yang Sama

**Ini masalah metodologis yang cukup penting.**

Kondisi A adalah *monolith* satu *database*, sedangkan *fault* seperti:

- Pesan *broker* duplikat
- *Worker* mati sebelum *event* dipublikasikan
- *Consumer crash* sebelum ACK
- *Saga* lintas layanan

tidak semuanya ada pada Kondisi A.

Karena itu, pernyataan bahwa seluruh A, B, dan C menerima *fault* yang identik tidak selalu mungkin. **Friedman A–B–C juga tidak tepat** untuk metrik yang secara konsep tidak terdapat pada Kondisi A.

**Desain yang lebih aman:**

| Perbandingan | Fungsi |
|--------------|--------|
| A–B–C | Metrik yang memang dimiliki ketiganya: hasil bisnis akhir, *latency*, *throughput*, *error rate* |
| B–C | *Dual-write*, *duplicate delivery*, *consumer crash*, *broker delay*, *recovery*, DLQ, *Saga* |
| Sebelum–sesudah mekanisme tertentu di C | Jika diperlukan untuk menjelaskan efek Outbox, OCC, atau *idempotency* secara lebih terisolasi |

Kondisi A tetap berguna sebagai *baseline* operasional, tetapi bukti sebab-akibat utama mekanisme proteksi sebenarnya datang dari B dibandingkan C karena keduanya menggunakan *stack* dan pembagian layanan yang sama.

### 4. Beban *Low*, *Medium*, dan *High* Belum Memiliki Angka

Dokumen berkali-kali menyebut beban rendah, sedang, dan tinggi, tetapi belum menetapkan:

- Target RPS atau VU
- Durasi *warm-up*
- Durasi *measurement*
- Komposisi transaksi
- Waktu *fault* disuntikkan
- *Recovery window*
- Batas SLA *read-model lag*
- Batas *retry* dan *timeout Saga*

Pak Made dapat langsung bertanya:

> "*High* itu berapa? Dasarnya dari mana?"

**Jawaban terbaik:**

- Nilai awal diperoleh melalui *pilot test*
- *Low* berada di bawah kapasitas normal
- *Medium* mendekati beban operasional/puncak
- *High* mendekati titik saturasi tanpa membuat seluruh kondisi langsung lumpuh
- Setelah *pilot* selesai, semua angka dikunci sebelum eksperimen utama

Akan lebih kuat jika Anda membawa satu tabel konfigurasi sementara meskipun masih berlabel "akan divalidasi melalui *pilot test*".

### 5. *Recovery Window* dan SLA Belum Benar-benar Ditentukan

Dokumen menyatakan:

> "Harus menuju kondisi akhir yang sama dalam batas waktu yang ditentukan sebelum eksperimen."

Namun angkanya belum ada. *Pilot test* boleh digunakan, tetapi SLA bisnis sebaiknya tidak hanya ditentukan dari kemampuan sistem.

**Pisahkan dua batas:**

- **Batas teknis**: diperoleh dari *pilot test*
- **Batas operasional**: diperoleh dari wawancara pengguna

Contoh:

- *Dashboard* stok harus memperbarui informasi maksimal 2 detik
- Transaksi yang gagal harus mencapai status terminal maksimal 30 detik
- Pesan di DLQ harus menghasilkan *alert* maksimal 1 menit
- Reservasi QRIS dilepas setelah *timeout* bisnis tertentu

Angka tersebut hanya contoh, jangan dipakai sebelum disepakati dengan pihak Apotek Bisma.

### 6. "Masuk DLQ" Belum Berarti Transaksi Berhasil Dipulihkan

Di F3 tertulis bahwa jika *retry* tetap gagal, pesan diamankan ke DLQ. Itu baru mencegah pesan hilang, belum menyelesaikan *Saga*.

Anda perlu membedakan:

- Pesan tersimpan aman
- Pesan sudah diproses
- Transaksi sudah mencapai status terminal

Pesan yang berada di DLQ tetap harus:

- Diputar ulang secara otomatis atau manual
- Menghasilkan *alert*
- Ditautkan ke `correlation_id`
- Diselesaikan atau ditandai `MANUAL_REVIEW`

Jika tidak, target "*terminal-state coverage* 100%" bertentangan dengan kondisi adanya pesan tertahan di DLQ.

### 7. Penanganan *Webhook* Midtrans Terlambat Perlu Lebih Hati-hati

Dokumen mengatakan pembayaran terlambat setelah *order* kedaluwarsa tidak boleh menghidupkan kembali transaksi. Itu benar untuk menjaga *state machine*, tetapi ada masalah bisnis:

> Bagaimana jika pelanggan benar-benar sudah membayar dan *settlement* Midtrans valid, tetapi stok sudah dilepas?

*Webhook* valid tidak boleh hanya ditolak dan dilupakan. Sistem perlu salah satu kebijakan:

- Rekonsiliasi manual
- *Refund*
- Pemenuhan ulang jika stok masih tersedia
- Status khusus seperti `PAID_AFTER_EXPIRY` atau `REQUIRES_RECONCILIATION`

Jika Pak Made menanyakan ini, jawaban yang aman:

> *Order* yang sudah kedaluwarsa tidak otomatis diaktifkan kembali. Namun pembayaran valid tetap dicatat sebagai fakta keuangan dan diarahkan ke proses rekonsiliasi atau *refund* agar tidak ada uang pelanggan yang hilang.

### 8. Definisi *Oversell* Masih Terlalu Sempit

Pada tabel metrik, *oversell* dijelaskan sebagai transaksi yang lolos saat stok kosong. Padahal *oversell* juga terjadi ketika stok belum nol, tetapi jumlah yang dijual melampaui `available`.

**Definisi yang lebih tepat:**

```
Oversell terjadi jika total kuantitas yang dikomit > stok tersedia yang sah
```

atau apabila:

```
available = on_hand - reserved < 0
```

Kasus stok 3 dan dua pembelian masing-masing 2 adalah *oversell* meskipun stok awal tidak kosong.

### 9. "Selisih Sistem dan Fisik Barang" Tidak Dapat Dibuktikan Murni oleh Eksperimen

Eksperimen perangkat lunak tidak mempunyai barang fisik nyata. Yang dapat dibuktikan adalah kecocokan antara:

- Saldo otoritatif
- *Ledger* mutasi
- *Test oracle*
- Hasil akhir setiap *database*

Jadi istilah "selisih hitungan sistem dan fisik barang" sebaiknya diubah menjadi:

> Selisih saldo sistem terhadap saldo yang diharapkan berdasarkan *test oracle* dan seluruh mutasi sah.

Kecocokan dengan stok fisik hanya dapat diperoleh melalui *stock opname* atau validasi lapangan.

### 10. Klaim "Gagal Total" Terlalu Keras

Kalimat:

> "Satu pelanggaran membuat arsitektur langsung dinyatakan gagal total"

sebaiknya diubah menjadi:

> Satu pelanggaran membuat Kondisi C tidak memenuhi kriteria penerimaan pada metrik keselamatan terkait dalam konfigurasi pengujian tersebut.

Ini lebih akademis dan sesuai dengan batas generalisasi. Satu *bug* implementasi tidak otomatis membuktikan seluruh pola arsitektur gagal secara universal.

---

## Koreksi Teknis dan Statistik Kecil

Beberapa hal kecil ini sebaiknya dibenahi:

1. **Prosedur eksperimen** menyebut F1–F4, tetapi tabel *fault* memiliki F1–F5.

2. **Median CPU/RAM** dijelaskan sebagai "penggunaan rata-rata". Median bukan rata-rata. Gunakan "nilai tengah penggunaan".

3. **Rumus Friedman** secara substansi benar, tetapi tampilan simbol *chi-square* terlihat seperti `(χ²₂)`. Seharusnya:

   `P(χ²(2) ≥ 8.4)`

4. **Pada bagian Wilcoxon** terdapat *bullet* kosong sebelum `(W⁺)`. Ini terlihat sebagai gangguan format.

5. **Bootstrap BCa** yang dilakukan terhadap 30 nilai p95 menghasilkan interval untuk median p95 antariterasi, bukan interval langsung untuk p95 *request*. Penjelasan sekarang sudah mengarah benar, tetapi istilahnya harus konsisten di semua bagian.

6. **Exact binomial 0/30** menghasilkan batas atas sekitar 9.5%. Ini menunjukkan bahwa 30 iterasi cukup untuk eksperimen awal, tetapi bukti statistik terhadap kejadian langka masih relatif lemah. Sampaikan bahwa *invariant* bersifat *deterministic acceptance test*, sedangkan interval binomial hanya menggambarkan keterbatasan kekuatan bukti.

7. **"Tiga mekanisme konsistensi"** tidak konsisten dengan banyaknya mekanisme yang disebutkan. Tentukan tiga mekanisme inti, misalnya:

   - **OCC** untuk *concurrent update*
   - **Outbox–Inbox** untuk *reliable* dan *idempotent delivery*
   - **Saga orchestration** untuk konsistensi transaksi lintas layanan

   *Retry*, DLQ, *worker*, dan *compensation* diposisikan sebagai komponen pendukung ketiganya.

---

## Masalah Ruang Lingkup Apotek

Topik Anda bisa melebar jika tidak dibatasi. Sistem apotek secara umum juga melibatkan:

- *Batch* atau *lot* obat
- Tanggal kedaluwarsa
- **FEFO** (*First Expired First Out*)
- Resep
- Obat keras
- Otorisasi apoteker
- *Retur* dan *recall*

Skripsi ini tidak harus menangani seluruhnya. Namun harus ditulis eksplisit bahwa penelitian membatasi kebenaran data pada:

- Stok kuantitatif per produk dan lokasi
- Reservasi
- Penjualan
- *Restock/transfer*
- Pembayaran

Jika *batch*, FEFO, resep, dan regulasi obat tidak diuji, nyatakan sebagai batasan atau pengembangan lanjutan. Ini mencegah Pak Made menarik pembahasan terlalu jauh.

---

## Apakah Judulnya Sudah Tepat?

Judul saat ini cukup kuat, tetapi belum sepenuhnya menggambarkan isi karena *Saga* dan *durable idempotency* sangat dominan.

Judul juga terasa agak panjang dan "*Asynchronous I/O*" bukan mekanisme yang menjaga konsistensi. *Asynchronous I/O* adalah karakter *runtime*, sedangkan kebenaran data berasal dari Outbox, OCC, *idempotency*, dan *Saga*.

**Versi yang lebih fokus:**

> **Implementasi Transactional Outbox, Optimistic Concurrency Control, dan Saga pada Migrasi Monolith ke Event-Driven Microservices untuk Menjaga Konsistensi Data**

Atau lebih berorientasi evaluasi:

> **Evaluasi Konsistensi Data pada Migrasi Monolith ke Event-Driven Microservices Menggunakan Transactional Outbox, OCC, dan Saga**

Saya cenderung memilih versi kedua jika Pak Made sensitif terhadap topik yang terkesan langsung menetapkan solusi. Kata "evaluasi" menegaskan bahwa Anda belum menganggap *microservices* pasti lebih baik.

---

## Prioritas Perbaikan Sebelum Presentasi

Jika waktunya terbatas, fokuslah pada enam hal berikut:

1. ✅ Tambahkan topologi konkret tiga cabang–satu gudang dan model stok per lokasi
2. ✅ Ubah narasi dari "*monolith* sudah bermasalah" menjadi "penelitian mengevaluasi kebutuhan migrasi akibat ekspansi kanal dan transaksi asinkron"
3. ✅ Tegaskan A sebagai *baseline* operasional, sedangkan pembuktian mekanisme utama berasal dari B–C
4. ✅ Buat matriks *fault* mana yang berlaku untuk A, B, dan C
5. ✅ Tambahkan parameter sementara *workload*, *timeout*, *recovery window*, dan cara menetapkan SLA
6. ✅ Perbaiki definisi *oversell*, DLQ, pembayaran terlambat, dan klaim "gagal total"

---

## Kesimpulan Akhir

Draft ini sudah layak dibawa sebagai bahan bimbingan kedua dan secara konseptual jauh lebih matang. Pak Made kemungkinan tidak lagi mempermasalahkan "apa itu Outbox/OCC/*Saga*" atau "bagaimana konsistensi diukur", karena bagian tersebut sudah kuat.

**Titik yang kemungkinan besar akan beliau tekan justru bergeser ke level yang lebih tinggi:**

- Benarkah Apotek Bisma membutuhkan *microservices*?
- Apa bukti masalah aktualnya?
- Bagaimana stok tiga cabang dimodelkan?
- Apakah perbandingan A–B–C adil?
- Apakah seluruh *fault* dapat diterapkan pada *monolith*?
- Dari mana angka beban dan SLA diperoleh?
- Bagaimana pembayaran valid yang datang setelah *timeout* ditangani?

**Jadi, jawaban objektifnya:**
> Sudah *robust* secara teori dan rancangan eksperimen, tetapi keterikatan antara arsitektur, data aktual Apotek Bisma, serta keadilan perbandingan kondisi masih perlu diperkuat. Itu area paling menentukan agar presentasi kedua besok benar-benar aman.