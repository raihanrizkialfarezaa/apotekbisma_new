# Draft Perbaikan Tambahan Proposal Skripsi

Dokumen ini disusun sebagai acuan tambahan untuk memperkuat draft proposal skripsi tanpa mengubah judul yang sudah disetujui. Fokus perbaikan diarahkan pada keterkaitan antara rancangan arsitektur, kondisi operasional terbaru Apotek Bisma, kebutuhan multi-cabang, gudang terpusat, pencatatan stok, transaksi, kartu stok, stock opname, serta kebutuhan audit operasional yang dapat diperiksa secara berkala oleh pihak terkait seperti Dinas Kesehatan.

Judul utama tetap dipertahankan sesuai draft yang sudah disetujui:

> **Implementasi Transactional Outbox dan Optimistic Concurrency Control pada Migrasi Monolith ke Event-Driven Microservices Berbasis Asynchronous I/O untuk Menjaga Konsistensi Data**

Catatan penting: walaupun judul tidak menyebut Saga, multi-cabang, atau gudang pusat secara eksplisit, aspek tersebut tetap dapat dimasukkan ke latar belakang, batasan masalah, rancangan arsitektur, use case, metrik, dan pembahasan sebagai konteks studi kasus Apotek Bisma.

---

## 1. Posisi Revisi Utama

Draft proposal saat ini sudah kuat pada aspek teknis seperti Transactional Outbox, Optimistic Concurrency Control, event-driven microservices, durable idempotency, Saga, fault injection, invariant, recovery, dan statistik eksperimen. Namun keterikatan dengan kondisi nyata Apotek Bisma perlu diperkuat agar penelitian tidak terlihat seperti eksperimen arsitektur yang berdiri sendiri.

Kondisi nyata terbaru yang perlu dijadikan dasar adalah:

- Sistem Apotek Bisma mulai beroperasi sejak tahun 2023 sebagai sistem monolith Laravel 8 untuk satu toko/cabang pusat.
- Sistem eksisting sudah dipakai kurang lebih tiga tahun sampai tahun 2026, sehingga konteksnya bukan sistem percobaan, tetapi legacy system nyata yang sudah membantu operasional harian.
- Sistem eksisting masih membantu operasional dasar, tetapi rancangan awalnya dibuat ketika kebutuhan bisnis masih berpusat pada satu cabang.
- Apotek Bisma sekarang berkembang menjadi tiga cabang dan sudah memiliki kebutuhan integrasi operasional lintas lokasi.
- Apotek Bisma juga sedang mempertimbangkan pembukaan cabang keempat melalui pemetaan lahan/lokasi strategis, sehingga sistem perlu lebih siap untuk pertumbuhan berikutnya.
- Pemilik apotek membutuhkan satu sistem terintegrasi untuk mengelola seluruh cabang.
- Terdapat kebutuhan gudang pusat yang mendistribusikan barang ke masing-masing cabang.
- Pencatatan stok tidak lagi cukup memakai satu angka stok global pada produk.
- Data operasional seperti transaksi, stok, kartu stok obat, mutasi barang, dan stock opname perlu rapi karena dapat diperiksa secara berkala oleh pihak terkait seperti Dinas Kesehatan.
- Pemilik apotek bukan pengguna teknis, sehingga sistem harus membantu pengawasan operasional dengan tampilan status yang jelas, alur yang sederhana, dan data yang dapat ditelusuri.

Narasi penelitian sebaiknya tidak mengatakan bahwa monolith lama sudah gagal. Narasi yang lebih aman adalah:

> Sistem monolith Apotek Bisma masih mampu menjalankan fungsi dasar ketika digunakan pada satu toko dan telah menjadi bagian dari operasional sejak tahun 2023. Namun, kebutuhan bisnis berubah ketika pada tahun 2026 Apotek Bisma berkembang menjadi tiga cabang dengan satu gudang pusat, serta mulai memetakan peluang pembukaan cabang keempat. Perubahan ini menuntut pencatatan stok per lokasi, transfer barang dari gudang ke cabang, konsolidasi transaksi, serta jejak audit yang lebih jelas. Penelitian ini mengevaluasi migrasi bertahap menuju event-driven microservices untuk mengetahui mekanisme apa yang diperlukan agar penyegaran dan pemecahan sistem tidak mengorbankan konsistensi data stok, transaksi, dan pembayaran.

---

## 2. Draft Revisi Ringkasan Eksekutif

Bagian Ringkasan Eksekutif dapat diperkuat dengan narasi berikut.

> Apotek Bisma merupakan apotek retail yang mulai menjalankan sistem POS dan ERP sederhana berbasis Laravel 8 monolith sejak tahun 2023 ketika operasional masih berpusat pada satu toko. Sistem tersebut telah membantu pencatatan transaksi dan operasional dasar selama kurang lebih tiga tahun. Seiring perkembangan usaha, pada tahun 2026 Apotek Bisma telah beroperasi pada tiga cabang dan membutuhkan satu gudang pusat untuk mendistribusikan barang ke masing-masing cabang. Bahkan, pemilik apotek juga mulai mempertimbangkan pembukaan cabang keempat melalui pemetaan lokasi strategis. Perubahan ini menimbulkan kebutuhan penyegaran sistem yang tidak hanya berkaitan dengan pencatatan transaksi penjualan, tetapi juga pencatatan saldo stok per lokasi, transfer gudang-cabang, kartu stok, stock opname, pelacakan mutasi barang, serta konsolidasi data operasional yang dapat dipertanggungjawabkan ketika dilakukan pengecekan berkala oleh pihak terkait seperti Dinas Kesehatan.

> Dalam kondisi satu cabang, stok produk dapat dicatat secara lebih sederhana sebagai satu nilai kuantitas. Namun pada kondisi multi-cabang, satu produk dapat memiliki jumlah stok berbeda di gudang pusat, Cabang 1, Cabang 2, dan Cabang 3. Penjualan di satu cabang hanya boleh mengurangi stok cabang tersebut, sedangkan pengiriman barang dari gudang ke cabang harus dicatat sebagai mutasi atau transfer yang memiliki status, waktu, asal, tujuan, dan penanggung jawab. Apabila sistem tidak memiliki model stok per lokasi dan mekanisme konsistensi yang kuat, maka risiko yang muncul bukan hanya stok minus atau oversell, tetapi juga selisih kartu stok, riwayat mutasi yang tidak lengkap, pembayaran yang tidak cocok dengan order, serta data operasional yang sulit direkonsiliasi saat stock opname.

> Penelitian ini tidak berangkat dari klaim bahwa monolith selalu buruk atau microservices selalu lebih baik. Sistem monolith lama tetap dijadikan baseline karena merupakan sistem yang benar-benar digunakan sejak 2023 pada fase awal digitalisasi Apotek Bisma. Fokus penelitian adalah mengevaluasi bagaimana sistem yang telah berjalan tersebut dapat disegarkan dan dimigrasikan secara bertahap menuju event-driven microservices ketika data dan proses bisnis mulai dipisahkan ke beberapa layanan. Kondisi yang dibandingkan meliputi Laravel monolith sebagai baseline, microservices naive dengan publikasi event langsung, dan microservices yang dilengkapi mekanisme Transactional Outbox, Optimistic Concurrency Control, durable idempotency, Saga orchestration, compensation, retry, dan DLQ.

> Evaluasi utama diarahkan pada konsistensi data dan kemampuan pemulihan, terutama pada transaksi stok, order, pembayaran, transfer gudang-cabang, dan event asinkron. Performa seperti latency, throughput, error rate, CPU, dan RAM tetap diukur, tetapi diperlakukan sebagai trade-off dari perubahan arsitektur. Dengan demikian, hasil penelitian diharapkan tidak hanya menghasilkan implementasi teknis, tetapi juga rekomendasi migrasi yang sesuai dengan kebutuhan nyata Apotek Bisma sebagai apotek multi-cabang.

---

## 3. Draft Revisi Latar Belakang

Bagian latar belakang perlu dibuat lebih membumi terhadap realita operasional Apotek Bisma.

> Apotek Bisma pada awalnya menerapkan sistem informasi berbasis Laravel 8 monolith sejak tahun 2023 untuk membantu pencatatan transaksi dan operasional pada satu cabang utama. Pada tahap tersebut, pendekatan monolith masih memadai karena alur bisnis relatif terpusat, jumlah pengguna terbatas, dan stok dapat dikelola sebagai satu kesatuan di lokasi yang sama. Sistem ini bukan prototipe semata, melainkan sistem eksisting yang telah digunakan dalam operasional harian selama kurang lebih tiga tahun. Namun perkembangan usaha mengubah kebutuhan sistem. Pada tahun 2026, Apotek Bisma telah memiliki tiga cabang, membutuhkan gudang pusat yang berperan sebagai titik utama penyimpanan serta distribusi barang ke masing-masing cabang, dan mulai merencanakan ekspansi lanjutan berupa cabang keempat.

> Kebutuhan multi-cabang membuat pencatatan stok menjadi lebih kompleks. Stok tidak lagi cukup dicatat sebagai satu nilai pada tabel produk, karena produk yang sama dapat tersedia dalam jumlah berbeda pada gudang pusat dan setiap cabang. Ketika Cabang 1 menjual suatu obat, saldo yang berkurang seharusnya hanya saldo Cabang 1, bukan saldo Cabang 2, Cabang 3, atau gudang pusat. Sebaliknya, ketika gudang mengirim barang ke cabang, sistem perlu mencatat pengurangan stok gudang, penambahan stok cabang, status pengiriman, waktu penerimaan, dan riwayat mutasi yang dapat ditelusuri kembali.

> Dalam operasional apotek, kerapian data stok dan transaksi memiliki nilai penting karena berkaitan dengan kartu stok obat, stock opname, laporan transaksi, dan pemeriksaan berkala oleh pihak terkait seperti Dinas Kesehatan. Ketidaksesuaian data tidak hanya mengganggu keputusan bisnis, tetapi juga dapat menyulitkan pemilik apotek ketika harus menunjukkan riwayat keluar-masuk barang, saldo stok, dan bukti transaksi. Kondisi ini menjadi semakin penting karena pemilik Apotek Bisma bukan pengguna teknis, sehingga sistem perlu memberikan data yang jelas, terintegrasi, mudah dipantau, dan dapat direkonsiliasi tanpa menuntut pemahaman teknis yang rumit.

> Tantangan utama muncul ketika sistem yang awalnya monolith mulai dikembangkan menjadi beberapa layanan terpisah. Pada monolith dengan satu database, transaksi lintas tabel masih dapat dikelola menggunakan transaksi lokal database. Namun ketika data order, stok, pembayaran, dan pelaporan dipisah ke beberapa service dengan database masing-masing, rollback otomatis lintas layanan tidak lagi tersedia. Kegagalan pada salah satu bagian proses dapat menyebabkan event tidak terkirim, pesan diproses lebih dari sekali, stok terpotong ganda, pembayaran tercatat ganda, atau status order menggantung. Oleh karena itu, penelitian ini berfokus pada mekanisme untuk menjaga konsistensi data pada migrasi monolith menuju event-driven microservices.

---

## 4. Topologi Operasional Apotek Bisma

Topologi bisnis yang perlu dijelaskan dalam proposal adalah sebagai berikut.

```text
Gudang Pusat
  - menerima stok dari supplier
  - menyimpan stok utama
  - mendistribusikan barang ke cabang
  - menjadi sumber mutasi masuk dan transfer keluar

Cabang 1
  - melayani penjualan pelanggan
  - memiliki stok lokal sendiri
  - menerima transfer dari gudang pusat

Cabang 2
  - melayani penjualan pelanggan
  - memiliki stok lokal sendiri
  - menerima transfer dari gudang pusat

Cabang 3
  - melayani penjualan pelanggan
  - memiliki stok lokal sendiri
  - menerima transfer dari gudang pusat
```

Aturan bisnis dasar:

