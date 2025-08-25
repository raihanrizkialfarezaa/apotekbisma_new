<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RekamanStok extends Model
{
    use HasFactory;

    protected $table = 'rekaman_stoks';
    protected $primaryKey = 'id_rekaman_stok';
    protected $guarded = [];
    protected $dates = ['waktu'];
    
    public static $skipMutators = false;

    public function setStokAwalAttribute($value)
    {
        if (static::$skipMutators) {
            $this->attributes['stok_awal'] = $value;
        } else {
            $this->attributes['stok_awal'] = intval($value);
        }
    }

    public function getStokAwalAttribute($value)
    {
        if (static::$skipMutators) {
            return $value;
        }
        return intval($value);
    }

    public function setStokSisaAttribute($value)
    {
        if (static::$skipMutators) {
            $this->attributes['stok_sisa'] = $value;
        } else {
            $this->attributes['stok_sisa'] = intval($value);
        }
    }

    public function getStokSisaAttribute($value)
    {
        if (static::$skipMutators) {
            return $value;
        }
        return intval($value);
    }

    public function produk()
    {
        return $this->belongsTo(Produk::class, 'id_produk', 'id_produk');
    }

    public function pembelian()
    {
        return $this->belongsTo(Pembelian::class, 'id_pembelian', 'id_pembelian');
    }

    public function penjualan()
    {
        return $this->belongsTo(Penjualan::class, 'id_penjualan', 'id_penjualan');
    }
}
