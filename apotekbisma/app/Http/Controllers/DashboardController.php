<?php

namespace App\Http\Controllers;

use App\Models\Kategori;
use App\Models\Member;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Pengeluaran;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Get period from request, default to current month
        $period = $request->get('period', 'month');
        $custom_start = $request->get('start_date');
        $custom_end = $request->get('end_date');

        // Calculate date ranges based on period
        $dateRange = $this->getDateRange($period, $custom_start, $custom_end);
        $tanggal_awal = $dateRange['start'];
        $tanggal_akhir = $dateRange['end'];

        // Basic counts
        $kategori = Kategori::count();
        $produk = Produk::count();
        $supplier = Supplier::count();
        $member = Member::count();

        if (auth()->user()->level == 1) {
            // Get comprehensive analytics for admin
            $analytics = $this->getAnalytics($tanggal_awal, $tanggal_akhir, $period);
            
            return view('admin.dashboard', compact(
                'kategori', 'produk', 'supplier', 'member', 
                'tanggal_awal', 'tanggal_akhir', 'analytics', 'period'
            ));
        } else {
            // Get kasir-specific analytics for today
            $analytics = $this->getKasirAnalytics();
            return view('kasir.dashboard', compact('analytics', 'period'));
        }
    }

    private function getDateRange($period, $custom_start = null, $custom_end = null)
    {
        $now = Carbon::now();
        
        switch ($period) {
            case 'today':
                return [
                    'start' => $now->format('Y-m-d'),
                    'end' => $now->format('Y-m-d')
                ];
            case 'week':
                return [
                    'start' => $now->startOfWeek()->format('Y-m-d'),
                    'end' => $now->endOfWeek()->format('Y-m-d')
                ];
            case 'month':
                return [
                    'start' => $now->startOfMonth()->format('Y-m-d'),
                    'end' => $now->endOfMonth()->format('Y-m-d')
                ];
            case 'year':
                return [
                    'start' => $now->startOfYear()->format('Y-m-d'),
                    'end' => $now->endOfYear()->format('Y-m-d')
                ];
            case 'custom':
                return [
                    'start' => $custom_start ?: $now->startOfMonth()->format('Y-m-d'),
                    'end' => $custom_end ?: $now->format('Y-m-d')
                ];
            default:
                return [
                    'start' => $now->startOfMonth()->format('Y-m-d'),
                    'end' => $now->format('Y-m-d')
                ];
        }
    }

    private function getAnalytics($tanggal_awal, $tanggal_akhir, $period)
    {
        // Revenue calculations
        $total_penjualan = Penjualan::whereBetween('created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                   ->sum('bayar');

        $total_pembelian = Pembelian::whereBetween('created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                   ->sum('bayar');

        $total_pengeluaran = Pengeluaran::whereBetween('created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                        ->sum('nominal');

        // Profit calculation (selling price - buying price)
        $profit_data = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                     ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                     ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                     ->selectRaw('
                                         SUM(penjualan_detail.jumlah * (penjualan_detail.harga_jual - produk.harga_beli)) as laba_kotor,
                                         SUM(penjualan_detail.jumlah) as total_qty_terjual,
                                         COUNT(DISTINCT penjualan.id_penjualan) as total_transaksi
                                     ')
                                     ->first();

        $laba_kotor = $profit_data->laba_kotor ?? 0;
        $laba_bersih = $laba_kotor - $total_pengeluaran;
        $total_qty_terjual = $profit_data->total_qty_terjual ?? 0;
        $total_transaksi = $profit_data->total_transaksi ?? 0;

        // Best selling products
        $produk_terlaris = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                         ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                         ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                         ->selectRaw('
                                             produk.id_produk,
                                             produk.nama_produk,
                                             produk.kode_produk,
                                             SUM(penjualan_detail.jumlah) as total_terjual,
                                             SUM(penjualan_detail.subtotal) as total_revenue,
                                             SUM(penjualan_detail.jumlah * (penjualan_detail.harga_jual - produk.harga_beli)) as total_profit
                                         ')
                                         ->groupBy('produk.id_produk', 'produk.nama_produk', 'produk.kode_produk')
                                         ->orderBy('total_terjual', 'desc')
                                         ->take(10)
                                         ->get();

        // Least selling products (products that have been sold but with low quantities)
        $produk_kurang_laris = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                             ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                             ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                             ->selectRaw('
                                                 produk.id_produk,
                                                 produk.nama_produk,
                                                 produk.kode_produk,
                                                 SUM(penjualan_detail.jumlah) as total_terjual,
                                                 SUM(penjualan_detail.subtotal) as total_revenue
                                             ')
                                             ->groupBy('produk.id_produk', 'produk.nama_produk', 'produk.kode_produk')
                                             ->orderBy('total_terjual', 'asc')
                                             ->take(10)
                                             ->get();

        // Chart data generation
        $chartData = $this->generateChartData($tanggal_awal, $tanggal_akhir, $period);

        // Recent transactions
        $transaksi_terbaru = Penjualan::with('detail.produk')
                                     ->whereBetween('created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                     ->orderBy('created_at', 'desc')
                                     ->take(10)
                                     ->get();

        // Low stock products
        $stok_menipis = Produk::where('stok', '<=', 5)
                             ->orderBy('stok', 'asc')
                             ->take(10)
                             ->get();

        return [
            'total_penjualan' => $total_penjualan,
            'total_pembelian' => $total_pembelian,
            'total_pengeluaran' => $total_pengeluaran,
            'laba_kotor' => $laba_kotor,
            'laba_bersih' => $laba_bersih,
            'total_qty_terjual' => $total_qty_terjual,
            'total_transaksi' => $total_transaksi,
            'produk_terlaris' => $produk_terlaris,
            'produk_kurang_laris' => $produk_kurang_laris,
            'transaksi_terbaru' => $transaksi_terbaru,
            'stok_menipis' => $stok_menipis,
            'chart_data' => $chartData,
        ];
    }

    private function generateChartData($tanggal_awal, $tanggal_akhir, $period)
    {
        $data_tanggal = [];
        $data_penjualan = [];
        $data_pembelian = [];
        $data_pendapatan = [];
        $data_transaksi = [];

        $start = Carbon::parse($tanggal_awal);
        $end = Carbon::parse($tanggal_akhir);

        switch ($period) {
            case 'today':
                // Hourly data for today
                for ($hour = 0; $hour < 24; $hour++) {
                    $hour_start = $start->copy()->setHour($hour)->setMinute(0)->setSecond(0);
                    $hour_end = $hour_start->copy()->setMinute(59)->setSecond(59);

                    $data_tanggal[] = $hour_start->format('H:i');
                    
                    $penjualan = Penjualan::whereBetween('created_at', [$hour_start, $hour_end])->sum('bayar') ?? 0;
                    $pembelian = Pembelian::whereBetween('created_at', [$hour_start, $hour_end])->sum('bayar') ?? 0;
                    $pengeluaran = Pengeluaran::whereBetween('created_at', [$hour_start, $hour_end])->sum('nominal') ?? 0;
                    $transaksi_count = Penjualan::whereBetween('created_at', [$hour_start, $hour_end])->count() ?? 0;

                    $data_penjualan[] = (float) $penjualan;
                    $data_pembelian[] = (float) $pembelian;
                    $data_pendapatan[] = (float) ($penjualan - $pembelian - $pengeluaran);
                    $data_transaksi[] = (int) $transaksi_count;
                }
                break;

            case 'week':
                // Daily data for the week
                $current = $start->copy();
                while ($current <= $end) {
                    $day_start = $current->copy()->startOfDay();
                    $day_end = $current->copy()->endOfDay();

                    $data_tanggal[] = $current->format('D, M d');
                    
                    $penjualan = Penjualan::whereBetween('created_at', [$day_start, $day_end])->sum('bayar') ?? 0;
                    $pembelian = Pembelian::whereBetween('created_at', [$day_start, $day_end])->sum('bayar') ?? 0;
                    $pengeluaran = Pengeluaran::whereBetween('created_at', [$day_start, $day_end])->sum('nominal') ?? 0;
                    $transaksi_count = Penjualan::whereBetween('created_at', [$day_start, $day_end])->count() ?? 0;

                    $data_penjualan[] = (float) $penjualan;
                    $data_pembelian[] = (float) $pembelian;
                    $data_pendapatan[] = (float) ($penjualan - $pembelian - $pengeluaran);
                    $data_transaksi[] = (int) $transaksi_count;

                    $current->addDay();
                }
                break;

            case 'month':
                // Daily data for the month
                $current = $start->copy();
                while ($current <= $end) {
                    $day_start = $current->copy()->startOfDay();
                    $day_end = $current->copy()->endOfDay();

                    $data_tanggal[] = $current->format('d');
                    
                    $penjualan = Penjualan::whereBetween('created_at', [$day_start, $day_end])->sum('bayar') ?? 0;
                    $pembelian = Pembelian::whereBetween('created_at', [$day_start, $day_end])->sum('bayar') ?? 0;
                    $pengeluaran = Pengeluaran::whereBetween('created_at', [$day_start, $day_end])->sum('nominal') ?? 0;
                    $transaksi_count = Penjualan::whereBetween('created_at', [$day_start, $day_end])->count() ?? 0;

                    $data_penjualan[] = (float) $penjualan;
                    $data_pembelian[] = (float) $pembelian;
                    $data_pendapatan[] = (float) ($penjualan - $pembelian - $pengeluaran);
                    $data_transaksi[] = (int) $transaksi_count;

                    $current->addDay();
                }
                break;

            case 'year':
                // Monthly data for the year
                $current = $start->copy()->startOfMonth();
                $end_month = $end->copy()->endOfMonth();
                
                while ($current <= $end_month) {
                    $month_start = $current->copy()->startOfMonth();
                    $month_end = $current->copy()->endOfMonth();

                    $data_tanggal[] = $current->format('M Y');
                    
                    $penjualan = Penjualan::whereBetween('created_at', [$month_start, $month_end])->sum('bayar') ?? 0;
                    $pembelian = Pembelian::whereBetween('created_at', [$month_start, $month_end])->sum('bayar') ?? 0;
                    $pengeluaran = Pengeluaran::whereBetween('created_at', [$month_start, $month_end])->sum('nominal') ?? 0;
                    $transaksi_count = Penjualan::whereBetween('created_at', [$month_start, $month_end])->count() ?? 0;

                    $data_penjualan[] = (float) $penjualan;
                    $data_pembelian[] = (float) $pembelian;
                    $data_pendapatan[] = (float) ($penjualan - $pembelian - $pengeluaran);
                    $data_transaksi[] = (int) $transaksi_count;

                    $current->addMonth();
                }
                break;

            default:
                // Daily data for custom period
                $current = $start->copy();
                while ($current <= $end) {
                    $day_start = $current->copy()->startOfDay();
                    $day_end = $current->copy()->endOfDay();

                    $data_tanggal[] = $current->format('M d');
                    
                    $penjualan = Penjualan::whereBetween('created_at', [$day_start, $day_end])->sum('bayar') ?? 0;
                    $pembelian = Pembelian::whereBetween('created_at', [$day_start, $day_end])->sum('bayar') ?? 0;
                    $pengeluaran = Pengeluaran::whereBetween('created_at', [$day_start, $day_end])->sum('nominal') ?? 0;
                    $transaksi_count = Penjualan::whereBetween('created_at', [$day_start, $day_end])->count() ?? 0;

                    $data_penjualan[] = (float) $penjualan;
                    $data_pembelian[] = (float) $pembelian;
                    $data_pendapatan[] = (float) ($penjualan - $pembelian - $pengeluaran);
                    $data_transaksi[] = (int) $transaksi_count;

                    $current->addDay();
                }
                break;
        }

        // If no data exists, add some sample data for demonstration
        if (array_sum($data_penjualan) == 0 && array_sum($data_transaksi) == 0) {
            // Keep the same structure but ensure we have valid data to display charts
            $data_penjualan = array_fill(0, count($data_tanggal), 0);
            $data_pembelian = array_fill(0, count($data_tanggal), 0);
            $data_pendapatan = array_fill(0, count($data_tanggal), 0);
            $data_transaksi = array_fill(0, count($data_tanggal), 0);
        }

        return [
            'labels' => $data_tanggal,
            'penjualan' => $data_penjualan,
            'pembelian' => $data_pembelian,
            'pendapatan' => $data_pendapatan,
            'transaksi' => $data_transaksi,
        ];
    }

    private function getKasirAnalytics()
    {
        $today = Carbon::today();
        $tanggal_awal = $today->format('Y-m-d');
        $tanggal_akhir = $today->format('Y-m-d');

        // Today's sales data
        $total_penjualan = Penjualan::whereBetween('created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                   ->sum('bayar');

        // Profit calculation for today
        $profit_data = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                     ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                     ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                     ->selectRaw('
                                         SUM(penjualan_detail.jumlah * (penjualan_detail.harga_jual - produk.harga_beli)) as laba_kotor,
                                         SUM(penjualan_detail.jumlah) as total_qty_terjual,
                                         COUNT(DISTINCT penjualan.id_penjualan) as total_transaksi
                                     ')
                                     ->first();

        $laba_kotor = $profit_data->laba_kotor ?? 0;
        $total_qty_terjual = $profit_data->total_qty_terjual ?? 0;
        $total_transaksi = $profit_data->total_transaksi ?? 0;

        // Today's best selling products
        $produk_terlaris = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                         ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                         ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                         ->selectRaw('
                                             produk.id_produk,
                                             produk.nama_produk,
                                             produk.kode_produk,
                                             SUM(penjualan_detail.jumlah) as total_terjual,
                                             SUM(penjualan_detail.subtotal) as total_revenue
                                         ')
                                         ->groupBy('produk.id_produk', 'produk.nama_produk', 'produk.kode_produk')
                                         ->orderBy('total_terjual', 'desc')
                                         ->take(5)
                                         ->get();

        // Recent transactions for today
        $transaksi_terbaru = Penjualan::whereBetween('created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                     ->orderBy('created_at', 'desc')
                                     ->take(8)
                                     ->get();

        // Hourly chart data for kasir
        $chartData = $this->generateKasirChartData($tanggal_awal);

        return [
            'total_penjualan' => $total_penjualan,
            'laba_kotor' => $laba_kotor,
            'total_qty_terjual' => $total_qty_terjual,
            'total_transaksi' => $total_transaksi,
            'produk_terlaris' => $produk_terlaris,
            'transaksi_terbaru' => $transaksi_terbaru,
            'chart_data' => $chartData,
        ];
    }

    private function generateKasirChartData($tanggal)
    {
        $data_tanggal = [];
        $data_penjualan = [];

        $start = Carbon::parse($tanggal);

        // Generate hourly data for today
        for ($hour = 6; $hour <= 22; $hour++) { // Store hours 6 AM to 10 PM
            $hour_start = $start->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            $hour_end = $hour_start->copy()->setMinute(59)->setSecond(59);

            $data_tanggal[] = $hour_start->format('H:i');
            
            $penjualan = Penjualan::whereBetween('created_at', [$hour_start, $hour_end])->sum('bayar') ?? 0;
            $data_penjualan[] = (float) $penjualan;
        }

        // If no sales data exists, set all values to 0 but keep the structure
        if (array_sum($data_penjualan) == 0) {
            $data_penjualan = array_fill(0, count($data_tanggal), 0);
        }

        return [
            'labels' => $data_tanggal,
            'penjualan' => $data_penjualan,
        ];
    }
}
