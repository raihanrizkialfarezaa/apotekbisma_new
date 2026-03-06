# Exception Report: Skipped Negative-Event Products

Generated from post-apply dry-run report `baseline_rebuild_report_20260306_133403.json`.

## Summary

- Total skipped products: **17**
- Total absolute delta (if forced): **2846**
- Current policy: safe mode (skip products with negative event chain)

## Product List

| id_produk | nama_produk          | delta_stok | negative_events_detected |
| --------: | -------------------- | ---------: | -----------------------: |
|        63 | ASAM MEFENAMAT 500mg |       -840 |                       12 |
|        23 | ALLOPURINOL 100mg    |       -710 |                       17 |
|       994 | AMOXICILIN 500mg HJ  |       -600 |                       23 |
|       860 | VOLTADEX             |       -280 |                       17 |
|       115 | B1 STRIP             |       -200 |                        4 |
|       293 | FOLAVIT              |        -70 |                        2 |
|       323 | GLUCOSAMIN MPL       |        -50 |                        4 |
|       410 | KONIDIN OBH          |        -28 |                        1 |
|       676 | PROMAG TAB           |        -22 |                       11 |
|       778 | SUPERTETRA           |        -15 |                        2 |
|       356 | HOT IN 60GR ALL VAR  |         -9 |                        3 |
|       473 | M.TAWON CC           |         -8 |                        4 |
|       175 | INSTO COOL           |         -4 |                        4 |
|       108 | BODREX               |         -3 |                        1 |
|       727 | SANMOL DROP          |         -3 |                        1 |
|        42 | ANAKONIDIN 60mL      |         -2 |                        1 |
|       135 | CALLUSOL             |         -2 |                        1 |

## Recommended Next Step

1. Review top 5 deltas first (`63`, `23`, `994`, `860`, `115`) against source transactions (purchase/sale/manual).
2. After approval, run targeted apply with `--include-negative-events` per product (one-by-one), not global.
3. Re-run dry-run verification after each apply to ensure no new drift is introduced.

## Suggested Execution Order (per-product apply)

Use one-by-one apply with `--include-negative-events --product=<id>` in this order:

`63, 23, 994, 860, 115, 293, 323, 410, 676, 778, 356, 473, 175, 108, 727, 42, 135`
