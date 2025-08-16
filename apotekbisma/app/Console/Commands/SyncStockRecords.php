<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Produk;
use App\Models\RekamanStok;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SyncStockRecords extends Command
{
    protected $signature = 'stock:sync {--force : Force sync without confirmation} {--dry-run : Show what would be done without making changes}';
    protected $description = 'Sinkronisasi rekaman stok dengan kondisi aktual stok produk';

    public function handle()
    {
        // Log for debugging web interface issues
        $logFile = storage_path('logs/command-sync.log');
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $isWebRequest = !$this->input->isInteractive();
        
        $logEntry = "[$timestamp] Command called. Web request: " . ($isWebRequest ? 'YES' : 'NO') . "\n";
        $logEntry .= "[$timestamp] Options: " . json_encode($this->options()) . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        $this->info('=== SINKRONISASI REKAMAN STOK ===');
        $this->info('Waktu: ' . Carbon::now()->format('Y-m-d H:i:s'));
        
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        
        if ($dryRun) {
            $this->warn('MODE DRY-RUN: Tidak ada perubahan yang akan disimpan');
        }

        $isWebRequest = !$this->input->isInteractive();
        
        if ($isWebRequest && $dryRun) {
            return $this->performCompactAnalysis();
        }

        $this->performDetailedAnalysis();
        
        if (!$force && !$dryRun) {
            if (!$this->confirm('Lanjutkan sinkronisasi rekaman stok?')) {
                $this->info('Operasi dibatalkan.');
                return 0;
            }
        }

        return $this->performSynchronization($dryRun);
    }

    private function performCompactAnalysis()
    {
        $inconsistentCount = DB::table('rekaman_stoks as rs')
            ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
            ->where(function($query) {
                $query->whereRaw('rs.stok_awal != p.stok')
                      ->orWhereRaw('rs.stok_sisa != p.stok')
                      ->orWhere('rs.stok_awal', '<', 0)
                      ->orWhere('rs.stok_sisa', '<', 0);
            })
            ->where(function($query) {
                $query->where('p.stok', '!=', 0)
                      ->orWhere('rs.stok_awal', '!=', 0)
                      ->orWhere('rs.stok_sisa', '!=', 0);
            })
            ->distinct('rs.id_produk')
            ->count();
        
        $negativeStockCount = DB::table('produk')
            ->where('stok', '<', 0)
            ->count();
        
        $summary = [
            'rekaman_tidak_konsisten' => $inconsistentCount,
            'produk_stok_minus' => $negativeStockCount,
            'total_masalah' => $inconsistentCount + $negativeStockCount
        ];
        
        if ($summary['total_masalah'] == 0) {
            $this->info('Semua rekaman sudah konsisten');
        } else {
            $this->info("Ditemukan {$summary['rekaman_tidak_konsisten']} rekaman tidak konsisten dan {$summary['produk_stok_minus']} produk dengan stok minus");
        }
        
        return 0;
    }

    private function performDetailedAnalysis()
    {
        $this->info("\n=== ANALISIS KONDISI SAAT INI ===");
        
        $totalProduk = Produk::count();
        $produkStokMinus = Produk::where('stok', '<', 0)->count();
        $produkStokNol = Produk::where('stok', '=', 0)->count();
        
        $totalRekaman = RekamanStok::count();
        
        // Hitung rekaman minus hanya dari rekaman terbaru per produk
        $rekamanAwalMinus = DB::table('rekaman_stoks as rs')
            ->whereIn('rs.id_rekaman_stok', function($subquery) {
                $subquery->select(DB::raw('MAX(id_rekaman_stok)'))
                         ->from('rekaman_stoks')
                         ->groupBy('id_produk');
            })
            ->where('rs.stok_awal', '<', 0)
            ->count();
            
        $rekamanSisaMinus = DB::table('rekaman_stoks as rs')
            ->whereIn('rs.id_rekaman_stok', function($subquery) {
                $subquery->select(DB::raw('MAX(id_rekaman_stok)'))
                         ->from('rekaman_stoks')
                         ->groupBy('id_produk');
            })
            ->where('rs.stok_sisa', '<', 0)
            ->count();
        
        $inconsistentRecords = $this->findInconsistentRecords();
        
        $this->table(['Metrik', 'Jumlah'], [
            ['Total Produk', $totalProduk],
            ['Produk Stok Minus', $produkStokMinus],
            ['Produk Stok Nol', $produkStokNol],
            ['Total Rekaman Stok', $totalRekaman],
            ['Rekaman Stok Awal Minus', $rekamanAwalMinus],
            ['Rekaman Stok Sisa Minus', $rekamanSisaMinus],
            ['Rekaman Tidak Konsisten', $inconsistentRecords->count()],
        ]);
    }

    private function findInconsistentRecords()
    {
        $rawRecords = DB::table('rekaman_stoks as rs')
            ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
            ->where(function($query) {
                $query->whereRaw('rs.stok_awal != p.stok')
                      ->orWhereRaw('rs.stok_sisa != p.stok')
                      ->orWhere('rs.stok_awal', '<', 0)
                      ->orWhere('rs.stok_sisa', '<', 0);
            })
            ->whereIn('rs.id_rekaman_stok', function($subquery) {
                $subquery->select(DB::raw('MAX(id_rekaman_stok)'))
                         ->from('rekaman_stoks')
                         ->groupBy('id_produk');
            })
            ->select('rs.id_rekaman_stok', 'rs.id_produk', 'p.nama_produk', 'p.stok', 'rs.stok_awal', 'rs.stok_sisa', 'rs.created_at')
            ->orderBy('p.nama_produk')
            ->get();

        $inconsistentRecords = collect();
        
        foreach ($rawRecords as $record) {
            if ($record->stok == 0 && $record->stok_awal == 0 && $record->stok_sisa == 0) {
                continue;
            }
            
            $inconsistentRecords->push($record);
        }
        
        return $inconsistentRecords;
    }

    private function performSynchronization($dryRun = false)
    {
        $this->info("\n=== MEMULAI SINKRONISASI ===");
        $stats = [
            'products_synced' => 0,
            'records_created' => 0,
            'negative_records_fixed' => 0,
            'negative_products_fixed' => 0
        ];

        if (!$dryRun) {
            DB::beginTransaction();
        }

        try {
            $stats = array_merge($stats, $this->syncRecordConsistency($dryRun));
            $stats = array_merge($stats, $this->fixNegativeRecords($dryRun));
            $stats = array_merge($stats, $this->fixNegativeProductStock($dryRun));

            if (!$dryRun) {
                $this->createAuditRecord($stats);
                DB::commit();
                $this->info("\nSinkronisasi berhasil disimpan ke database");
            } else {
                $this->info("\nAnalisis selesai (tidak ada perubahan disimpan)");
            }

            $this->displayResults($stats);
            return 0;

        } catch (\Exception $e) {
            if (!$dryRun) {
                DB::rollBack();
            }
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }

    private function syncRecordConsistency($dryRun)
    {
        $this->info("1. Memeriksa konsistensi rekaman stok...");
        $stats = ['products_synced' => 0];
        
        $inconsistentQuery = DB::table('rekaman_stoks as rs')
            ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
            ->where(function($query) {
                $query->whereRaw('rs.stok_awal != p.stok')
                      ->orWhereRaw('rs.stok_sisa != p.stok')
                      ->orWhere('rs.stok_awal', '<', 0)
                      ->orWhere('rs.stok_sisa', '<', 0);
            })
            ->whereIn('rs.id_rekaman_stok', function($subquery) {
                $subquery->select(DB::raw('MAX(id_rekaman_stok)'))
                         ->from('rekaman_stoks')
                         ->groupBy('id_produk');
            })
            ->select('rs.id_rekaman_stok', 'rs.id_produk', 'rs.stok_awal', 'rs.stok_sisa', 'p.stok as current_stock', 'p.nama_produk')
            ->orderBy('p.nama_produk')
            ->get();

        if ($inconsistentQuery->count() > 0) {
            $this->info("Ditemukan " . $inconsistentQuery->count() . " rekaman yang tidak konsisten:");
            
            foreach ($inconsistentQuery as $record) {
                $this->line("- {$record->nama_produk}: Stok = {$record->current_stock}, Rekaman awal = {$record->stok_awal}, Rekaman sisa = {$record->stok_sisa}");
                
                if (!$dryRun) {
                    $correctStock = max(0, $record->current_stock);
                    
                    $updated = DB::table('rekaman_stoks')
                        ->where('id_rekaman_stok', $record->id_rekaman_stok)
                        ->update([
                            'stok_awal' => $correctStock,
                            'stok_sisa' => $correctStock
                        ]);
                    
                    if ($updated) {
                        $stats['products_synced']++;
                    }
                }
            }
        } else {
            $this->info("Semua rekaman stok sudah konsisten");
        }

        return $stats;
    }

    private function fixNegativeRecords($dryRun)
    {
        $this->info("\n2. Memperbaiki rekaman stok minus...");
        $stats = ['negative_records_fixed' => 0];
        
        // Hanya ambil rekaman minus dari rekaman terbaru per produk
        $negativeRecords = DB::table('rekaman_stoks as rs')
            ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
            ->where(function($query) {
                $query->where('rs.stok_awal', '<', 0)
                      ->orWhere('rs.stok_sisa', '<', 0);
            })
            ->whereIn('rs.id_rekaman_stok', function($subquery) {
                $subquery->select(DB::raw('MAX(id_rekaman_stok)'))
                         ->from('rekaman_stoks')
                         ->groupBy('id_produk');
            })
            ->select('rs.id_rekaman_stok', 'rs.id_produk', 'rs.stok_awal', 'rs.stok_sisa', 'p.stok as current_stock', 'p.nama_produk')
            ->get();

        if ($negativeRecords->count() > 0) {
            $this->info("Ditemukan " . $negativeRecords->count() . " rekaman dengan nilai minus");
            
            foreach ($negativeRecords as $record) {
                $changes = [];
                
                if ($record->stok_awal < 0) {
                    $changes[] = "stok_awal: {$record->stok_awal} -> {$record->current_stock}";
                }
                
                if ($record->stok_sisa < 0) {
                    $changes[] = "stok_sisa: {$record->stok_sisa} -> {$record->current_stock}";
                }
                
                if (count($changes) > 0) {
                    $this->line("Rekaman {$record->id_rekaman_stok} ({$record->nama_produk}): " . implode(', ', $changes));
                    
                    if (!$dryRun) {
                        $updateData = [];
                        if ($record->stok_awal < 0) {
                            $updateData['stok_awal'] = $record->current_stock;
                        }
                        if ($record->stok_sisa < 0) {
                            $updateData['stok_sisa'] = $record->current_stock;
                        }
                        
                        $updated = DB::table('rekaman_stoks')
                            ->where('id_rekaman_stok', $record->id_rekaman_stok)
                            ->update($updateData);
                        
                        if ($updated) {
                            $stats['negative_records_fixed']++;
                        }
                    }
                }
            }
        } else {
            $this->info("Tidak ada rekaman minus");
        }
        
        return $stats;
    }

    private function fixNegativeProductStock($dryRun)
    {
        $this->info("\n3. Memperbaiki stok produk minus...");
        $stats = ['negative_products_fixed' => 0];
        
        $negativeProducts = Produk::where('stok', '<', 0)->get();
        
        if ($negativeProducts->count() > 0) {
            $this->info("Ditemukan " . $negativeProducts->count() . " produk dengan stok minus");
            
            foreach ($negativeProducts as $product) {
                $oldStock = $product->stok;
                $this->line("{$product->nama_produk}: {$oldStock} -> 0");
                
                if (!$dryRun) {
                    $product->stok = 0;
                    $product->save();
                    
                    RekamanStok::create([
                        'id_produk' => $product->id_produk,
                        'waktu' => Carbon::now(),
                        'stok_masuk' => 0,
                        'stok_keluar' => abs($oldStock),
                        'stok_awal' => $oldStock,
                        'stok_sisa' => 0,
                        'keterangan' => 'Sinkronisasi: Koreksi stok minus menjadi 0'
                    ]);
                }
                
                $stats['negative_products_fixed']++;
            }
        } else {
            $this->info("Tidak ada produk dengan stok minus");
        }
        
        return $stats;
    }

    private function createAuditRecord($stats)
    {
        $keterangan = "Sinkronisasi Console " . Carbon::now()->format('Y-m-d H:i:s') . 
                     " | Rekaman disamakan: {$stats['products_synced']} | " .
                     "Rekaman minus diperbaiki: {$stats['negative_records_fixed']} | " .
                     "Produk minus diperbaiki: {$stats['negative_products_fixed']}";
        
        RekamanStok::create([
            'id_produk' => 1,
            'waktu' => Carbon::now(),
            'stok_masuk' => 0,
            'stok_keluar' => 0,
            'stok_awal' => 0,
            'stok_sisa' => 0,
            'keterangan' => $keterangan
        ]);
    }

    private function displayResults($stats)
    {
        $this->info("\n=== HASIL SINKRONISASI ===");
        $this->table(['Item', 'Jumlah'], [
            ['Rekaman stok yang disamakan', $stats['products_synced']],
            ['Rekaman minus diperbaiki', $stats['negative_records_fixed']],
            ['Produk minus diperbaiki', $stats['negative_products_fixed']],
        ]);
    }
}