- Setiap lokasi memiliki saldo stok sendiri.
- Gudang pusat dan cabang sama-sama dianggap sebagai lokasi stok.
- Penjualan cabang hanya mengurangi stok cabang tersebut.
- Cabang tidak boleh menjual stok milik cabang lain tanpa proses transfer resmi.
- Gudang pusat tidak langsung melayani transaksi retail, kecuali jika secara bisnis ditentukan sebagai lokasi penjualan khusus.
- Transfer gudang ke cabang harus memiliki nomor transfer atau nomor mutasi.
- Setiap transfer memiliki status yang dapat dilacak.
- Setiap perubahan stok harus menghasilkan kartu stok atau stock ledger.
- Stok akhir sistem harus dapat direkonsiliasi dengan test oracle dan dapat dibandingkan dengan hasil stock opname lapangan.

Jawaban defensif jika ditanya dosen:

> Multi-cabang pada penelitian ini tidak dimodelkan sebagai tiga microservice berbeda. Cabang direpresentasikan sebagai entitas lokasi pada domain Inventory. Microservice tetap dipisahkan berdasarkan bounded context seperti Sales, Inventory, Payment, dan Reporting. Dengan demikian, jumlah cabang tidak menentukan jumlah service, tetapi memengaruhi model data stok dan aturan bisnis.

---

## 4A. Timeline Evolusi Sistem dan Kebutuhan Penyegaran

Bagian ini dapat digunakan untuk menjawab pertanyaan mengapa pengembangan dilakukan sekarang, bukan sejak awal sistem dibuat.

| Tahun/Fase | Kondisi Operasional Apotek Bisma | Implikasi Terhadap Sistem |
|---|---|---|
| 2023 | Sistem monolith Laravel 8 mulai digunakan untuk mendukung satu cabang utama | Satu database dan pencatatan stok sederhana masih relatif memadai |
| 2024-2025 | Sistem berjalan sebagai alat bantu transaksi dan operasional dasar | Sistem mulai menjadi aset operasional nyata, tetapi masih membawa asumsi desain satu lokasi |
| 2026 | Apotek Bisma telah berkembang menjadi tiga cabang dan membutuhkan gudang pusat | Dibutuhkan model stok per lokasi, transfer gudang-cabang, laporan per cabang, dan rekonsiliasi lintas lokasi |
| Rencana berikutnya | Pemilik mulai memetakan peluang pembukaan cabang keempat | Sistem perlu disegarkan agar tidak hanya cocok untuk kondisi satu toko dan lebih siap terhadap pertumbuhan cabang |

Narasi siap pakai:

> Sistem Apotek Bisma bukan sistem baru yang dibuat dari nol untuk kebutuhan skripsi. Sistem ini sudah berjalan sejak tahun 2023 sebagai monolith untuk mendukung operasional satu cabang. Namun, pada tahun 2026, kondisi bisnis sudah berubah. Apotek Bisma telah berkembang menjadi tiga cabang, membutuhkan gudang pusat, dan sedang mempertimbangkan pembukaan cabang keempat. Karena itu, penelitian ini berangkat dari kebutuhan penyegaran sistem nyata yang telah digunakan selama beberapa tahun agar lebih siap mendukung operasional multi-cabang.

> Fokus penelitian bukan membuktikan bahwa monolith gagal, tetapi mengevaluasi risiko dan mekanisme konsistensi ketika sistem dikembangkan menuju arsitektur event-driven microservices. Dalam konteks apotek, kesalahan data tidak hanya berdampak pada transaksi, tetapi juga pada kartu stok, stock opname, mutasi barang, laporan cabang, dan kesiapan data saat pemeriksaan operasional. Oleh karena itu, konsistensi data menjadi aspek utama yang diuji.

Problem statement yang lebih kuat:

> Permasalahan utama penelitian ini adalah bagaimana menyegarkan sistem Apotek Bisma yang telah berjalan sejak 2023 sebagai monolith satu cabang agar dapat mendukung kebutuhan bisnis tahun 2026 yang telah berkembang menjadi tiga cabang, satu gudang pusat, dan rencana ekspansi cabang keempat, tanpa mengorbankan konsistensi data stok, transaksi, pembayaran, mutasi barang, dan kartu stok.

Kalimat akademik alternatif:

> Perubahan skala operasional Apotek Bisma dari satu cabang menjadi multi-cabang menimbulkan tantangan baru pada konsistensi data. Sistem yang awalnya menggunakan satu database dan model stok sederhana perlu dikaji ulang karena kini harus menangani stok per lokasi, transfer gudang-cabang, transaksi konkuren, pembayaran asinkron, dan data audit operasional. Penelitian ini mengevaluasi migrasi bertahap menuju event-driven microservices dengan mekanisme Transactional Outbox, OCC, durable idempotency, dan Saga untuk menjaga integritas data pada konteks tersebut.

---

## 4B. Rumusan Masalah Revisi

Rumusan masalah berikut dapat digunakan sebagai versi yang lebih kuat tanpa mengganti judul utama.

1. Bagaimana memodelkan stok per lokasi untuk mendukung tiga cabang dan satu gudang pusat pada sistem Apotek Bisma yang sebelumnya berbasis monolith satu cabang?
2. Bagaimana merancang migrasi bertahap dari monolith menuju event-driven microservices untuk mendukung pemisahan domain Sales, Inventory, Payment, dan Reporting?
3. Bagaimana menerapkan Transactional Outbox, Optimistic Concurrency Control, durable idempotency, dan Saga untuk menjaga konsistensi stok, order, pembayaran, transfer barang, dan kartu stok?
4. Bagaimana perilaku sistem pada Kondisi A monolith baseline, Kondisi B naive event-driven microservices, dan Kondisi C robust event-driven microservices ketika diuji dengan concurrent update, duplicate event, crash, timeout, partial failure, dan transfer gudang-cabang?
5. Apa trade-off performa, kompleksitas, dan kemampuan pemulihan yang muncul dari penerapan mekanisme konsistensi tersebut pada konteks operasional Apotek Bisma?

Catatan defensif:

> Rumusan masalah tidak diarahkan untuk membuktikan bahwa microservices selalu lebih baik daripada monolith. Rumusan masalah diarahkan untuk mengevaluasi apakah rancangan event-driven microservices dengan mekanisme konsistensi yang tepat dapat mendukung kebutuhan Apotek Bisma yang telah berkembang dari satu cabang menjadi multi-cabang.

---

## 4C. Tujuan Penelitian Revisi

Tujuan penelitian dapat diperkuat sebagai berikut.

1. Merancang model stok per lokasi untuk kebutuhan Apotek Bisma yang telah berkembang dari satu cabang menjadi tiga cabang dan satu gudang pusat.
2. Merancang pemisahan domain sistem menjadi Sales, Inventory, Payment, dan Reporting tanpa menghilangkan konsistensi data bisnis.
3. Mengimplementasikan Transactional Outbox, Optimistic Concurrency Control, durable idempotency, dan Saga pada skenario order, stok, pembayaran, transfer barang, dan kartu stok.
4. Mengevaluasi kemampuan sistem dalam mencegah oversell, lost update, duplicate effect, permanent mismatch, event loss, dan Saga yang menggantung.
5. Mengevaluasi kemampuan sistem mencapai kondisi akhir yang sah setelah terjadi crash, duplicate event, consumer failure, webhook terlambat, dan partial failure lintas layanan.
6. Menganalisis trade-off antara konsistensi data, recovery, latency, throughput, error rate, dan penggunaan resource pada tiga kondisi arsitektur.
7. Menghasilkan rekomendasi pengembangan sistem Apotek Bisma yang realistis untuk mendukung operasional multi-cabang, gudang pusat, dan rencana ekspansi cabang berikutnya.

---

## 4D. Kontribusi Penelitian Revisi

Kontribusi penelitian perlu diposisikan sebagai kontribusi desain, implementasi, dan evaluasi, bukan sebagai klaim menemukan algoritma baru.

| Jenis Kontribusi | Bentuk Kontribusi |
|---|---|
| Kontribusi praktis | Rancangan penyegaran sistem Apotek Bisma dari monolith satu cabang menuju sistem multi-cabang terintegrasi |
| Kontribusi data | Model stok per lokasi, kartu stok digital, mutasi gudang-cabang, dan rekonsiliasi saldo sistem |
| Kontribusi arsitektur | Implementasi event-driven microservices dengan database per service untuk Sales, Inventory, Payment, dan Reporting |
| Kontribusi konsistensi | Penerapan Transactional Outbox, OCC, durable idempotency, Saga, compensation, retry, dan DLQ |
| Kontribusi evaluasi | Desain eksperimen tiga kondisi, fault injection, invariant bisnis, recovery window, dan rekonsiliasi otomatis |
| Kontribusi analitis | Analisis trade-off antara keamanan data, kemampuan pemulihan, latency, throughput, dan resource |

Kalimat siap pakai:

> Penelitian ini tidak mengusulkan algoritma baru, tetapi memberikan kontribusi berupa rancangan, implementasi, dan evaluasi mekanisme konsistensi data pada kasus nyata sistem apotek multi-cabang. Kontribusi utama terletak pada bagaimana sistem monolith yang telah berjalan sejak 2023 dapat dievaluasi untuk disegarkan menuju event-driven microservices dengan tetap menjaga kebenaran stok, order, pembayaran, transfer barang, dan kartu stok.

---

## 4E. Bukti Teknis Dari Sistem Eksisting

Bagian ini dapat digunakan untuk menutup celah pertanyaan: "Apakah masalah konsistensi stok ini benar-benar terjadi atau hanya skenario buatan?"

Jawaban utamanya:

> Masalah konsistensi stok bukan skenario buatan. Codebase sistem eksisting Apotek Bisma menunjukkan adanya kebutuhan nyata untuk menjaga, memeriksa, merekalkulasi, dan memperbaiki data stok. Hal ini terlihat dari adanya model stok global pada `produk.stok`, kartu stok pada `rekaman_stoks`, fungsi rekalkulasi stok, baseline stock reflow, monitoring stok negatif, audit transaksi terhadap rekaman stok, serta berbagai utilitas analisis dan perbaikan stok.

### 4E.1 Temuan Struktur Sistem Lama

| Temuan Pada Sistem Eksisting | Bukti Teknis | Implikasi Penelitian |
|---|---|---|
| Stok utama masih melekat pada produk | Model `Produk` menggunakan tabel `produk` dengan atribut `stok` | Sistem lama masih berorientasi pada satu nilai stok global, belum saldo stok per lokasi |
| Riwayat stok dicatat melalui kartu stok | Model `RekamanStok` menggunakan tabel `rekaman_stoks` | Sistem sudah membutuhkan ledger/kartu stok, tetapi masih perlu dijaga konsistensinya |
| Ada rekalkulasi stok historis | `RekamanStok::recalculateStock($productId)` | Master stok dan kartu stok dapat berbeda sehingga perlu rekonsiliasi |
| Ada koreksi otomatis `stok_sisa` | `RekamanStok` mengoreksi `stok_sisa` jika tidak sesuai rumus | Invariant stok sudah menjadi kebutuhan nyata, bukan hanya konsep penelitian |
| Ada guard stok negatif | `ProdukObserver`, `RekamanStokObserver`, dan `StockRuntimeIntegrityService` | Risiko stok negatif pernah/masih perlu dicegah pada level runtime |
| Ada baseline stock reflow | `BaselineStockReflowService` memakai baseline `Saldo Awal Stok per 31-12-2025` | Setelah beberapa tahun berjalan, sistem memerlukan titik acuan dan rekonstruksi stok |
| Ada audit transaksi vs rekaman stok | `audit_and_repair_transaction_stock_integrity.php` memeriksa mismatch header-detail-ledger | Validitas transaksi tidak cukup dari satu tabel; harus cocok dengan detail dan ledger |
| Ada monitoring kesehatan stok | `MonitorStockHealth` memeriksa stok negatif, record inkonsisten, dan orphaned record | Sistem membutuhkan monitoring berulang terhadap kesehatan data stok |
| Ada utilitas perbaikan stok | `FixStockInconsistencyComprehensive` dan beberapa script `analyze_*`, `check_*`, `debug_*`, `cleanup_*` | Riwayat troubleshooting stok cukup intensif dan perlu pendekatan yang lebih sistematis |

