<?php

return [
    'cutoff_datetime' => env('STOCK_BASELINE_CUTOFF', '2025-12-31 23:59:59'),
    'baseline_csv' => env('STOCK_BASELINE_CSV', 'REKAMAN STOK FINAL 31 DESEMBER 2025_2.csv'),
    'enable_destructive_rebuild_tools' => (bool) env('ENABLE_DESTRUCTIVE_STOCK_TOOLS', false),
    'enable_legacy_sync_command' => (bool) env('ENABLE_LEGACY_STOCK_SYNC', false),
    'stale_draft_minutes' => (int) env('STOCK_STALE_DRAFT_MINUTES', 30),
    'excluded_manual_keterangan_patterns' => [
        'cutoff 31 desember 2025',
        'baseline_opname_31des2025',
        'sinkronisasi',
        'auto sync',
        'rekonstruksi',
        'perfect stock record fixer',
        'reconcile',
        'baseline csv 31-12-2025',
        'penghapusan transaksi pembelian',
        'auto-created: rekaman stok awal produk',
    ],
];
