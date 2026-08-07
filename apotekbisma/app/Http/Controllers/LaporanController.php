<?php

namespace App\Http\Controllers;

use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Pengeluaran;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\Member;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade as PDF;
use Carbon\Carbon;

class LaporanController extends Controller
{
    public function index(Request $request)
    {
        $tanggalAwal = date('Y-m-d', mktime(0, 0, 0, date('m'), 1, date('Y')));
        $tanggalAkhir = date('Y-m-d');

        if ($request->has('tanggal_awal') && $request->tanggal_awal != "" && $request->has('tanggal_akhir') && $request->tanggal_akhir) {
            $tanggalAwal = $request->tanggal_awal;
            $tanggalAkhir = $request->tanggal_akhir;
        }

        // Get summary statistics
        $statistics = $this->getStatistics($tanggalAwal, $tanggalAkhir);

        return view('laporan.index', compact('tanggalAwal', 'tanggalAkhir', 'statistics'));
    }

    public function getStatistics($tanggalAwal, $tanggalAkhir)
    {
        // Total calculations for the period
        $totalPenjualan = Penjualan::where(function ($query) use ($tanggalAwal, $tanggalAkhir) {
            $query->whereBetween('created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                  ->orWhereBetween('waktu', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59']);
        })->sum('bayar');

