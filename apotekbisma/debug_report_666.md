# Analysis for Product ID 666 (POSTINOR)

Baseline Stock (Opname 31 Dec 2025 from CSV): **6**

Current Product Stock in DB (`produk.stok`): **0**

## Transaction History Overview
Total Records: 70
First Record Date: 2025-12-22 22:45:42
Last Record Date: 2026-01-14 23:12:45

## Transactions after cutoff (2025-12-31 23:59:59):
| ID | Date | Type | Masuk | Keluar | Awal (DB) | Sisa (DB) | Notes |
|---|---|---|---|---|---|---|---|
| 176033 | 2026-01-08 18:56:06 |  | 0 | 1 | 4 | 3 | **Mismatch Start** (Exp: 6, Act: 4) **Mismatch End** (Exp: 5, Act: 3)  |
| 176034 | 2026-01-08 18:56:06 |  | 0 | 3 | 3 | 0 | **Mismatch Start** (Exp: 5, Act: 3) **Mismatch End** (Exp: 2, Act: 0)  |
| 176365 | 2026-01-10 05:45:00 |  | 0 | 5 | 0 | -5 | **Mismatch Start** (Exp: 2, Act: 0) **Mismatch End** (Exp: -3, Act: -5)  |
| 177119 | 2026-01-10 18:45:24 |  | 0 | 1 | 6 | 5 | **Mismatch Start** (Exp: -3, Act: 6) **Mismatch End** (Exp: -4, Act: 5)  |
| 177694 | 2026-01-13 17:37:38 |  | 0 | 1 | 5 | 4 | **Mismatch Start** (Exp: -4, Act: 5) **Mismatch End** (Exp: -5, Act: 4)  |
| 178097 | 2026-01-14 20:19:10 |  | 6 | 0 | -5 | 1 |  |
| 178435 | 2026-01-14 21:16:35 |  | 0 | 5 | 11 | 6 | **Mismatch Start** (Exp: 1, Act: 11) **Mismatch End** (Exp: -4, Act: 6)  |
| 178188 | 2026-01-14 23:12:45 |  | 0 | 1 | 1 | 0 | **Mismatch Start** (Exp: -4, Act: 1) **Mismatch End** (Exp: -5, Act: 0)  |


## Conclusion
Calculated Final Stock (from CSV baseline): **-5**
Current DB Stock: **0**
