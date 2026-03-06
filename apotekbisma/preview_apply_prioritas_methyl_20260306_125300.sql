-- PREVIEW SQL ONLY (DO NOT EXECUTE AUTOMATICALLY)
-- Generated at: 2026-03-06 12:53:00
-- Targets: 510, 511, 512
-- This file is a dry-run preview for manual review.

START TRANSACTION;

-- ==========================================================
-- Product 510: METHYLPREDNISOLON 16mg
-- Current stok: 90
-- Record #174345 2025-12-27 17:06:41 | Pembelian: Update jumlah transaksi
UPDATE rekaman_stoks SET stok_awal = 30, stok_sisa = 130, updated_at = NOW() WHERE id_rekaman_stok = 174345;
-- Record #174417 2025-12-27 17:06:42 | Penjualan: Update jumlah transaksi
UPDATE rekaman_stoks SET stok_awal = 130, stok_sisa = 120, updated_at = NOW() WHERE id_rekaman_stok = 174417;
-- Record #179868 2025-12-31 23:59:59 | BASELINE_OPNAME_31DES2025_V3
UPDATE rekaman_stoks SET stok_awal = 120, stok_sisa = 190, updated_at = NOW() WHERE id_rekaman_stok = 179868;
-- Record #180717 2026-01-23 09:26:48 | Stock Opname Cutoff 31 Desember 2025
UPDATE rekaman_stoks SET stok_awal = 190, stok_sisa = 280, updated_at = NOW() WHERE id_rekaman_stok = 180717;
-- Sync produk.stok 90 -> 280 (delta 190)
UPDATE produk SET stok = 280, updated_at = NOW() WHERE id_produk = 510;

-- ==========================================================
-- Product 511: METHYLPREDNISOLON 4mg
-- Current stok: 540
-- Record #180718 2026-01-23 09:26:48 | Stock Opname Cutoff 31 Desember 2025
UPDATE rekaman_stoks SET stok_awal = 540, stok_sisa = 1230, updated_at = NOW() WHERE id_rekaman_stok = 180718;
-- Sync produk.stok 540 -> 1230 (delta 690)
UPDATE produk SET stok = 1230, updated_at = NOW() WHERE id_produk = 511;

-- ==========================================================
-- Product 512: METHYLPREDNISOLON 8mg
-- Current stok: 200
-- Record #180719 2026-01-23 09:26:48 | Stock Opname Cutoff 31 Desember 2025
UPDATE rekaman_stoks SET stok_awal = 200, stok_sisa = 520, updated_at = NOW() WHERE id_rekaman_stok = 180719;
-- Sync produk.stok 200 -> 520 (delta 320)
UPDATE produk SET stok = 520, updated_at = NOW() WHERE id_produk = 512;

-- REVIEW ALL STATEMENTS ABOVE BEFORE APPLY
-- COMMIT;
-- ROLLBACK;
