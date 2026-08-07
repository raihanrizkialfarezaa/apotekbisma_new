**DRAFT PENYUSUNAN KONSEP DAN TOPIK SKRIPSI** 

Program Studi S1 Teknik Informatika, Universitas Negeri Surabaya 

# **Implementasi Transactional Outbox dan Optimistic Concurrency Control pada Migrasi Monolith ke Event-Driven Microservices Berbasis Asynchronous I/O untuk Menjaga Konsistensi Data** 

_Studi Kasus: Sistem POS dan ERP Apotek Retail, Apotek Bisma, Kabupaten Mojokerto_ 

|**Diajukan oleh**|**Raihan Rizki Alfareza, NIM 23051204067**|
|---|---|
|Program Studi|S1 Teknik Informatika, Angkatan 2023|
|Dosen Pembimbing|I Made Suartana, S.Kom., M.Kom.|
|Bidang Keahlian Pembimbing|Arsitektur Perangkat Lunak dan Sistem Modular|



## **1. Ringkasan Eksekutif** 

Apotek Bisma mengelola operasional ritel melalui tiga cabang yang didukung oleh sistem distribusi terpusat. Mengingat setiap transaksi penjualan dari berbagai titik, seperti kasir, _Self-Order Kiosk_ , maupun pembayaran digital, memerlukan sinkronisasi inventaris yang lebih baik, arsitektur sistem saat ini yang berbasis Laravel 8 monolith dengan basis data MySQL tunggal mulai memiliki keterbatasan dalam menangani transaksi konkuren dan proses asinkron seiring pertumbuhan volume penjualan. Lingkungan transaksi yang bersifat konkuren dan asinkron ini meningkatkan potensi anomali data, mulai dari ketidaksinkronan stok akibat _race condition_ , redundansi pemrosesan _webhook_ , hingga kegagalan pelepasan stok pada skenario transaksi yang tidak tuntas. 

Topik skripsi ini menguji migrasi bertahap menuju event-driven microservices. 3 kondisi akan dibandingkan. Mulai dari aplikasi Laravel yang telah berjalan sebagai baseline saat ini, _microservices_ Node.js dengan publikasi _event_ langsung sebagai kondisi _naïve_ , hingga rancangan sistem penuh yang menggunakan _Transactional Outbox_ , OCC, _durable idempotency_ , serta _Saga orchestration_ dan _compensation_ (Richardson, 2018).  Stack tidak disamakan karena tujuan penelitian adalah melihat evolusi arsitektur dalam skenario migrasi yang sebenarnya. Perbedaan framework dan runtime tetap diakui sebagai bagian dari perubahan implementasi yang dapat memengaruhi performa. Karena migrasi juga mengubah bentuk arsitektur, komunikasi, dan pengelolaan data, selisih latency atau throughput tidak akan diatribusikan kepada framework saja. Hasil performa ditafsirkan sebagai dampak keseluruhan dari konfigurasi setiap kondisi, sedangkan evaluasi utama penelitian tetap berfokus pada konsistensi data, kemampuan pemulihan, dan konsekuensi teknis yang ada di dalamnya. 

## **1.1 Pertanyaan dan Tujuan** 

- Bagaimana menjaga stok, order, dan pembayaran tetap valid ketika database dipecah nantinya? 

- Seberapa baik rancangan mampu pulih dari konkurensi, event duplikat, crash, timeout, dan 

- kegagalan parsial? 

- Seberapa besar dampak dari mekanisme tersebut terhadap latency, throughput, dan sumber daya? 

- Tujuan dianggap tercapai bila tidak ada oversell, pembaruan data hilang, transaksi ganda, data event hilang permanen, atau Saga yang tertahan tanpa handling yang baik. Data antarservice boleh berbeda 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 1 

sementara, tetapi harus menuju kondisi akhir yang sama dalam batas waktu sinkronisasi yang sudah ditentukan sebelum eksperimen. 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 2 

## **2. Latar Belakang, Alasan Topik, dan Posisi Riset** 

Topik ini diangkat berdasarkan kebutuhan operasional nyata di Apotek Bisma, bukan skenario yang dirancang hanya untuk kepentingan pengujian. Apotek Bisma saat ini mengoperasikan tiga cabang dengan satu gudang terpusat yang mendistribusikan produk ke setiap unit cabang. Sistem POS harus menangani transaksi dari seluruh cabang tersebut, sementara penggunaan kiosk menambah saluran penjualan baru. Selain itu, integrasi pembayaran melalui webhook Midtrans serta verifikasi admin untuk QRIS statis semakin meningkatkan kompleksitas sistem. Kondisi ini memperbesar risiko terjadinya bentrokan data, di mana beberapa proses berusaha memperbarui stok dan informasi transaksi yang sama secara bersamaan. 

Pendekatan monolith mempermudah pembatalan transaksi di berbagai tabel secara bersamaan. Ketika arsitektur beralih ke microservices, di mana Sales, Inventory, dan Payment memiliki basis data sendiri, fitur _rollback_ otomatis lintas layanan ini menjadi hilang. Tantangan inilah yang diselesaikan dalam penelitian ini, yaitu bagaimana memastikan seluruh aturan dan integritas data bisnis tetap terjaga pascamigrasi (Richardson, 2018). 

## **2.1 Perbedaan Eksplisit dengan Jurnal WMS Candra: “Pengembangan Sistem Manajemen Gudang Berbasis Web Dengan Event-Driven Architecture”** 

|**Aspek**|**Hasil Temuan Jurnal Terkait**|**Penelitian Ini**|
|---|---|---|
|Domain|Manajemen gudang dan aliran<br>stok.|POS/ERP apotek. Mencakup<br>penjualan, reservasi, restock,<br>pembayaran, kiosk.|
|Batas sistem|EDA untuk menghubungkan<br>modul pada sistem gudang.|Microservices dengan basis<br>kode dan database terpisah per<br>domain.|
|Masalah utama|Pengembangan alur event dan<br>respon sistem.|Kebenaran transaksi saat<br>concurrent update, dual-write,<br>duplicate delivery, dan partial<br>failure.|
|Mekanisme|Event broker sebagai<br>penghubung proses.|Outbox, worker, durable inbox,<br>OCC, Saga Orchestrator,<br>compensation, retry, dan DLQ.|
|Pembayaran|Tidak menjadi skenario utama.|Midtrans serta QRIS statis<br>human-in-the-loop (input<br>manual oleh admin) diuji<br>eksplisit.|
|Evaluasi|Fungsionalitas dan performa<br>EDA.|Tiga tahap evolusi, fault<br>injection, invariant bisnis,<br>recovery, konvergensi, dan<br>konsekuensi performa.|



Penelitian ini tidak bertujuan untuk menyanggah atau mencari kelemahan dari sistem yang sudah ada pada jurnal terkait, karena fokus masalah yang diangkat memang berbeda. Penelitian pada jurnal tersebut justru menjadi dasar untuk membuktikan bahwa EDA cocok diterapkan pada manajemen persediaan 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 3 

(Rochman & Suartana, 2026). Dari aspek tersebut, skripsi ini dibuat lebih spesifik untuk menguji konsistensi transaksi lintas layanan dengan cara menyuntikkan kegagalan sistem yang disengaja. 

## **2.2 Kontribusi dan Batas Klaim** 

Pada dasarnya, penelitian ini disusun untuk membantu Apotek Bisma menjaga akurasi transaksi serta persediaan barang saat operasional berjalan di tiga cabang dengan satu gudang terpusat. Hasil implementasi, perbandingan tiga kondisi sistem, indikator pengujian, serta umpan balik pengguna digunakan sebagai dasar rekomendasi migrasi yang selaras dengan kebutuhan operasional apotek. Secara akademis, penelitian ini memberikan bukti empiris mengenai penerapan serta evaluasi gabungan pola Outbox, OCC, durable idempotency, dan Saga pada sistem untuk apotek multi-cabang. Metode tersebut tidak diklaim sebagai algoritma baru, melainkan sumbangsih dalam bentuk rancangan evaluasi, bukti eksperimen, dan pembahasan mengenai konsekuensi teknis pada kasus nyata. Ruang lingkup kesimpulan penelitian ini dibatasi pada Apotek Bisma dan pola transaksi yang diuji dalam lingkungan terkontrol. 

## **3. Rancangan Tiga Kondisi dan Arsitektur Target** 