### 4E.2 Bukti Model Lama Masih Single-Location

Pada sistem eksisting, master stok masih disimpan sebagai atribut langsung pada produk.

```text
produk
- id_produk
- nama_produk
- stok
```

Model seperti ini masih masuk akal ketika Apotek Bisma hanya memiliki satu toko. Namun ketika bisnis berkembang menjadi tiga cabang dan satu gudang pusat, model tersebut tidak cukup untuk menjawab pertanyaan operasional berikut:

- Berapa stok produk A di gudang pusat?
- Berapa stok produk A di Cabang 1?
- Berapa stok produk A di Cabang 2?
- Berapa stok produk A di Cabang 3?
- Apakah stok Cabang 1 boleh dipakai untuk transaksi Cabang 2?
- Apakah barang yang sedang dikirim dari gudang sudah boleh dianggap stok tersedia di cabang?
- Bagaimana membedakan stok fisik di gudang, stok fisik di cabang, dan stok yang sedang di-reserve?

Karena itu, penelitian mengusulkan model baru:

```text
stock_balances
- product_id
- location_id
- on_hand
- reserved
- version
```

Kalimat siap pakai:

> Pada sistem eksisting, stok masih direpresentasikan sebagai satu nilai pada tabel produk. Model ini cukup untuk satu cabang, tetapi tidak lagi memadai untuk tiga cabang dan satu gudang pusat. Oleh karena itu, penelitian ini mengubah pendekatan dari `produk.stok` menjadi saldo stok per lokasi melalui `stock_balances(product_id, location_id, on_hand, reserved, version)`.

### 4E.3 Bukti Kebutuhan Rekonsiliasi Stok

Sistem eksisting memiliki kartu stok melalui `rekaman_stoks`. Namun adanya fungsi rekalkulasi dan sinkronisasi menunjukkan bahwa master stok dan ledger dapat berbeda.

Invariant yang sudah tampak dari sistem lama:

```text
stok_sisa = stok_awal + stok_masuk - stok_keluar
```

Pada sistem eksisting, ketika nilai `stok_sisa` tidak sesuai dengan rumus, sistem melakukan koreksi otomatis. Selain itu, `RekamanStok::recalculateStock($productId)` menghitung ulang histori stok dan menyinkronkan kembali `produk.stok` dengan record terakhir. Ini menunjukkan bahwa konsistensi stok perlu dijaga melalui proses rekonsiliasi.

Kalimat siap pakai:

> Kebutuhan rekonsiliasi stok sudah terlihat pada sistem lama melalui keberadaan fungsi rekalkulasi stok dan koreksi otomatis kartu stok. Hal ini memperkuat keputusan penelitian untuk menggunakan test oracle dan script rekonsiliasi lintas database sebagai bagian dari metode evaluasi.

### 4E.4 Bukti Risiko Stok Negatif dan Stok Selisih

Sistem eksisting memiliki beberapa mekanisme untuk menangani potensi stok negatif dan selisih stok:

- `ProdukObserver` mencegah master stok produk bernilai negatif.
- `RekamanStokObserver` mencatat peringatan ketika `stok_sisa` negatif, tetapi tidak langsung memaksa menjadi nol agar rantai perhitungan ledger tidak rusak.
- `StockRuntimeIntegrityService` dapat melempar `UnsafeStockMutationException` ketika sinkronisasi stok tidak konsisten.
- `MonitorStockHealth` memeriksa stok negatif, record stok inkonsisten, dan orphaned stock records.
- Script forensic seperti `analyze_persistent_negative_products_ultra_detail.php` menunjukkan adanya kebutuhan analisis mendalam terhadap produk dengan stok negatif persisten.

Implikasinya:

> Sistem lama sudah memiliki kebutuhan nyata untuk mencegah stok negatif dan stok selisih. Pada rancangan baru, kebutuhan ini diformalkan menjadi invariant seperti `available >= 0`, `oversell = 0`, `lost update = 0`, dan `stock balance mismatch = 0`.

### 4E.5 Bukti Baseline dan Reflow Setelah Sistem Berjalan Lama

Pada sistem eksisting terdapat `BaselineStockReflowService` yang menggunakan acuan:

```text
Saldo Awal Stok per 31-12-2025
```

Maknanya, setelah sistem berjalan sejak 2023, pernah diperlukan titik baseline untuk menstabilkan histori stok setelah periode tertentu. Ini sangat relevan dengan konteks penelitian karena sistem sudah digunakan selama beberapa tahun dan mulai membutuhkan penyegaran data serta arsitektur.

Kalimat siap pakai:

> Keberadaan baseline stock reflow menunjukkan bahwa setelah sistem berjalan beberapa tahun, data stok membutuhkan titik acuan dan rekonstruksi ulang agar saldo sistem kembali dapat dipercaya. Hal ini menjadi bukti bahwa penyegaran sistem pada tahun 2026 memiliki dasar operasional yang jelas.

### 4E.6 Bukti Kesesuaian Transaksi, Detail, dan Ledger

Script `audit_and_repair_transaction_stock_integrity.php` memeriksa berbagai anomali seperti:

```text
invalid_header_waktu
header_at_or_before_cutoff
header_in_future
finalized_without_detail
rekaman_distinct_product_mismatch
rekaman_row_count_mismatch
rekaman_qty_mismatch
```

Maknanya, data valid tidak cukup hanya dilihat dari satu tabel. Transaksi final harus memiliki header yang sah, detail yang sesuai, waktu transaksi yang benar, dan rekaman stok yang cocok.

Implikasi pada proposal:

> Definisi data valid dan konsisten dalam penelitian perlu mencakup hubungan antara order, item transaksi, pembayaran, stock movement, dan status akhir. Karena itu penelitian tidak hanya mengukur latency atau throughput, tetapi juga memeriksa invariant lintas tabel dan lintas service.

### 4E.7 Bukti Bahwa Perbaikan Manual Perlu Dikurangi

Pada sistem eksisting terdapat berbagai utilitas `analyze_*`, `check_*`, `debug_*`, `cleanup_*`, serta command dan controller yang berkaitan dengan perbaikan stok. Keberadaan utilitas tersebut membantu pemulihan data pada sistem lama, tetapi juga menunjukkan bahwa proses penjagaan konsistensi masih banyak bergantung pada audit dan perbaikan setelah masalah terjadi.

Kalimat aman untuk proposal:

> Sistem lama telah memiliki berbagai utilitas audit dan perbaikan stok. Utilitas tersebut berguna sebagai respons terhadap masalah data, tetapi penelitian ini berupaya merancang mekanisme yang lebih preventif dan sistematis agar risiko selisih stok, event ganda, dan transaksi menggantung dapat dicegah atau dipulihkan secara terukur.

Catatan yang tidak perlu ditulis mentah-mentah:

- Jangan menampilkan istilah informal dari script lama.
- Jangan menulis bahwa sistem lama "buruk" atau "gagal".
- Jangan menyalahkan implementasi lama, karena sistem lama memang dibangun untuk skala satu cabang.
- Gunakan istilah akademik seperti "legacy system", "riwayat kebutuhan rekonsiliasi", "keterbatasan model stok global", dan "kebutuhan penyegaran arsitektur".

### 4E.8 Implikasi Langsung Ke Rancangan Skripsi

| Masalah Pada Sistem Eksisting | Rancangan Pada Penelitian |
|---|---|
| `produk.stok` hanya satu nilai global | `stock_balances` per produk dan lokasi |
| Kartu stok perlu rekalkulasi | Stock movement ledger dengan invariant dan rekonsiliasi otomatis |
| Stok negatif perlu dimonitor | OCC dan validasi `available >= 0` sebelum commit |
| Master stok dan ledger bisa berbeda | Test oracle dan audit lintas database |
| Transaksi final bisa tidak cocok dengan rekaman stok | Saga state dan correlation_id untuk menautkan order, payment, dan stock movement |
| Duplikasi/inkonsistensi perlu dibersihkan manual | Durable inbox/idempotency untuk mencegah duplicate effect |
| Perbaikan stok dilakukan setelah masalah terjadi | Outbox, retry, DLQ, compensation, dan manual review sebagai mekanisme pemulihan terstruktur |

Paragraf penutup section:

> Dengan bukti teknis tersebut, penelitian ini memiliki dasar yang lebih kuat. Permasalahan yang diuji bukan sekadar kemungkinan teoritis pada arsitektur microservices, tetapi merupakan perluasan dari masalah nyata yang sudah terlihat pada sistem monolith eksisting: menjaga agar master stok, kartu stok, transaksi, dan data pembayaran tetap selaras. Migrasi menuju event-driven microservices membuat tantangan ini semakin besar karena data dipisahkan ke beberapa service. Karena itu, mekanisme Transactional Outbox, OCC, durable idempotency, Saga, compensation, retry, DLQ, dan rekonsiliasi otomatis menjadi bagian penting dari rancangan penelitian.

---

## 4F. Kondisi Operasional Sementara: Clone Aplikasi Lokal, Database Terpisah, dan Rekonsiliasi Manual

Kondisi operasional Apotek Bisma saat ini semakin memperkuat kebutuhan integrasi sistem. Karena pengembangan sistem multi-cabang belum sempat dilakukan secara penuh, Apotek Bisma menjalankan solusi sementara agar operasional tiga cabang tetap berjalan. Solusi tersebut dilakukan dengan menjalankan salinan aplikasi monolith eksisting secara lokal/offline pada masing-masing cabang. Setiap cabang memiliki database sendiri, mencatat transaksi penjualan sendiri, dan mengelola stok lokal secara terpisah.

Di sisi gudang, gudang pusat yang mulai beroperasi sekitar tujuh bulan terakhir masih menggunakan pencatatan berbasis Excel/spreadsheet untuk mencatat stok gudang dan distribusi barang ke cabang. Data gudang tersebut kemudian dicocokkan kembali dengan laporan transaksi dan stok dari masing-masing cabang. Proses konsolidasi ini membutuhkan pegawai khusus yang menangani sinkronisasi data antar cabang dan gudang.

Narasi akademik yang aman:

> Kondisi operasional sementara Apotek Bisma menunjukkan adanya fragmentasi data. Setiap cabang menjalankan salinan aplikasi dan database lokal masing-masing, sedangkan gudang pusat memiliki pencatatan terpisah menggunakan spreadsheet. Sinkronisasi data stok dan transaksi dilakukan secara periodik melalui laporan manual. Kondisi ini membuat kebenaran data akhir sangat bergantung pada proses rekonsiliasi manusia, sehingga meningkatkan risiko keterlambatan sinkronisasi, duplikasi pencatatan, perbedaan format laporan, dan selisih stok yang terlambat diketahui.

### 4F.1 Skema Operasional Sementara

```text
Cabang 1
- Clone aplikasi monolith lokal
- Database lokal Cabang 1
- Transaksi penjualan Cabang 1
- Stok lokal Cabang 1
- Laporan stok dan transaksi akhir periode

Cabang 2
- Clone aplikasi monolith lokal
- Database lokal Cabang 2
- Transaksi penjualan Cabang 2
- Stok lokal Cabang 2
- Laporan stok dan transaksi akhir periode

Cabang 3
- Clone aplikasi monolith lokal
- Database lokal Cabang 3
- Transaksi penjualan Cabang 3
- Stok lokal Cabang 3
- Laporan stok dan transaksi akhir periode

Gudang Pusat
- Excel/spreadsheet
- Stok gudang
- Distribusi barang ke cabang
- Laporan mutasi gudang akhir periode

Petugas Sinkronisasi
- Mengumpulkan laporan cabang
- Mengumpulkan laporan gudang
- Mencocokkan stok, transaksi, dan distribusi
- Menandai selisih untuk dicek ulang
- Menyusun laporan konsolidasi operasional
```

