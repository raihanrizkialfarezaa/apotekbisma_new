<?php

namespace App\Http\Controllers;

use App\Models\Produk;
use App\Models\RekamanStok;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Http\Request;

class KartuStokController extends Controller
{
    public function index()
    {
        $produk = Produk::all();
        return view('kartu_stok.index', compact('produk'));
    }
    public function detail($id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        $nama_barang = $produk->nama_produk;
        $produk_id = $id;
        return view('kartu_stok.detail', compact('produk_id', 'nama_barang'));
    }
    
    public function getData($id)
    {
        $no = 1;
        $data = array();
        $stok = RekamanStok::where('id_produk', $id)->get();

        foreach ($stok as $item) {
            $row = array();
            $row['DT_RowIndex'] = $no++;
            $row['tanggal'] = tanggal_indonesia($item->waktu, false);
            $row['stok_masuk'] = ($item->stok_masuk != NULL) ? $item->stok_masuk : '-';
            $row['stok_keluar'] = $row['stok_keluar'] = ($item->stok_keluar != NULL) ? $item->stok_keluar : '-';;
            $row['stok_awal'] = $item->stok_awal;
            $row['stok_sisa'] = $item->stok_sisa;
            $row['keterangan'] = ($item->stok_masuk != NULL) ? 'Pembelian' : 'Penjualan';
            $data[] = $row;
        }

        $data[] = [
            'DT_RowIndex' => '',
            'tanggal' => '',
            'stok_masuk' => '',
            'stok_keluar' => '',
            'stok_awal' => '',
            'stok_sisa' => '',
            'keterangan' => '',
        ];

        return $data;
    }

    public function data($id)
    {
        $data = $this->getData($id);

        return datatables()
            ->of($data)
            ->make(true);
    }

    public function exportPDF($id)
    {
        // dd(public_path());
        $data = $this->getData($id);
        $nama_obats = Produk::where('id_produk', $id)->first();
        $nama_obat = $nama_obats->nama_produk;
        // dd($nama_obats->kategori);
        $satuan = $nama_obats->kategori->nama_kategori;
        $pdf  = PDF::loadView('kartu_stok.pdf', compact('data', 'nama_obat', 'satuan'));
        $pdf->setPaper('a4', 'potrait');
        
        return $pdf->stream('Kartu-Stok-'.$nama_obat . ' ' . date('Y-m-d-his') .'.pdf');
    }
}