|**Kondisi**|**Implementasi**|**Tujuan Pembandingan**|
|---|---|---|
|A -> Baseline|Laravel 8 monolith, 1 database<br>MySQL, transaksi lokal dan<br>locking yang sudah digunakan.|Merekam perilaku sistem awal<br>yang benar-benar ada dan<br>berjalan saat ini.|
|B -> Naïve EDA|Menggunakan NestJS<br>microservices dan database per<br>service. Perubahan bisnis<br>disimpan lalu event<br>dipublikasikan langsung ke<br>RabbitMQ.|Menunjukkan kerentanan<br>sistem terhadap kegagalan<br>_dual-write_dan duplikasi<br>pengiriman event pada kondisi<br>layanan terpisah sebelum<br>mekanisme proteksi diterapkan.|
|C -> Robust EDA|Kondisi B ditambah Outbox,<br>worker, inbox/idempotency,<br>OCC, Saga Orchestrator,<br>compensation, retry, dan DLQ.|Mengevaluasi kemampuan<br>arsitektur dalam menjaga<br>integritas data dan melakukan<br>pemulihan sistem saat terjadi<br>kegagalan, sekaligus<br>menganalisis dampak_overhead_-<br>nya.|



Kondisi A tetap menggunakan Laravel sebagai baseline sistem dengan menyamakan seluruh variabel pengujian ( _dataset_ , _workload_ , dan spesifikasi mesin) demi validitas data. Evaluasi utama berfokus pada aspek konsistensi data dan kemampuan pemulihan. Sehingga selisih performa antara Laravel dan Node.js tidak diadu secara mutlak karena adanya perbedaan _runtime_ dan struktur arsitektur. 

## **3.1 Batas Domain** 

|**Service**|**Tanggung Jawab dan Data Otoritatif**|
|---|---|
|Sales|Order, item penjualan, status transaksi, serta state<br>Saga.|
|Inventory|On-hand, reserved, available, mutasi, dan version|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 4 

|**Service**|**Tanggung Jawab dan Data Otoritatif**<br>untuk OCC.|
|---|---|
|Payment|Payment attempt, nominal, status pembayaran,<br>webhook, dan idempotency key.|
|Reporting/Notifcation|Read model untuk dashboard dan notifkasi.<br>Bukan sumber kebenaran stok.|



## **3.2 Kontrak Komunikasi** 

Pesan berbentuk _Command_ seperti _ReserveStock_ dikirim untuk meminta tindakan dan bisa saja ditolak, sedangkan _Event_ seperti _StockReserved_ mencatat fakta bisnis yang sudah terjadi. Setiap pesan dibekali atribut penting yaitu event_id untuk mencegah pemrosesan ganda atau deduplikasi, serta correlation_id untuk melacak jejak transaksi utuh dari Sales, Payment, hingga Inventory. 

## **3.3 Makna Asynchronous I/O** 

Node.js atau NestJS mampu memproses operasi basis data, broker, webhook, dan WebSocket tanpa harus menahan thread saat menunggu proses I/O selesai. Karakteristik ini sangat membantu dalam menangani banyak transaksi bersamaan, namun tidak menjamin kebenaran data secara otomatis. Kepastian konsistensi data tetap bergantung penuh pada mekanisme transaksi lokal, constraint, Outbox, OCC, inbox, serta Saga. 

## **4. Cara Kerja EDA dan Mekanisme Konsistensi** 

Pada arsitektur EDA, setiap layanan dilarang mengubah basis data milik layanan lain secara langsung. Begitu sebuah fakta bisnis terjadi, layanan akan mengirimkan _event_ melalui RabbitMQ. Setelah pesan diterima broker, publisher memperoleh publisher confirm. Pada sisi penerima, consumer baru memberikan ACK ( _Acknowledge)_ . Jika consumer gagal memproses pesan, RabbitMQ dapat mengirimkannya kembali sesuai konfigurasi retry. Pesan yang tetap gagal setelah melewati batas percobaan dialihkan ke Dead Letter Queue agar dapat diperiksa tanpa risiko hilang secara misterius (RabbitMQ/Broadcom Inc., t.t.). 

|**Konsep**|**Penjelasan Sederhana**|**Fungsi pada Project**|
|---|---|---|
|Pessimistic locking|Baris dikunci sebelum diubah.<br>Transaksi lain menunggu.|Laravel bertindak sebagai<br>baseline untuk menguji konfik<br>dalam satu basis data yang<br>kondisinya aman tetapi rawan<br>meningkatkan waktu tunggu<br>proses.|
|Optimistic locking/OCC|Data dibaca bersama nomor<br>versioning atau versioning id.<br>Update hanya berhasil jika<br>version belum berubah.|Layanan Inventory akan<br>otomatis menolak pembaruan<br>data yang menggunakan versi<br>lama, memaksa sistem pengirim<br>untuk membaca ulang data<br>terbaru lalu mencoba proses<br>kembali dalam batas tertentu.|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 5 

|**Konsep**|**Penjelasan Sederhana**|**Fungsi pada Project**|
|---|---|---|
|Transactional Outbox|Data bisnis dan catatan event<br>disimpan dalam transaksi lokal<br>yang sama.|Mencegah situasi di mana order<br>berhasil disimpan tetapi data<br>event gagal tercatat akibat<br>sistem mengalami crash saat<br>melakukan_dual-write_.|
|Outbox worker|Proses otomatis di balik layar<br>untuk mengambil catatan<br>pengiriman lalu meneruskannya<br>ke antrean pesan agar siap<br>disebarkan ke layanan lain.|Mengirim ulang pesan sampai<br>RabbitMQ memberikan<br>publisher confrm, lalu mencatat<br>waktu publikasinya. Consumer<br>ACK diberikan secara terpisah<br>oleh layanan penerima setelah<br>pesan selesai diproses.|
|Durable inbox/idempotency|Consumer menyimpan<br>event_id/webhook key yang<br>sudah diproses.|Menyaring pesan yang masuk<br>agar pengiriman ulang akibat<br>gangguan jaringan tidak sampai<br>menduplikasi data stok atau<br>mencatat pembayaran baru.|
|Saga|Gabungan beberapa proses<br>transaksi di setiap layanan<br>untuk menyelesaikan satu alur<br>bisnis utuh.|Mengikat alur pembuatan<br>pesanan, alokasi stok, dan<br>verifkasi pembayaran agar<br>tetap sinkron secara otomatis<br>meski tanpa basis data tunggal.|
|Saga Orchestrator|Komponen yang mencatat state<br>dan menentukan langkah<br>berikutnya.|Pengendali pusat di layanan<br>Sales yang bertugas<br>mengirimkan perintah transaksi,<br>memeriksa status keberhasilan,<br>lalu menentukan langkah<br>berikutnya atau menjalankan<br>pembatalan jika terjadi eror.|
|Compensation|Menjalankan aksi pembatalan<br>secara mandiri di tingkat bisnis<br>untuk memulihkan kondisi awal<br>dan bukan membatalkan<br>transaksi basis data secara<br>menyeluruh.|Jika transaksi pembayaran<br>ternyata gagal setelah stok<br>terlanjur dipesan, layanan<br>Inventory akan otomatis<br>mengembalikan jumlah stok<br>tersebut ke kondisi semula.|



## **4.1 Contoh OCC** 

Saat stok obat tersisa 3 unit pada versi 7, mesin kasir dan layanan kiosk secara bersamaan meminta pembelian masing-masing 2 unit. Permintaan pertama berhasil masuk, mengubah stok menjadi 1 unit, dan menaikkan status menjadi versi 8. Permintaan kedua otomatis ditolak karena masih membawa data versi 7, yang nantinya digunakan untuk menguji apakah bentrokan pembaruan data dapat dicegah (Kleppmann, 2017). Ketika sistem mencoba mengulang proses secara otomatis, pembelian kedua 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 6 

akhirnya dinyatakan gagal karena sisa stok memang sudah tidak mencukupi. 

## **4.2 Ketahanan Proses** 

Status data untuk Saga dan Inbox wajib disimpan secara permanen di basis data, bukan sekadar di memori sementara (Richardson, 2018; Richardson, t.t.-b; Richardson, t.t.-c). Jika layanan Sales mendadak mati di tengah jalan, tugas otomatis di balik layar akan langsung membaca status transaksi yang belum selesai begitu layanan aktif kembali. Mekanisme batas waktu kemudian bisa melanjutkan pengiriman ulang pesan, menjalankan pembatalan otomatis, atau menandai transaksi agar diperiksa manual oleh admin, di mana seluruh riwayat keputusan tersebut diikat oleh satu identitas transaksi yang sama. 

## **5. Use Case dan Contoh Alur Konkret** 

## **5.1 UC-1 — POS dan Kiosk Menjual Produk yang Sama** 