Diagram ringkas:

```text
                Gudang Pusat
            Excel / Spreadsheet
          stok gudang + distribusi
                    |
                    | laporan distribusi
                    v
Cabang 1 ------> Petugas Sinkronisasi <------ Cabang 2
DB Lokal           Rekonsiliasi Manual        DB Lokal
Transaksi          Stok + Transaksi           Transaksi
Stok Lokal         Laporan Bulanan            Stok Lokal
                    ^
                    |
                 Cabang 3
                 DB Lokal
                 Transaksi
                 Stok Lokal
```

### 4F.2 SOP Sinkronisasi Manual Saat Ini

1. Setiap cabang menjalankan aplikasi lokal secara mandiri.
2. Transaksi penjualan dicatat pada database lokal masing-masing cabang.
3. Stok cabang berubah berdasarkan transaksi lokal.
4. Gudang mencatat stok dan distribusi barang menggunakan Excel/spreadsheet.
5. Pada akhir bulan atau akhir periode operasional, setiap cabang mengirimkan laporan transaksi dan stok.
6. Gudang mengirimkan laporan distribusi barang ke cabang.
7. Pegawai khusus menggabungkan laporan dari cabang dan gudang.
8. Data stok cabang dicocokkan dengan transaksi penjualan dan distribusi gudang.
9. Jika ada selisih, dilakukan pengecekan ulang ke cabang atau gudang.
10. Hasil akhir menjadi laporan konsolidasi operasional.

Catatan penting:

> SOP manual ini membantu bisnis tetap berjalan ketika sistem terintegrasi belum tersedia. Namun, SOP ini bukan solusi jangka panjang yang ideal karena data antar cabang dan gudang tidak tersinkronisasi secara real-time dan masih bergantung pada ketelitian manusia.

### 4F.3 Titik Rawan Kondisi Operasional Sementara

| Titik Rawan | Dampak Operasional | Dampak Terhadap Penelitian |
|---|---|---|
| Database berbeda per cabang | Tidak ada satu sumber kebenaran real-time | Memperkuat kebutuhan konsolidasi data dan ownership domain |
| Clone aplikasi lokal | Perubahan data dan fitur sulit diseragamkan | Menunjukkan risiko fragmentasi sistem yang tidak terkelola |
| Gudang memakai Excel/spreadsheet | Distribusi barang tidak otomatis mengubah stok cabang | Memperkuat kebutuhan `stock_transfers` dan `stock_movements` |
| Sinkronisasi akhir bulan | Selisih baru diketahui terlambat | Memperkuat kebutuhan read model dan rekonsiliasi lebih cepat |
| Rekonsiliasi manual | Bergantung pada ketelitian pegawai | Memperkuat kebutuhan test oracle dan audit otomatis |
| Format laporan bisa berbeda | Risiko salah input atau salah interpretasi | Memperkuat kebutuhan kontrak data yang eksplisit |
| Transfer gudang-cabang dicatat terpisah | Barang keluar gudang belum tentu langsung tercatat masuk cabang | Memperkuat kebutuhan status transfer dan Saga |
| Tidak ada correlation ID lintas proses | Sulit melacak satu alur barang dari gudang sampai cabang | Memperkuat kebutuhan `correlation_id` |
| Tidak ada invariant otomatis lintas lokasi | Stok minus atau mismatch bisa terlambat diketahui | Memperkuat kebutuhan invariant dan fault injection |

### 4F.4 Perbandingan Kondisi Sementara Dengan Rancangan Penelitian

| Aspek | Kondisi Operasional Sementara | Rancangan Penelitian |
|---|---|---|
| Sistem cabang | Clone aplikasi lokal per cabang | Satu rancangan sistem terintegrasi multi-cabang |
| Database | Database berbeda per cabang | Database per service berdasarkan domain |
| Gudang | Excel/spreadsheet | Inventory Service dengan lokasi gudang |
| Sinkronisasi | Laporan manual akhir bulan | Event-driven dan rekonsiliasi otomatis |
| Stok | Stok lokal tidak memiliki sumber kebenaran bersama | `stock_balances` per produk dan lokasi |
| Transfer barang | Dicatat manual di gudang | `stock_transfers` dan `stock_movements` |
| Audit | Pengecekan manual oleh pegawai khusus | Correlation ID, ledger, test oracle, dan script rekonsiliasi |
| Waktu deteksi selisih | Umumnya akhir periode/bulan | Setelah recovery window dan audit otomatis |
| Risiko utama | Selisih terlambat diketahui | Invariant diperiksa otomatis |
| Pengguna | Bergantung pada pegawai sinkronisasi | Dashboard, alert, dan status operasional |

### 4F.5 Hubungan Dengan Arsitektur Microservices

Kondisi operasional sementara sebenarnya sudah menunjukkan bentuk pemisahan data, tetapi pemisahan tersebut terjadi secara tidak terkontrol secara arsitektural.

```text
Kondisi saat ini:
- Cabang 1 memiliki aplikasi dan database sendiri.
- Cabang 2 memiliki aplikasi dan database sendiri.
- Cabang 3 memiliki aplikasi dan database sendiri.
- Gudang memiliki pencatatan spreadsheet sendiri.
- Sinkronisasi dilakukan manual oleh pegawai.
```

Penelitian ini tidak sekadar memecah sistem menjadi banyak service. Penelitian ini mengevaluasi transisi dari fragmentasi data manual menuju pemisahan domain yang terstruktur.

```text
Rancangan penelitian:
- Sales Service mengelola order dan transaksi penjualan.
- Inventory Service mengelola produk, lokasi, saldo stok, kartu stok, dan transfer.
- Payment Service mengelola pembayaran dan webhook.
- Reporting Service mengelola read model dan laporan konsolidasi.
- Message broker menghubungkan event antar service.
- Outbox-Inbox menjaga event tidak hilang dan tidak berdampak ganda.
- OCC menjaga stok tidak mengalami lost update.
- Saga mengatur alur lintas layanan sampai terminal state.
```

Kalimat kunci:

> Kondisi saat ini dapat dipandang sebagai fragmentasi data manual yang belum terkelola secara arsitektural. Setiap cabang memiliki salinan aplikasi dan database sendiri, sedangkan gudang memiliki pencatatan terpisah melalui spreadsheet. Penelitian ini mengevaluasi transisi dari fragmentasi manual tersebut menuju pemisahan layanan yang lebih terstruktur melalui event-driven microservices.

### 4F.6 Hubungan Dengan Kondisi A, B, dan C

Kondisi operasional sementara tidak dijadikan kondisi eksperimen utama karena melibatkan banyak proses manual, format laporan, kebiasaan pegawai, dan faktor non-teknis yang sulit dikontrol secara kuantitatif. Namun kondisi tersebut menjadi bukti kebutuhan bisnis dan dasar perancangan sistem target.

| Konteks | Fungsi Dalam Penelitian |
|---|---|
| Kondisi operasional sementara | Bukti kebutuhan integrasi, bukan kondisi eksperimen utama |
| Kondisi A monolith baseline | Mewakili karakter sistem eksisting secara teknis dalam bentuk terkontrol |
| Kondisi B naive event-driven microservices | Menunjukkan risiko pemisahan layanan tanpa proteksi konsistensi |
| Kondisi C robust event-driven microservices | Mengevaluasi mekanisme konsistensi yang diperlukan untuk sistem target |

Kalimat siap pakai:

> Kondisi clone aplikasi lokal per cabang dan pencatatan gudang menggunakan spreadsheet tidak dijadikan kondisi eksperimen utama karena terlalu banyak dipengaruhi proses manual dan sulit dikontrol. Namun kondisi tersebut digunakan sebagai bukti operasional bahwa Apotek Bisma memang membutuhkan integrasi sistem multi-cabang. Eksperimen tetap dilakukan pada tiga kondisi terkontrol, yaitu monolith baseline, naive event-driven microservices, dan robust event-driven microservices.

### 4F.7 Implikasi Operasional Terhadap Kebutuhan Sistem Target

| Kebutuhan Operasional Saat Ini | Kebutuhan Sistem Target |
|---|---|
| Cabang perlu mencatat transaksi masing-masing | Order harus memiliki `location_id` atau `branch_id` |
| Cabang perlu mengetahui stok lokal | Inventory harus memiliki `stock_balances` per lokasi |
| Gudang perlu mencatat distribusi barang | Inventory harus memiliki `stock_transfers` |
| Laporan cabang harus digabung | Reporting harus membuat read model konsolidasi |
| Selisih harus ditelusuri | Stock movement harus memiliki referensi dan `correlation_id` |
| Pegawai sinkronisasi perlu data yang jelas | Sistem harus menyediakan status, alert, dan laporan rekonsiliasi |
| Sinkronisasi akhir bulan terlalu lambat | Sistem perlu konvergensi data dalam recovery window yang ditentukan |

### 4F.8 Batas Klaim Dari Kondisi Operasional Sementara

Kondisi operasional sementara ini memperkuat landasan penelitian, tetapi tetap perlu dibatasi agar proposal tidak melebar.

Yang diklaim:

- Ada fragmentasi data nyata pada operasional Apotek Bisma saat ini.
- Ada database cabang yang berdiri sendiri.
- Ada pencatatan gudang yang masih terpisah melalui spreadsheet.
- Ada proses sinkronisasi manual akhir periode.
- Ada kebutuhan integrasi data stok, transaksi, dan distribusi gudang.

Yang tidak diklaim:

- Tidak mengklaim seluruh proses manual saat ini gagal total.
- Tidak menjadikan kualitas kerja pegawai sebagai objek penelitian.
- Tidak menguji proses Excel gudang sebagai kondisi eksperimen utama.
- Tidak mengklaim microservices otomatis menghilangkan seluruh selisih fisik.
- Tidak menggantikan stock opname fisik dan pemeriksaan administratif resmi.

Paragraf penutup section:

> Dengan adanya kondisi operasional sementara tersebut, kebutuhan penelitian menjadi lebih kuat. Apotek Bisma saat ini tidak hanya memiliki masalah pengembangan fitur, tetapi juga masalah integrasi data antar cabang dan gudang. Penelitian ini memosisikan event-driven microservices sebagai rancangan evaluatif untuk mengubah fragmentasi data manual menjadi pemisahan domain yang lebih terstruktur, terukur, dan dapat direkonsiliasi.

---

## 5. Model Data Stok Per Lokasi

Model lama yang hanya menyimpan stok pada produk tidak cukup untuk sistem multi-cabang. Model baru yang diusulkan adalah saldo stok per produk dan per lokasi.

```text
products
- id
- sku
- name
- unit
- category_id
- status

locations
- id
- code
- name
- type: WAREHOUSE | BRANCH
- address
- status

stock_balances
- product_id
- location_id
- on_hand
- reserved
- version
- updated_at

stock_movements
- id
- product_id
- location_id
- movement_type
- quantity_in
- quantity_out
- balance_after
- reference_type
- reference_id
- correlation_id
- actor_id
- occurred_at

stock_transfers
- id
- transfer_number
- source_location_id
- destination_location_id
- status
- requested_by
- approved_by
- shipped_by
- received_by
- requested_at
- approved_at
- shipped_at
- received_at

stock_transfer_items
- transfer_id
- product_id
- requested_quantity
- shipped_quantity
- received_quantity
```

Definisi saldo:

```text
available = on_hand - reserved
```

Invariant dasar:

```text
on_hand >= 0
reserved >= 0
available >= 0
```

Makna kolom:

| Kolom | Makna Operasional |
|---|---|
| `on_hand` | Jumlah stok yang tercatat tersedia secara fisik/sistem pada lokasi tertentu |
| `reserved` | Jumlah stok yang sedang dicadangkan untuk order yang belum final |
| `available` | Jumlah stok yang boleh dijual atau dialokasikan |
| `version` | Nomor versi untuk mencegah lost update melalui OCC |
| `stock_movements` | Kartu stok digital yang mencatat riwayat keluar-masuk barang |
| `correlation_id` | Identitas pelacakan lintas service untuk audit dan rekonsiliasi |

