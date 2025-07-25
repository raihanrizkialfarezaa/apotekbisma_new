<?php

namespace App\Http\Controllers;

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Pembelian;
use App\Models\Penjualan;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Http\Request;

class KartuStokController extends Controller
{
    public function index()
    {
        $produk = Produk::with('kategori')->orderBy('nama_produk', 'asc')->get();
        return view('kartu_stok.index', compact('produk'));
    }

    public function detail($id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        
        if (!$produk) {
            return redirect()->route('kartu_stok.index')
                           ->with('error', 'Produk tidak ditemukan');
        }
        
        $nama_barang = $produk->nama_produk;
        $produk_id = $id;
        
        return view('kartu_stok.detail', compact('produk_id', 'nama_barang', 'produk'));
    }
    
    public function getData($id)
    {
        $produk = Produk::find($id);
        if (!$produk) {
            return [];
        }

        $no = 1;
        $data = array();
        
        // Get all stock records for this product, ordered by date
        $stok = RekamanStok::where('id_produk', $id)
                          ->orderBy('waktu', 'asc')
                          ->get();

        foreach ($stok as $item) {
            $row = array();
            $row['DT_RowIndex'] = $no++;
            $row['tanggal'] = tanggal_indonesia($item->waktu, false);
            
            // Format stock movements
            $row['stok_masuk'] = ($item->stok_masuk != NULL && $item->stok_masuk > 0) 
                               ? format_uang($item->stok_masuk) 
                               : '-';
            
            $row['stok_keluar'] = ($item->stok_keluar != NULL && $item->stok_keluar > 0) 
                                ? format_uang($item->stok_keluar) 
                                : '-';
            
            $row['stok_awal'] = format_uang($item->stok_awal);
            $row['stok_sisa'] = format_uang($item->stok_sisa);
            
            // Determine transaction type and add reference
            $keterangan = '';
            if ($item->stok_masuk > 0) {
                $keterangan = 'Pembelian';
                if ($item->id_pembelian) {
                    $pembelian = Pembelian::find($item->id_pembelian);
                    if ($pembelian && $pembelian->no_faktur && $pembelian->no_faktur != 'o') {
                        $keterangan .= ' - Faktur: ' . $pembelian->no_faktur;
                    }
                }
            } elseif ($item->stok_keluar > 0) {
                $keterangan = 'Penjualan';
                if ($item->id_penjualan) {
                    $penjualan = Penjualan::find($item->id_penjualan);
                    if ($penjualan) {
                        $keterangan .= ' - ID: ' . $penjualan->id_penjualan;
                    }
                }
            } else {
                $keterangan = 'Penyesuaian Stok';
            }
            
            $row['keterangan'] = $keterangan;
            $data[] = $row;
        }

        // Add current stock summary as last row
        if (!empty($data)) {
            $data[] = [
                'DT_RowIndex' => '',
                'tanggal' => '<strong>STOK SAAT INI</strong>',
                'stok_masuk' => '',
                'stok_keluar' => '',
                'stok_awal' => '',
                'stok_sisa' => '<strong>' . format_uang($produk->stok) . '</strong>',
                'keterangan' => '<strong>Stok Aktual</strong>',
            ];
        }

        return $data;
    }

    public function data($id)
    {
        $data = $this->getData($id);

        return datatables()
            ->of($data)
            ->rawColumns(['tanggal', 'stok_sisa', 'keterangan'])
            ->make(true);
    }

    public function exportPDF($id)
    {
        $produk = Produk::with('kategori')->find($id);
        
        if (!$produk) {
            return redirect()->route('kartu_stok.index')
                           ->with('error', 'Produk tidak ditemukan');
        }
        
        $data = $this->getData($id);
        $nama_obat = $produk->nama_produk;
        $satuan = $produk->kategori ? $produk->kategori->nama_kategori : 'N/A';
        $kode_produk = $produk->kode_produk;
        
        $pdf = PDF::loadView('kartu_stok.pdf', compact('data', 'nama_obat', 'satuan', 'kode_produk', 'produk'));
        $pdf->setPaper('a4', 'portrait');
        
        return $pdf->stream('Kartu-Stok-' . $nama_obat . '-' . date('Y-m-d-His') . '.pdf');
    }
}