Ketika kasir dan kiosk mengirimkan permintaan pembelian secara bersamaan, layanan Sales akan membuat status pesanan baru menjadi tertunda. Layanan Inventory kemudian melakukan pemesanan stok menggunakan metode OCC, di mana hanya satu permintaan yang berhasil sementara transaksi lainnya langsung ditolak karena bentrokan data atau stok habis. Sistem memastikan pesanan yang kalah saing tidak akan pernah berubah statusnya menjadi selesai, dengan target utama menjaga data penjualan tetap akurat tanpa ada stok minus. 

## **5.2 UC-2 — Restock Berdekatan dengan Penjualan** 

Proses penambahan stok dilakukan melalui 1 nomor mutasi yang valid. Layanan Outbox akan mencatat riwayat pembaruan ini dalam transaksi yang sama sebelum disebarkan oleh worker ke antrean pesan (Richardson, t.t.-a). Jika terjadi pengiriman ulang akibat gangguan sistem, mekanisme Inbox akan otomatis mengunci pesan tersebut agar tidak terjadi manipulasi data stok untuk kedua kalinya (Richardson, t.t.-c). Dalam skenario pengujian dengan stok awal 20 unit, ditambah pasokan baru 100 unit, dan dikurangi transaksi penjualan 15 unit, saldo akhir yang diharapkan berdasarkan test oracle adalah 105 unit. 

## **5.3 UC-3 — Pembayaran Midtrans** 

Alur dimulai ketika pesanan dibuat, stok dialokasikan, dan layanan Payment menyiapkan proses pembayaran. Saat menerima update dari Midtrans, sistem wajib memvalidasi keaslian digital signature, identitas order, nilai transaksi, mata uang, serta perubahan statusnya (Midtrans, t.t.). Informasi pembayaran sukses yang tiba terlambat atau terkirim berulang kali akan disaring oleh mekanisme idempotency agar status transaksi hanya berubah menjadi lunas dan selesai sekali saja, sementara data yang tidak valid otomatis ditolak. 

|**Tahap**|**Status/Peristiwa**|**Jika Gagal**|
|---|---|---|
|1|Sales.PENDING →<br>ReserveStock|Order dibatalkan bila stok tidak<br>cukup.|
|2|StockReserved →<br>Payment.PENDING|State Saga menunggu webhook<br>sampai timeout.|
|3|Webhook valid →|Pesan duplikat otomatis|
||Payment.PAID|diabaikan karena sistem|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 7 

|**Tahap**|**Status/Peristiwa**|**Jika Gagal**|
|---|---|---|
|||mengenali bahwa transaksi<br>tersebut sudah pernah diproses<br>sebelumnya.|
|4|PaymentSucceeded →<br>Sales.COMPLETED|Proses otomatis akan mencoba<br>mengirim ulang pesan jika<br>layanan penerima mengalami<br>kegagalan, sementara status<br>transaksi dipulihkan oleh<br>worker di latar belakang.|
|5|Payment.FAILED/EXPIRED|Mengembalikan jumlah stok<br>yang sempat dipesan sebagai<br>langkah pembatalan otomatis.|



## **5.4 UC-4 — QRIS Statis dengan Konfirmasi Admin** 

Ketika order dibuat, statusnya akan tertahan sebagai menunggu konfirmasi pembayaran dan jumlah barang yang diinginkan langsung ditandai sebagai stok cadangan, sehingga belum dianggap terjual. Sisa stok yang siap dibeli dihitung dari total fisik dikurangi jumlah cadangan tersebut. Admin memiliki kendali penuh untuk menyetujui atau menolak transaksi ini melalui jalur khusus yang aman. Jika batas waktu tunggu habis, sistem otomatis membatalkan pemesanan stok. 

Sebagai contoh, jika ada 10 barang dan 2 sedang dicadangkan, maka sisa stok yang tersedia menjadi 8, di mana jika waktu habis, jumlah cadangan kembali nol dan sisa stok kembali utuh menjadi 10. 

Persetujuan dari admin yang tiba setelah batas waktu habis otomatis ditolak agar tidak menghidupkan kembali transaksi yang sudah mati secara sepihak. Status akhir transaksi bersifat searah dan mutlak, artinya setiap perubahan pada data yang sudah final hanya boleh dilakukan melalui proses audit manual yang tercatat resmi di sistem. 

## **6. Definisi Data Valid dan Konsisten** 

Sistem ini membedakan dengan tegas antara keabsahan dan keselarasan data. Data dinyatakan valid jika seluruh input serta bukti transaksi sudah sesuai dengan aturan operasional. Sementara itu, data dianggap konsisten jika hubungan antar-kejadian bisnis terbukti benar setelah seluruh proses selesai atau melewati masa pemulihan. Konsep ini tidak menuntut data produk selalu statis, sebab stok barang tentu akan berubah akibat transaksi penjualan atau re-stock baru. Pengujian ini fokus membuktikan bahwa setiap pergeseran angka memiliki alasan yang jelas dan jumlah akhir di akhir hari selalu bisa dihitung kecocokannya. 

|**Use Case**|**Data Valid Jika**|**Data Konsisten Jika**|**Parameter Lulus**|
|---|---|---|---|
|UC-1 Sales race|Barang tersedia,<br>jumlah pesanan di atas<br>nol, serta asal kanal<br>dan kunci unik<br>transaksi|Hanya order dengan<br>dukungan fsik stok<br>yang berhasil<br>dikonfrmasi, serta<br>catatan riwayat dan|Tidak terjadi penjualan<br>melebihi stok, tidak<br>ada pembaruan data<br>yang saling menimpa,<br>dan selisih angka|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 8 

|**Use Case**|**Data Valid Jika**|**Data Konsisten Jika**|**Parameter Lulus**|
|---|---|---|---|
||teridentifkasi.|tampilan data terbukti<br>sama.|barang mutlak nol.|
|UC-2 Restock|Data pasokan dan<br>pemasok terdaftar,<br>jumlah barang di atas<br>nol, transaksi berstatus<br>fnal, serta diikat satu<br>id mutasi.|Jumlah stok bertambah<br>tepat satu kali sesuai<br>pasokan baru dan<br>berkurang secara<br>akurat sesuai angka<br>penjualan.|Tidak ada penambahan<br>stok ganda, tidak ada<br>pesan laporan yang<br>hilang, dan selisih<br>perhitungan barang<br>mutlak nol.|
|UC-3 Midtrans|Digital Signature asli,<br>nomor pesanan dan<br>bukti bayar cocok,<br>serta nilai uang dan<br>perubahan status<br>terbukti sah.|Setiap transaksi<br>pembayaran hanya<br>berdampak satu kali<br>dan status di seluruh<br>layanan terbukti<br>sinkron tanpa celah.|Dampak transaksi<br>ilegal bernilai nol,<br>tidak ada pencatatan<br>bayar ganda, dan<br>ketidakcocokan data<br>permanen mutlak nol.|
|UC-4 QRIS|Transaksi order masih<br>aktif, nilai pembayaran<br>tepat, disetujui pihak<br>terkait, dan bukti setor<br>terekam.|Persetujuan admin<br>memfnalisasi data<br>sekali, sedangkan<br>penolakan atau<br>habisnya waktu tunggu<br>melepas cadangan stok<br>sekali.|Batas waktu tunggu<br>yang gagal dilepas<br>bernilai nol, dampak<br>aksi tanpa izin bernilai<br>nol, dan alur transaksi<br>tuntas 100%.|



## **6.1 Invariant yang Diperiksa** 

- Sisa stok yang siap dibeli dihitung dari total fisik dikurangi jumlah cadangan pesanan dan angkanya tidak boleh minus. 

- Jumlah akhir barang wajib sama dengan stok awal ditambah pasokan baru, dikurangi total penjualan, serta disesuaikan dengan perubahan lain yang sah. 

- Setiap satu order hanya boleh memiliki satu bukti pembayaran sah dan satu kali pemotongan stok final. 

- Status transaksi yang sudah lunas atau selesai dilarang keras kembali menjadi tertunda hanya karena ada laporan yang terlambat datang. 

- Setiap alur transaksi terdistribusi wajib diselesaikan sampai tuntas, baik sukses, dibatalkan secara sistem, maupun ditandai untuk aksi manual. 

## **6.2 Cara Membuktikan** 

Begitu pengujian selesai, sebuah program audit mandiri akan memeriksa basis data penjualan, inventaris, pembayaran, serta catatan pesan masuk dan keluar. Seluruh data tersebut dicocokkan berdasarkan unique 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 9 

id transaksi yang sama. Sistem dinyatakan mengalami selisih jika ada pasangan data wajib yang hilang setelah masa pemulihan selesai. Selain itu, aspek kelengkapan, keaslian, ketepatan waktu, dan kemudahan pelacakan data juga akan diuji. 