Catatan penting untuk proposal:

> Dalam penelitian ini, `stock_balances` menjadi sumber kebenaran saldo stok saat ini, sedangkan `stock_movements` menjadi kartu stok digital yang menjelaskan alasan perubahan saldo. Setiap perubahan stok harus memiliki referensi bisnis seperti penjualan, retur, restock, transfer, pembatalan, atau penyesuaian stock opname.

---

## 6. Keterkaitan Dengan Stock Opname, Kartu Stok, dan Pemeriksaan Dinkes

Bagian ini penting agar penelitian terlihat relevan dengan realita apotek, bukan hanya eksperimen teknis.

> Dalam operasional apotek, data stok tidak hanya dipakai untuk mengetahui jumlah barang yang tersedia, tetapi juga untuk menelusuri riwayat keluar-masuk obat. Riwayat ini lazim direpresentasikan sebagai kartu stok. Kartu stok memuat perubahan saldo akibat transaksi penjualan, restock, transfer antar lokasi, retur, koreksi, atau hasil stock opname. Karena Apotek Bisma merupakan apotek retail yang dapat mengalami pemeriksaan berkala oleh pihak terkait seperti Dinas Kesehatan, sistem perlu menghasilkan data yang dapat ditelusuri, konsisten, dan dapat dipertanggungjawabkan.

> Penelitian ini tidak mengklaim menggantikan seluruh kewajiban administratif dan regulasi apotek. Namun, penelitian ini memperkuat fondasi data operasional agar saldo stok dan transaksi memiliki jejak digital yang jelas. Kecocokan terhadap stok fisik tetap memerlukan proses stock opname oleh petugas. Eksperimen perangkat lunak hanya membuktikan bahwa saldo sistem sesuai dengan test oracle berdasarkan seluruh mutasi sah yang terjadi selama pengujian. Dengan demikian, hasil penelitian mendukung proses audit operasional, tetapi tidak menggantikan validasi fisik di lapangan.

Perbedaan antara bukti eksperimen dan validasi lapangan:

| Aspek | Dibuktikan Dalam Eksperimen | Dibuktikan Dalam Operasional Nyata |
|---|---|---|
| Saldo sistem | Ya, melalui test oracle dan rekonsiliasi database | Ya, melalui laporan sistem |
| Riwayat mutasi/kartu stok | Ya, melalui stock_movements | Ya, melalui kartu stok digital |
| Stok fisik | Tidak langsung | Ya, melalui stock opname |
| Kecocokan sistem-fisik | Tidak murni dari eksperimen | Ya, melalui stock opname berkala |
| Audit Dinkes | Didukung oleh jejak data | Diperiksa berdasarkan dokumen dan kondisi nyata |

Kalimat batas klaim:

> Pengujian dalam penelitian ini membuktikan kecocokan saldo sistem terhadap test oracle dan seluruh mutasi sah, bukan membuktikan kecocokan langsung dengan stok fisik. Kecocokan dengan stok fisik tetap memerlukan stock opname lapangan. Namun, rancangan kartu stok digital dan stock movement ledger dalam sistem dapat menjadi dasar yang lebih kuat untuk mendukung pemeriksaan operasional berkala.

---

## 7. Batas Domain Yang Direvisi

Draft saat ini sudah memiliki Sales, Inventory, Payment, dan Reporting. Perbaikan yang diperlukan adalah memperjelas bahwa Inventory mengelola lokasi, gudang, cabang, saldo stok, kartu stok, dan transfer.

| Service | Tanggung Jawab | Data Otoritatif |
|---|---|---|
| Sales | Order, item penjualan, status order, channel penjualan, status Saga | Order, order_items, sales_state, saga_state |
| Inventory | Produk, lokasi, saldo stok per lokasi, reserved stock, kartu stok, mutasi, transfer gudang-cabang, version OCC | products, locations, stock_balances, stock_movements, stock_transfers |
| Payment | Payment attempt, status pembayaran, webhook Midtrans, QRIS manual, idempotency key | payments, payment_attempts, webhook_logs, payment_idempotency_keys |
| Reporting/Notification | Read model dashboard, laporan operasional, notifikasi status, alert DLQ/manual review | reporting_read_models, notification_logs |

Catatan defensif:

> Reporting bukan sumber kebenaran stok. Jika dashboard berbeda sementara dengan Inventory karena proses asinkron, kondisi tersebut masih dapat diterima selama read model kembali konvergen dalam SLA yang ditentukan.

---

## 8. Use Case Yang Direvisi Agar Lebih Terkait Multi-Cabang

Draft awal memiliki empat use case. Agar tetap tidak melebar, tetap dapat memakai empat use case, tetapi UC-2 diperluas menjadi restock dan transfer gudang-cabang.

### 8.1 UC-1: POS dan Kiosk Menjual Produk Yang Sama Pada Cabang Yang Sama

Konteks:

```text
Cabang: Cabang 1
Produk: Obat A
on_hand: 3
reserved: 0
available: 3
```

Skenario:

- Kasir Cabang 1 menjual 2 unit Obat A.
- Kiosk Cabang 1 pada waktu hampir bersamaan menjual 2 unit Obat A.
- Keduanya membaca saldo awal yang sama.
- Inventory menggunakan OCC pada `stock_balances(product_id, location_id)`.
- Hanya satu transaksi boleh berhasil.
- Transaksi lain ditolak atau diarahkan ke status gagal karena stok tidak cukup setelah retry.

Target:

```text
Tidak ada oversell pada Cabang 1.
available tidak boleh negatif.
Order yang kalah tidak boleh berubah menjadi COMPLETED.
Kartu stok hanya mencatat pemotongan sah.
```

Jawaban akademik:

> UC-1 membuktikan bahwa konflik tidak diuji pada stok global, tetapi pada saldo stok lokal cabang. Hal ini sesuai dengan kebutuhan Apotek Bisma yang memiliki beberapa lokasi stok.

### 8.2 UC-2: Restock Supplier Ke Gudang dan Transfer Gudang Ke Cabang

Konteks:

```text
Gudang Pusat menerima barang dari supplier.
Gudang kemudian mendistribusikan sebagian barang ke Cabang 1.
Pada saat yang berdekatan, Cabang 1 tetap melayani penjualan.
```

Contoh test oracle:

```text
Gudang awal: 100
Cabang 1 awal: 20
Restock supplier ke gudang: +50
Transfer gudang ke Cabang 1: 30
Penjualan Cabang 1: 15

Gudang akhir yang diharapkan: 100 + 50 - 30 = 120
Cabang 1 akhir yang diharapkan: 20 + 30 - 15 = 35
Total perusahaan: 120 + 35 = 155
```

Skenario:

- Petugas gudang mencatat restock dari supplier.
- Sistem mencatat stock movement masuk ke gudang.
- Gudang membuat transfer ke Cabang 1.
- Sistem melakukan reserve atau alokasi stok gudang.
- Barang dikirim dan diterima oleh Cabang 1.
- Stok gudang berkurang dan stok Cabang 1 bertambah sesuai status transfer.
- Jika event transfer dikirim ulang, penambahan stok cabang tidak boleh terjadi dua kali.

Status transfer:

```text
REQUESTED
APPROVED
RESERVED
IN_TRANSIT
RECEIVED
COMPLETED
CANCELLED
REQUIRES_RECONCILIATION
```

Target:

```text
Tidak ada restock ganda akibat duplicate event.
Tidak ada transfer ganda akibat retry.
Stok gudang dan cabang sesuai test oracle.
Kartu stok gudang dan cabang sama-sama memiliki mutasi yang dapat ditelusuri.
```

### 8.3 UC-3: Pembayaran Midtrans

Konteks:

```text
Order dibuat dari cabang tertentu.
Stok cabang di-reserve.
Payment menunggu webhook Midtrans.
Webhook dapat valid, duplikat, terlambat, atau tidak valid.
```

Aturan:

- Webhook harus divalidasi signature, order id, nominal, currency, dan status transaksi.
- Webhook duplikat tidak boleh mencatat pembayaran ganda.
- Jika pembayaran sukses sebelum timeout, order menjadi COMPLETED dan stok reserved dikomit.
- Jika pembayaran gagal atau expired, reserved stock dilepas.
- Jika pembayaran valid datang setelah order expired, order tidak otomatis dihidupkan kembali.
- Pembayaran valid setelah expired dicatat sebagai fakta finansial dan diarahkan ke rekonsiliasi.

Status tambahan:

```text
PAID_AFTER_EXPIRY
REQUIRES_RECONCILIATION
REFUND_PENDING
REFUNDED
```

Jawaban defensif:

> Pembayaran valid yang terlambat tidak boleh diabaikan karena menyangkut hak pelanggan dan catatan keuangan. Namun order yang sudah expired juga tidak boleh otomatis aktif kembali karena stok mungkin sudah dilepas atau dijual ke pelanggan lain. Karena itu pembayaran tersebut dicatat dan diarahkan ke proses rekonsiliasi atau refund.

### 8.4 UC-4: QRIS Statis Dengan Konfirmasi Admin

Konteks:

```text
Pemilik atau admin apotek dapat menerima pembayaran QRIS statis.
Karena QRIS statis membutuhkan verifikasi manual, sistem harus menunggu konfirmasi admin.
```

Aturan:

- Order dibuat dengan status WAITING_ADMIN_CONFIRMATION.
- Stok cabang di-reserve selama batas waktu tertentu.
- Admin menyetujui hanya jika bukti pembayaran valid.
- Jika admin menyetujui sebelum timeout, order menjadi COMPLETED.
- Jika admin menolak atau timeout, reserved stock dilepas.
- Jika admin menyetujui setelah timeout, aksi ditolak dan diarahkan ke audit manual.

Target:

```text
Reserved stock tidak menggantung.
Admin tidak dapat memfinalisasi order yang sudah terminal tanpa proses audit.
Setiap keputusan admin tercatat dengan actor_id dan timestamp.
```

---

## 9. Definisi Data Valid dan Konsisten Yang Direvisi

### 9.1 Definisi Data Valid

Data dinyatakan valid jika memenuhi aturan bisnis dan administratif yang berlaku pada konteks Apotek Bisma.

Contoh data valid:

- Produk memiliki identitas jelas seperti SKU, nama, satuan, dan status aktif.
- Setiap stok terikat pada `product_id` dan `location_id`.
- Setiap order memiliki cabang asal.
- Setiap pembayaran memiliki nominal, metode, status, dan referensi order.
- Setiap transfer memiliki lokasi asal, lokasi tujuan, item, kuantitas, status, dan penanggung jawab.
- Setiap perubahan stok memiliki catatan `stock_movements`.
- Setiap event memiliki `event_id` dan `correlation_id`.
- Setiap aksi penting memiliki `actor_id` atau identitas sistem yang menjalankan aksi.

### 9.2 Definisi Data Konsisten

Data dinyatakan konsisten jika setelah seluruh proses utama, retry, compensation, dan recovery window selesai, seluruh service mencapai fakta akhir yang sesuai dengan test oracle.

Contoh konsistensi:

```text
Order COMPLETED harus memiliki pembayaran valid dan stok yang sudah dikomit.
Order CANCELLED/EXPIRED harus melepaskan reserved stock.
Transfer COMPLETED harus mengurangi stok gudang dan menambah stok cabang tepat satu kali.
Payment PAID tidak boleh berdampak lebih dari satu kali walaupun webhook dikirim ulang.
Reporting boleh tertinggal sementara, tetapi harus kembali sesuai dalam SLA.
```

---

## 10. Tabel Validitas dan Konsistensi Use Case

