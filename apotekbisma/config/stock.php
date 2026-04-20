<?php

return [
    'cutoff_datetime' => env('STOCK_BASELINE_CUTOFF', '2025-12-31 23:59:59'),
    'baseline_csv' => env('STOCK_BASELINE_CSV', 'REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv'),
    'enable_destructive_rebuild_tools' => (bool) env('ENABLE_DESTRUCTIVE_STOCK_TOOLS', false),
    'enable_legacy_sync_command' => (bool) env('ENABLE_LEGACY_STOCK_SYNC', false),
    'stale_draft_minutes' => (int) env('STOCK_STALE_DRAFT_MINUTES', 30),
    'max_future_transaction_minutes' => (int) env('STOCK_MAX_FUTURE_TRANSACTION_MINUTES', 5),
    'excluded_manual_keterangan_patterns' => [
        'cutoff 31 desember 2025',
        'baseline_opname_31des2025',
        'sinkronisasi',
        'auto sync',
        'auto-negative-stabilizer',
        'rekonstruksi',
        'perfect stock record fixer',
        'reconcile',
        'baseline csv 31-12-2025',
        'penghapusan transaksi pembelian',
        'auto-created: rekaman stok awal produk',
    ],
    'purchase_source_overrides' => [
        'NPS-2602-629903' => [
            [
                'product_id' => 954,
                'raw_name' => 'GUAIFENESIN TAB / GG TRIMAN NR ;80X100',
                'jumlah' => 200,
                'reason' => 'Invoice NPS-2602-629903 has one box that must be converted to 200 unit tablets for GUAFINESIN.',
            ],
        ],
    ],
];