## **7. Metode dan Desain Eksperimen** 

Penelitian ini menerapkan metode rekayasa perangkat lunak melalui pendekatan studi kasus dan evaluasi komparatif. Evaluasi teknis dijalankan menggunakan serangkaian eksperimen kuantitatif yang berulang, dengan beban kerja yang konsisten untuk setiap kondisi arsitektur. Di samping itu, pengujian pengguna melibatkan admin, petugas gudang, dan staf apotek yang sehari-hari menjalankan proses bisnis di Apotek Bisma. Dalam pengujian ini, para pengguna mencoba alur kerja utama sesuai peran masing-masing, kemudian memberikan penilaian terkait kesesuaian fungsi, kemudahan penggunaan, kejelasan status transaksi, serta sejauh mana alur sistem mendukung pekerjaan mereka. 

Hasil penilaian tersebut menjadi dasar perbaikan sistem untuk memastikan bahwa hasil penelitian tidak sekadar benar secara teknis, tetapi juga relevan dalam menjawab kendala operasional yang nyata. 

## **7.1 Variabel** 

|**Jenis**|**Isi**|
|---|---|
|Bebas|Variasi model arsitektur sistem, tingkat<br>kepadatan beban kerja, serta jenis gangguan yang<br>sengaja disuntikkan ke dalam sistem.|
|Terikat|Penjualan melebihi stok, pembaruan data yang<br>saling menimpa, selisih hitungan barang, dampak<br>transaksi ganda, waktu pemulihan, jeda<br>penyelarasan data, kecepatan respons, jumlah<br>transaksi per detik, tingkat error, serta konsumsi<br>daya CPU dan RAM.|
|Kontrol|Kesamaan data awal, aturan operasional bisnis,<br>spesifkasi komputer, batasan kapasitas<br>kontainer, jalur akses yang setara, pola request,<br>durasi pengujian, fase warming-up sistem, serta<br>versi dependency.|
|Perancu|Perbedaan runtime antara framework Laravel dan<br>Node.js, beban latensi jaringan, manajemen<br>memori otomatis, serta warisan ftur lama yang<br>semuanya wajib dicatat dan dianalisis secara<br>terbuka dalam pembahasan.|



## **7.2 Prosedur Satu Eksperimen** 

|**Tahap**|**Pelaksanaan**|
|---|---|
|Persiapan|Melakukan reset database menggunakan<br>|
||snapshot, memverifkasi data seed dan checksum,<br>serta memastikan seluruh service dalam kondisi|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 10 

|**Tahap**|**Pelaksanaan**|
|---|---|
||_healthy_.|
|Warm-up|Menjalankan_workload_singkat terlebih dahulu<br>agar koneksi dan_cache_berada dalam kondisi<br>stabil, di mana hasil pada tahap ini tidak masuk<br>dalam perhitungan metrik.|
|Measurement|Mengeksekusi pengujian menggunakan k6 pada<br>tingkat beban_low_,_medium_, dan_high_dengan<br>konfgurasi_seed_serta distribusi request yang<br>identik.|
|Fault injection|Menyuntikkan gangguan dengan mematikan<br>service atau worker pada titik F1–F4,<br>mengirimkan pesan duplikat/_out-of-order_, atau<br>memberikan delay pada broker.|
|Recovery|Menghidupkan kembali seluruh komponen,<br>menunggu hingga_recovery window_selesai, dan<br>menghentikan aktivitas producer sebelum<br>memulai proses rekonsiliasi akhir.|
|Rekonsiliasi|Menghitung_invariant_dan metrik dari sumber<br>data mentah, serta menyimpan_log_,<br>_correlation_id_, konfgurasi, dan_timestamp_.|



## **7.3 Replikasi dan Analisis** 

Setiap kombinasi kondisi, beban kerja, use case, dan fault akan diuji ulang minimal 30 kali setelah melalui tahap pilot test. Kondisi A, B, dan C pada satu nomor pengulangan menggunakan seed, dataset awal, pola request, serta titik gangguan yang sama. Ketiganya diperlakukan sebagai satu blok pengujian agar perbedaan hasil lebih mencerminkan perubahan rancangan sistem, bukan perubahan data uji. Urutan eksekusi antarkondisi tetap diacak atau dirotasi untuk mengurangi bias akibat waktu, suhu mesin, cache, maupun proses latar belakang. 

**Metrik kontinu.** Data latency, throughput, read model lag, waktu _recovery_ , CPU, dan RAM diringkas per pengulangan menggunakan median, IQR, serta p95/p99. Nilai p95 misalnya, menunjukkan bahwa 95% request selesai tidak lebih lambat dari nilai tersebut. Cara semacam ini lebih mewakili kondisi saat sistem sibuk dibandingkan rata-rata yang mudah berubah akibat beberapa request sangat lambat. 

**Perbandingan 3 kondisi.** Karena hasil A, B, dan C dipasangkan dengan blok pengujian yang sama, perbedaan ketiganya dianalisis menggunakan Friedman test. Jika hasil uji menunjukkan perbedaan, analisis dilanjutkan menggunakan Wilcoxon signed-rank untuk pasangan A–B, A–C, dan B–C. Koreksi Benjamini–Hochberg diterapkan pada keluarga pengujian yang sudah ditentukan sebelum eksperimen, yaitu seluruh perbandingan pasangan untuk satu metrik dan satu use case pada seluruh level beban dan fault terkait. Kendall's W dan matched-pairs rank-biserial correlation ikut dilaporkan sebagai effect size agar besar perbedaannya tetap terlihat dan pembahasan tidak hanya bergantung pada p-value. 

**Confidence interval.** Ketidakpastian median dan p95/p99 dihitung menggunakan bootstrap BCa 95% sebanyak 10.000 resample pada tingkat iterasi. Setiap iterasi terlebih dahulu menghasilkan satu nilai 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 11 

ringkasan sehingga ribuan request dari pengujian yang sama tidak dianggap sebagai sampel independen. Pemeriksaan histogram, Q–Q plot, dan Shapiro–Wilk tetap dicantumkan di lampiran sebagai diagnosis bentuk distribusi, tidak dijadikan sebagai satu-satunya dasar pemilihan uji. 

**Metrik keselamatan data.** Oversell, permanent mismatch, lost update, dan duplicate effect tidak dinilai dari beda rata-rata karena syarat utama Kondisi C adalah tidak adanya satu pun pelanggaran logika. Jumlah kejadian tetap dilaporkan bersama jumlah peluang yang benar-benar diuji, seperti jumlah transaksi konkuren, event duplikat yang dikirim, atau Saga yang terkena partial failure. Jika satu pelanggaran ditemukan, Kondisi C dinyatakan gagal pada metrik tersebut. Jika hasilnya nol, batas atas interval kepercayaan satu sisi 95% exact binomial turut dicantumkan untuk menunjukkan bahwa hasil nol pada sampel tidak berarti risiko di luar eksperimen pasti nol. 

Contoh penerapan analisis pada alur transaksi dapat dilihat pada tabel berikut. 

|**Metrik yang Dilihat**|**Cara Analisis**|**Contoh Use Case Sederhana**|
|---|---|---|
|Latency p95/p99|Ringkasan per pengulangan,<br>Friedman, post-hoc Wilcoxon,<br>efect size, dan bootstrap BCa<br>95%.|Dua kasir menekan tombol<br>bayar saat beban tinggi. Nilai<br>p95 menunjukkan batas waktu<br>penyelesaian bagi sebagian<br>besar transaksi pada A, B, dan<br>C.|
|Throughput (RPS)|Median dan IQR per<br>pengulangan, kemudian<br>dibandingkan sebagai data<br>berpasangan.|K6 mengirim pola transaksi<br>yang sama. Hasilnya<br>menunjukkan berapa request<br>per detik yang dapat<br>diselesaikan setiap kondisi.|
|Read model lag dan waktu<br>pemulihan|Median, p95/p99, bootstrap<br>BCa, serta perbandingan 3<br>kondisi.|Pembayaran telah berhasil,<br>tetapi stok dashboard belum<br>berubah. Waktu dari event<br>terjadi sampai tampilan kembali<br>benar dicatat sebagai lag.|
|Oversell dan lost update|Jumlah pelanggaran, jumlah<br>peluang konfik, serta batas atas<br>CI satu sisi ketika hasilnya nol.|Stok tersisa 3, sedangkan kasir<br>dan kiosk bersamaan membeli<br>masing-masing 2. Sistem lulus<br>hanya jika tidak ada dua<br>transaksi yang sama-sama lolos.|
|Duplicate efect|Jumlah efek ganda<br>dibandingkan dengan jumlah<br>event duplikat yang sengaja<br>dikirim.|Event PaymentCompleted<br>dikirim ulang. Stok dan<br>pembayaran tetap hanya boleh<br>dicatat satu kali.|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 12 

