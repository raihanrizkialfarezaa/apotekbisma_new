<?php

namespace App\Imports;

use App\Models\Produk;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ObatImport implements ToModel, WithHeadingRow
{
    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        // $produk = Produk::latest()->first() ?? new Produk();
        return new Produk([
            'id_kategori' => $row['id_kategori'],
            'nama_produk' => $row['nama_produk'],
            'merk' => $row['merk'],
            'harga_jual' => $row['harga_jual'],
            'diskon' => $row['diskon'],
            'harga_beli' => $row['harga_beli'],
            'stok' => $row['stok'],
        ]);
    }
}
