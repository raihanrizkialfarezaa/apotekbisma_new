# ANALISIS KARTU STOK PRODUK 48

## Informasi Produk
- **Nama**: ANDALAN
- **Stok di DB**: -2

## 15 Rekaman Terakhir (Descending)

| ID | Waktu | Awal | Masuk | Keluar | Sisa | JualID | BeliID |
|---|---|---|---|---|---|---|---|
| 178159 | 2026-01-14 15:58:49 | 0 | 0 | 2 | -2 | 1097 | - |
| 178105 | 2026-01-14 15:58:49 | -2 | 30 | 0 | 28 | - | 445 |
| 178018 | 2026-01-13 07:00:00 | 1 | 0 | 1 | 0 | 1094 | - |
| 177978 | 2026-01-13 07:00:00 | 3 | 0 | 2 | 1 | 1093 | - |
| 177733 | 2026-01-13 07:00:00 | 4 | 0 | 1 | 3 | 1085 | - |
| 177605 | 2026-01-13 07:00:00 | 6 | 0 | 2 | 4 | 1081 | - |
| 177568 | 2026-01-12 07:00:00 | 7 | 0 | 1 | 6 | 1080 | - |
| 177461 | 2026-01-12 07:00:00 | 9 | 0 | 2 | 7 | 1077 | - |
| 177364 | 2026-01-12 07:00:00 | 10 | 0 | 1 | 9 | 1074 | - |
| 177215 | 2026-01-10 07:00:00 | 12 | 0 | 2 | 10 | 1070 | - |
| 177085 | 2026-01-10 07:00:00 | 13 | 0 | 1 | 12 | 1066 | - |
| 177035 | 2026-01-10 07:00:00 | 14 | 0 | 1 | 13 | 1064 | - |
| 176117 | 2026-01-03 01:28:43 | 15 | 0 | 1 | 14 | 1062 | - |
| 176116 | 2026-01-02 19:30:44 | 16 | 0 | 1 | 15 | 1059 | - |
| 176115 | 2026-01-02 18:53:14 | 19 | 0 | 3 | 16 | 1057 | - |

## Duplikat Penjualan

Total duplikat: 19 penjualan

| ID Penjualan | Jumlah Record |
|---|---|
| 23 | 2 |
| 24 | 2 |
| 17 | 2 |
| 72 | 2 |
| 89 | 3 |
| 113 | 2 |
| 128 | 2 |
| 131 | 4 |
| 231 | 2 |
| 232 | 2 |
| 268 | 2 |
| 374 | 2 |
| 381 | 2 |
| 402 | 2 |
| 735 | 2 |
| 833 | 2 |
| 897 | 2 |
| 970 | 2 |
| 979 | 2 |

**Total record duplikat yang berlebih**: 22

## Analisis Konsistensi

Total rekaman: 255

- **Stok akhir dari kalkulasi rekaman**: 28
- **Stok di tabel produk**: -2
- **Selisih**: 30

## Detail Masalah Data Tampilan

Dari gambar yang diberikan user, terlihat:

1. No.3 tanggal 13 Jan 2026 stok keluar 2, stok akhir **4**
2. No.4 tanggal 13 Jan 2026 stok keluar 1, stok akhir **3**
3. No.2 tanggal 14 Jan 2026 stok keluar 2, stok akhir **-2** (aneh!)
4. No.1 tanggal 14 Jan 2026 stok masuk 30, stok akhir **28**

### Mengapa hal ini terjadi:

Tampilan menunjukkan urutan **DESCENDING** (terbaru di atas), tapi **stok_sisa (stok akhir)** dihitung berdasarkan urutan **ASCENDING** (dari transaksi pertama).

Mari verifikasi urutan ascending:

| No (tampilan) | Waktu | Masuk | Keluar | Stok Akhir |
|---|---|---|---|---|
| 1 | 2026-01-14 15:58:49 | - | 2 | -2 |
| 2 | 2026-01-14 15:58:49 | 30 | - | 28 |
| 3 | 2026-01-13 07:00:00 | - | 1 | 0 |
| 4 | 2026-01-13 07:00:00 | - | 2 | 1 |
| 5 | 2026-01-13 07:00:00 | - | 1 | 3 |
| 6 | 2026-01-13 07:00:00 | - | 2 | 4 |
| 7 | 2026-01-12 07:00:00 | - | 1 | 6 |
| 8 | 2026-01-12 07:00:00 | - | 2 | 7 |