||Jumlah ketidaksesuaian setelah|Worker dimatikan setelah order<br>dibuat, lalu dihidupkan kembali.|
|---|---|---|
|Permanent mismatch|masa recovery dan rekonsiliasi<br>selesai.|Data order, pembayaran, dan<br>stok wajib akhirnya kembali<br>sesuai.|



Contoh pembacaan hasil: apabila Kondisi C mencatat 0 duplicate effect dari 3.000 event duplikat yang diinjeksi, hasil tersebut memenuhi syarat teknis pada ruang pengujian. Interval kepercayaan tetap ditampilkan untuk menjelaskan batas ketidakpastiannya. Sebaliknya, satu duplicate effect saja sudah menjadi pelanggaran, walaupun latency dan throughput Kondisi C lebih baik. 

## **7.4 Pengambilan, Perhitungan, dan Pembacaan Data** 

Bagian ini memberi gambaran operasional mengenai perjalanan data dari proses pengujian sampai menjadi kesimpulan. Angka yang digunakan merupakan ilustrasi perhitungan dan nantinya diganti dengan hasil eksperimen sebenarnya. Pada setiap iterasi, k6 menyimpan waktu request, waktu respon, status, dan jumlah request selesai. Aplikasi menyimpan _correlation_id_ , status order, perubahan stok, pembayaran, event, retry, dan timestamp. Data CPU/RAM diambil secara berkala, kemudian script rekonsiliasi memeriksa fakta akhir pada seluruh database setelah _recovery window_ selesai. Seluruh data kemudian digabung berdasarkan kode kondisi, use case, beban, fault, seed, dan nomor iterasi yang sama. 

## **7.4.1 Data yang Dibentuk pada Setiap Iterasi** 

Satu iterasi tidak langsung dianggap sebagai ribuan sampel independen. Request di dalamnya terlebih dahulu diringkas menjadi satu baris hasil. Dengan demikian, 30 iterasi menghasilkan 30 baris untuk setiap kondisi pada kombinasi pengujian yang sama. 

|**Metrik**|**Cara Mengambil dan**<br>**Menghitung**|**Cara Membaca**|
|---|---|---|
|Latency p95/p99|Latency = waktu respon −<br>waktu request (_timestamp based_<br>masing2). Seluruh request<br>dalam satu iterasi diurutkan.<br>Posisi p95 = 0,95 × N dan p99<br>⌈<br>⌉<br>= 0,99 × N .<br>⌈<br>⌉|p95 = 230 ms berarti 95%<br>request selesai tidak lebih dari<br>230 ms.|
|Throughput dan error|RPS = request selesai ÷ durasi<br>measurement yang ditetapkan.<br>Error rate = request gagal ÷<br>seluruh request × 100%.|103 RPS berarti 103 request<br>selesai per detik. Nilainya<br>dibaca bersama error rate.|
|Lag dan recovery|Lag = waktu read model<br>diperbarui − waktu event dibuat<br>(write model). Recovery =<br>waktu seluruh invariant kembali<br>benar − waktu fault dihentikan.|Menunjukkan berapa lama data<br>berbeda sementara dan berapa<br>lama sistem pulih setelah fault<br>injection massal.|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 13 

|**Metrik**|**Cara Mengambil dan**<br>**Menghitung**|**Cara Membaca**|
|---|---|---|
|CPU dan RAM|Penggunaan CPU dan RAM<br>diambil datanya tiap 1 detik.<br>Dari seluruh data dalam 1<br>iterasi, dihitung Median (p50)<br>dan p95 (persentil ke-95).|Median adalah penggunaan<br>rata-rata saat kondisi normal<br>(_baseline_), sedangkan p95<br>merupakan batas penggunaan<br>saat kondisi puncak (_peak load_).<br>Nilai p95 = 85% berarti selama<br>95% waktu pengujian<br>pemakaian <=85.|
|Oversell/lost update|Script audit memeriksa stok<br>akhir = stok awal + masuk −<br>penjualan + kompensasi dan<br>stok tidak boleh negatif.|Satu transaksi yang lolos<br>melebihi stok sudah termasuk<br>pelanggaran.|
|Duplicate/mismatch|Mengirim pesan duplikat<br>dengan_correlation_id_yang<br>sama, lalu memeriksa basis data<br>setelah_recovery_untuk<br>memastikan status_order_,<br>pembayaran, stok, serta<br>_inbox/outbox_diproses tepat satu<br>kali.|Nilai Target = 0. Nilai 0 berarti<br>sistem berhasil menangani<br>duplikasi. Munculnya 1 saja<br>data ganda atau tidak cocok<br>membuat pengujian dianggap<br>gagal.|



## **7.4.2 Contoh Perhitungan Metrik** 

Sebagai contoh, misalnya satu iterasi berisi 1.000 request. Posisi p95 adalah 0,95 × 1.000 = 950. Jika⌈ ⌉ latency urutan ke-950 bernilai 230 ms, maka 95% request selesai paling lama 230 ms. Jika 30.900 request selesai selama 300 detik, throughput-nya adalah 30.900 ÷ 300 = 103 RPS. Apabila PaymentCompleted dibuat pada _timestamp_ 10:00:00.250 dan read model selesai diperbarui pada 10:00:00.440, lag yang dicatat adalah 190 ms. Jika worker kembali hidup pada 10:05:00.000 dan semua invariant baru benar pada 10:05:00.760, waktu recovery-nya adalah 760 ms. 

Untuk stok, misalnya saat ini tersedia 3 unit, kemudian ketika kasir dan kiosk bersamaan membeli masing-masing 2 unit. Hasil yang benar adalah satu transaksi berhasil, satu ditolak, dan stok akhir 1. Jika keduanya berhasil, perhitungan 3 − 2 − 2 menghasilkan −1 sehingga tercatat sebagai oversell. Pada pesan duplikat, pengiriman ulang _PaymentCompleted_ dengan _correlation_id_ yang sama juga harus tetap menghasilkan satu pencatatan pembayaran dan satu pemotongan stok saja. 

## **7.4.3 Contoh Perbandingan Tiga Kondisi** 

K6 menjalankan pola transaksi identik dengan seed dan dataset awal yang sama di Kondisi A, B, dan C. Nilai latency yang tercantum, seperti 180 ms, 205 ms, dan 230 ms, bukan waktu dari satu request, melainkan nilai p95 dari ribuan request yang diurutkan dalam satu iterasi. 

Tabel 5 iterasi ini hanya digunakan untuk mensimulasikan mekanisme perhitungan secara manual. Pengujian utama tetap menggunakan minimal 30 iterasi. 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 14 

|**Iterasi**|**A (ms)**|**B (ms)**|**C (ms)**|**Rank A**|**Rank B**|**Rank C**|
|---|---|---|---|---|---|---|
|1|180|205|230|1|2|3|
|2|190|215|238|1|2|3|
|3|175|220|225|1|2|3|
|4|200|198|235|2|1|3|
|5|185|210|228|1|2|3|
|Jumlah rank|–|–|–|6|9|15|



Friedman test merupakan uji nonparametrik baku untuk membandingkan tiga atau lebih kelompok berpasangan. Uji ini sesuai karena A, B, dan C pada nomor iterasi yang sama menerima blok pengujian yang sama. Pada setiap baris, latency terkecil diberi rank 1, berikutnya rank 2, dan terbesar rank 3. Rank dijumlahkan per kondisi sehingga diperoleh R_A = 6, R_B = 9, dan R_C = 15. 

|**Simbol**|**Arti**|**Nilai Ilustrasi**|
|---|---|---|
|n|Jumlah blok/iterasi berpasangan|5|
|k|Jumlah<br>kondisi<br>yang<br>dibandingkan|3|
|Rⱼ|Jumlah rank kondisi ke-j|R_A=6; R_B=9; R_C=15|
|ΣR ²ⱼ|Jumlah kuadrat seluruh total<br>rank|6²+9²+15²=342|



Rumus statistik Friedman yang digunakan adalah: 

2 _Q_ =<sup>12</sup> / _nk_ ( _k_ + 1) Σ _Rj_ − 3 _n_ ( _k_ + 1) 

Nilai pada tabel kemudian dimasukkan secara bertahap: 

_Q_ =<sup>12</sup> / 5 × 3(3 + 1) × 342 − 3 × 5(3 + 1) 

_Q_ =<sup>12</sup> / 60 × 342 − 60 = 68,4 − 60 = 8,4 