| Use Case | Data Valid Jika | Data Konsisten Jika | Parameter Lulus |
|---|---|---|---|
| UC-1 Sales race cabang | Order memiliki cabang asal, produk aktif, jumlah > 0, dan stok cabang tersedia berdasarkan `available` | Hanya transaksi yang didukung stok cabang yang sah yang dapat selesai | Oversell = 0, lost update = 0, available cabang tidak negatif |
| UC-2 Restock dan transfer | Restock memiliki supplier/referensi, transfer memiliki gudang asal, cabang tujuan, item, jumlah, dan status | Stok gudang dan cabang sesuai test oracle, mutasi tercatat di kartu stok masing-masing lokasi | Duplicate stock movement = 0, mismatch saldo lokasi = 0 |
| UC-3 Midtrans | Signature, order id, nominal, currency, dan status webhook valid | Pembayaran berdampak satu kali, order dan stok mencapai status akhir sah | Duplicate payment effect = 0, reserved stock menggantung = 0 |
| UC-4 QRIS admin | Bukti bayar, nominal, admin, waktu konfirmasi, dan status order valid | Approval/penolakan/timeout hanya berdampak satu kali dan tercatat | Unauthorized finalization = 0, terminal-state coverage = 100% |

---

## 11. Invariant Yang Direvisi

Invariant stok per lokasi:

```text
stock_balances.on_hand >= 0
stock_balances.reserved >= 0
stock_balances.on_hand - stock_balances.reserved >= 0
```

Invariant sales:

```text
Setiap order COMPLETED harus memiliki payment valid atau metode pembayaran sah.
Setiap order COMPLETED harus memiliki stock movement penjualan yang sah.
Setiap order CANCELLED/EXPIRED tidak boleh memiliki pemotongan stok final.
```

Invariant payment:

```text
Satu order hanya boleh memiliki satu pembayaran final yang sah.
Webhook dengan idempotency key yang sama hanya boleh berdampak satu kali.
Webhook valid setelah expiry harus masuk rekonsiliasi, bukan menghidupkan order otomatis.
```

Invariant transfer:

```text
Transfer COMPLETED harus memiliki mutasi keluar di gudang dan mutasi masuk di cabang.
Transfer CANCELLED sebelum pengiriman harus melepaskan reserved stock gudang.
Transfer IN_TRANSIT tidak boleh langsung dihitung sebagai stok available di cabang sebelum diterima.
Transfer dengan perbedaan jumlah kirim dan terima harus masuk REQUIRES_RECONCILIATION.
```

Invariant kartu stok:

```text
Setiap perubahan stock_balances harus memiliki stock_movements.
balance_after pada stock_movements harus sesuai dengan saldo akhir lokasi terkait.
Tidak boleh ada stock movement tanpa reference_id atau correlation_id untuk proses otomatis.
```

Invariant pelaporan:

```text
Reporting read model boleh berbeda sementara.
Perbedaan harus hilang dalam SLA read model lag.
Reporting tidak boleh menjadi sumber kebenaran stok.
```

---

## 12. Cara Membuktikan Melalui Rekonsiliasi

Program audit mandiri perlu memeriksa data berikut:

```text
Sales database
- orders
- order_items
- saga_states

Inventory database
- stock_balances
- stock_movements
- stock_transfers
- stock_transfer_items

Payment database
- payments
- payment_attempts
- webhook_logs
- idempotency_keys

Messaging tables
- outbox_messages
- inbox_messages
- retry_logs
- dlq_messages

Reporting database
- stock_dashboard_read_model
- sales_summary_read_model
```

Rumus rekonsiliasi per lokasi:

```text
expected_on_hand(location, product)
= initial_on_hand
+ restock_in
+ transfer_in_completed
+ sales_compensation
- sales_completed
- transfer_out_completed
- adjustment_out
+ adjustment_in
```

Rumus available:

```text
expected_available = expected_on_hand - expected_reserved
```

Pelanggaran dicatat jika:

```text
actual_on_hand != expected_on_hand
actual_reserved != expected_reserved
available < 0
order terminal tidak sesuai payment dan stock movement
event committed tidak pernah diproses atau tidak masuk DLQ/manual review
pesan duplikat menyebabkan efek bisnis ganda
```

---

## 13. Matriks Fault Yang Lebih Adil

Tidak semua fault dapat diterapkan pada monolith. Karena itu proposal perlu membedakan perbandingan A-B-C dan B-C.

| Fault | Berlaku Untuk A | Berlaku Untuk B | Berlaku Untuk C | Catatan |
|---|---:|---:|---:|---|
| F1 crash setelah data bisnis tersimpan sebelum event dipublish | Tidak langsung | Ya | Ya | Relevan untuk dual-write/event-driven |
| F2 duplicate event | Tidak | Ya | Ya | Relevan untuk broker dan consumer idempotency |
| F3 consumer crash sebelum ACK | Tidak | Ya | Ya | Relevan untuk RabbitMQ consumer |
| F4 webhook terlambat | Ya | Ya | Ya | Monolith dan microservices sama-sama bisa menerima webhook terlambat |
| F5 concurrent stock update | Ya | Ya | Ya | Relevan untuk semua kondisi |
| F6 transfer gudang-cabang partial failure | Tidak langsung | Ya | Ya | Relevan untuk Saga lintas layanan |
| F7 reporting lag | Tidak langsung | Ya | Ya | Relevan untuk read model asinkron |

Aturan analisis:

| Perbandingan | Digunakan Untuk |
|---|---|
| A-B-C | Latency, throughput, error rate, concurrent stock update, hasil bisnis akhir yang dapat dimodelkan di semua kondisi |
| B-C | Duplicate event, broker delay, consumer crash, Outbox, Inbox, DLQ, Saga partial failure |
| C acceptance test | Oversell, lost update, duplicate effect, terminal-state coverage, committed event processing |

Kalimat proposal:

> Kondisi A tetap digunakan sebagai baseline operasional karena mewakili sistem monolith yang benar-benar ada. Namun fault yang secara arsitektural hanya muncul pada event-driven microservices tidak dipaksakan ke Kondisi A. Untuk fault seperti duplicate event, consumer crash, broker delay, dan partial failure lintas layanan, pembandingan utama dilakukan antara Kondisi B dan Kondisi C. Dengan cara ini, eksperimen tetap adil karena setiap kondisi hanya diuji pada gangguan yang relevan dengan arsitekturnya.

---

## 14. Workload Awal dan Pilot Test

Angka berikut sebaiknya disebut sebagai konfigurasi awal yang akan divalidasi melalui pilot test, bukan angka final.

| Level | Virtual User | Target RPS Awal | Durasi Warm-up | Durasi Measurement | Tujuan |
|---|---:|---:|---:|---:|---|
| Low | 10 VU | 5-10 RPS | 1 menit | 5 menit | Beban operasional ringan |
| Medium | 30 VU | 20-30 RPS | 2 menit | 10 menit | Mendekati jam sibuk cabang |
| High | 60 VU | 50-70 RPS | 3 menit | 10 menit | Mendekati saturasi terkendali |

Komposisi request awal:

| Aktivitas | Proporsi |
|---|---:|
| Lihat produk dan stok cabang | 35% |
| Checkout POS/kiosk | 30% |
| Payment status/webhook simulation | 15% |
| Restock dan transfer gudang-cabang | 10% |
| Dashboard/reporting/status check | 10% |

Kalimat proposal:

> Nilai workload awal digunakan sebagai titik awal pilot test. Setelah pilot test selesai, nilai VU, RPS, durasi warm-up, durasi measurement, timeout, dan recovery window dikunci sebelum eksperimen utama. High load tidak dimaksudkan untuk membuat seluruh sistem lumpuh, tetapi untuk mendekati titik saturasi terkendali agar perbedaan perilaku antar kondisi dapat diamati.

---

## 15. SLA dan Recovery Window

SLA harus dibedakan antara batas operasional dan batas teknis.

| Parameter | Nilai Awal | Sumber Penentuan | Catatan |
|---|---:|---|---|
| Read model lag dashboard stok | <= 2 detik | Wawancara pengguna + pilot test | Agar admin tidak terlalu lama melihat data usang |
| Order mencapai terminal state | <= 30 detik | Kebutuhan checkout + pilot test | Untuk order sukses, batal, expired, atau manual review |
| Retry interval event | 1-5 detik | Pilot test | Disesuaikan dengan stabilitas broker/service |
| DLQ alert muncul | <= 1 menit | Kebutuhan admin | Agar pesan bermasalah tidak diam tanpa penanganan |
| QRIS/admin confirmation timeout | 10-15 menit | Kebijakan bisnis | Bisa disesuaikan dengan kebiasaan operasional apotek |
| Payment gateway reconciliation | Manual review maksimal harian | Kebijakan operasional | Untuk kasus paid after expiry atau selisih pembayaran |

Kalimat proposal:

> Recovery window tidak hanya ditentukan berdasarkan kemampuan teknis sistem, tetapi juga mempertimbangkan kebutuhan operasional Apotek Bisma. Batas awal ditentukan melalui wawancara dengan pengguna dan divalidasi melalui pilot test. Setelah itu, seluruh parameter dikunci sebelum eksperimen utama agar standar keberhasilan tidak berubah mengikuti hasil pengujian.

---

## 16. Perbaikan Metrik dan Aturan Keputusan

Tabel metrik yang direvisi:

| Tujuan | Metrik | Definisi Revisi | Syarat Kondisi C |
|---|---|---|---|
| Kebenaran stok | Oversell count | Jumlah transaksi yang dikomit melebihi available stock sah pada lokasi terkait | 0 |
| Kebenaran saldo | Stock balance mismatch | Selisih saldo sistem terhadap test oracle dan seluruh mutasi sah | 0 |
| Kartu stok | Movement completeness | Setiap perubahan saldo memiliki stock movement yang dapat ditelusuri | 100% |
| Efek tunggal | Duplicate effect | Event/webhook duplikat menyebabkan aksi bisnis ganda | 0 |
| Pemulihan | Terminal-state coverage | Semua transaksi mencapai COMPLETED, CANCELLED, EXPIRED, FAILED, REFUNDED, atau MANUAL_REVIEW | 100% |
| Event reliability | Committed event processing | Event yang sudah tercatat akhirnya dipublish, diproses, atau masuk DLQ/manual review dengan alert | 100% |
| Konvergensi | Read model lag | Waktu hingga dashboard/reporting sesuai sumber otoritatif | Sesuai SLA |
| Biaya | Latency, throughput, CPU/RAM | Dampak performa dari mekanisme konsistensi | Dilaporkan sebagai trade-off |

Perbaikan definisi oversell:

```text
Oversell terjadi jika total kuantitas yang dikomit melebihi stok tersedia yang sah pada lokasi terkait.
```

Atau secara invariant:

```text
available = on_hand - reserved < 0
```

Perbaikan klaim kegagalan:

> Jika ditemukan satu pelanggaran seperti oversell, duplicate payment effect, lost update, atau Saga yang menggantung tanpa status terminal, maka Kondisi C dinyatakan tidak memenuhi kriteria penerimaan pada metrik keselamatan terkait dalam konfigurasi pengujian tersebut. Pernyataan ini tidak digeneralisasi sebagai kegagalan universal pola arsitektur, tetapi menjadi bukti bahwa implementasi atau konfigurasi pada skenario tersebut belum memenuhi invariant yang ditetapkan.

---

## 17. DLQ dan Manual Review

DLQ perlu diposisikan dengan benar.

> DLQ bukan tanda bahwa transaksi sudah pulih. DLQ hanya menunjukkan bahwa pesan tidak hilang dan diamankan setelah gagal diproses melewati batas retry. Transaksi yang pesannya masuk DLQ tetap harus diselesaikan melalui replay otomatis, replay manual, compensation, atau penandaan `MANUAL_REVIEW`.

Aturan DLQ:

- Pesan DLQ harus memiliki `correlation_id`.
- Pesan DLQ harus memiliki alasan kegagalan.
- Pesan DLQ harus menghasilkan alert ke admin atau operator.
- Pesan DLQ harus dapat direplay.
- Jika tidak bisa diselesaikan otomatis, transaksi terkait masuk `MANUAL_REVIEW`.
- `MANUAL_REVIEW` dihitung sebagai terminal state terkendali, bukan status menggantung.

Status terminal yang diakui:

