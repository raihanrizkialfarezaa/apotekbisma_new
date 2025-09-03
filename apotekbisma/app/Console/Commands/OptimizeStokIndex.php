<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OptimizeStokIndex extends Command
{
    protected $signature = 'stok:optimize-index';

    protected $description = 'Optimize database indexes for stock synchronization';

    public function handle()
    {
        $this->info('Mengoptimalkan index database untuk sinkronisasi stok...');
        
        try {
            $indexes = [
                [
                    'name' => 'idx_rekaman_stoks_produk_id',
                    'sql' => "CREATE INDEX idx_rekaman_stoks_produk_id ON rekaman_stoks (id_produk, id_rekaman_stok DESC)"
                ],
                [
                    'name' => 'idx_produk_stok',
                    'sql' => "CREATE INDEX idx_produk_stok ON produk (id_produk, stok)"
                ],
                [
                    'name' => 'idx_rekaman_stoks_waktu',
                    'sql' => "CREATE INDEX idx_rekaman_stoks_waktu ON rekaman_stoks (waktu)"
                ],
                [
                    'name' => 'idx_rekaman_stoks_composite',
                    'sql' => "CREATE INDEX idx_rekaman_stoks_composite ON rekaman_stoks (id_produk, waktu)"
                ]
            ];
            
            foreach ($indexes as $index) {
                try {
                    DB::statement($index['sql']);
                    $this->line("✓ Index {$index['name']} berhasil dibuat");
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate key name') !== false || 
                        strpos($e->getMessage(), 'already exists') !== false) {
                        $this->line("✓ Index {$index['name']} sudah ada");
                    } else {
                        $this->line("! Mencoba drop dan recreate index {$index['name']}");
                        try {
                            DB::statement("DROP INDEX {$index['name']} ON " . 
                                         (strpos($index['sql'], 'produk') !== false ? 'produk' : 'rekaman_stoks'));
                            DB::statement($index['sql']);
                            $this->line("✓ Index {$index['name']} berhasil dibuat ulang");
                        } catch (\Exception $e2) {
                            $this->error("✗ Error pada index {$index['name']}: " . $e2->getMessage());
                        }
                    }
                }
            }
            
            $this->info('Optimasi index selesai!');
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error saat optimasi index: ' . $e->getMessage());
            return 1;
        }
    }
}
