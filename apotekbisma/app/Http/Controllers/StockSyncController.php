<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\PembelianDetail;
use App\Models\PenjualanDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class StockSyncController extends Controller
{
    public function index()
    {
        $analysis = $this->getStockAnalysis();
        return view('admin.stock-sync.index', compact('analysis'));
    }

    public function getStockAnalysis()
    {
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
        
        $recentSyncRecords = RekamanStok::where('keterangan', 'LIKE', '%Sinkronisasi%')
                                      ->orderBy('waktu', 'desc')
                                      ->limit(5)
                                      ->get();

        return [
            'summary' => [
                'total_produk' => $totalProduk,
                'produk_stok_minus' => $produkStokMinus,
                'produk_stok_nol' => $produkStokNol,
                'total_rekaman' => $totalRekaman,
                'rekaman_awal_minus' => $rekamanAwalMinus,
                'rekaman_sisa_minus' => $rekamanSisaMinus,
            ],
            'inconsistent_products' => $inconsistentRecords,
            'recent_sync_records' => $recentSyncRecords,
            'health_score' => $this->calculateHealthScore($produkStokMinus, $rekamanAwalMinus, $rekamanSisaMinus, $inconsistentRecords->count())
        ];
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
            
            $record->calculated_stock = 0;
            $record->difference = 0;
            $inconsistentRecords->push($record);
        }
        
        return $inconsistentRecords->take(20);
    }

    private function calculateHealthScore($produkMinus, $rekamanAwalMinus, $rekamanSisaMinus, $inconsistentCount)
    {
        $totalProduk = Produk::count();
        
        if ($totalProduk == 0) {
            return 100;
        }
        
        $healthScore = round((($totalProduk - $inconsistentCount) / $totalProduk) * 100, 2);
        
        return max(0, $healthScore);
    }

    public function performSync(Request $request)
    {
        try {
            // Set memory dan timeout
            ini_set('memory_limit', '512M');
            set_time_limit(120);
            
            // Log untuk debugging
            $logFile = storage_path('logs/web-sync-simple.log');
            $timestamp = now()->format('Y-m-d H:i:s');
            $logEntry = "[$timestamp] Web sync started\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
            // SISTEM SINKRONISASI SEDERHANA
            $result = $this->performSimpleSync();
            
            $logEntry = "[$timestamp] Web sync completed: " . json_encode($result) . "\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
            return response()->json([
                'success' => true,
                'message' => 'Sinkronisasi berhasil!',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            $logEntry = "[$timestamp] Web sync error: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
    
    private function performSimpleSync()
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $output = "=== SINKRONISASI SEDERHANA ===\n";
        $output .= "Waktu: $timestamp\n\n";
        
        $fixedCount = 0;
        
        // 1. Cari semua produk dengan rekaman stok yang tidak konsisten
        $inconsistentData = DB::table('rekaman_stoks as rs')
            ->join('produk as p', 'rs.id_produk', '=', 'p.id_produk')
            ->select('p.id_produk', 'p.nama_produk', 'p.stok as current_stok', 'rs.id_rekaman_stok', 'rs.stok_awal', 'rs.stok_sisa')
            ->where(function($query) {
                $query->whereRaw('rs.stok_awal != p.stok')
                      ->orWhereRaw('rs.stok_sisa != p.stok');
            })
            ->whereIn('rs.id_rekaman_stok', function($query) {
                $query->select(DB::raw('MAX(id_rekaman_stok)'))
                      ->from('rekaman_stoks')
                      ->groupBy('id_produk');
            })
            ->get();
        
        $output .= "Ditemukan " . $inconsistentData->count() . " produk yang tidak konsisten:\n";
        
        // 2. Perbaiki satu per satu
        foreach ($inconsistentData as $data) {
            $output .= "- {$data->nama_produk}: ";
            
            // Update rekaman stok sesuai dengan stok produk saat ini
            DB::table('rekaman_stoks')
                ->where('id_rekaman_stok', $data->id_rekaman_stok)
                ->update([
                    'stok_awal' => $data->current_stok,
                    'stok_sisa' => $data->current_stok,
                    'updated_at' => now()
                ]);
            
            $output .= "Disamakan menjadi {$data->current_stok}\n";
            $fixedCount++;
        }
        
        if ($fixedCount == 0) {
            $output .= "Tidak ada data yang perlu diperbaiki\n";
        }
        
        $output .= "\n=== HASIL ===\n";
        $output .= "Total diperbaiki: $fixedCount produk\n";
        
        return [
            'output' => $output,
            'fixed_count' => $fixedCount,
            'success' => true
        ];
    }

    public function getAnalysisData()
    {
        $analysis = $this->getStockAnalysis();
        return response()->json($analysis);
    }
}