```text
COMPLETED
CANCELLED
EXPIRED
FAILED
REFUNDED
MANUAL_REVIEW
```

---

## 18. Ruang Lingkup Apotek Yang Dibatasi

Karena domain apotek bisa sangat luas, batasan harus eksplisit.

Yang menjadi fokus:

- Stok kuantitatif per produk dan lokasi.
- Penjualan POS/kiosk.
- Reservasi stok.
- Restock supplier ke gudang.
- Transfer gudang ke cabang.
- Pembayaran Midtrans dan QRIS statis.
- Kartu stok digital berbasis stock movement.
- Rekonsiliasi saldo sistem terhadap test oracle.

Yang tidak menjadi fokus utama:

- Batch atau lot obat.
- Tanggal kedaluwarsa.
- FEFO.
- Resep dokter.
- Obat keras dan otorisasi apoteker.
- Retur supplier lanjutan.
- Recall obat.
- Kepatuhan regulasi farmasi secara menyeluruh.

Kalimat batasan:

> Penelitian ini tidak membahas seluruh kompleksitas regulasi dan proses farmasi. Fokus penelitian dibatasi pada konsistensi data stok kuantitatif, transaksi penjualan, pembayaran, reservasi, restock, transfer gudang-cabang, dan kartu stok digital. Aspek batch, tanggal kedaluwarsa, FEFO, resep, obat keras, dan otorisasi apoteker diposisikan sebagai pengembangan lanjutan.

---

## 19. Jawaban Antisipasi Pertanyaan Dosen

### 19.1 Mengapa microservices, padahal monolith masih bisa?

> Untuk kondisi satu cabang, monolith masih cukup dan lebih sederhana. Penelitian ini tidak mengklaim microservices selalu lebih baik. Sistem Apotek Bisma juga sudah membuktikan bahwa monolith dapat membantu operasional dasar sejak 2023. Masalah penelitian muncul karena kebutuhan bisnis tahun 2026 sudah berubah: Apotek Bisma berkembang menjadi tiga cabang, membutuhkan gudang pusat, dan mulai merencanakan cabang keempat. Kondisi ini membuat stok harus dikelola per lokasi, transfer barang perlu dicatat, pembayaran asinkron perlu direkonsiliasi, dan data operasional harus mudah diaudit. Karena itu, microservices dievaluasi sebagai pendekatan migrasi dan penyegaran sistem, bukan diasumsikan sebagai solusi pasti.

### 19.2 Apa bukti masalah nyata Apotek Bisma?

> Bukti kebutuhan nyata berasal dari evolusi operasional Apotek Bisma. Sistem lama mulai digunakan sejak 2023 untuk satu cabang. Pada tahun 2026, Apotek Bisma sudah berkembang menjadi tiga cabang dengan kebutuhan gudang pusat, bahkan sedang memetakan rencana cabang keempat. Saat cabang bertambah, muncul kebutuhan pencatatan stok per lokasi, distribusi gudang ke cabang, laporan transaksi per cabang, kartu stok, stock opname, dan konsolidasi data. Kondisi ini cukup menjadi dasar penelitian karena masalahnya bukan semata performa aplikasi lama, tetapi perubahan skala bisnis dan risiko konsistensi data ketika sistem dikembangkan.

### 19.2D Bagaimana kondisi operasional sementara saat sistem terintegrasi belum tersedia?

> Saat ini Apotek Bisma menjalankan solusi sementara dengan menggunakan salinan aplikasi monolith secara lokal pada masing-masing cabang. Setiap cabang memiliki database sendiri dan mencatat transaksi serta stok secara terpisah. Gudang pusat yang mulai berjalan sekitar tujuh bulan terakhir masih menggunakan Excel/spreadsheet untuk mencatat stok dan distribusi barang. Pada akhir bulan atau akhir periode, laporan cabang dan gudang dikirim untuk direkonsiliasi oleh pegawai khusus. Kondisi ini menunjukkan adanya fragmentasi data nyata yang belum terkelola secara arsitektural.

### 19.2E Mengapa kondisi clone aplikasi dan Excel gudang memperkuat penelitian?

> Karena kondisi tersebut menunjukkan bahwa kebutuhan multi-cabang sudah berjalan di lapangan, tetapi integrasinya masih dilakukan secara manual. Masalahnya bukan sekadar ingin mengganti teknologi, melainkan bagaimana mengubah database cabang yang terpisah, laporan manual, dan pencatatan gudang spreadsheet menjadi sistem yang memiliki sumber kebenaran data yang jelas, ledger mutasi, status transfer, correlation ID, dan rekonsiliasi otomatis.

### 19.2A Apa bukti teknis dari sistem lama bahwa stok memang perlu direkonsiliasi?

> Bukti teknisnya terlihat dari codebase sistem eksisting. Sistem lama masih menyimpan master stok pada `produk.stok`, sedangkan riwayat perubahan stok dicatat pada `rekaman_stoks`. Di dalam sistem juga terdapat fungsi rekalkulasi stok, baseline stock reflow, monitoring stok negatif, audit kesesuaian transaksi dengan rekaman stok, serta utilitas analisis dan perbaikan stok. Keberadaan mekanisme tersebut menunjukkan bahwa menjaga konsistensi stok merupakan kebutuhan nyata pada sistem lama. Penelitian ini menjadikan kebutuhan tersebut lebih formal melalui model stok per lokasi, invariant, OCC, Outbox-Inbox, idempotency, Saga, dan rekonsiliasi otomatis.

### 19.2B Mengapa pengembangan ini dilakukan sekarang?

> Karena sistem eksisting sudah berjalan kurang lebih tiga tahun sejak 2023 dan awalnya dirancang untuk satu cabang. Pada saat itu, rancangan monolith dengan satu database masih masuk akal. Namun pada tahun 2026, Apotek Bisma sudah memiliki tiga cabang, membutuhkan gudang pusat, dan sedang menyiapkan kemungkinan cabang keempat. Jadi pengembangan ini bukan perubahan tanpa alasan, melainkan penyegaran sistem agar sesuai dengan skala operasional baru.

### 19.2C Mengapa tidak cukup hanya tambah fitur di monolith?

> Menambah fitur di monolith masih mungkin dilakukan, dan karena itu monolith tetap dijadikan baseline. Namun penelitian ini ingin mengevaluasi konsekuensi ketika sistem mulai dipisah berdasarkan domain seperti Sales, Inventory, Payment, dan Reporting. Pemisahan ini relevan karena transaksi multi-cabang, stok per lokasi, transfer gudang, pembayaran asinkron, dan pelaporan membutuhkan batas tanggung jawab data yang lebih jelas. Evaluasi tetap dilakukan secara komparatif agar klaim microservices tidak dibuat sepihak.

### 19.3 Multi-cabangnya ada di mana secara teknis?

> Multi-cabang dimodelkan melalui `locations` dan `stock_balances`. Setiap cabang dan gudang pusat memiliki saldo stok masing-masing. Penjualan mengurangi stok cabang asal order, sedangkan transfer gudang-cabang dicatat sebagai mutasi antar lokasi. Dengan demikian, aspek multi-cabang masuk ke model data, use case, invariant, dan rekonsiliasi.

### 19.3B Apakah kondisi operasional sementara dijadikan kondisi eksperimen?

> Tidak. Kondisi clone aplikasi lokal per cabang dan pencatatan gudang menggunakan Excel tidak dijadikan kondisi eksperimen utama karena terlalu banyak dipengaruhi proses manual, format laporan, dan kebiasaan pegawai. Kondisi tersebut digunakan sebagai bukti kebutuhan bisnis dan dasar perancangan sistem target. Eksperimen tetap dilakukan pada tiga kondisi terkontrol: monolith baseline, naive event-driven microservices, dan robust event-driven microservices.

### 19.3A Mengapa `produk.stok` tidak cukup untuk multi-cabang?

> `produk.stok` hanya menyimpan satu nilai stok untuk satu produk. Pada kondisi satu cabang, model ini masih dapat digunakan. Namun pada tiga cabang dan satu gudang pusat, satu produk dapat memiliki saldo berbeda di setiap lokasi. Karena itu, stok perlu dipindah menjadi saldo per lokasi melalui `stock_balances(product_id, location_id, on_hand, reserved, version)`. Dengan model ini, penjualan Cabang 1 tidak mengurangi stok Cabang 2, dan transfer gudang ke cabang dapat dicatat sebagai mutasi antar lokasi.

### 19.4 Apakah tiga cabang berarti tiga microservice?

> Tidak. Cabang adalah entitas bisnis berupa lokasi stok, bukan batas service. Service dipisahkan berdasarkan domain tanggung jawab seperti Sales, Inventory, Payment, dan Reporting. Tiga cabang cukup direpresentasikan sebagai data lokasi dalam Inventory Service.

### 19.5 Bagaimana jika Dinkes memeriksa kartu stok?

> Sistem dirancang menghasilkan stock movement ledger sebagai kartu stok digital. Setiap perubahan stok memiliki referensi, waktu, lokasi, aktor, dan correlation_id. Penelitian tidak mengklaim menggantikan proses pemeriksaan fisik atau kewajiban regulasi, tetapi menyediakan fondasi data yang lebih rapi untuk mendukung stock opname dan penelusuran mutasi.

### 19.6 Apakah eksperimen bisa membuktikan stok fisik sama?

> Tidak secara langsung. Eksperimen perangkat lunak membuktikan saldo sistem sesuai dengan test oracle berdasarkan seluruh mutasi sah. Kecocokan dengan stok fisik tetap harus divalidasi melalui stock opname. Karena itu, istilah yang digunakan adalah selisih saldo sistem terhadap test oracle, bukan klaim mutlak terhadap stok fisik.

### 19.7 Mengapa tidak membahas batch, expired date, dan FEFO?

> Karena ruang lingkup skripsi dibatasi pada konsistensi data stok kuantitatif, transaksi, pembayaran, dan transfer lokasi. Batch, expired date, FEFO, resep, dan regulasi obat adalah domain penting dalam sistem apotek, tetapi jika dimasukkan seluruhnya, ruang lingkup penelitian menjadi terlalu luas. Aspek tersebut dijadikan batasan dan pengembangan lanjutan.

### 19.8 Bagaimana jika pembayaran datang setelah order expired?

> Order yang sudah expired tidak otomatis dihidupkan kembali karena stok mungkin sudah dilepas atau dijual. Namun pembayaran valid tetap dicatat sebagai fakta keuangan dan diarahkan ke proses rekonsiliasi, refund, atau pemenuhan ulang manual. Dengan demikian, sistem menjaga state machine tanpa mengabaikan hak pelanggan.

### 19.9 Apakah DLQ berarti transaksi selesai?

> Tidak. DLQ hanya berarti pesan tidak hilang. Transaksi selesai jika sudah berhasil diproses, dikompensasi, direfund, dibatalkan, atau ditandai manual review. Karena itu terminal-state coverage tetap wajib 100%.

### 19.10 Apa kontribusi skripsi ini?

> Kontribusi penelitian adalah rancangan dan evaluasi konsistensi data pada penyegaran sistem Apotek Bisma yang telah berjalan sejak 2023 dari monolith satu cabang menuju event-driven microservices untuk kebutuhan multi-cabang. Kontribusinya meliputi model stok per lokasi, mekanisme Outbox-Inbox, OCC, Saga, idempotency, fault injection, invariant bisnis, script rekonsiliasi, dan analisis trade-off pada kasus nyata apotek yang berkembang menjadi tiga cabang dengan gudang pusat dan rencana ekspansi cabang berikutnya.

### 19.11 Apa hubungan rencana cabang keempat dengan skripsi?

> Rencana cabang keempat tidak dijadikan objek eksperimen utama, karena kondisi nyata yang diuji tetap tiga cabang dan satu gudang pusat. Namun rencana tersebut memperkuat alasan mengapa sistem perlu disegarkan. Jika sistem tetap dirancang dengan asumsi satu cabang dan satu stok global, maka setiap penambahan cabang akan meningkatkan risiko duplikasi data, selisih stok, dan kesulitan konsolidasi laporan. Karena itu, model stok per lokasi dan arsitektur yang lebih modular menjadi relevan untuk pertumbuhan berikutnya.

