# Audit Produk Minus Pasca Baseline

Tanggal analisis: 2026-03-12 01:03:35
Cutoff baseline: 2025-12-31 23:59:59
Until event: 2026-03-12 01:02:44
Source of truth awal: C:\laragon\www\apotekbisma\apotekbisma\REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv
Delimiter CSV terdeteksi: ;
Cakupan: hanya produk yang match antara database dan CSV baseline.

## Ringkasan

- Total produk dengan rantai stok sempat minus: 34
- Produk shortage total: 18
- Produk minus sementara: 16
- Catatan penting: pembelian invalid terdeteksi 0 pada cohort ini; pola dominan adalah pembelian belum tercatat, terlambat dicatat, atau total supply memang kalah dari penjualan.

Arti kolom:
- Beli Valid Qty: total pembelian yang lolos filter rebuild.
- Jual Qty: total penjualan valid pasca baseline.
- +Manual / -Manual: penyesuaian manual non-synthetic pasca baseline.
- Gap Final: baseline + pembelian valid + manual masuk - penjualan - manual keluar. Nilai negatif berarti total supply masih kurang.
- Sold < Beli1: total penjualan yang terjadi sebelum pembelian valid pertama tercatat.

## Prioritas Tinggi: Shortage Total

| No | ID Produk | Nama Produk | Baseline | Beli Valid Rows | Beli Valid Qty | Invalid Rows | Jual Rows | Jual Qty | +Manual | -Manual | Gap Final | Neg Event | Minus Pertama | Beli 1 | Beli Terakhir | Sold < Beli1 | Fokus Audit |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 115 | B1 STRIP | 204 | 0 | 0 | 0 | 13 | 126 | 186 | 584 | -320 | 10 | 2026-01-22 12:41:18 | - | - | 126 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 2 | 63 | ASAM MEFENAMAT 500mg | 380 | 1 | 1000 | 0 | 115 | 1640 | 0 | 0 | -260 | 61 | 2026-01-14 07:00:00 | 2026-02-10 07:00:00 | 2026-02-10 07:00:00 | 960 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 3 | 860 | VOLTADEX | 30 | 2 | 800 | 0 | 69 | 1000 | 0 | 0 | -170 | 66 | 2026-01-02 07:00:00 | 2026-01-31 07:00:00 | 2026-02-24 07:00:00 | 470 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 4 | 293 | FOLAVIT | 30 | 1 | 100 | 0 | 13 | 170 | 0 | 0 | -40 | 4 | 2026-01-17 07:00:00 | 2026-01-26 07:00:00 | 2026-01-26 07:00:00 | 60 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 5 | 358 | HUFAGRIP KUNING | 4 | 1 | 6 | 0 | 18 | 18 | 0 | 0 | -8 | 13 | 2026-01-22 07:00:00 | 2026-02-14 07:00:00 | 2026-02-14 07:00:00 | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 6 | 410 | KONIDIN OBH | 1 | 0 | 0 | 0 | 2 | 8 | 0 | 0 | -7 | 2 | 2026-01-06 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 7 | 843 | VICKS VAP 10g | 4 | 1 | 12 | 0 | 22 | 23 | 0 | 0 | -7 | 16 | 2026-01-22 07:00:00 | 2026-02-24 07:00:00 | 2026-02-24 07:00:00 | 14 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 8 | 28 | XYLERGY | 2 | 0 | 0 | 0 | 7 | 8 | 0 | 0 | -6 | 6 | 2026-01-29 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 9 | 276 | FEMINAX | 4 | 0 | 0 | 0 | 8 | 10 | 0 | 0 | -6 | 5 | 2026-02-05 07:00:00 | - | - | 10 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 10 | 42 | ANAKONIDIN 60mL | 1 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -5 | 5 | 2026-01-15 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 11 | 237 | EM KAPSUL | 1 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -5 | 5 | 2026-02-07 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 12 | 998 | HOT IN DCL 60GR | 3 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -5 | 5 | 2026-02-12 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 13 | 64 | ASEPSO ALL VAR | 6 | 1 | 6 | 0 | 11 | 16 | 0 | 0 | -4 | 2 | 2026-03-04 07:00:00 | 2026-01-26 07:00:00 | 2026-01-26 07:00:00 | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 14 | 40 | ANACETIN | 1 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -3 | 3 | 2026-02-05 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 15 | 356 | HOT IN 60GR ALL VAR | 2 | 3 | 18 | 0 | 23 | 23 | 0 | 0 | -3 | 22 | 2026-01-12 07:00:00 | 2026-01-26 07:00:00 | 2026-02-26 07:00:00 | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 16 | 298 | FUNGIDREM K | 2 | 1 | 3 | 0 | 7 | 7 | 0 | 0 | -2 | 5 | 2026-02-04 07:00:00 | 2026-02-26 07:00:00 | 2026-02-26 07:00:00 | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 17 | 782 | SUTRA OK 3'S | 2 | 1 | 6 | 0 | 9 | 10 | 0 | 0 | -2 | 8 | 2026-02-07 07:00:00 | 2026-03-04 07:00:00 | 2026-03-04 07:00:00 | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 18 | 135 | CALLUSOL | 1 | 1 | 3 | 0 | 5 | 5 | 0 | 0 | -1 | 4 | 2026-01-20 07:00:00 | 2026-02-19 07:00:00 | 2026-02-19 07:00:00 | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |

