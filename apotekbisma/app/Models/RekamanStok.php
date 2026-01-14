<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
        
        $lockKey = 'stock_recalc_lock_' . $productId;
        $lock = Cache::lock($lockKey, 30);
        
        if (!$lock->get()) {
            Log::info('recalculateStock skipped - lock held by another process', ['product_id' => $productId]);
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
                $lock->release();
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

            if (!empty($updates)) {
                foreach ($updates as $recordId => $updateData) {
                    DB::table('rekaman_stoks')
                        ->where('id_rekaman_stok', $recordId)
                        ->update($updateData);
                }
                
                Log::info('recalculateStock fixed records', [
                    'product_id' => $productId,
                    'records_fixed' => count($updates)
                ]);
            }
            
            $finalStock = max(0, $runningStock);
            $currentStock = DB::table('produk')->where('id_produk', $productId)->value('stok');
            
            if (intval($currentStock) !== $finalStock) {
                DB::table('produk')
                    ->where('id_produk', $productId)
                    ->update(['stok' => $finalStock]);
                    
                Log::info('recalculateStock synced produk.stok', [
                    'product_id' => $productId,
                    'old_stock' => $currentStock,
                    'new_stock' => $finalStock
                ]);
            }
                
            static::$preventRecalculation = false;
            $lock->release();
            
        } catch (\Exception $e) {
            static::$preventRecalculation = false;
            $lock->release();
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
        
        $chainErrors = 0;
        $stokRecords = DB::table('rekaman_stoks')
            ->where('id_produk', $productId)
            ->orderBy('waktu', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();
            
        if ($stokRecords->isNotEmpty()) {
            $runningStock = intval($stokRecords->first()->stok_awal);
            $isFirst = true;
            
            foreach ($stokRecords as $record) {
                if (!$isFirst && intval($record->stok_awal) != $runningStock) {
                    $chainErrors++;
                }
                
                $calculatedSisa = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);
                if (intval($record->stok_sisa) != $calculatedSisa) {
                    $chainErrors++;
                }
                
                $runningStock = $calculatedSisa;
                $isFirst = false;
            }
        }
        
        return [
            'valid' => $productStock === $calculatedStock && $chainErrors === 0,
            'product_stock' => $productStock,
            'calculated_stock' => $calculatedStock,
            'difference' => $productStock - $calculatedStock,
            'chain_errors' => $chainErrors
        ];
    }
    
    public static function cleanupDuplicates($productId)
    {
        $duplicatePenjualan = DB::table('rekaman_stoks')
            ->select('id_penjualan', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->where('id_produk', $productId)
            ->whereNotNull('id_penjualan')
            ->groupBy('id_penjualan')
            ->having('cnt', '>', 1)
            ->get();
        
        $deletedCount = 0;
        foreach ($duplicatePenjualan as $dup) {
            $deleted = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('id_penjualan', $dup->id_penjualan)
                ->where('id_rekaman_stok', '!=', $dup->keep_id)
                ->delete();
            $deletedCount += $deleted;
        }
        
        $duplicatePembelian = DB::table('rekaman_stoks')
            ->select('id_pembelian', DB::raw('MIN(id_rekaman_stok) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->where('id_produk', $productId)
            ->whereNotNull('id_pembelian')
            ->groupBy('id_pembelian')
            ->having('cnt', '>', 1)
            ->get();
        
        foreach ($duplicatePembelian as $dup) {
            $deleted = DB::table('rekaman_stoks')
                ->where('id_produk', $productId)
                ->where('id_pembelian', $dup->id_pembelian)
                ->where('id_rekaman_stok', '!=', $dup->keep_id)
                ->delete();
            $deletedCount += $deleted;
        }
        
        if ($deletedCount > 0) {
            Log::info('cleanupDuplicates removed records', [
                'product_id' => $productId,
                'deleted_count' => $deletedCount
            ]);
        }
        
        return $deletedCount;
    }
    
    public static function fullRepair($productId)
    {
        $duplicatesRemoved = self::cleanupDuplicates($productId);
        
        self::recalculateStock($productId);
        
        $integrity = self::verifyIntegrity($productId);
        
        return [
            'duplicates_removed' => $duplicatesRemoved,
            'integrity' => $integrity
        ];
    }
}
