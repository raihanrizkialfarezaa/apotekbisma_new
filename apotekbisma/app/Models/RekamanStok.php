<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class RekamanStok extends Model
{
    use HasFactory;

    protected $table = 'rekaman_stoks';
    protected $primaryKey = 'id_rekaman_stok';
    protected $guarded = [];
    protected $dates = ['waktu'];
    
    public static $skipMutators = false;

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($rekamanStok) {
            // Validate consistency before creating
            $calculatedSisa = $rekamanStok->stok_awal + $rekamanStok->stok_masuk - $rekamanStok->stok_keluar;
            if ($rekamanStok->stok_sisa !== null && $rekamanStok->stok_sisa != $calculatedSisa) {
                Log::warning('Inconsistent stock record being created', [
                    'id_produk' => $rekamanStok->id_produk,
                    'stok_awal' => $rekamanStok->stok_awal,
                    'stok_masuk' => $rekamanStok->stok_masuk,
                    'stok_keluar' => $rekamanStok->stok_keluar,
                    'stok_sisa_provided' => $rekamanStok->stok_sisa,
                    'stok_sisa_calculated' => $calculatedSisa,
                    'keterangan' => $rekamanStok->keterangan
                ]);
            }
        });
        
        static::updating(function ($rekamanStok) {
            // Validate consistency before updating
            $calculatedSisa = $rekamanStok->stok_awal + $rekamanStok->stok_masuk - $rekamanStok->stok_keluar;
            if ($rekamanStok->stok_sisa !== null && $rekamanStok->stok_sisa != $calculatedSisa) {
                Log::warning('Inconsistent stock record being updated', [
                    'id_rekaman_stok' => $rekamanStok->id_rekaman_stok,
                    'id_produk' => $rekamanStok->id_produk,
                    'stok_awal' => $rekamanStok->stok_awal,
                    'stok_masuk' => $rekamanStok->stok_masuk,
                    'stok_keluar' => $rekamanStok->stok_keluar,
                    'stok_sisa_provided' => $rekamanStok->stok_sisa,
                    'stok_sisa_calculated' => $calculatedSisa,
                    'keterangan' => $rekamanStok->keterangan
                ]);
            }
        });
    }

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