Derajat bebasnya adalah _df_ = _k_ − 1 = 2. Nilai _Q_ = 8,4 bersama _df_ = 2 dibaca pada distribusi chi-square atau dihitung dengan fungsi friedmanchisquare pada SciPy. Hasilnya adalah _p_ = _P_ (χ22 ≥ 8,4) ≈ 0,015. Nilai tersebut berarti bahwa jika A, B, dan C sebenarnya tidak berbeda, peluang memperoleh pola perbedaan setidaknya sebesar contoh ini hanya sekitar 1,5%. Karena 0,015 < 0,05, hipotesis tidak adanya perbedaan ditolak. Friedman baru membuktikan bahwa ada perbedaan, tetapi belum menunjukkan pasangan mana yang berbeda. 

Kekuatan perbedaannya dihitung menggunakan Kendall's W, yaitu ukuran effect size baku yang menyertai Friedman: 

_W_ =<sup>_Q_</sup> / _n_ ( _k_ − 1) =<sup>8,4</sup> / 5(3 − 1) =<sup>8,4</sup> / 10 = 0,84 

Nilai _W_ bergerak dari 0 sampai 1. Nilai 0,84 menunjukkan bahwa urutan perbedaan ketiga kondisi sangat konsisten pada iterasi ilustratif. Dalam konteks latency, nilai ini tidak otomatis berarti Kondisi C lebih baik. Kondisi C justru hampir selalu mendapat rank 3, sehingga _p_ 95-nya cenderung paling tinggi atau paling lambat. 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 15 

## **Contoh post-hoc Wilcoxon A–C** 

Wilcoxon signed-rank merupakan uji nonparametrik untuk membandingkan dua kelompok berpasangan. Contoh berikut memakai selisih C − A dari iterasi yang sama. Nilai absolut selisih diurutkan dari yang terkecil. Nilai yang sama memperoleh rank rata-rata. 

|**Iterasi**|**A**|**C**|**C−A**|**|C−A|**|**Rank**|**Tanda**|
|---|---|---|---|---|---|---|
|1|180|230|+50|50|4,5|+|
|2|190|238|+48|48|3|+|
|3|175|225|+50|50|4,5|+|
|4|200|235|+35|35|1|+|
|5|185|228|+43|43|2|+|



_W_<sup>+</sup> = 4,5 + 3 + 4,5 + 1 + 2 = 15; _W_<sup>−</sup> = 0 

Pada _N_ = 5 pasangan data ini, diperoleh _W_<sup>+</sup> = 15 dan _W_<sup>−</sup> = 0. _Exact two-sided p-value_ dihitung dari probabilitas kombinasi ekstrem dua arah, yaitu _p_ = 2 × (1/2)<sup>5</sup> = 2 × (1/32) = 0,0625. Meskipun nilai Kondisi C selalu lebih tinggi dibanding Kondisi A, sampel _N_ = 5 secara matematis belum bisa menembus batas signifikansi _α_ = 0,05 (nilai _p_ terkecil untuk _N_ = 5 adalah 0,0625). Hal inilah yang menjadi alasan eksperimen utama wajib menggunakan minimal 30 iterasi. 

Sementara itu, _effect size_ dihitung menggunakan _matched-pairs rank-biserial_ : 

_r_ =<sup>_W_+ −</sup><sup>_W_−</sup> / _W_ + + _W_ − =<sup>15 − 0</sup> / 15 + 0 = 1 

Nilai _r_ = 1 menunjukkan bahwa Kondisi C secara sempurna dan konsisten memiliki _latency_ lebih tinggi dibanding Kondisi A pada seluruh iterasi. Hasil _p_ -value dan _effect size_ resmi nantinya dihitung dari 30 iterasi eksperimen sebenarnya. 

## **Contoh koreksi Benjamini–Hochberg** 

Koreksi Benjamini–Hochberg merupakan prosedur untuk mengendalikan _false discovery rate_ ketika banyak pengujian dilakukan. Misalnya satu keluarga berisi 6 _p_ -value yang telah diurutkan. Setiap _p_ -value dibandingkan dengan batas ( _i_ / _m_ ) × _α_ , dengan _i_ sebagai nomor urut, _m_ = 6 sebagai jumlah pengujian, dan _α_ = 0,05 sebagai tingkat kesalahan yang ditetapkan sebelum eksperimen. 

|**i**|**p-value**|**Batas (i/6)×0,05**|**Keputusan Awal**|
|---|---|---|---|
|1|0,002|0,0083|Memenuhi|
|2|0,011|0,0167|Memenuhi|
|3|0,024|0,0250|Memenuhi|
|4|0,041|0,0333|Tidak|
|5|0,080|0,0417|Tidak|
|6|0,210|0,0500|Tidak|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 16 

P-value terbesar yang masih memenuhi batas berada pada urutan ketiga. Melalui prosedur step-up, 3 pengujian pertama dinyatakan signifikan. Dalam pelaksanaan sebenarnya, pengurutan dan adjusted p- value dihitung menggunakan multipletests(method='fdr_bh') pada Statsmodels agar prosesnya konsisten dan dapat direplikasi. 

## **7.4.4 Confidence Interval dan Metrik Bernilai Nol** 

Bootstrap BCa adalah metode yang digunakan untuk membentuk _confidence interval_ ketika distribusi statistik, seperti median atau p95/p99, sulit didekati dengan rumus normal. Pada UC-1 beban high dan fault F2, Kondisi C dijalankan 30 kali dan setiap iterasi menghasilkan satu p95. Data masukannya berbentuk C = [230, 238, 225, 235, 228, …] sampai N-ke 30. Komputer kemudian mengambil kembali 30 nilai secara acak dengan pengembalian. Dalam satu resample, nilai 230 dapat terambil dua kali sementara nilai lain tidak terambil. Median resample dihitung dan langkah ini diulang 10.000 kali sampai terbentuk 10.000 median bootstrap. 

BCa memperbaiki dua hal, yaitu bias hasil bootstrap melalui faktor z dan ketidaksimetrisan distribusi₀ melalui acceleration a yang diperoleh dari proses _jackknife_ . Rumus dan penghitungannya merupakan prosedur statistik baku yang dijalankan perangkat lunak. Penelitian tetap menyimpan seed bootstrap, 30 nilai input, jumlah resample, serta batas interval agar hasil dapat direplikasi. Misalnya median p95 Kondisi C adalah 232 ms dengan BCa 95% CI 226–239 ms. Artinya, ketidakpastian estimasi median p95 berada pada rentang tersebut. Rentang itu bukan berarti 95% request memiliki latency 226–239 ms. 

## **Contoh exact binomial pada oversell** 

Pada setiap iterasi UC-1 dibuat satu peluang konflik independen: Kasir dan kiosk bersamaan meminta masing-masing 2 unit ketika stok hanya 3. Setelah 30 iterasi tersedia _N_ = 30 peluang oversell. 

Jika Kondisi C menghasilkan _x_ = 0 kejadian, observasi 0/30 memenuhi syarat teknis pada lingkup pengujian, tetapi belum membuktikan risiko sebenarnya pasti nol seterusnya. Untuk nol kejadian, batas atas exact binomial satu sisi 95% dapat disederhanakan menjadi rumus baku berikut, dengan _α_ = 0,05: 

Batas Atas CI 95% = 1 − _α_<sup>1/</sup><sup>_N_</sup> 

Batas Atas CI 95% = 1 − 0,05<sup>1/30</sup> ≈ 1 − 0,905 = 0,095 (9,5%) 

Nilai 9,5% bukan perkiraan bahwa oversell pasti terjadi sebesar 9,5%. Angka tersebut merupakan batas atas risiko yang masih sesuai dengan hasil nol kejadian pada 30 peluang dan tingkat keyakinan 95%. Denominator ditentukan sebelum eksperimen. 

Ribuan event dalam satu iterasi tidak boleh langsung dianggap independen hanya untuk memperkecil interval. Interval ini dipakai untuk menjelaskan kekuatan bukti, sedangkan aturan kelulusan tetap berpegang pada kondisi oversell, lost update, duplicate effect, atau permanent mismatch yang membuat Kondisi C gagal pada metrik terkait. 

## **7.4.5 Bentuk Pembacaan Hasil Akhir** 

