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
        return response()->json([
            'success' => false,
            'message' => '🚨 FITUR SINKRONISASI DINONAKTIFKAN UNTUK KEAMANAN DATA!',
            'details' => [
                'reason' => 'Fitur ini dapat merusak integritas data dan audit trail',
                'recommendation' => 'Sistem sudah dilindungi dengan Observer auto-correction',
                'safe_alternative' => 'Gunakan penyesuaian stok manual melalui transaksi pembelian/penjualan'
            ]
        ], 400);
    }
    
    private function performSimpleSync()
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $output = "=== FITUR SINKRONISASI DINONAKTIFKAN ===\n";
        $output .= "Waktu: $timestamp\n\n";
        $output .= "🚨 PERINGATAN KEAMANAN:\n";
        $output .= "Fitur sinkronisasi ini telah dinonaktifkan karena BERBAHAYA!\n\n";
        $output .= "ALASAN:\n";
        $output .= "- Menghancurkan audit trail dan history transaksi\n";
        $output .= "- Menulis ulang stok_awal dan stok_sisa secara paksa\n";
        $output .= "- Menciptakan konsistensi palsu yang menyembunyikan masalah\n";
        $output .= "- Dapat menyebabkan anomali stok seperti '10 + 15 = 0'\n\n";
        $output .= "SOLUSI AMAN:\n";
        $output .= "- Sistem Observer sudah otomatis mencegah kesalahan matematika\n";
        $output .= "- Gunakan penyesuaian stok manual melalui transaksi\n";
        $output .= "- Manual adjustment lebih aman dan teraudit\n\n";
        $output .= "Status: DISABLED FOR SAFETY\n";
        
        return [
            'output' => $output,
            'fixed_count' => 0,
            'success' => false,
            'disabled' => true,
            'message' => 'Fitur dinonaktifkan untuk melindungi integritas data'
        ];
    }

    public function getAnalysisData()
    {
        $analysis = $this->getStockAnalysis();
        return response()->json($analysis);
    }
}
