<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Produk;

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
        
        static::created(function ($rekamanStok) {
            // Check if there are records chronologically AFTER this one.
            // If so, we must recalculate to ensure consistency.
            $laterRecords = self::where('id_produk', $rekamanStok->id_produk)
                ->where(function($q) use ($rekamanStok) {
                    $q->where('waktu', '>', $rekamanStok->waktu)
                      ->orWhere(function($q2) use ($rekamanStok) {
                          $q2->where('waktu', '=', $rekamanStok->waktu)
                             ->where('id_rekaman_stok', '>', $rekamanStok->id_rekaman_stok);
                      });
                })
                ->exists();

            if ($laterRecords) {
                self::recalculateStock($rekamanStok->id_produk);
            }
        });
        
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

    public static function recalculateStock($productId)
    {
        $stokRecords = self::where('id_produk', $productId)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        if ($stokRecords->isEmpty()) {
            return;
        }

        $runningStock = 0;
        $isFirst = true;

        foreach ($stokRecords as $record) {
            $needsUpdate = false;

            if ($isFirst) {
                $runningStock = $record->stok_awal;
                $isFirst = false;
            } else {
                if ($record->stok_awal != $runningStock) {
                    $record->stok_awal = $runningStock;
                    $needsUpdate = true;
                }
            }

            $calculatedSisa = $runningStock + $record->stok_masuk - $record->stok_keluar;

            if ($record->stok_sisa != $calculatedSisa) {
                $record->stok_sisa = $calculatedSisa;
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $record->id_rekaman_stok)
                    ->update([
                        'stok_awal' => $record->stok_awal,
                        'stok_sisa' => $record->stok_sisa
                    ]);
            }

            $runningStock = $calculatedSisa;
        }
        
        // Update Product Master Stock
        $produk = Produk::find($productId);
        if ($produk && $produk->stok != $runningStock) {
            $produk->stok = $runningStock;
            $produk->save();
        }
    }
}