---

## 20. Blok Narasi Siap Tempel Untuk Proposal

### 20.1 Narasi Kebutuhan User

> Kebutuhan pengembangan sistem juga didorong oleh karakteristik pengguna. Pemilik Apotek Bisma membutuhkan sistem yang dapat membantu pengawasan operasional tanpa menuntut pemahaman teknis yang rumit. Karena itu, sistem tidak cukup hanya menyimpan data transaksi, tetapi juga harus menampilkan status stok, status transfer, status pembayaran, serta pengecualian yang membutuhkan perhatian admin secara jelas. Kasus seperti pembayaran terlambat, transfer berbeda jumlah, pesan gagal diproses, atau stok yang perlu direkonsiliasi harus diarahkan ke status yang dapat dipahami pengguna, misalnya `MANUAL_REVIEW` atau `REQUIRES_RECONCILIATION`.

### 20.1A Narasi Penyegaran Sistem 2023-2026

> Sistem Apotek Bisma telah digunakan sejak tahun 2023 ketika operasional masih berpusat pada satu cabang. Setelah berjalan kurang lebih tiga tahun, sistem tersebut menjadi bagian dari proses bisnis harian dan tetap berfungsi sebagai baseline operasional. Namun perkembangan usaha membuat kebutuhan sistem berubah. Pada tahun 2026, Apotek Bisma telah memiliki tiga cabang, membutuhkan gudang pusat, dan mulai mempertimbangkan pembukaan cabang keempat. Kondisi ini menunjukkan bahwa pengembangan yang dilakukan bukan sekadar mengganti teknologi, tetapi menyegarkan sistem eksisting agar sesuai dengan skala bisnis yang baru.

### 20.1B Narasi Bukan Over-Solution

> Penelitian ini tidak memosisikan microservices sebagai jawaban mutlak untuk semua masalah. Untuk satu cabang, monolith tetap lebih sederhana dan sudah terbukti membantu operasional Apotek Bisma sejak 2023. Namun, ketika sistem harus mendukung tiga cabang, gudang pusat, pembayaran asinkron, transfer barang, kartu stok, dan rencana ekspansi lanjutan, risiko konsistensi data menjadi lebih besar. Karena itu, penelitian ini mengevaluasi apakah migrasi bertahap menuju event-driven microservices layak dilakukan dan mekanisme apa yang wajib diterapkan agar pemisahan layanan tidak merusak kebenaran data.

### 20.1C Narasi Bukti Teknis Sistem Eksisting

> Kebutuhan penelitian juga diperkuat oleh kondisi teknis sistem eksisting. Pada sistem lama, stok masih disimpan sebagai satu nilai pada tabel produk, sedangkan riwayat perubahan stok dicatat pada `rekaman_stoks`. Dalam praktiknya, codebase sistem telah memiliki berbagai mekanisme audit dan perbaikan seperti rekalkulasi stok, baseline stock reflow, monitoring stok negatif, pemeriksaan kesesuaian transaksi dengan rekaman stok, serta utilitas perbaikan kartu stok. Keberadaan mekanisme tersebut menunjukkan bahwa menjaga konsistensi stok merupakan kebutuhan nyata pada sistem Apotek Bisma. Namun, pendekatan lama masih belum cukup ideal untuk kebutuhan tahun 2026 yang melibatkan tiga cabang, satu gudang pusat, dan rencana ekspansi cabang berikutnya. Karena itu, penelitian ini mengusulkan model stok per lokasi dan mekanisme konsistensi yang lebih eksplisit melalui OCC, Transactional Outbox, durable idempotency, dan Saga.

### 20.1D Narasi Operasional Sementara Clone Aplikasi dan Excel Gudang

> Kondisi operasional sementara Apotek Bisma menunjukkan adanya fragmentasi data yang nyata. Karena sistem multi-cabang terintegrasi belum tersedia, masing-masing cabang menjalankan salinan aplikasi monolith eksisting secara lokal/offline dengan database yang berbeda. Setiap cabang mencatat transaksi dan stoknya sendiri, lalu mengirimkan laporan stok dan transaksi pada akhir periode sebagai bagian dari SOP sinkronisasi operasional. Di sisi lain, gudang pusat yang mulai berjalan sekitar tujuh bulan terakhir masih menggunakan Excel/spreadsheet untuk mencatat stok gudang dan distribusi barang ke cabang. Data cabang dan data gudang kemudian dicocokkan oleh pegawai khusus yang menangani konsolidasi data antar lokasi.

### 20.1E Narasi Fragmentasi Manual Ke Pemisahan Domain Terstruktur

> Kondisi saat ini dapat dipandang sebagai fragmentasi data manual yang belum terkelola secara arsitektural. Setiap cabang memiliki aplikasi dan database sendiri, sedangkan gudang memiliki pencatatan terpisah melalui spreadsheet. Penelitian ini tidak sekadar memecah sistem menjadi microservices, tetapi mengevaluasi transisi dari fragmentasi manual tersebut menuju pemisahan domain yang lebih terstruktur melalui Sales, Inventory, Payment, dan Reporting Service. Dengan rancangan ini, stok per lokasi, transfer gudang-cabang, transaksi penjualan, pembayaran, dan pelaporan dapat dihubungkan melalui event, correlation ID, ledger, dan rekonsiliasi otomatis.

### 20.2 Narasi Gudang Terpusat

> Dalam rancangan baru, gudang pusat diposisikan sebagai lokasi stok utama yang menerima restock dari supplier dan mendistribusikan barang ke cabang. Setiap cabang memiliki saldo stok sendiri. Proses distribusi tidak diperlakukan sebagai perubahan angka stok sederhana, tetapi sebagai transfer bisnis yang memiliki status. Dengan model ini, sistem dapat membedakan barang yang masih berada di gudang, sedang dikirim, sudah diterima cabang, atau memerlukan rekonsiliasi.

### 20.3 Narasi Kartu Stok

> Setiap perubahan saldo stok menghasilkan catatan stock movement sebagai kartu stok digital. Catatan ini memuat produk, lokasi, jenis mutasi, jumlah masuk, jumlah keluar, saldo akhir, referensi transaksi, waktu kejadian, dan aktor. Kartu stok digital ini penting karena memudahkan penelusuran ketika terjadi selisih, mendukung proses stock opname, dan membantu kesiapan data operasional apabila dilakukan pemeriksaan berkala.

### 20.4 Narasi Batas Klaim

> Penelitian ini membuktikan konsistensi saldo sistem berdasarkan test oracle dan mutasi sah dalam lingkungan eksperimen. Penelitian tidak mengklaim membuktikan kecocokan langsung dengan stok fisik tanpa stock opname. Validasi fisik tetap menjadi proses operasional di apotek, sedangkan sistem yang dirancang bertugas menyediakan data digital yang konsisten, lengkap, dan dapat ditelusuri.

---

## 21. Revisi Prioritas Yang Disarankan Pada Draft Utama

Judul tidak perlu diubah. Perbaikan cukup dilakukan pada isi.

1. Ganti narasi "monolith mulai memiliki keterbatasan" menjadi "sistem eksisting sudah berjalan sejak 2023 dan perlu disegarkan karena kebutuhan berubah akibat ekspansi tiga cabang, gudang pusat, dan rencana cabang keempat".
2. Tambahkan topologi gudang pusat, Cabang 1, Cabang 2, dan Cabang 3.
3. Tambahkan model `locations`, `stock_balances`, `stock_movements`, dan `stock_transfers`.
4. Tegaskan cabang adalah lokasi, bukan service.
5. Ubah UC-1 agar eksplisit terjadi pada cabang tertentu.
6. Ubah UC-2 agar mencakup restock supplier ke gudang dan transfer gudang ke cabang.
7. Tambahkan hubungan kartu stok, stock opname, dan dukungan pemeriksaan Dinkes.
8. Perbaiki definisi oversell menjadi melebihi `available`, bukan hanya saat stok kosong.
9. Ganti istilah selisih fisik dalam eksperimen menjadi selisih saldo sistem terhadap test oracle.
10. Tambahkan status rekonsiliasi untuk webhook valid yang terlambat.
11. Jelaskan bahwa DLQ bukan transaksi selesai.
12. Tambahkan matriks fault A-B-C dan B-C.
13. Tambahkan workload awal dan SLA awal yang akan dikunci setelah pilot test.
14. Ganti klaim "gagal total" menjadi "tidak memenuhi kriteria penerimaan pada metrik terkait".
15. Tambahkan batasan bahwa batch, expired date, FEFO, resep, dan regulasi obat tidak menjadi fokus utama.
16. Tambahkan timeline 2023-2026 agar urgensi penelitian terlihat sebagai evolusi sistem nyata, bukan proyek migrasi yang dibuat-buat.
17. Tambahkan rumusan masalah dan tujuan yang menekankan penyegaran legacy system nyata, bukan penggantian total karena monolith gagal.
18. Tambahkan bukti teknis dari codebase eksisting: `produk.stok`, `rekaman_stoks`, rekalkulasi stok, baseline reflow, monitoring stok negatif, audit transaksi-ledger, dan utilitas repair.
19. Jelaskan bahwa bukti teknis tersebut tidak digunakan untuk menyalahkan sistem lama, tetapi untuk menunjukkan kebutuhan penyegaran dan formalisasi mekanisme konsistensi.
20. Tambahkan kondisi operasional sementara saat ini: clone aplikasi lokal per cabang, database berbeda, gudang menggunakan Excel/spreadsheet, laporan akhir bulan, dan pegawai khusus sinkronisasi.
21. Jelaskan bahwa kondisi operasional sementara tersebut adalah bukti fragmentasi data manual, bukan kondisi eksperimen utama.
22. Hubungkan fragmentasi manual saat ini dengan kebutuhan pemisahan domain yang lebih terstruktur melalui Sales, Inventory, Payment, Reporting, event, correlation ID, ledger, dan rekonsiliasi otomatis.

---

## 22. Kesimpulan Draft Perbaikan

Dengan perbaikan ini, proposal tetap mempertahankan judul yang sudah disetujui, tetapi substansinya menjadi lebih kuat karena penelitian tidak lagi terlihat sebagai migrasi microservices abstrak. Kasus Apotek Bisma menjadi lebih nyata melalui tiga lapisan bukti. Pertama, bukti bisnis berupa evolusi sistem sejak 2023, perkembangan menjadi tiga cabang, gudang pusat, dan rencana cabang berikutnya. Kedua, bukti teknis dari sistem eksisting yang telah membutuhkan audit, rekalkulasi, monitoring, baseline reflow, dan perbaikan stok. Ketiga, bukti operasional sementara berupa clone aplikasi lokal per cabang, database terpisah, gudang berbasis Excel/spreadsheet, laporan akhir bulan, dan rekonsiliasi manual oleh pegawai khusus.

Formulasi akhir yang paling aman:

> Penelitian ini mengevaluasi migrasi bertahap dan penyegaran sistem Apotek Bisma yang telah berjalan sejak 2023 dari monolith menuju event-driven microservices dalam konteks operasional apotek multi-cabang dengan gudang pusat. Saat sistem terintegrasi belum tersedia, operasional sementara dilakukan melalui clone aplikasi lokal per cabang, database terpisah, pencatatan gudang berbasis spreadsheet, dan rekonsiliasi manual akhir periode. Fokus utama penelitian adalah menjaga konsistensi data stok per lokasi, order, pembayaran, transfer barang, dan kartu stok melalui Transactional Outbox, Optimistic Concurrency Control, durable idempotency, dan Saga. Hasil penelitian diharapkan memberikan rekomendasi teknis yang realistis untuk mengubah fragmentasi data manual menjadi pemisahan domain yang lebih terstruktur, sekaligus menunjukkan trade-off antara keamanan data, kemampuan pemulihan, dan performa sistem.