## Prioritas Sedang: Minus Sementara

| No | ID Produk | Nama Produk | Baseline | Beli Valid Rows | Beli Valid Qty | Invalid Rows | Jual Rows | Jual Qty | Gap Final | Neg Event | Minus Pertama | Beli 1 | Beli Terakhir | Sold < Beli1 | Fokus Audit |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 23 | ALLOPURINOL 100mg | 310 | 2 | 1300 | 0 | 111 | 1610 | 0 | 58 | 2026-01-10 07:00:00 | 2026-02-05 07:00:00 | 2026-02-10 07:00:00 | 1030 | Cek urutan atau tanggal input pembelian versus penjualan |
| 2 | 41 | ANAKONIDIN 30mL | 4 | 1 | 3 | 0 | 7 | 7 | 0 | 3 | 2026-02-18 07:00:00 | 2026-03-04 07:00:00 | 2026-03-04 07:00:00 | 7 | Cek urutan atau tanggal input pembelian versus penjualan |
| 3 | 175 | INSTO COOL | 2 | 4 | 21 | 0 | 23 | 23 | 0 | 7 | 2026-01-03 07:00:00 | 2026-01-06 07:00:00 | 2026-02-19 07:00:00 | 4 | Cek urutan atau tanggal input pembelian versus penjualan |
| 4 | 349 | HEROCYN 150mg | 1 | 1 | 3 | 0 | 4 | 4 | 0 | 2 | 2026-02-27 07:00:00 | 2026-03-04 07:00:00 | 2026-03-04 07:00:00 | 3 | Cek urutan atau tanggal input pembelian versus penjualan |
| 5 | 473 | M.TAWON CC | 3 | 2 | 24 | 0 | 26 | 27 | 0 | 16 | 2026-01-07 07:00:00 | 2026-01-27 07:00:00 | 2026-02-28 07:00:00 | 10 | Cek urutan atau tanggal input pembelian versus penjualan |
| 6 | 778 | SUPERTETRA | 14 | 3 | 60 | 0 | 56 | 74 | 0 | 20 | 2026-01-19 07:00:00 | 2026-01-24 07:00:00 | 2026-03-07 07:00:00 | 22 | Cek urutan atau tanggal input pembelian versus penjualan |
| 7 | 994 | AMOXICILIN 500mg HJ | 140 | 3 | 1600 | 0 | 127 | 1740 | 0 | 99 | 2026-01-05 07:00:00 | 2026-01-17 07:00:00 | 2026-03-07 07:00:00 | 540 | Cek urutan atau tanggal input pembelian versus penjualan |
| 8 | 386 | KAKAK TUA | 1 | 2 | 6 | 0 | 6 | 6 | 1 | 5 | 2026-01-29 07:00:00 | 2026-02-10 07:00:00 | 2026-03-04 07:00:00 | 4 | Cek urutan atau tanggal input pembelian versus penjualan |
| 9 | 842 | VICKS F 44 27ml | 4 | 1 | 6 | 0 | 9 | 9 | 1 | 1 | 2026-02-11 07:00:00 | 2026-02-24 07:00:00 | 2026-02-24 07:00:00 | 5 | Cek urutan atau tanggal input pembelian versus penjualan |
| 10 | 999 | HOT IN DCL 120 GR | 2 | 1 | 6 | 0 | 7 | 7 | 1 | 4 | 2026-01-24 07:00:00 | 2026-02-26 07:00:00 | 2026-02-26 07:00:00 | 6 | Cek urutan atau tanggal input pembelian versus penjualan |
| 11 | 727 | SANMOL DROP | 2 | 2 | 12 | 0 | 12 | 12 | 2 | 1 | 2026-01-09 07:00:00 | 2026-01-10 07:00:00 | 2026-02-10 07:00:00 | 3 | Cek urutan atau tanggal input pembelian versus penjualan |
| 12 | 796 | TERA F | 110 | 1 | 200 | 0 | 25 | 300 | 10 | 2 | 2026-02-05 07:00:00 | 2026-02-10 07:00:00 | 2026-02-10 07:00:00 | 150 | Cek urutan atau tanggal input pembelian versus penjualan |
| 13 | 816 | OM TEST | 30 | 2 | 100 | 0 | 59 | 117 | 13 | 20 | 2026-02-07 07:00:00 | 2026-02-10 07:00:00 | 2026-03-04 07:00:00 | 49 | Cek urutan atau tanggal input pembelian versus penjualan |
| 14 | 323 | GLUCOSAMIN MPL | 10 | 3 | 300 | 0 | 27 | 290 | 20 | 17 | 2026-01-06 07:00:00 | 2026-01-27 07:00:00 | 2026-03-07 07:00:00 | 90 | Cek urutan atau tanggal input pembelian versus penjualan |
| 15 | 676 | PROMAG TAB | 20 | 4 | 192 | 0 | 127 | 192 | 20 | 36 | 2026-01-13 07:00:00 | 2026-01-20 07:00:00 | 2026-03-04 07:00:00 | 34 | Cek urutan atau tanggal input pembelian versus penjualan |
| 16 | 25 | ALPARA | 160 | 2 | 450 | 0 | 42 | 520 | 90 | 7 | 2026-02-05 07:00:00 | 2026-02-10 07:00:00 | 2026-03-04 07:00:00 | 260 | Cek urutan atau tanggal input pembelian versus penjualan |