Contoh kesimpulan akhirnya, Friedman test menunjukkan perbedaan latency p95 antara Kondisi A, B, dan C pada UC-1 beban high dengan fault F2 (p < 0,05; Kendall's W = 0,71). Wilcoxon setelah koreksi Benjamini–Hochberg menunjukkan Kondisi C memiliki latency lebih tinggi daripada Kondisi A dengan effect size besar. Pada saat yang sama, Kondisi C mencatat nol oversell, nol duplicate effect, dan nol permanent mismatch. Dengan demikian, proteksi konsistensi pada Kondisi C bekerja sesuai sasaran, tetapi menimbulkan tambahan latency yang perlu dilaporkan sebagai trade-off. Kesimpulan performa 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 17 

tetap dibatasi pada konfigurasi sistem yang diuji karena Kondisi A dan C menggunakan runtime dan struktur arsitektur yang berbeda. 

## **8. Metrik, Fault Injection, dan Aturan Keputusan** 

|**Tujuan**|**Metrik/Defnisi**|**Syarat Utama Kondisi C**|
|---|---|---|
|Kebenaran stok|**_Oversell count_**: Jumlah<br>transaksi yang lolos saat stok<br>kosong.<br>**_Stock conservation_**: Selisih<br>antara hitungan sistem dan fsik<br>barang.|**Mutlak 0**. Tidak boleh ada<br>penjualan melebihi stok atau<br>selisih fsik pada seluruh<br>pengujian.|
|Kecocokan lintas service|**_Permanent mismatch_**: Jumlah<br>data akhir yang tidak sinkron<br>atau menggantung antar-<br>layanan setelah masa pemulihan<br>error selesai.|**Mutlak 0**. seluruh data fnal di<br>semua layanan wajib sinkron<br>tanpa ada yang macet.|
|Efek tunggal|**_Duplicate efect rate_**: Jumlah<br>aksi ganda_(_seperti potong stok<br>2x atau bayar 2x_)_akibat sistem<br>mengirim ulang pesan yang<br>sama.|**Mutlak 0**. Setiap transaksi<br>hanya berdampak satu kali ke<br>data meskipun pesan terkirim<br>berulang.|
|Pemulihan Saga|**_Terminal-state coverage_**:<br>Persentase transaksi<br>terdistribusi yang berhasil<br>tuntas sampai akhir, baik sukses<br>maupun batal otomatis.|**Wajib 100%**. Seluruh alur<br>transaksi harus selesai dan tidak<br>boleh ada status yang<br>menggantung di tengah jalan.|
|Event tidak hilang|**_Committed event processing_**:<br>Jumlah pesan transaksi yang<br>sempat tertahan akibat sistem<br>mati, tetapi akhirnya sukses<br>diproses ulang saat sistem<br>hidup.|**Wajib 100%**. Tidak boleh ada<br>data antrean pesan yang hilang<br>setelah proses pemulihan aktif.|
|Konvergensi|**_Read model lag_**: Durasi waktu<br>yang dibutuhkan sejak tombol<br>bayar ditekan hingga data<br>terbaru muncul secara akurat di<br>layar_dashboard_.|**Sesuai SLA**. Nilai kecepatan<br>_p95_wajib berada di bawah<br>ambang batas waktu yang<br>dikunci sejak tahap awal.|
|Biaya|**_Resource & Speed_**: Kecepatan<br>respon_(latency)_, kapasitas<br>tampung beban_(RPS)_, tingkat<br>error, serta beban memori|**Dilaporkan sebagai****_trade-of_**.<br>Dinilai sebagai konsekuensi<br>yang harus ditanggung dari<br>beban validasi ekstra arsitektur|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 18 

|**Tujuan**|**Metrik/Defnisi**|**Syarat Utama Kondisi C**|
|---|---|---|
||CPU/RAM.|target, bukan syarat mutlak.|



## **8.1 Fault yang Disuntikkan** 

|**Kode**|**Gangguan**|**Bukti yang Dicari**|
|---|---|---|
|F1|**_Crash_ Layanan**: Mematikan<br>paksa service atau worker tepat<br>setelah data pesanan disimpan,<br>namun sebelum pesan<br>disebarkan ke antrean.|**Pesan Tetap Terkirim**: Sistem<br>otomatis mengirim ulang pesan<br>yang tertahan begitu service<br>dinyalakan kembali.|
|F2|**Duplikasi Pesan**: Sengaja<br>memicu pengiriman pesan<br>transaksi yang sama lebih dari<br>satu kali ke dalam antrean<br>akibat gangguan jaringan.|**Pencegahan Data Ganda**:<br>Mekanisme_Inbox_berhasil<br>menyaring pesan tersebut<br>sehingga tidak ada aksi duplikat<br>pada data.|
|F3|**Gagal Proses Tengah Jalan**:<br>Memutus aliran data secara<br>mendadak saat layanan<br>penerima (_consumer_) sedang<br>memproses transaksi.|**Antrean Cadangan Berfungsi**:<br>Proses_retry_berjalan otomatis,<br>dan jika tetap gagal, pesan<br>diamankan ke DLQ agar dapat<br>diputar ulang.|
|F4|**Pesan Terlambat**:<br>Menyuntikkan hambatan waktu<br>pengiriman atau keterlambatan<br>laporan pembayaran dari<br>payment gateway (_webhook_).|**Pembatalan Transaksi Aman**:<br>Mekanisme Saga sukses<br>membatalkan order, dan laporan<br>yang terlambat tidak merusak<br>status akhir.|
|F5|**Bentrokan Data Bersamaan**:<br>Mengirimkan 2 permintaan<br>pembaruan stok barang di<br>waktu bersamaan menggunakan<br>basis versi yang sama.|**Perlindungan****_Lost Update_**:<br>Hanya satu transaksi yang<br>berhasil mengubah data,<br>sementara transaksi yang kalah<br>otomatis ditolak/diulang.|



## **8.2 Justifikasi Pencapaian Tujuan** 

● **Keamanan Data Adalah Poin Utama** : Arsitektur baru _(Kondisi C)_ tidak dapat dinyatakan lulus hanya karena memiliki kecepatan respon _(latency)_ yang bagus. Sistem baru dianggap berhasil jika aturan baku keselamatan data, kelancaran proses pemulihan error, dan batas waktu penyelarasan data terpenuhi secara bersamaan. 

- **Toleransi Error Teknis** : Munculnya bentrokan data _(OCC conflict)_ atau adanya pengiriman ulang pesan _(retry)_ bukanlah sebuah kegagalan, melainkan bukti bahwa sistem bekerja menjaga akurasi saldo akhir barang. 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 19 

- **Batasan Kegagalan Mutlak** : Sebaliknya, jika ditemukan satu saja kasus penjualan melebihi stok _(oversell)_ , pencatatan pembayaran ganda, atau ada pesan alur transaksi _(Saga)_ yang hilang tanpa jejak, maka arsitektur tersebut langsung dinyatakan gagal total meskipun rata-rata performa kecepatannya sangat tinggi. 

- **Penguncian Ambang Batas** : Batas toleransi jeda waktu pembaruan data _(convergence lag)_ akan 

ditentukan terlebih dahulu melalui tahapan uji coba awal _(pilot test)_ sesuai kebutuhan tampilan dashboard. Angka ini wajib dikunci rapat sebelum eksperimen utama dimulai demi menjaga integritas penelitian agar standar kelulusan tidak diubah secara sepihak setelah hasil data keluar. 

## **9. Ancaman Validitas, Kelayakan, dan Jawaban Inti** 

|**Ancaman**|**Mitigasi**|
|---|---|
|**Perbedaan framework:**Adanya bias performa<br>karena membandingkan basis sistem Laravel<br>_(baseline)_dengan Node.js_(target)_.|**Fokus Keselamatan Data**: Kesimpulan utama<br>eksperimen didasarkan pada kebenaran data<br>(_invariant_/_recovery_), sedangkan selisih performa<br>kecepatan diberi catatan_confounding_(faktor<br>perancu).|
|**Workload tidak ekuivalen:**Beban pengujian<br>yang diberikan antar-kondisi tidak adil atau tidak<br>seimbang.|**Standardisasi Beban**: Wajib menggunakan<br>_dataset_, aturan bisnis, distribusi request, dan<br>target luaran yang sama persis. Fitur yang tidak<br>tersedia akan ditandai.|
|**Clock drift:**Selisih atau ketidakakuratan catatan<br>waktu antar-komputer saat menghitung durasi<br>proses.|**Sinkronisasi Waktu**: Pengukuran jeda waktu<br>diambil dalam satu lingkungan eksperimen yang<br>sama, serta memanfaatkan ftur_monotonic clock_<br>jika tersedia.|
|**Cache, GC, dan urutan run:**Gangguan memori<br>otomatis (_garbage collection_) atau sisa data lama<br>yang membuat hasil tes tidak stabil.|**Sterilisasi Sistem**: Melakukan warming-up,<br>menjadwalkan restart sistem secara berkala,<br>mengacak urutan pengujian, serta mencatat<br>performa_runtime_.|
|**Fault tidak reproducible:**Gangguan error yang<br>disuntikkan bersifat acak sehingga sulit diulang<br>untuk pembuktian.|**Eror Deterministik**: Menggunakan pemicu<br>(_trigger_) dan titik henti paksa (_crash_), serta<br>menyimpan data seed, konfgurasi, dan catatan<br>log secara utuh.|
|**Bias ambang keberhasilan:**Standar kelulusan<br>pengujian diubah di tengah jalan agar arsitektur<br>target terlihat selalu berhasil.|**Penguncian Standar**: Parameter baku data<br>(_invariant_) dan batas toleransi waktu (SLA)<br>wajib ditetapkan dan dikunci sebelum<br>eksperimen utama dimulai.|
|**Generalisasi terbatas**|Klaim dibatasi pada Apotek Bisma dan pola<br>transaksi yang diuji.|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 20 

## **9.1 Kelayakan Implementasi** 

   - **Monorepo & Shared Utility:** Keempat service dikembangkan dalam satu _repository_ (Turborepo) supaya kontrak data ( _event_ ) dan fungsi utilitas dapat dipakai bersama tanpa pusing sinkronisasi. 

   - **Infrastruktur Ringan:** Menggunakan Docker Compose alih-alih Kubernetes untuk menghemat _resource_ laptop saat pengujian, namun tetap menjamin lingkungan _deployment_ yang konsisten. 

   - **Cakupan Fokus:** Implementasi hanya dibatasi pada 4 use case utama dan 3 mekanisme konsistensi data. _Dashboard observability_ hanya alat bantu ukur, bukan kontribusi inti skripsi. 

   - **Tahapan Kerja Jelas:** Pengujian dimulai dari membuat baseline data normal, disusul uji Kondisi B (simulasi kegagalan), lalu ditutup dengan penerapan proteksi di Kondisi C. 

- Validasi Pengguna: User testing bersama admin, petugas gudang, dan petugas apotek untuk memastikan fungsi serta alur sistem sesuai dengan pekerjaan nyata di Apotek Bisma. 

## **9.2 Rangkuman** 

|**Pertanyaan**|**Jawaban**|
|---|---|
|Mengapa baseline (Kondisi A) tidak disamakan<br>stack-nya?|Karena Kondisi A mewakili sistem monolitik<br>Laravel lama yang benar-benar berjalan saat ini.<br>Menyamakan stack justru menghilangkan jejak<br>migrasi aslinya. Efek perbedaan_framework_<br>sudah dibatasi dan diisolasi dalam klaim<br>penelitian.|
|Apa bedanya EDA ini dengan penelitian WMS<br>sebelumnya?|Penelitian ini berfokus pada arsitektur_Database_<br>_per Service_, menangani masalah transaksi lintas<br>domain, pembayaran asinkron, serta<br>mengevaluasi_fault/recovery_menggunakan<br>kombinasi Outbox + OCC + Saga.|
|Kapan data di sistem asinkron ini dianggap<br>konsisten?|Bukan saat data berhenti berubah, tetapi ketika<br>setiap status perubahan terbukti sah, aturan bisnis<br>terpenuhi, dan semua service akhirnya mencapai<br>kesepakatan fakta akhir yang sama (_eventual_<br>_consistency_).|
|Apa bukti kalau tujuan penelitian ini tercapai?|Dibuktikan dengan nol pelanggaran keselamatan<br>(_zero mismatch_), mekanisme recovery berjalan<br>lengkap, konvergensi data memenuhi target SLA,<br>dan metrik overhead performa dilaporkan secara<br>transparan.|
|Mengapa memilih topik ini dan jadikan Apotek<br>Bisma sebagai use case real nya?|Karena integrasi sistem Kiosk, POS, dan<br>pembayaran digital dapat memicu masalah<br>konkurensi tinggi pada aplikasi Apotek Bisma<br>yang saat ini sedang dimigrasikan.|



Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 21 

## **10. Rencana Pelaksanaan dan Luaran** 

|**Minggu**|**Fokus Kegiatan**|**Output**|
|---|---|---|
|1–2|**Fondasi dan Data**|Audit sistem lama, fnalisasi<br>data, kontrak API, serta<br>penentuan_test oracle_.|
|3–5|**Arsitektur Dasar**|Implementasi 4 service,<br>arsitektur_database per service_,<br>broker pesan RabbitMQ, dan<br>simulasi kegagalan pada<br>Kondisi B.|
|6–9|**Mekanisme Proteksi**|Penerapan pola Outbox,<br>_Optimistic Concurrency_<br>_Control_, Saga Orchestrator,<br>logika kompensasi,_retry_, serta<br>antrean pesan gagal.|
|10–11|**Integrasi dan Tracing**|Uji coba 4 kasus penggunaan<br>utama, pemasangan pelacakan<br>metrik sistem, dan pembuatan<br>skrip rekonsiliasi data.|
|12|**Uji Coba Awal**|Uji coba skala kecil,<br>penguncian beban kerja target,<br>penentuan batas waktu<br>pemulihan sistem, serta titik<br>injeksi kesalahan.|
|13–14|**Eksperimen Utama**|Eksekusi pengujian utama,<br>replikasi data ke berbagai<br>skenario, dan pengumpulan<br>seluruh data mentah hasil uji.|
|15–16|**Analisis dan Laporan**|Analisis statistik hasil<br>eksperimen, pembahasan<br>kelebihan dan kekurangan<br>arsitektur, serta penyusunan bab<br>akhir dokumen.|



## **10.1 Luaran** 

- **Artefak Perangkat Lunak:** Implementasi penuh sistem dalam 3 kondisi uji yang siap dijalankan ulang menggunakan Docker Compose untuk kebutuhan replikasi riset. 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 22 

- **Dataset Eksperimen dan Pengujian:** Paket data pengujian lengkap yang mencakup skrip pengujian workload k6, fault injection tools, test oracle, serta script rekonsiliasi otomatis lintas database. 

- **Laporan Evaluasi:** Tabel konsistensi data, durasi pemulihan, penggunaan resources server, hasil user testing, serta rekomendasi migrasi yang mempertimbangkan kebutuhan operasional Apotek Bisma. 

## **Daftar Pustaka** 

Fowler, M. (2002). _Patterns of enterprise application architecture_ . Addison-Wesley. 

Kleppmann, M. (2017). _Designing data-intensive applications: The big ideas behind reliable, scalable, and maintainable systems_ . O'Reilly Media. 

Midtrans. (t.t.). _Midtrans documentation: HTTP notification (webhook), signature verification, dan transaction status_ . Diakses 15 Juli 2026, dari https://docs.midtrans.com 

Oracle Corporation. (t.t.). _MySQL 8.0 reference manual: InnoDB locking and locking reads_ . Diakses 15 - Juli 2026, dari https://dev.mysql.com/doc/refman/8.0/en/innodb <u>locking.html</u> 

Peffers, K., Tuunanen, T., Rothenberger, M. A., & Chatterjee, S. (2007). A design science research methodology for information systems research. _Journal of Management Information Systems_ , _24_ (3), 45 –77. https://doi.org/10.2753/MIS0742-1222240302 

RabbitMQ / Broadcom Inc. (t.t.). _RabbitMQ documentation: Consumer acknowledgements and publisher confirms; Reliability guide; Dead letter exchanges_ . Diakses 15 Juli 2026, dari <u>https://www.rabbitmq.com/docs</u> 

Richardson, C. (2018). _Microservices patterns: With examples in Java_ . Manning Publications. 

Richardson, C. (t.t.-a). _Pattern: Transactional outbox_ . Microservices.io. Diakses 15 Juli 2026, dari <u>https://microservices.io/patterns/data/transactional-outbox.html</u> 

Richardson, C. (t.t.-b). _Pattern: Saga_ . Microservices.io. Diakses 15 Juli 2026, dari <u>https://microservices.io/patterns/data/saga.html</u> 

Richardson, C. (t.t.-c). _Pattern: Idempotent consumer_ . Microservices.io. Diakses 15 Juli 2026, dari <u>https://microservices.io/patterns/communication-style/idempotent-consumer.html</u> 

Rochman, C. B. A., & Suartana, I. M. (2026). Pengembangan sistem manajemen gudang berbasis web dengan event-driven architecture. _Journal of Informatics and Computer Science (JINACS)_ , _7_ (4), 1044– 1048. 

Grafana Labs. (t.t.). _k6 documentation: Scenarios, thresholds, checks, and test lifecycle_ . Diakses 15 Juli 2026, dari https://k6.io/docs 

Draft Konsep Topik Skripsi_Raihan Rizki Alfareza 23 