        $totalPembelian = Pembelian::where(function ($query) use ($tanggalAwal, $tanggalAkhir) {
            $query->whereBetween('created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                  ->orWhereBetween('waktu', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59']);
        })->sum('bayar');

        $totalPengeluaran = Pengeluaran::whereBetween('created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                                      ->sum('nominal');

        $totalPendapatan = $totalPenjualan - $totalPembelian - $totalPengeluaran;

        // Transaction counts
        $jumlahTransaksiPenjualan = Penjualan::where(function ($query) use ($tanggalAwal, $tanggalAkhir) {
            $query->whereBetween('created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                  ->orWhereBetween('waktu', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59']);
        })->count();

        $jumlahTransaksiPembelian = Pembelian::where(function ($query) use ($tanggalAwal, $tanggalAkhir) {
            $query->whereBetween('created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                  ->orWhereBetween('waktu', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59']);
        })->count();

        // Best selling products
        $produkTerlaris = PenjualanDetail::join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                                        ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
                                        ->whereBetween('penjualan.created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                                        ->selectRaw('produk.nama_produk, SUM(penjualan_detail.jumlah) as total_terjual, SUM(penjualan_detail.subtotal) as total_revenue')
                                        ->groupBy('produk.id_produk', 'produk.nama_produk')
                                        ->orderBy('total_terjual', 'desc')
                                        ->take(5)
                                        ->get();

        // Low stock products
        $stokMenupis = Produk::where('stok', '<=', 5)->orderBy('stok', 'asc')->take(10)->get();

        // Member statistics
        $totalMember = Member::count();
        $memberAktif = Penjualan::whereBetween('created_at', [$tanggalAwal . ' 00:00:00', $tanggalAkhir . ' 23:59:59'])
                               ->whereNotNull('id_member')
                               ->distinct('id_member')
                               ->count();

        // Daily averages
        $jumlahHari = Carbon::parse($tanggalAwal)->diffInDays(Carbon::parse($tanggalAkhir)) + 1;
        $rataRataPenjualanHarian = $jumlahHari > 0 ? $totalPenjualan / $jumlahHari : 0;
        $rataRataTransaksiHarian = $jumlahHari > 0 ? $jumlahTransaksiPenjualan / $jumlahHari : 0;

        return [
            'totalPenjualan' => $totalPenjualan,
            'totalPembelian' => $totalPembelian,
            'totalPengeluaran' => $totalPengeluaran,
            'totalPendapatan' => $totalPendapatan,
            'jumlahTransaksiPenjualan' => $jumlahTransaksiPenjualan,
            'jumlahTransaksiPembelian' => $jumlahTransaksiPembelian,
            'produkTerlaris' => $produkTerlaris,
            'stokMenupis' => $stokMenupis,
            'totalMember' => $totalMember,
            'memberAktif' => $memberAktif,
            'rataRataPenjualanHarian' => $rataRataPenjualanHarian,
            'rataRataTransaksiHarian' => $rataRataTransaksiHarian,
            'jumlahHari' => $jumlahHari,
        ];
    }

    public function getData($awal, $akhir)
    {
        $no = 1;
        $data = array();
        $total_pendapatan = 0;
        $total_penjualan_keseluruhan = 0;
        $total_pembelian_keseluruhan = 0;
        $total_pengeluaran_keseluruhan = 0;

        $startDate = Carbon::parse($awal)->startOfDay();
        $endDate = Carbon::parse($akhir)->endOfDay();
        $range = [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()];

        $penjualanByDate = Penjualan::whereBetween('created_at', $range)
            ->selectRaw('DATE(created_at) as tgl, SUM(bayar) as total_penjualan, COUNT(*) as jumlah_transaksi')
            ->groupBy('tgl')
            ->get()
            ->keyBy('tgl');

        $penjualanWaktuByDate = Penjualan::whereBetween('waktu', $range)
            ->where(function ($query) {
                $query->whereNull('created_at')
                      ->orWhereRaw('DATE(created_at) != DATE(waktu)');
            })
            ->selectRaw('DATE(waktu) as tgl, SUM(bayar) as total_penjualan, COUNT(*) as jumlah_transaksi')
            ->groupBy('tgl')
            ->get()
            ->keyBy('tgl');

        $pembelianByDate = Pembelian::whereBetween('created_at', $range)
            ->selectRaw('DATE(created_at) as tgl, SUM(bayar) as total_pembelian, COUNT(*) as jumlah_transaksi')
            ->groupBy('tgl')
            ->get()
            ->keyBy('tgl');

        $pembelianWaktuByDate = Pembelian::whereBetween('waktu', $range)
            ->where(function ($query) {
                $query->whereNull('created_at')
                      ->orWhereRaw('DATE(created_at) != DATE(waktu)');
            })
            ->selectRaw('DATE(waktu) as tgl, SUM(bayar) as total_pembelian, COUNT(*) as jumlah_transaksi')
            ->groupBy('tgl')
            ->get()
            ->keyBy('tgl');

        $pengeluaranByDate = Pengeluaran::whereBetween('created_at', $range)
            ->selectRaw('DATE(created_at) as tgl, SUM(nominal) as total_pengeluaran')
            ->groupBy('tgl')
            ->get()
            ->keyBy('tgl');

        $current = $startDate->copy();
        while ($current <= $endDate) {
            $tanggal = $current->format('Y-m-d');

            $penjualanRow = $penjualanByDate->get($tanggal);
            $penjualanWaktuRow = $penjualanWaktuByDate->get($tanggal);
            $pembelianRow = $pembelianByDate->get($tanggal);
            $pembelianWaktuRow = $pembelianWaktuByDate->get($tanggal);
            $pengeluaranRow = $pengeluaranByDate->get($tanggal);

            $total_penjualan = (float) ($penjualanRow->total_penjualan ?? 0) + (float) ($penjualanWaktuRow->total_penjualan ?? 0);
            $jumlah_transaksi_penjualan = (int) ($penjualanRow->jumlah_transaksi ?? 0) + (int) ($penjualanWaktuRow->jumlah_transaksi ?? 0);

            $total_pembelian = (float) ($pembelianRow->total_pembelian ?? 0) + (float) ($pembelianWaktuRow->total_pembelian ?? 0);
            $jumlah_transaksi_pembelian = (int) ($pembelianRow->jumlah_transaksi ?? 0) + (int) ($pembelianWaktuRow->jumlah_transaksi ?? 0);

            $total_pengeluaran = (float) ($pengeluaranRow->total_pengeluaran ?? 0);

            $pendapatan = $total_penjualan - $total_pembelian - $total_pengeluaran;
            $total_pendapatan += $pendapatan;
            $total_penjualan_keseluruhan += $total_penjualan;
            $total_pembelian_keseluruhan += $total_pembelian;
            $total_pengeluaran_keseluruhan += $total_pengeluaran;

            $row = array();
            $row['DT_RowIndex'] = $no++;
            $row['tanggal'] = tanggal_indonesia($tanggal, false);
            $row['penjualan'] = 'Rp. ' . format_uang($total_penjualan) . ' (' . $jumlah_transaksi_penjualan . ' transaksi)';
            $row['pembelian'] = 'Rp. ' . format_uang($total_pembelian) . ' (' . $jumlah_transaksi_pembelian . ' transaksi)';
            $row['pengeluaran'] = 'Rp. ' . format_uang($total_pengeluaran);
            $row['pendapatan'] = 'Rp. ' . format_uang($pendapatan);

            $data[] = $row;

            $current->addDay();
        }

        // Add summary row
        $data[] = [
            'DT_RowIndex' => '',
            'tanggal' => '<strong>TOTAL KESELURUHAN</strong>',
            'penjualan' => '<strong>Rp. ' . format_uang($total_penjualan_keseluruhan) . '</strong>',
            'pembelian' => '<strong>Rp. ' . format_uang($total_pembelian_keseluruhan) . '</strong>',
            'pengeluaran' => '<strong>Rp. ' . format_uang($total_pengeluaran_keseluruhan) . '</strong>',
            'pendapatan' => '<strong>Rp. ' . format_uang($total_pendapatan) . '</strong>',
        ];

        return $data;
    }

    public function data($awal, $akhir)
    {
        $data = $this->getData($awal, $akhir);

        return datatables()
            ->of($data)
            ->rawColumns(['tanggal', 'penjualan', 'pembelian', 'pengeluaran', 'pendapatan'])
            ->make(true);
    }

    public function exportPDF($awal, $akhir)
    {
        $data = $this->getData($awal, $akhir);
        $statistics = $this->getStatistics($awal, $akhir);
        
        $pdf = PDF::loadView('laporan.pdf', compact('awal', 'akhir', 'data', 'statistics'));
        $pdf->setPaper('a4', 'portrait');
        
        return $pdf->stream('Laporan-Keuangan-'. date('Y-m-d-His') .'.pdf');
    }
}
