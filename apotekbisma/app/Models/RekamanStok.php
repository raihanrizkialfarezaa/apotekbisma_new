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
    public static $preventRecalculation = false;

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($rekamanStok) {
            $calculatedSisa = intval($rekamanStok->stok_awal) + intval($rekamanStok->stok_masuk) - intval($rekamanStok->stok_keluar);
            if ($rekamanStok->stok_sisa !== null && intval($rekamanStok->stok_sisa) != $calculatedSisa) {
                Log::warning('RekamanStok: Auto-correcting stok_sisa on create', [
                    'id_produk' => $rekamanStok->id_produk,
                    'stok_awal' => $rekamanStok->stok_awal,
                    'stok_masuk' => $rekamanStok->stok_masuk,
                    'stok_keluar' => $rekamanStok->stok_keluar,
                    'provided_sisa' => $rekamanStok->stok_sisa,
                    'calculated_sisa' => $calculatedSisa,
                ]);
                $rekamanStok->stok_sisa = $calculatedSisa;
            }
            
            if ($rekamanStok->stok_sisa < 0) {
                $rekamanStok->stok_sisa = 0;
            }
        });
        
        static::updating(function ($rekamanStok) {
            $calculatedSisa = intval($rekamanStok->stok_awal) + intval($rekamanStok->stok_masuk) - intval($rekamanStok->stok_keluar);
            if ($rekamanStok->stok_sisa !== null && intval($rekamanStok->stok_sisa) != $calculatedSisa) {
                Log::warning('RekamanStok: Auto-correcting stok_sisa on update', [
                    'id_rekaman_stok' => $rekamanStok->id_rekaman_stok,
                    'id_produk' => $rekamanStok->id_produk,
                    'provided_sisa' => $rekamanStok->stok_sisa,
                    'calculated_sisa' => $calculatedSisa,
                ]);
                $rekamanStok->stok_sisa = $calculatedSisa;
            }
            
            if ($rekamanStok->stok_sisa < 0) {
                $rekamanStok->stok_sisa = 0;
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
        if (static::$preventRecalculation) {
            return;
        }
        
        try {
            static::$preventRecalculation = true;
            
            $stokRecords = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->orderBy('waktu', 'asc')
                ->orderBy('created_at', 'asc')
                ->orderBy('id_rekaman_stok', 'asc')
                ->get();

            if ($stokRecords->isEmpty()) {
                static::$preventRecalculation = false;
                return;
            }

            $runningStock = 0;
            $isFirst = true;
            $updates = [];

            foreach ($stokRecords as $record) {
                $needsUpdate = false;
                $updateData = [];

                if ($isFirst) {
                    $runningStock = intval($record->stok_awal);
                    $isFirst = false;
                } else {
                    if (intval($record->stok_awal) != $runningStock) {
                        $updateData['stok_awal'] = $runningStock;
                        $needsUpdate = true;
                    }
                }

                $calculatedSisa = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);

                if (intval($record->stok_sisa) != $calculatedSisa) {
                    $updateData['stok_sisa'] = $calculatedSisa;
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $updates[$record->id_rekaman_stok] = $updateData;
                }

                $runningStock = $calculatedSisa;
            }

            foreach ($updates as $recordId => $updateData) {
                DB::table('rekaman_stoks')
                    ->where('id_rekaman_stok', $recordId)
                    ->update($updateData);
            }
            
            DB::table('produk')
                ->where('id_produk', $productId)
                ->update(['stok' => max(0, $runningStock)]);
                
            static::$preventRecalculation = false;
            
        } catch (\Exception $e) {
            static::$preventRecalculation = false;
            Log::error('RekamanStok::recalculateStock error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public static function getCalculatedStock($productId)
    {
        $stokRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        if ($stokRecords->isEmpty()) {
            return 0;
        }

        $runningStock = intval($stokRecords->first()->stok_awal);

        foreach ($stokRecords as $record) {
            $runningStock = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);
        }

        return max(0, $runningStock);
    }
    
    public static function verifyIntegrity($productId)
    {
        $produk = Produk::find($productId);
        if (!$produk) {
            return ['valid' => false, 'error' => 'Product not found'];
        }
        
        $calculatedStock = self::getCalculatedStock($productId);
        $productStock = intval($produk->stok);
        
        return [
            'valid' => $productStock === $calculatedStock,
            'product_stock' => $productStock,
            'calculated_stock' => $calculatedStock,
            'difference' => $productStock - $calculatedStock
        ];
    }
}
