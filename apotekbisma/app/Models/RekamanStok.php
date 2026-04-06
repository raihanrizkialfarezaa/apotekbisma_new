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

            static::assertManualAdjustmentNotNegative($rekamanStok);
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

            static::assertManualAdjustmentNotNegative($rekamanStok);
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
            
            $stokRecords = static::applyStockOrder(
                DB::table('rekaman_stoks')->where('id_produk', $productId)
            )->get();

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

                if (static::isStockOpnameRecord($record)) {
                    // Stock opname manual diperlakukan sebagai anchor absolut.
                    // Kita pertahankan stok_sisa yang diinput admin sebagai source of truth.
                    $runningStock = intval($record->stok_sisa);

                    if ($needsUpdate) {
                        $updates[$record->id_rekaman_stok] = $updateData;
                    }

                    continue;
                }

                $calculatedSisa = $runningStock + intval($record->stok_masuk) - intval($record->stok_keluar);

                if (intval($record->stok_sisa) != $calculatedSisa) {
                    $updateData['stok_sisa'] = $calculatedSisa;
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    // MySQL TIMESTAMP lama bisa auto-update ke NOW saat kolom lain diubah.
                    // Pastikan waktu transaksi asli tetap dipertahankan.
                    $updateData['waktu'] = $record->waktu;
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
        $stokRecords = static::applyStockOrder(
            DB::table('rekaman_stoks')->where('id_produk', $productId)
        )->get();

        if ($stokRecords->isEmpty()) {
            return 0;
        }

        $runningStock = intval($stokRecords->first()->stok_awal);

        foreach ($stokRecords as $record) {
            if (static::isStockOpnameRecord($record)) {
                $runningStock = intval($record->stok_sisa);
                continue;
            }

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
        $stokRecords = static::applyStockOrder(
            DB::table('rekaman_stoks')->where('id_produk', $productId)
        )->get();
            
        if ($stokRecords->isNotEmpty()) {
            $runningStock = intval($stokRecords->first()->stok_awal);
            $isFirst = true;
            
            foreach ($stokRecords as $record) {
                if (!$isFirst && intval($record->stok_awal) != $runningStock) {
                    $chainErrors++;
                }
                
                if (static::isStockOpnameRecord($record)) {
                    $runningStock = intval($record->stok_sisa);
                    $isFirst = false;
                    continue;
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

    private static function applyStockOrder($query)
    {
        return $query
            ->orderBy('waktu', 'asc')
            ->orderByRaw("CASE
                WHEN id_pembelian IS NOT NULL THEN 0
                WHEN id_penjualan IS NOT NULL THEN 1
                WHEN LOWER(COALESCE(keterangan, '')) LIKE '%stock opname%' THEN 2
                WHEN LOWER(COALESCE(keterangan, '')) LIKE '%perubahan stok manual%' THEN 2
                WHEN LOWER(COALESCE(keterangan, '')) LIKE '%penyesuaian stok%' THEN 2
                WHEN LOWER(COALESCE(keterangan, '')) LIKE '%saldo awal stok%' THEN 2
                ELSE 3
            END ASC")
            ->orderBy('created_at', 'asc')
            ->orderBy('id_rekaman_stok', 'asc');
    }

    private static function isStockOpnameRecord($record): bool
    {
        $keterangan = strtolower(trim((string) ($record->keterangan ?? '')));
        if ($keterangan === '') {
            return false;
        }

        return strpos($keterangan, 'stock opname') !== false
            || strpos($keterangan, 'perubahan stok manual') !== false
            || strpos($keterangan, 'penyesuaian stok') !== false
            || strpos($keterangan, 'saldo awal stok') !== false;
    }

    private static function isManualAdjustmentRecord($record): bool
    {
        $keterangan = strtolower(trim((string) ($record->keterangan ?? '')));
        if ($keterangan === '') {
            return false;
        }

        return (
            strpos($keterangan, 'stock opname') !== false
            || strpos($keterangan, 'perubahan stok manual') !== false
            || strpos($keterangan, 'penyesuaian stok') !== false
        ) && strpos($keterangan, 'saldo awal stok') === false;
    }

    private static function assertManualAdjustmentNotNegative($record): void
    {
        if (!static::isManualAdjustmentRecord($record)) {
            return;
        }

        $stokSisa = intval($record->stok_sisa ?? 0);
        if ($stokSisa < 0) {
            throw new \InvalidArgumentException('Penyesuaian stok manual tidak boleh menghasilkan stok akhir negatif.');
        }
    }
}
