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
use App\Models\RekamanStok;
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
            
            // Get stock health status
            $stockHealth = $this->getStockHealthStatus();
            
            return view('admin.dashboard', compact(
                'kategori', 'produk', 'supplier', 'member', 
                'tanggal_awal', 'tanggal_akhir', 'analytics', 'period', 'stockHealth'
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

        // Supplier dengan pemasok produk terbanyak (berdasarkan total barang yang dibeli)
        $supplier_terbanyak = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                            ->join('supplier', 'pembelian.id_supplier', '=', 'supplier.id_supplier')
                                            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
                                            ->selectRaw('
                                                supplier.id_supplier,
                                                supplier.nama,
                                                supplier.telepon,
                                                supplier.alamat,
                                                SUM(pembelian_detail.jumlah) as total_barang_dibeli,
                                                COUNT(DISTINCT pembelian_detail.id_produk) as jenis_produk,
                                                SUM(pembelian_detail.subtotal) as total_pembelian
                                            ')
                                            ->groupBy('supplier.id_supplier', 'supplier.nama', 'supplier.telepon', 'supplier.alamat')
                                            ->orderBy('total_barang_dibeli', 'desc')
                                            ->take(10)
                                            ->get();

        // Produk yang paling sering dibeli dari setiap supplier (top 3 untuk setiap supplier teratas)
        $produk_per_supplier = [];
        $topSupplierIds = $supplier_terbanyak->take(5)->pluck('id_supplier');
        if ($topSupplierIds->isNotEmpty()) {
            $produkTerlarisSupplierRows = PembelianDetail::join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                                        ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
                                                        ->whereIn('pembelian.id_supplier', $topSupplierIds->all())
                                                        ->selectRaw('
                                                            pembelian.id_supplier,
                                                            produk.id_produk,
                                                            produk.nama_produk,
                                                            produk.kode_produk,
                                                            SUM(pembelian_detail.jumlah) as total_dibeli,
                                                            COUNT(pembelian_detail.id_pembelian_detail) as frekuensi_beli,
                                                            SUM(pembelian_detail.subtotal) as total_nilai
                                                        ')
                                                        ->groupBy('pembelian.id_supplier', 'produk.id_produk', 'produk.nama_produk', 'produk.kode_produk')
                                                        ->orderBy('pembelian.id_supplier', 'asc')
                                                        ->orderBy('total_dibeli', 'desc')
                                                        ->get();

            $supplierProdukBySupplier = $produkTerlarisSupplierRows->groupBy('id_supplier');
            foreach ($topSupplierIds as $supplierId) {
                $supplier = $supplier_terbanyak->firstWhere('id_supplier', $supplierId);
                $produk_per_supplier[$supplierId] = [
                    'supplier_info' => $supplier,
                    'produk_terlaris' => $supplierProdukBySupplier->get($supplierId, collect())->take(3),
                ];
            }
        }

        // Produk favorit per supplier berdasarkan penjualan terbanyak
        $produk_favorit_penjualan_per_supplier = [];
        
        // Ambil semua supplier yang memiliki produk yang terjual dalam periode ini
        $supplier_dengan_penjualan = DB::table('penjualan_detail')
                                      ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                      ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                      ->join('pembelian_detail', 'produk.id_produk', '=', 'pembelian_detail.id_produk')
                                      ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                      ->join('supplier', 'pembelian.id_supplier', '=', 'supplier.id_supplier')
                                      ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                      ->selectRaw('
                                          supplier.id_supplier,
                                          supplier.nama,
                                          COUNT(DISTINCT penjualan_detail.id_produk) as jenis_produk_terjual,
                                          SUM(penjualan_detail.jumlah) as total_qty_terjual
                                      ')
                                      ->groupBy('supplier.id_supplier', 'supplier.nama')
                                      ->orderBy('total_qty_terjual', 'desc')
                                      ->take(8)
                                      ->get();

        $salesSupplierIds = $supplier_dengan_penjualan->pluck('id_supplier');
        if ($salesSupplierIds->isNotEmpty()) {
            $produkTerlarisPenjualanRows = DB::table('penjualan_detail')
                                          ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                          ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                          ->join('pembelian_detail', 'produk.id_produk', '=', 'pembelian_detail.id_produk')
                                          ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                                          ->whereIn('pembelian.id_supplier', $salesSupplierIds->all())
                                          ->whereBetween('penjualan.created_at', [$tanggal_awal . ' 00:00:00', $tanggal_akhir . ' 23:59:59'])
                                          ->selectRaw('
                                              pembelian.id_supplier,
                                              produk.id_produk,
                                              produk.nama_produk,
                                              produk.kode_produk,
                                              SUM(penjualan_detail.jumlah) as total_terjual,
                                              COUNT(DISTINCT penjualan_detail.id_penjualan_detail) as frekuensi_terjual,
                                              SUM(penjualan_detail.subtotal) as total_revenue,
                                              SUM(penjualan_detail.jumlah * (penjualan_detail.harga_jual - produk.harga_beli)) as total_profit
                                          ')
                                          ->groupBy('pembelian.id_supplier', 'produk.id_produk', 'produk.nama_produk', 'produk.kode_produk')
                                          ->orderBy('pembelian.id_supplier', 'asc')
                                          ->orderBy('total_terjual', 'desc')
                                          ->get();

            $salesProdukBySupplier = $produkTerlarisPenjualanRows->groupBy('id_supplier');
            foreach ($supplier_dengan_penjualan as $supplier) {
                $favoritRows = $salesProdukBySupplier->get($supplier->id_supplier, collect())->take(3);
                if ($favoritRows->count() > 0) {
                    $produk_favorit_penjualan_per_supplier[$supplier->id_supplier] = [
                        'supplier_info' => $supplier,
                        'produk_terlaris' => $favoritRows,
                    ];
                }
            }
        }

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
            'supplier_terbanyak' => $supplier_terbanyak,
            'produk_per_supplier' => $produk_per_supplier,
            'produk_favorit_penjualan_per_supplier' => $produk_favorit_penjualan_per_supplier,
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

        $keyFormat = null;
        $labelCallback = null;

        switch ($period) {
            case 'today':
                $keyFormat = 'Y-m-d H:00:00';
                $labelCallback = fn (Carbon $moment) => $moment->format('H:i');
                break;

            case 'week':
                $keyFormat = 'Y-m-d';
                $labelCallback = fn (Carbon $moment) => $moment->format('D, M d');
                break;

            case 'month':
                $keyFormat = 'Y-m-d';
                $labelCallback = fn (Carbon $moment) => $moment->format('d');
                break;

            case 'year':
                $keyFormat = 'Y-m';
                $labelCallback = fn (Carbon $moment) => $moment->format('M Y');
                break;

            default:
                $keyFormat = 'Y-m-d';
                $labelCallback = fn (Carbon $moment) => $moment->format('M d');
                break;
        }

        $rangeStart = $start->copy();
        $rangeEnd = $end->copy()->endOfDay();

        if ($period === 'today') {
            $rangeStart = $start->copy()->startOfDay();
            $labelMoment = fn (Carbon $moment) => $moment->setMinute(0)->setSecond(0);
            $bucketFormat = 'Y-m-d H:00:00';
            $step = 'addHour';
        } elseif ($period === 'year') {
            $rangeStart = $start->copy()->startOfMonth();
            $rangeEnd = $end->copy()->endOfMonth();
            $labelMoment = fn (Carbon $moment) => $moment;
            $bucketFormat = 'Y-m';
            $step = 'addMonth';
        } else {
            $labelMoment = fn (Carbon $moment) => $moment->startOfDay();
            $bucketFormat = 'Y-m-d';
            $step = 'addDay';
        }

        $penjualanByBucket = Penjualan::whereBetween('created_at', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket, SUM(bayar) as total', [$bucketFormat === 'Y-m' ? '%Y-%m' : ($bucketFormat === 'Y-m-d H:00:00' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d')])
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($value) => (float) $value)
            ->all();

        $pembelianByBucket = Pembelian::whereBetween('created_at', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket, SUM(bayar) as total', [$bucketFormat === 'Y-m' ? '%Y-%m' : ($bucketFormat === 'Y-m-d H:00:00' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d')])
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($value) => (float) $value)
            ->all();

        $pengeluaranByBucket = Pengeluaran::whereBetween('created_at', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket, SUM(nominal) as total', [$bucketFormat === 'Y-m' ? '%Y-%m' : ($bucketFormat === 'Y-m-d H:00:00' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d')])
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($value) => (float) $value)
            ->all();

        $transaksiByBucket = Penjualan::whereBetween('created_at', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket, COUNT(*) as total', [$bucketFormat === 'Y-m' ? '%Y-%m' : ($bucketFormat === 'Y-m-d H:00:00' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d')])
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($value) => (int) $value)
            ->all();

        $current = $rangeStart->copy();
        while ($current <= $rangeEnd) {
            $bucketKey = $labelMoment($current->copy())->format($keyFormat);
            $data_tanggal[] = $labelCallback($labelMoment($current->copy()));

            $penjualan = $penjualanByBucket[$bucketKey] ?? 0;
            $pembelian = $pembelianByBucket[$bucketKey] ?? 0;
            $pengeluaran = $pengeluaranByBucket[$bucketKey] ?? 0;
            $transaksi_count = $transaksiByBucket[$bucketKey] ?? 0;

            $data_penjualan[] = (float) $penjualan;
            $data_pembelian[] = (float) $pembelian;
            $data_pendapatan[] = (float) ($penjualan - $pembelian - $pengeluaran);
            $data_transaksi[] = (int) $transaksi_count;

            $current->{$step}();
        }

        if (array_sum($data_penjualan) == 0 && array_sum($data_transaksi) == 0) {
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

    private function getStockHealthStatus()
    {
        $totalProduk = Produk::count();
        $produkStokMinus = Produk::where('stok', '<', 0)->count();
        $produkStokNol = Produk::where('stok', '=', 0)->count();
        $produkStokRendah = Produk::where('stok', '>', 0)->where('stok', '<=', 5)->count();
        
        // Calculate health score
        $score = 100;
        if ($produkStokMinus > 0) $score -= 40;
        if ($produkStokNol > 0) $score -= min(30, $produkStokNol * 2);
        if ($produkStokRendah > 0) $score -= min(20, $produkStokRendah);
        
        $healthScore = max(0, $score);
        
        // Determine status
        $status = 'healthy';
        $message = 'Sistem stok dalam kondisi sehat';
        $alertClass = 'success';
        
        if ($healthScore < 60) {
            $status = 'critical';
            $message = 'Banyak produk memerlukan restock';
            $alertClass = 'danger';
        } elseif ($healthScore < 80) {
            $status = 'warning';
            $message = 'Beberapa produk perlu di-restock';
            $alertClass = 'warning';
        }
        
        return [
            'health_score' => $healthScore,
            'status' => $status,
            'message' => $message,
            'alert_class' => $alertClass,
            'produk_minus' => $produkStokMinus,
            'produk_nol' => $produkStokNol,
            'produk_rendah' => $produkStokRendah,
            'total_produk' => $totalProduk
        ];
    }

    public function syncStock(Request $request)
    {
        if (!config('stock.enable_legacy_sync_command', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Sinkronisasi legacy dinonaktifkan. Gunakan proses baseline rebuild yang tervalidasi.'
            ], 403);
        }

        try {
            $lockKey = 'sync_stock_in_progress';
            $lockTimeout = 300;
            
            if (cache()->has($lockKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sinkronisasi sedang berlangsung, silakan tunggu beberapa saat'
                ], 429);
            }
            
            cache()->put($lockKey, true, $lockTimeout);
            
            try {
                $exitCode = \Illuminate\Support\Facades\Artisan::call('stok:sinkronisasi');
                
                if ($exitCode === 0) {
                    $output = \Illuminate\Support\Facades\Artisan::output();
                    
                    preg_match('/Produk yang disinkronkan: (\d+)/', $output, $updatedMatches);
                    preg_match('/Produk yang sudah sinkron: (\d+)/', $output, $synchronizedMatches);
                    
                    $updated = isset($updatedMatches[1]) ? (int)$updatedMatches[1] : 0;
                    $synchronized = isset($synchronizedMatches[1]) ? (int)$synchronizedMatches[1] : 0;
                    
                    cache()->forget($lockKey);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Sinkronisasi stok berhasil',
                        'updated' => $updated,
                        'synchronized' => $synchronized
                    ]);
                } else {
                    cache()->forget($lockKey);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal menjalankan sinkronisasi stok'
                    ], 500);
                }
            } catch (\Exception $e) {
                cache()->forget($lockKey);
                throw $e;
            }
        } catch (\Exception $e) {
            cache()->forget($lockKey);
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
}
