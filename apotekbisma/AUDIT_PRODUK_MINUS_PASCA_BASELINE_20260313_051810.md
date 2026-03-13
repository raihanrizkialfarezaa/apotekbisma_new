# Audit Produk Minus Pasca Baseline

Tanggal analisis: 2026-03-13 05:19:01
Cutoff baseline: 2025-12-31 23:59:59
Until event: 2026-03-13 05:18:10
Source of truth awal: C:\laragon\www\apotekbisma\apotekbisma\REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv
Delimiter CSV terdeteksi: ;
Cakupan: hanya produk yang match antara database dan CSV baseline.

## Ringkasan

- Total produk dengan rantai stok sempat minus: 315
- Produk shortage total: 315
- Produk minus sementara: 0
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
| 1 | 994 | AMOXICILIN 500mg HJ | 140 | 0 | 0 | 0 | 129 | 1770 | 0 | 0 | -1630 | 120 | 2026-01-05 07:00:00 | - | - | 1770 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 2 | 755 | PIROXICAM 20MG | 210 | 0 | 0 | 0 | 120 | 1700 | 0 | 101 | -1591 | 106 | 2026-01-10 07:00:00 | - | - | 1700 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 3 | 214 | DEXTEEM PLUS | 350 | 0 | 0 | 0 | 110 | 1880 | 0 | 0 | -1530 | 90 | 2026-01-14 07:00:00 | - | - | 1880 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 4 | 23 | ALLOPURINOL 100mg | 310 | 0 | 0 | 0 | 111 | 1610 | 0 | 0 | -1300 | 89 | 2026-01-10 07:00:00 | - | - | 1610 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 5 | 63 | ASAM MEFENAMAT 500mg | 380 | 0 | 0 | 0 | 115 | 1640 | 0 | 0 | -1260 | 91 | 2026-01-14 07:00:00 | - | - | 1640 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 6 | 204 | DEMACOLIN TAB | 450 | 0 | 0 | 0 | 126 | 1710 | 0 | 0 | -1260 | 97 | 2026-01-19 07:00:00 | - | - | 1710 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 7 | 388 | KALMETASON | 240 | 0 | 0 | 0 | 90 | 1410 | 0 | 0 | -1170 | 75 | 2026-01-13 07:00:00 | - | - | 1410 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 8 | 860 | VOLTADEX | 30 | 0 | 0 | 0 | 69 | 1000 | 0 | 0 | -970 | 67 | 2026-01-02 07:00:00 | - | - | 1000 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 9 | 161 | CETIRIZIN | 520 | 0 | 0 | 0 | 97 | 1360 | 0 | 0 | -840 | 58 | 2026-01-30 07:00:00 | - | - | 1360 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 10 | 209 | DEXAMETHASONE 0,5 | 280 | 0 | 0 | 0 | 83 | 1050 | 0 | 0 | -770 | 61 | 2026-01-21 07:00:00 | - | - | 1050 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 11 | 210 | DEXAMETHASONE 0,75 | 210 | 0 | 0 | 0 | 61 | 900 | 0 | 0 | -690 | 47 | 2026-01-19 07:00:00 | - | - | 900 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 12 | 537 | MOLACORT 0,75 | 70 | 0 | 0 | 0 | 44 | 640 | 0 | 0 | -570 | 39 | 2026-01-16 07:00:00 | - | - | 640 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 13 | 33 | AMLODIPIN 10mg | 480 | 0 | 0 | 0 | 64 | 1030 | 0 | 0 | -550 | 37 | 2026-01-30 07:00:00 | - | - | 1030 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 14 | 34 | AMLODIPIN 5mg | 740 | 0 | 0 | 0 | 75 | 1270 | 0 | 0 | -530 | 31 | 2026-02-06 07:00:00 | - | - | 1270 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 15 | 55 | ANTASIDA DOEN | 460 | 0 | 0 | 0 | 63 | 990 | 0 | 0 | -530 | 32 | 2026-02-16 07:00:00 | - | - | 990 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 16 | 220 | DICLOFENAC POTASIUM | 160 | 0 | 0 | 0 | 56 | 660 | 0 | 0 | -500 | 43 | 2026-01-19 07:00:00 | - | - | 660 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 17 | 253 | ERPHAFLAM | 50 | 0 | 0 | 0 | 36 | 500 | 0 | 0 | -450 | 33 | 2026-01-19 07:00:00 | - | - | 500 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 18 | 320 | GLIMEPIRID 2mg | 110 | 0 | 0 | 0 | 30 | 530 | 0 | 0 | -420 | 23 | 2026-01-20 07:00:00 | - | - | 530 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 19 | 509 | METFORMIN 500 mg | 300 | 0 | 0 | 0 | 32 | 690 | 0 | 0 | -390 | 18 | 2026-01-30 07:00:00 | - | - | 690 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 20 | 25 | ALPARA | 160 | 0 | 0 | 0 | 44 | 540 | 0 | 0 | -380 | 30 | 2026-02-05 07:00:00 | - | - | 540 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 21 | 428 | LANSOPRAZOLE | 80 | 0 | 0 | 0 | 36 | 420 | 0 | 0 | -340 | 30 | 2026-01-19 07:00:00 | - | - | 420 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 22 | 560 | NEURALGIN | 270 | 0 | 0 | 0 | 40 | 610 | 0 | 0 | -340 | 19 | 2026-02-13 07:00:00 | - | - | 610 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 23 | 196 | DANASONE | 90 | 0 | 0 | 0 | 35 | 420 | 0 | 0 | -330 | 28 | 2026-01-22 07:00:00 | - | - | 420 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 24 | 524 | MIXALGIN | 130 | 0 | 0 | 0 | 40 | 460 | 0 | 0 | -330 | 30 | 2026-01-30 07:00:00 | - | - | 460 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 25 | 115 | B1 STRIP | 204 | 0 | 0 | 0 | 13 | 126 | 186 | 584 | -320 | 10 | 2026-01-22 12:41:18 | - | - | 126 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 26 | 30 | AMBROXOL | 130 | 0 | 0 | 0 | 38 | 440 | 0 | 0 | -310 | 28 | 2026-01-27 07:00:00 | - | - | 440 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 27 | 323 | GLUCOSAMIN MPL | 10 | 0 | 0 | 0 | 27 | 290 | 0 | 0 | -280 | 26 | 2026-01-06 07:00:00 | - | - | 290 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 28 | 504 | MEFINAL | 210 | 0 | 0 | 0 | 43 | 480 | 0 | 0 | -270 | 25 | 2026-01-31 07:00:00 | - | - | 480 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 29 | 795 | TEOSAL | 550 | 0 | 0 | 0 | 49 | 820 | 0 | 0 | -270 | 16 | 2026-02-17 07:00:00 | - | - | 820 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 30 | 402 | KETOCONAZOLE TAB | 220 | 0 | 0 | 0 | 39 | 480 | 0 | 0 | -260 | 22 | 2026-02-06 07:00:00 | - | - | 480 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 31 | 45 | ANASTAN | 130 | 0 | 0 | 0 | 33 | 380 | 0 | 0 | -250 | 21 | 2026-02-07 07:00:00 | - | - | 380 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 32 | 379 | INTERHISTIN | 70 | 0 | 0 | 0 | 27 | 310 | 0 | 0 | -240 | 21 | 2026-01-16 07:00:00 | - | - | 310 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 33 | 385 | KADITIC | 370 | 0 | 0 | 0 | 51 | 600 | 0 | 0 | -230 | 20 | 2026-02-09 07:00:00 | - | - | 600 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 34 | 756 | SIMVASTATIN 10 | 650 | 0 | 0 | 0 | 68 | 880 | 0 | 0 | -230 | 18 | 2026-02-28 07:00:00 | - | - | 880 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 35 | 512 | METHYLPREDNISOLON 8mg | 320 | 0 | 0 | 0 | 42 | 540 | 0 | 0 | -220 | 19 | 2026-02-18 07:00:00 | - | - | 540 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 36 | 152 | CEFADROXIL 500mg | 70 | 0 | 0 | 0 | 17 | 270 | 0 | 0 | -200 | 10 | 2026-02-11 07:00:00 | - | - | 270 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 37 | 24 | ALLOPURINOL 300mg | 230 | 0 | 0 | 0 | 35 | 420 | 0 | 0 | -190 | 16 | 2026-02-14 07:00:00 | - | - | 420 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 38 | 609 | OMEGZOLE | 80 | 0 | 0 | 0 | 25 | 270 | 0 | 0 | -190 | 17 | 2026-01-27 07:00:00 | - | - | 270 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 39 | 796 | TERA F | 110 | 0 | 0 | 0 | 25 | 300 | 0 | 0 | -190 | 14 | 2026-02-05 07:00:00 | - | - | 300 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 40 | 563 | NEURODEX | 120 | 0 | 0 | 0 | 28 | 300 | 0 | 0 | -180 | 16 | 2026-02-05 07:00:00 | - | - | 300 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 41 | 676 | PROMAG TAB | 20 | 0 | 0 | 0 | 129 | 196 | 0 | 0 | -176 | 115 | 2026-01-13 07:00:00 | - | - | 196 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 42 | 619 | OMETILSON | 200 | 0 | 0 | 0 | 34 | 370 | 0 | 0 | -170 | 17 | 2026-02-03 07:00:00 | - | - | 370 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 43 | 51 | ANTANGIN CAIR | 113 | 0 | 0 | 0 | 75 | 275 | 0 | 0 | -162 | 45 | 2026-02-03 07:00:00 | - | - | 275 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 44 | 539 | MOLEXFLU | 30 | 0 | 0 | 0 | 17 | 190 | 0 | 0 | -160 | 14 | 2026-01-22 07:00:00 | - | - | 190 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 45 | 610 | OMELLEGAR | 90 | 0 | 0 | 0 | 24 | 250 | 0 | 0 | -160 | 15 | 2026-01-29 07:00:00 | - | - | 250 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 46 | 335 | GRATAZONE | 80 | 0 | 0 | 0 | 17 | 230 | 0 | 0 | -150 | 12 | 2026-01-27 07:00:00 | - | - | 230 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 47 | 886 | salbutamol 4mg | 240 | 0 | 0 | 0 | 25 | 390 | 0 | 0 | -150 | 9 | 2026-02-20 07:00:00 | - | - | 390 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 48 | 142 | CARBIDU 0,5 | 130 | 0 | 0 | 0 | 25 | 270 | 0 | 0 | -140 | 12 | 2026-02-10 07:00:00 | - | - | 270 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 49 | 293 | FOLAVIT | 30 | 0 | 0 | 0 | 13 | 170 | 0 | 0 | -140 | 11 | 2026-01-17 07:00:00 | - | - | 170 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 50 | 442 | LERZIN TAB | 40 | 0 | 0 | 0 | 15 | 170 | 0 | 0 | -130 | 12 | 2026-02-09 07:00:00 | - | - | 170 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 51 | 444 | LICOKALK | 160 | 0 | 0 | 0 | 21 | 290 | 0 | 0 | -130 | 9 | 2026-02-23 07:00:00 | - | - | 290 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 52 | 612 | OMEPRASOL | 300 | 0 | 0 | 0 | 38 | 430 | 0 | 0 | -130 | 12 | 2026-02-18 07:00:00 | - | - | 430 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 53 | 366 | IBUPROFEN | 310 | 0 | 0 | 0 | 36 | 430 | 0 | 0 | -120 | 11 | 2026-02-14 07:00:00 | - | - | 430 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 54 | 567 | NEUROSANBE PLUS | 110 | 0 | 0 | 0 | 19 | 230 | 0 | 0 | -120 | 10 | 2026-02-05 07:00:00 | - | - | 230 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 55 | 689 | RANITIDINE | 230 | 0 | 0 | 0 | 27 | 350 | 0 | 0 | -120 | 9 | 2026-03-03 07:00:00 | - | - | 350 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 56 | 118 | BROADAMOX | 50 | 0 | 0 | 0 | 16 | 160 | 0 | 0 | -110 | 11 | 2026-01-17 07:00:00 | - | - | 160 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 57 | 290 | FLUTAMOL | 110 | 0 | 0 | 0 | 22 | 220 | 0 | 0 | -110 | 11 | 2026-02-13 07:00:00 | - | - | 220 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 58 | 506 | MELOXICAM 15mg | 320 | 0 | 0 | 0 | 41 | 430 | 0 | 0 | -110 | 10 | 2026-02-28 07:00:00 | - | - | 430 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 59 | 956 | GABAPENTIN 300mg | 30 | 0 | 0 | 0 | 14 | 140 | 0 | 0 | -110 | 11 | 2026-01-16 07:00:00 | - | - | 140 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 60 | 154 | CEFIXIM 100MG | 30 | 0 | 0 | 0 | 13 | 130 | 0 | 0 | -100 | 10 | 2026-01-16 07:00:00 | - | - | 130 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 61 | 223 | DIVOLTAR | 50 | 0 | 0 | 0 | 11 | 150 | 0 | 0 | -100 | 8 | 2026-01-27 07:00:00 | - | - | 150 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 62 | 318 | GLIBENCLAMIDE | 210 | 0 | 0 | 0 | 16 | 310 | 0 | 0 | -100 | 5 | 2026-02-14 07:00:00 | - | - | 310 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 63 | 359 | HUFAGRIP  FORTE TAB | 120 | 0 | 0 | 0 | 19 | 220 | 0 | 0 | -100 | 9 | 2026-02-03 07:00:00 | - | - | 220 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 64 | 48 | ANDALAN | 20 | 0 | 0 | 0 | 82 | 116 | 0 | 0 | -96 | 67 | 2026-01-13 07:00:00 | - | - | 116 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 65 | 706 | SALBUTAMOL 2mg | 140 | 0 | 0 | 0 | 16 | 230 | 0 | 0 | -90 | 5 | 2026-02-26 07:00:00 | - | - | 230 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 66 | 816 | OM TEST | 30 | 0 | 0 | 0 | 60 | 119 | 0 | 0 | -89 | 40 | 2026-02-07 07:00:00 | - | - | 119 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 67 | 145 | CATAFLAM 50mg | 45 | 0 | 0 | 0 | 47 | 127 | 0 | 0 | -82 | 32 | 2026-01-27 07:00:00 | - | - | 127 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 68 | 319 | GLIMEPIRID 1mg | 110 | 0 | 0 | 0 | 14 | 190 | 0 | 0 | -80 | 6 | 2026-02-04 07:00:00 | - | - | 190 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 69 | 324 | GLUDEPATIC | 40 | 0 | 0 | 0 | 12 | 120 | 0 | 0 | -80 | 8 | 2026-01-17 07:00:00 | - | - | 120 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 70 | 880 | HUFARIZINE TAB | 60 | 0 | 0 | 0 | 14 | 140 | 0 | 0 | -80 | 8 | 2026-02-02 07:00:00 | - | - | 140 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 71 | 808 | TOLAK ANGIN DEWASA | 47 | 0 | 0 | 0 | 40 | 120 | 0 | 0 | -73 | 23 | 2026-02-02 07:00:00 | - | - | 120 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 72 | 140 | CAPTOPRIL 25mg | 40 | 0 | 0 | 0 | 8 | 110 | 0 | 0 | -70 | 5 | 2026-02-18 07:00:00 | - | - | 110 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 73 | 165 | CIPROFLOXACIN 500mg | 120 | 0 | 0 | 0 | 17 | 190 | 0 | 0 | -70 | 6 | 2026-02-25 07:00:00 | - | - | 190 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 74 | 280 | FIMESTAN | 70 | 0 | 0 | 0 | 14 | 140 | 0 | 0 | -70 | 7 | 2026-02-09 07:00:00 | - | - | 140 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 75 | 331 | GRAFADON | 30 | 0 | 0 | 0 | 8 | 100 | 0 | 0 | -70 | 5 | 2026-02-03 07:00:00 | - | - | 100 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 76 | 365 | HYSTIGO | 130 | 0 | 0 | 0 | 19 | 200 | 0 | 0 | -70 | 6 | 2026-02-27 07:00:00 | - | - | 200 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 77 | 921 | GLUCODEX | 60 | 0 | 0 | 0 | 12 | 130 | 0 | 0 | -70 | 6 | 2026-01-31 07:00:00 | - | - | 130 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 78 | 375 | INCIDAL | 34 | 0 | 0 | 0 | 30 | 103 | 0 | 0 | -69 | 21 | 2026-01-19 07:00:00 | - | - | 103 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 79 | 108 | BODREX | 9 | 0 | 0 | 0 | 35 | 69 | 0 | 0 | -60 | 30 | 2026-01-28 07:00:00 | - | - | 69 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 80 | 181 | CORTIDEX | 10 | 0 | 0 | 0 | 7 | 70 | 0 | 0 | -60 | 6 | 2026-02-03 07:00:00 | - | - | 70 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 81 | 225 | DOLOLICOBION | 30 | 0 | 0 | 0 | 8 | 90 | 0 | 0 | -60 | 6 | 2026-01-22 07:00:00 | - | - | 90 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 82 | 381 | ISDN | 210 | 0 | 0 | 0 | 9 | 270 | 0 | 0 | -60 | 2 | 2026-02-19 07:00:00 | - | - | 270 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 83 | 511 | METHYLPREDNISOLON 4mg | 690 | 0 | 0 | 0 | 56 | 750 | 0 | 0 | -60 | 5 | 2026-03-04 07:00:00 | - | - | 750 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 84 | 778 | SUPERTETRA | 14 | 0 | 0 | 0 | 56 | 74 | 0 | 0 | -60 | 46 | 2026-01-19 07:00:00 | - | - | 74 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 85 | 21 | ALLOFAR 100MG | 90 | 0 | 0 | 0 | 12 | 140 | 0 | 0 | -50 | 4 | 2026-03-05 07:00:00 | - | - | 140 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 86 | 27 | HUFADEXTA-M | 20 | 0 | 0 | 0 | 7 | 70 | 0 | 0 | -50 | 5 | 2026-02-07 07:00:00 | - | - | 70 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 87 | 624 | OSKADON | 15 | 0 | 0 | 0 | 46 | 65 | 0 | 0 | -50 | 35 | 2026-01-27 07:00:00 | - | - | 65 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 88 | 421 | LACTO-B | 11 | 0 | 0 | 0 | 28 | 59 | 0 | 0 | -48 | 21 | 2026-01-20 07:00:00 | - | - | 59 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 89 | 832 | VEGETA HERBAL | 19 | 0 | 0 | 0 | 17 | 65 | 0 | 0 | -46 | 12 | 2026-01-27 07:00:00 | - | - | 65 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 90 | 516 | MICROGYNON | 17 | 0 | 0 | 0 | 56 | 61 | 0 | 0 | -44 | 40 | 2026-01-23 07:00:00 | - | - | 61 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 91 | 825 | ULTRAFLU | 16 | 0 | 0 | 0 | 37 | 58 | 0 | 0 | -42 | 25 | 2026-01-27 07:00:00 | - | - | 58 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 92 | 169 | CLINDAMICIN 300mg | 20 | 0 | 0 | 0 | 6 | 60 | 0 | 0 | -40 | 4 | 2026-01-28 07:00:00 | - | - | 60 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 93 | 239 | EMTURNAS | 80 | 0 | 0 | 0 | 11 | 120 | 0 | 0 | -40 | 3 | 2026-03-02 07:00:00 | - | - | 120 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 94 | 764 | SOLDEXTAM | 140 | 0 | 0 | 0 | 17 | 180 | 0 | 0 | -40 | 4 | 2026-02-26 07:00:00 | - | - | 180 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 95 | 849 | CANDESARTAN 8MG | 70 | 0 | 0 | 0 | 10 | 110 | 0 | 0 | -40 | 4 | 2026-02-20 07:00:00 | - | - | 110 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 96 | 639 | PARATUSIN | 15 | 0 | 0 | 0 | 47 | 53 | 0 | 0 | -38 | 34 | 2026-01-28 07:00:00 | - | - | 53 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 97 | 724 | SANGOBION S'4 | 2 | 0 | 0 | 0 | 39 | 40 | 0 | 0 | -38 | 37 | 2026-01-13 07:00:00 | - | - | 40 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 98 | 746 | SILADEX ALL VAR 60ml | 10 | 0 | 0 | 0 | 39 | 41 | 0 | 0 | -31 | 29 | 2026-01-17 07:00:00 | - | - | 41 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 99 | 121 | BUFACARYL | 150 | 0 | 0 | 0 | 18 | 180 | 0 | 0 | -30 | 3 | 2026-03-05 07:00:00 | - | - | 180 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 100 | 264 | ETAMOX | 30 | 0 | 0 | 0 | 6 | 60 | 0 | 0 | -30 | 3 | 2026-02-11 07:00:00 | - | - | 60 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 101 | 429 | LAPISIV-T | 20 | 0 | 0 | 0 | 5 | 50 | 0 | 0 | -30 | 3 | 2026-02-07 07:00:00 | - | - | 50 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 102 | 514 | METRONIDAZOL | 40 | 0 | 0 | 0 | 7 | 70 | 0 | 0 | -30 | 3 | 2026-02-06 07:00:00 | - | - | 70 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 103 | 717 | SAMCOFENAC | 430 | 0 | 0 | 0 | 25 | 460 | 0 | 0 | -30 | 2 | 2026-03-12 07:00:00 | - | - | 460 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 104 | 873 | XON-CE | 43 | 0 | 0 | 0 | 26 | 72 | 0 | 0 | -29 | 12 | 2026-02-10 07:00:00 | - | - | 72 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 105 | 557 | NEO RHEUMACYL | 22 | 0 | 0 | 0 | 35 | 49 | 0 | 0 | -27 | 19 | 2026-02-09 07:00:00 | - | - | 49 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 106 | 304 | PROMAG HERBAL | 7 | 0 | 0 | 0 | 10 | 32 | 0 | 0 | -25 | 9 | 2026-01-17 07:00:00 | - | - | 32 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 107 | 473 | M.TAWON CC | 3 | 0 | 0 | 0 | 26 | 27 | 0 | 0 | -24 | 23 | 2026-01-07 07:00:00 | - | - | 27 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 108 | 562 | NEUROBION 5000 | 9 | 0 | 0 | 0 | 31 | 32 | 0 | 0 | -23 | 23 | 2026-01-19 07:00:00 | - | - | 32 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 109 | 296 | FRESH CARE ALL | 49 | 0 | 0 | 0 | 59 | 71 | 0 | 0 | -22 | 22 | 2026-02-13 07:00:00 | - | - | 71 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 110 | 600 | OBH COMBI PLUS 100ml | 6 | 0 | 0 | 0 | 27 | 28 | 0 | 0 | -22 | 21 | 2026-01-14 07:00:00 | - | - | 28 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 111 | 602 | OBH ITRASAL | 8 | 0 | 0 | 0 | 25 | 30 | 0 | 0 | -22 | 18 | 2026-02-02 07:00:00 | - | - | 30 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 112 | 175 | INSTO COOL | 2 | 0 | 0 | 0 | 23 | 23 | 0 | 0 | -21 | 21 | 2026-01-03 07:00:00 | - | - | 23 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 113 | 356 | HOT IN 60GR ALL VAR | 2 | 0 | 0 | 0 | 23 | 23 | 0 | 0 | -21 | 21 | 2026-01-12 07:00:00 | - | - | 23 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 114 | 543 | MYCORAL TAB | 64 | 0 | 0 | 0 | 24 | 85 | 0 | 0 | -21 | 8 | 2026-02-19 07:00:00 | - | - | 85 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 115 | 103 | BIOPLACENTON | 3 | 0 | 0 | 0 | 18 | 23 | 0 | 0 | -20 | 15 | 2026-01-15 07:00:00 | - | - | 23 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 116 | 107 | BISOPROLOL FURMATE | 20 | 0 | 0 | 0 | 3 | 40 | 0 | 0 | -20 | 1 | 2026-03-07 07:00:00 | - | - | 40 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 117 | 256 | ESEMAG | 17 | 0 | 0 | 0 | 9 | 37 | 0 | 0 | -20 | 6 | 2026-02-28 07:00:00 | - | - | 37 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 118 | 299 | FUROSEMIDE | 30 | 0 | 0 | 0 | 4 | 50 | 0 | 0 | -20 | 2 | 2026-02-23 07:00:00 | - | - | 50 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 119 | 309 | GEMFIBROZIL | 100 | 0 | 0 | 0 | 8 | 120 | 0 | 0 | -20 | 2 | 2026-02-25 07:00:00 | - | - | 120 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 120 | 374 | INAMID | 50 | 0 | 0 | 0 | 6 | 70 | 0 | 0 | -20 | 2 | 2026-02-17 07:00:00 | - | - | 70 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 121 | 455 | LODIA | 100 | 0 | 0 | 0 | 12 | 120 | 0 | 0 | -20 | 2 | 2026-02-17 07:00:00 | - | - | 120 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 122 | 558 | NEOZEP | 12 | 0 | 0 | 0 | 22 | 32 | 0 | 0 | -20 | 13 | 2026-02-06 07:00:00 | - | - | 32 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 123 | 666 | POSTINOR | 6 | 0 | 0 | 0 | 20 | 26 | 0 | 0 | -20 | 16 | 2026-01-13 07:00:00 | - | - | 26 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 124 | 820 | TRIOCID TAB | 170 | 0 | 0 | 0 | 11 | 190 | 0 | 0 | -20 | 1 | 2026-03-07 07:00:00 | - | - | 190 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 125 | 872 | X-FLAM | 30 | 0 | 0 | 0 | 5 | 50 | 0 | 0 | -20 | 2 | 2026-02-14 07:00:00 | - | - | 50 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 126 | 954 | GUAFINESIN | 180 | 0 | 0 | 0 | 15 | 200 | 0 | 0 | -20 | 2 | 2026-03-07 07:00:00 | - | - | 200 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 127 | 1001 | PARAVEN TAB | 140 | 0 | 0 | 0 | 10 | 160 | 0 | 0 | -20 | 1 | 2026-02-17 07:00:00 | - | - | 160 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 128 | 708 | SALEP 88 | 10 | 0 | 0 | 0 | 28 | 29 | 0 | 0 | -19 | 18 | 2026-02-05 07:00:00 | - | - | 29 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 129 | 843 | VICKS VAP 10g | 4 | 0 | 0 | 0 | 22 | 23 | 0 | 0 | -19 | 18 | 2026-01-22 07:00:00 | - | - | 23 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 130 | 94 | BETADINE SOL 5mL | 11 | 0 | 0 | 0 | 27 | 28 | 0 | 0 | -17 | 17 | 2026-01-26 07:00:00 | - | - | 28 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 131 | 598 | OBH COMBI ANAK ALL VAR | 20 | 0 | 0 | 0 | 36 | 37 | 0 | 0 | -17 | 17 | 2026-02-12 07:00:00 | - | - | 37 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 132 | 856 | VITAMIN B KOMP IPI | 8 | 0 | 0 | 0 | 21 | 25 | 0 | 0 | -17 | 15 | 2026-01-19 07:00:00 | - | - | 25 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 133 | 126 | BYE FEVER BABY | 11 | 0 | 0 | 0 | 13 | 27 | 0 | 0 | -16 | 7 | 2026-02-04 07:00:00 | - | - | 27 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 134 | 240 | ENBATIC | 20 | 0 | 0 | 0 | 18 | 36 | 0 | 0 | -16 | 8 | 2026-02-16 07:00:00 | - | - | 36 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 135 | 341 | HANSAPLAST ROLL 5m | 3 | 0 | 0 | 0 | 18 | 19 | 0 | 0 | -16 | 15 | 2026-01-27 07:00:00 | - | - | 19 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 136 | 9 | ACYCLOVIR CREAM | 11 | 0 | 0 | 0 | 23 | 26 | 0 | 0 | -15 | 14 | 2026-01-22 07:00:00 | - | - | 26 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 137 | 125 | BYE FEVER ANAK | 17 | 0 | 0 | 0 | 24 | 32 | 0 | 0 | -15 | 11 | 2026-02-03 07:00:00 | - | - | 32 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 138 | 906 | PIKANG SUANG | 6 | 0 | 0 | 0 | 20 | 21 | 0 | 0 | -15 | 14 | 2026-01-17 07:00:00 | - | - | 21 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 139 | 111 | CONTREXYN | 4 | 0 | 0 | 0 | 12 | 18 | 0 | 0 | -14 | 10 | 2026-01-16 07:00:00 | - | - | 18 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 140 | 358 | HUFAGRIP KUNING | 4 | 0 | 0 | 0 | 18 | 18 | 0 | 0 | -14 | 14 | 2026-01-22 07:00:00 | - | - | 18 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 141 | 400 | KETOCONAZOLE CR DEXA | 7 | 0 | 0 | 0 | 17 | 21 | 0 | 0 | -14 | 10 | 2026-02-10 07:00:00 | - | - | 21 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 142 | 54 | ANTANGIN TAB | 9 | 0 | 0 | 0 | 12 | 22 | 0 | 0 | -13 | 8 | 2026-01-30 07:00:00 | - | - | 22 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 143 | 202 | DEGIROL S'10 | 2 | 0 | 0 | 0 | 14 | 15 | 0 | 0 | -13 | 12 | 2026-01-14 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 144 | 272 | LAXING  S'10 | 8 | 0 | 0 | 0 | 13 | 21 | 0 | 0 | -13 | 9 | 2026-01-23 07:00:00 | - | - | 21 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 145 | 535 | MKP LANG 60mL | 11 | 0 | 0 | 0 | 24 | 24 | 0 | 0 | -13 | 13 | 2026-01-26 07:00:00 | - | - | 24 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 146 | 661 | POLYSILANE TAB | 8 | 0 | 0 | 0 | 17 | 21 | 0 | 0 | -13 | 9 | 2026-02-18 07:00:00 | - | - | 21 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 147 | 704 | SAGESTAM | 4 | 0 | 0 | 0 | 17 | 17 | 0 | 0 | -13 | 13 | 2026-02-07 07:00:00 | - | - | 17 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 148 | 914 | OBH TROPICA PLUS ANAK ALL | 7 | 0 | 0 | 0 | 19 | 20 | 0 | 0 | -13 | 12 | 2026-01-24 07:00:00 | - | - | 20 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 149 | 775 | SUCRALFAT SYR | 4 | 0 | 0 | 0 | 16 | 16 | 0 | 0 | -12 | 12 | 2026-01-22 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 150 | 953 | VICKS IN HALER | 4 | 0 | 0 | 0 | 16 | 16 | 0 | 0 | -12 | 12 | 2026-01-26 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 151 | 980 | KASSA BIASA | 34 | 0 | 0 | 0 | 29 | 46 | 0 | 0 | -12 | 8 | 2026-02-25 07:00:00 | - | - | 46 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 152 | 227 | DRAMAMIN | 41 | 0 | 0 | 0 | 14 | 52 | 0 | 0 | -11 | 5 | 2026-02-11 07:00:00 | - | - | 52 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 153 | 533 | MKP LANG 15mL | 5 | 0 | 0 | 0 | 16 | 16 | 0 | 0 | -11 | 11 | 2026-01-26 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 154 | 599 | OBH COMBI DAHAK | 9 | 0 | 0 | 0 | 20 | 20 | 0 | 0 | -11 | 11 | 2026-02-13 07:00:00 | - | - | 20 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 155 | 727 | SANMOL DROP | 2 | 0 | 0 | 0 | 13 | 13 | 0 | 0 | -11 | 11 | 2026-01-09 07:00:00 | - | - | 13 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 156 | 784 | SUTRA MERAH S'12 | 1 | 0 | 0 | 0 | 12 | 12 | 0 | 0 | -11 | 11 | 2026-01-16 07:00:00 | - | - | 12 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 157 | 2 | ACETHYLESISTEIN 200mg | 30 | 0 | 0 | 0 | 4 | 40 | 0 | 0 | -10 | 1 | 2026-02-18 07:00:00 | - | - | 40 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 158 | 47 | ANATON TAB | 120 | 0 | 0 | 0 | 10 | 130 | 0 | 0 | -10 | 1 | 2026-03-10 07:00:00 | - | - | 130 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 159 | 64 | ASEPSO ALL VAR | 6 | 0 | 0 | 0 | 11 | 16 | 0 | 0 | -10 | 6 | 2026-02-02 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 160 | 248 | ERLADERM-N | 13 | 0 | 0 | 0 | 17 | 23 | 0 | 0 | -10 | 7 | 2026-02-02 07:00:00 | - | - | 23 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 161 | 278 | FG TROCHES | 7 | 0 | 0 | 0 | 17 | 17 | 0 | 0 | -10 | 10 | 2026-02-03 07:00:00 | - | - | 17 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 162 | 333 | GRAFAZOL | 30 | 0 | 0 | 0 | 4 | 40 | 0 | 0 | -10 | 1 | 2026-03-06 07:00:00 | - | - | 40 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 163 | 362 | HUFAMAG TAB | 140 | 0 | 0 | 0 | 12 | 150 | 0 | 0 | -10 | 1 | 2026-03-10 07:00:00 | - | - | 150 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 164 | 377 | INSTO | 7 | 0 | 0 | 0 | 17 | 17 | 0 | 0 | -10 | 10 | 2026-02-10 07:00:00 | - | - | 17 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 165 | 418 | LACOLDIN TAB | 20 | 0 | 0 | 0 | 3 | 30 | 0 | 0 | -10 | 1 | 2026-03-06 07:00:00 | - | - | 30 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 166 | 457 | LOPAMID | 180 | 0 | 0 | 0 | 16 | 190 | 0 | 0 | -10 | 1 | 2026-03-12 07:00:00 | - | - | 190 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 167 | 601 | OBH COMBI PLUS 60ml | 4 | 0 | 0 | 0 | 14 | 14 | 0 | 0 | -10 | 10 | 2026-02-09 07:00:00 | - | - | 14 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 168 | 606 | OMEDOM TAB | 140 | 0 | 0 | 0 | 15 | 150 | 0 | 0 | -10 | 1 | 2026-03-10 07:00:00 | - | - | 150 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 169 | 665 | BROMIFAR | 30 | 0 | 0 | 0 | 4 | 40 | 0 | 0 | -10 | 1 | 2026-03-07 07:00:00 | - | - | 40 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 170 | 728 | SANMOL SYR | 11 | 0 | 0 | 0 | 20 | 21 | 0 | 0 | -10 | 10 | 2026-01-26 07:00:00 | - | - | 21 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 171 | 861 | WINATIN | 20 | 0 | 0 | 0 | 3 | 30 | 0 | 0 | -10 | 1 | 2026-03-05 07:00:00 | - | - | 30 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 172 | 879 | YUSIMOX TABLET | 50 | 0 | 0 | 0 | 5 | 60 | 0 | 0 | -10 | 1 | 2026-03-05 07:00:00 | - | - | 60 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 173 | 923 | INFLASON | 90 | 0 | 0 | 0 | 9 | 100 | 0 | 0 | -10 | 1 | 2026-02-28 07:00:00 | - | - | 100 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 174 | 172 | COMBANTRIN SYR | 6 | 0 | 0 | 0 | 14 | 15 | 0 | 0 | -9 | 8 | 2026-02-02 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 175 | 474 | M.TAWON DD | 6 | 0 | 0 | 0 | 14 | 15 | 0 | 0 | -9 | 8 | 2026-02-05 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 176 | 517 | MICROLAX | 6 | 0 | 0 | 0 | 15 | 15 | 0 | 0 | -9 | 9 | 2026-01-24 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 177 | 534 | MKP LANG 30mL | 11 | 0 | 0 | 0 | 19 | 20 | 0 | 0 | -9 | 8 | 2026-02-19 07:00:00 | - | - | 20 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 178 | 711 | SALICYL MENTOL | 4 | 0 | 0 | 0 | 10 | 13 | 0 | 0 | -9 | 8 | 2026-01-27 07:00:00 | - | - | 13 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 179 | 785 | SUTRA MERAH S'3 | 15 | 0 | 0 | 0 | 24 | 24 | 0 | 0 | -9 | 9 | 2026-02-17 07:00:00 | - | - | 24 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 180 | 112 | BODREX MIGRA | 4 | 0 | 0 | 0 | 10 | 12 | 0 | 0 | -8 | 7 | 2026-01-21 07:00:00 | - | - | 12 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 181 | 325 | GPU 30mL | 8 | 0 | 0 | 0 | 15 | 16 | 0 | 0 | -8 | 8 | 2026-01-31 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 182 | 357 | HUFAGRIP BP IJO | 12 | 0 | 0 | 0 | 19 | 20 | 0 | 0 | -8 | 8 | 2026-03-02 07:00:00 | - | - | 20 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 183 | 363 | HYDROCORTISON | 3 | 0 | 0 | 0 | 9 | 11 | 0 | 0 | -8 | 7 | 2026-01-29 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 184 | 399 | KEJIBELING PLUS GINGSENG | 3 | 0 | 0 | 0 | 11 | 11 | 0 | 0 | -8 | 8 | 2026-01-28 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 185 | 682 | PRORIS SYR | 7 | 0 | 0 | 0 | 15 | 15 | 0 | 0 | -8 | 8 | 2026-02-04 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 186 | 702 | ROOHTO COOL | 5 | 0 | 0 | 0 | 13 | 13 | 0 | 0 | -8 | 8 | 2026-02-13 07:00:00 | - | - | 13 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 187 | 782 | SUTRA OK 3'S | 2 | 0 | 0 | 0 | 9 | 10 | 0 | 0 | -8 | 7 | 2026-02-07 07:00:00 | - | - | 10 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 188 | 28 | XYLERGY | 2 | 0 | 0 | 0 | 8 | 9 | 0 | 0 | -7 | 7 | 2026-01-29 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 189 | 29 | AMBEVEN | 10 | 0 | 0 | 0 | 17 | 17 | 0 | 0 | -7 | 7 | 2026-02-06 07:00:00 | - | - | 17 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 190 | 57 | ANTASIDA SYR FM | 9 | 0 | 0 | 0 | 15 | 16 | 0 | 0 | -7 | 7 | 2026-02-16 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 191 | 410 | KONIDIN OBH | 1 | 0 | 0 | 0 | 2 | 8 | 0 | 0 | -7 | 2 | 2026-01-06 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 192 | 416 | KULDON | 2 | 0 | 0 | 0 | 7 | 9 | 0 | 0 | -7 | 5 | 2026-01-20 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 193 | 550 | NATUR-E S'16 | 4 | 0 | 0 | 0 | 11 | 11 | 0 | 0 | -7 | 7 | 2026-02-17 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 194 | 625 | OSKADON SP | 13 | 0 | 0 | 0 | 18 | 20 | 0 | 0 | -7 | 7 | 2026-02-03 07:00:00 | - | - | 20 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 195 | 881 | VICKS F 44 54ML | 5 | 0 | 0 | 0 | 12 | 12 | 0 | 0 | -7 | 7 | 2026-01-26 07:00:00 | - | - | 12 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 196 | 97 | BETASON-N | 8 | 0 | 0 | 0 | 14 | 14 | 0 | 0 | -6 | 6 | 2026-02-27 07:00:00 | - | - | 14 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 197 | 276 | FEMINAX | 4 | 0 | 0 | 0 | 8 | 10 | 0 | 0 | -6 | 5 | 2026-02-05 07:00:00 | - | - | 10 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 198 | 355 | HOT IN 120GR ALL VAR | 6 | 0 | 0 | 0 | 11 | 12 | 0 | 0 | -6 | 5 | 2026-01-22 07:00:00 | - | - | 12 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 199 | 698 | RHEUMASON NELLCO | 2 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -6 | 6 | 2026-01-19 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 200 | 699 | RIVANOL 100mL | 5 | 0 | 0 | 0 | 11 | 11 | 0 | 0 | -6 | 6 | 2026-02-20 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 201 | 774 | STREPSIL | 14 | 0 | 0 | 0 | 17 | 20 | 0 | 0 | -6 | 6 | 2026-02-10 07:00:00 | - | - | 20 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 202 | 853 | VITACIMIN | 36 | 0 | 0 | 0 | 24 | 42 | 0 | 0 | -6 | 3 | 2026-02-28 07:00:00 | - | - | 42 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 203 | 908 | MYLANTA TAB | 4 | 0 | 0 | 0 | 9 | 10 | 0 | 0 | -6 | 6 | 2026-01-27 07:00:00 | - | - | 10 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 204 | 995 | KASSA KOTAK OM | 4 | 0 | 0 | 0 | 6 | 10 | 0 | 0 | -6 | 3 | 2026-02-27 07:00:00 | - | - | 10 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 205 | 15 | ALKOHOL 70% 100mL | 2 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -5 | 5 | 2026-01-30 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 206 | 42 | ANAKONIDIN 60mL | 1 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -5 | 5 | 2026-01-15 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 207 | 52 | ANTANGIN JUNIOR | 25 | 0 | 0 | 0 | 15 | 30 | 0 | 0 | -5 | 3 | 2026-03-06 07:00:00 | - | - | 30 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 208 | 104 | VENTOLIN NEBUL | 7 | 0 | 0 | 0 | 9 | 12 | 0 | 0 | -5 | 5 | 2026-02-13 07:00:00 | - | - | 12 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 209 | 179 | COPAL | 3 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -5 | 5 | 2026-01-29 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 210 | 184 | COUNTERPAIN 30g | 2 | 0 | 0 | 0 | 6 | 7 | 0 | 0 | -5 | 4 | 2026-01-19 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 211 | 205 | DENOMIX KRIM 10g | 2 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -5 | 5 | 2026-01-19 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 212 | 237 | EM KAPSUL | 1 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -5 | 5 | 2026-02-07 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 213 | 245 | ENTROSTOP | 12 | 0 | 0 | 0 | 16 | 17 | 0 | 0 | -5 | 5 | 2026-02-20 07:00:00 | - | - | 17 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 214 | 262 | ELKANA SYR | 1 | 0 | 0 | 0 | 5 | 6 | 0 | 0 | -5 | 4 | 2026-01-21 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 215 | 298 | FUNGIDREM K | 2 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -5 | 5 | 2026-02-04 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 216 | 386 | KAKAK TUA | 1 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -5 | 5 | 2026-01-29 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 217 | 393 | KAOTIN SYR | 1 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -5 | 5 | 2026-01-29 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 218 | 419 | LACTACYD BABY | 1 | 0 | 0 | 0 | 5 | 6 | 0 | 0 | -5 | 4 | 2026-01-14 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 219 | 489 | M.TELON MY BABY  + 60ML | 3 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -5 | 5 | 2026-02-10 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 220 | 649 | PIMTRAKOL ALL VAR | 22 | 0 | 0 | 0 | 25 | 27 | 0 | 0 | -5 | 4 | 2026-02-18 07:00:00 | - | - | 27 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 221 | 726 | SANMAG SYR | 2 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -5 | 5 | 2026-01-17 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 222 | 753 | PACDIN COUGH ALL VAR | 3 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -5 | 5 | 2026-01-28 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 223 | 765 | SOLINFEC CR | 4 | 0 | 0 | 0 | 8 | 9 | 0 | 0 | -5 | 5 | 2026-02-13 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 224 | 842 | VICKS F 44 27ml | 4 | 0 | 0 | 0 | 9 | 9 | 0 | 0 | -5 | 5 | 2026-02-11 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 225 | 962 | ULTRAFIX | 10 | 0 | 0 | 0 | 12 | 15 | 0 | 0 | -5 | 4 | 2026-02-19 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 226 | 998 | HOT IN DCL 60GR | 3 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -5 | 5 | 2026-02-12 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 227 | 999 | HOT IN DCL 120 GR | 2 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -5 | 5 | 2026-01-24 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 228 | 135 | CALLUSOL | 1 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -4 | 4 | 2026-01-20 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 229 | 157 | CENDO XITROL | 5 | 0 | 0 | 0 | 9 | 9 | 0 | 0 | -4 | 4 | 2026-02-23 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 230 | 228 | DULCOLAX S'10 | 5 | 0 | 0 | 0 | 9 | 9 | 0 | 0 | -4 | 4 | 2026-02-09 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 231 | 244 | OBH TROPICA EXTRA ALL | 7 | 0 | 0 | 0 | 11 | 11 | 0 | 0 | -4 | 4 | 2026-02-20 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 232 | 306 | GELIGA BALSEM 20g | 7 | 0 | 0 | 0 | 10 | 11 | 0 | 0 | -4 | 4 | 2026-02-14 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 233 | 311 | GENOINT SK | 3 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -4 | 4 | 2026-02-14 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 234 | 314 | GENTAMICIN SLP | 12 | 0 | 0 | 0 | 12 | 16 | 0 | 0 | -4 | 3 | 2026-03-03 07:00:00 | - | - | 16 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 235 | 412 | KOOLFEVER ANAK | 11 | 0 | 0 | 0 | 10 | 15 | 0 | 0 | -4 | 2 | 2026-02-13 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 236 | 542 | MYCORAL CREAM | 1 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -4 | 4 | 2026-02-23 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 237 | 604 | OBP ITRASAL | 1 | 0 | 0 | 0 | 4 | 5 | 0 | 0 | -4 | 3 | 2026-02-20 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 238 | 703 | SAFE CARE | 2 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -4 | 4 | 2026-02-09 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 239 | 732 | SCABIMITE K | 3 | 0 | 0 | 0 | 6 | 7 | 0 | 0 | -4 | 4 | 2026-02-18 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 240 | 809 | TOLAK LINU | 20 | 0 | 0 | 0 | 8 | 24 | 0 | 0 | -4 | 3 | 2026-01-28 07:00:00 | - | - | 24 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 241 | 882 | ZENIREX SYR | 2 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -4 | 4 | 2026-01-26 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 242 | 902 | POLIDENT K | 3 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -4 | 4 | 2026-02-07 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 243 | 4 | ACIFAR CREAM | 1 | 0 | 0 | 0 | 2 | 4 | 0 | 0 | -3 | 1 | 2026-03-02 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 244 | 38 | SILADEX UNGU 60ML | 6 | 0 | 0 | 0 | 9 | 9 | 0 | 0 | -3 | 3 | 2026-03-04 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 245 | 40 | ANACETIN | 1 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -3 | 3 | 2026-02-05 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 246 | 41 | ANAKONIDIN 30mL | 4 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -3 | 3 | 2026-02-18 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 247 | 72 | BALPIRIK MERAH | 5 | 0 | 0 | 0 | 6 | 8 | 0 | 0 | -3 | 1 | 2026-02-23 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 248 | 85 | BENOSON-N 5g | 3 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -3 | 3 | 2026-01-29 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 249 | 90 | BETADINE KUMUR K | 2 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -3 | 3 | 2026-02-26 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 250 | 98 | BEVALEX | 2 | 0 | 0 | 0 | 4 | 5 | 0 | 0 | -3 | 3 | 2026-01-22 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 251 | 155 | CENDO CATARLENT 15ml | 3 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -3 | 3 | 2026-02-13 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 252 | 185 | COUNTERPAIN 5g | 5 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -3 | 3 | 2026-03-03 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 253 | 246 | ENTROSTOP ANAK | 8 | 0 | 0 | 0 | 4 | 11 | 0 | 0 | -3 | 2 | 2026-02-12 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 254 | 349 | HEROCYN 150mg | 1 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -3 | 3 | 2026-02-27 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 255 | 383 | JF SULFUR KUNING | 1 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -3 | 3 | 2026-01-13 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 256 | 545 | MYLANTA LIQ 50mL | 6 | 0 | 0 | 0 | 9 | 9 | 0 | 0 | -3 | 3 | 2026-02-12 07:00:00 | - | - | 9 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 257 | 597 | OB HERBAL JUNIOR | 2 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -3 | 3 | 2026-01-28 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 258 | 628 | PAGODA SALEP | 8 | 0 | 0 | 0 | 8 | 11 | 0 | 0 | -3 | 2 | 2026-03-09 07:00:00 | - | - | 11 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 259 | 646 | PHARMATON | 1 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -3 | 3 | 2026-01-17 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 260 | 788 | TEMPRA DROP | 2 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -3 | 3 | 2026-02-09 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 261 | 791 | TEMPRA SYR K | 2 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -3 | 3 | 2026-02-09 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 262 | 871 | WOOD'S PERMEN ALL | 5 | 0 | 0 | 0 | 7 | 8 | 0 | 0 | -3 | 3 | 2026-02-25 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 263 | 878 | YUSIMOX SYR | 7 | 0 | 0 | 0 | 10 | 10 | 0 | 0 | -3 | 3 | 2026-02-21 07:00:00 | - | - | 10 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 264 | 916 | MY WELL D3 | 2 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -3 | 3 | 2026-02-19 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 265 | 929 | DIAPET S'10 | 12 | 0 | 0 | 0 | 14 | 15 | 0 | 0 | -3 | 3 | 2026-02-23 07:00:00 | - | - | 15 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 266 | 19 | IMBOST S'4 | 3 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -2 | 2 | 2026-02-13 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 267 | 20 | ALLETROL TM | 5 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -2 | 2 | 2026-02-13 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 268 | 43 | ANAKONIDIN OBH 30mL | 3 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -2 | 2 | 2026-02-18 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 269 | 96 | BETAMETASON | 6 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -2 | 2 | 2026-03-02 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 270 | 123 | BUFACORT-N | 1 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -2 | 2 | 2026-02-21 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 271 | 130 | CALADINE LOT 60mL | 4 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -2 | 2 | 2026-02-26 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 272 | 148 | CAZETIN | 2 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -2 | 2 | 2026-02-07 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 273 | 159 | CEREBROFOT GOLD ALL VAR | 4 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -2 | 2 | 2026-03-02 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 274 | 195 | DAKTARIN | 1 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -2 | 2 | 2026-03-06 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 275 | 249 | ERLAMICETIN SM | 2 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -2 | 2 | 2026-01-19 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 276 | 267 | EYEVIT | 2 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -2 | 2 | 2026-03-05 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 277 | 271 | FARSIFEN SYR | 1 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -2 | 2 | 2026-02-26 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 278 | 313 | GENOINT TM | 6 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -2 | 2 | 2026-03-07 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 279 | 315 | MEDICREPE 3'' | 2 | 0 | 0 | 0 | 3 | 4 | 0 | 0 | -2 | 2 | 2026-02-06 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 280 | 392 | KANDISTATIN | 3 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -2 | 2 | 2026-02-26 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 281 | 409 | KONIDIN | 10 | 0 | 0 | 0 | 9 | 12 | 0 | 0 | -2 | 2 | 2026-03-04 07:00:00 | - | - | 12 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 282 | 427 | LANCAR ASI | 1 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -2 | 2 | 2026-01-24 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 283 | 745 | SILADEX ALL VAR 30ml | 6 | 0 | 0 | 0 | 8 | 8 | 0 | 0 | -2 | 2 | 2026-02-17 07:00:00 | - | - | 8 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 284 | 800 | TERMOREX PLUS 60mL | 3 | 0 | 0 | 0 | 5 | 5 | 0 | 0 | -2 | 2 | 2026-02-28 07:00:00 | - | - | 5 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 285 | 835 | VENTOLIN SPRAY | 1 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -2 | 2 | 2026-02-09 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 286 | 901 | MICONAZOLE | 24 | 0 | 0 | 0 | 15 | 26 | 0 | 0 | -2 | 1 | 2026-03-10 07:00:00 | - | - | 26 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 287 | 915 | LARUTAN BOTOL B | 4 | 0 | 0 | 0 | 6 | 6 | 0 | 0 | -2 | 2 | 2026-02-28 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 288 | 928 | SANBE TEARS | 1 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -2 | 2 | 2026-03-04 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 289 | 61 | APIALYS DROP | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-02-06 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 290 | 79 | BATUGIN K | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-02-09 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 291 | 173 | COMBANTRIN TAB | 6 | 0 | 0 | 0 | 7 | 7 | 0 | 0 | -1 | 1 | 2026-03-10 07:00:00 | - | - | 7 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 292 | 180 | COPARCETIN SYR | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-03-04 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 293 | 186 | COUNTERPAIN 60g | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-03-07 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 294 | 242 | ENERVON C BTL | 3 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -1 | 1 | 2026-02-20 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 295 | 308 | GELIGA CAIR 30ML | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-03-04 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 296 | 312 | GENOINT SM | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-03-06 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 297 | 345 | HEMAVITON STAMINA | 12 | 0 | 0 | 0 | 13 | 13 | 0 | 0 | -1 | 1 | 2026-03-10 07:00:00 | - | - | 13 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 298 | 370 | IMBOOST KIDS | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-03-12 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 299 | 417 | LACOLDIN SYR | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-02-10 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 300 | 422 | APIALYS SYR | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-03-12 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 301 | 432 | LASERIN MADU 60ML | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-02-26 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 302 | 490 | M.TELON MY BABY + 90ML | 0 | 0 | 0 | 0 | 1 | 1 | 0 | 0 | -1 | 1 | 2026-02-16 07:00:00 | - | - | 1 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 303 | 502 | MEDIKLIN | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-02-18 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 304 | 527 | MKP GAJAH 60mL | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-03-11 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 305 | 655 | PLANTACID SYR | 3 | 0 | 0 | 0 | 3 | 4 | 0 | 0 | -1 | 1 | 2026-03-10 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 306 | 690 | RAPET WANGI | 3 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -1 | 1 | 2026-03-12 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 307 | 715 | SALONPAS KOYO ALL VAR | 43 | 0 | 0 | 0 | 35 | 44 | 0 | 0 | -1 | 1 | 2026-03-10 07:00:00 | - | - | 44 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 308 | 803 | THROMBO GEL | 3 | 0 | 0 | 0 | 4 | 4 | 0 | 0 | -1 | 1 | 2026-03-11 07:00:00 | - | - | 4 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 309 | 805 | TISU MAGIC ALL | 25 | 0 | 0 | 0 | 23 | 26 | 0 | 0 | -1 | 1 | 2026-03-12 07:00:00 | - | - | 26 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 310 | 827 | LACTULOSE SYR | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-02-13 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 311 | 924 | IMBOST S'10 | 2 | 0 | 0 | 0 | 3 | 3 | 0 | 0 | -1 | 1 | 2026-02-26 07:00:00 | - | - | 3 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 312 | 930 | SANTADEX TT | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-02-18 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 313 | 945 | STIMUNO CAPSUL | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-02-10 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 314 | 959 | VITAQUIN CR | 1 | 0 | 0 | 0 | 2 | 2 | 0 | 0 | -1 | 1 | 2026-02-10 07:00:00 | - | - | 2 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |
| 315 | 963 | DERMAFIX 10X25 | 5 | 0 | 0 | 0 | 5 | 6 | 0 | 0 | -1 | 1 | 2026-03-12 07:00:00 | - | - | 6 | Cek faktur fisik pembelian yang belum tercatat atau terlambat dicatat |

## Prioritas Sedang: Minus Sementara

| No | ID Produk | Nama Produk | Baseline | Beli Valid Rows | Beli Valid Qty | Invalid Rows | Jual Rows | Jual Qty | Gap Final | Neg Event | Minus Pertama | Beli 1 | Beli Terakhir | Sold < Beli1 | Fokus Audit |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
