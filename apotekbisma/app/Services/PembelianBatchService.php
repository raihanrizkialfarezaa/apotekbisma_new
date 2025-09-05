<?php

namespace App\Services;

use App\Models\Produk;
use App\Models\PembelianDetail;
use App\Models\RekamanStok;
use App\Models\Pembelian;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PembelianBatchService
{
    public function bulkUpdateStok(array $updates)
    {
        set_time_limit(180);
        ini_set('memory_limit', '1G');
        
        $results = [];
        $errors = [];
        
        foreach (array_chunk($updates, 10) as $chunk) {
            try {
                DB::transaction(function () use ($chunk, &$results, &$errors) {
                    foreach ($chunk as $update) {
                        try {
                            $result = $this->updateSingleItem($update);
                            $results[] = $result;
                        } catch (\Exception $e) {
                            $errors[] = [
                                'id' => $update['id'] ?? 'unknown',
                                'error' => $e->getMessage()
                            ];
                        }
                    }
                }, 5);
            } catch (\Exception $e) {
                Log::error('Batch transaction failed: ' . $e->getMessage(), [
                    'chunk' => $chunk,
                    'trace' => $e->getTraceAsString()
                ]);
                
                foreach ($chunk as $update) {
                    $errors[] = [
                        'id' => $update['id'] ?? 'unknown',
                        'error' => 'Batch transaction failed: ' . $e->getMessage()
                    ];
                }
            }
        }
        
        return [
            'success' => $results,
            'errors' => $errors,
            'total_processed' => count($results),
            'total_errors' => count($errors)
        ];
    }
    
    private function updateSingleItem(array $data)
    {
        $detail = PembelianDetail::findOrFail($data['id']);
        $produk = Produk::where('id_produk', $detail->id_produk)->lockForUpdate()->first();
        
        if (!$produk) {
            throw new \Exception('Produk tidak ditemukan');
        }
        
        $old_jumlah = $detail->jumlah;
        $new_jumlah = (int) $data['jumlah'];
        $selisih = $new_jumlah - $old_jumlah;
        
        if ($selisih == 0) {
            return [
                'id' => $data['id'],
                'message' => 'Tidak ada perubahan',
                'jumlah' => $new_jumlah,
                'stok' => $produk->stok
            ];
        }
        
        $stok_sebelum = $produk->stok;
        $stok_baru = $stok_sebelum + $selisih;
        
        if ($stok_baru < 0 || $stok_baru > 2147483647) {
            throw new \Exception('Stok hasil tidak valid: ' . $stok_baru);
        }
        
        $produk->stok = $stok_baru;
        $produk->save();
        
        $detail->jumlah = $new_jumlah;
        $detail->subtotal = $detail->harga_beli * $new_jumlah;
        $detail->save();
        
        $pembelian = Pembelian::find($detail->id_pembelian);
        $waktu_transaksi = $pembelian && $pembelian->waktu ? $pembelian->waktu : Carbon::now();
        
        RekamanStok::updateOrCreate(
            [
                'id_pembelian' => $detail->id_pembelian,
                'id_produk' => $detail->id_produk
            ],
            [
                'waktu' => $waktu_transaksi,
                'stok_masuk' => $new_jumlah,
                'stok_awal' => $stok_sebelum - $old_jumlah,
                'stok_sisa' => $stok_baru,
                'keterangan' => 'Pembelian: Batch update jumlah transaksi'
            ]
        );
        
        return [
            'id' => $data['id'],
            'message' => 'Berhasil diperbarui',
            'jumlah' => $new_jumlah,
            'subtotal' => $detail->subtotal,
            'stok' => $stok_baru
        ];
    }
    
    public function optimizeRekamanStok($id_pembelian)
    {
        try {
            DB::transaction(function () use ($id_pembelian) {
                $duplicateRekaman = DB::select('
                    SELECT id_produk, COUNT(*) as count 
                    FROM rekaman_stoks 
                    WHERE id_pembelian = ? 
                    GROUP BY id_produk 
                    HAVING count > 1
                ', [$id_pembelian]);
                
                foreach ($duplicateRekaman as $duplicate) {
                    $rekaman_list = RekamanStok::where('id_pembelian', $id_pembelian)
                                               ->where('id_produk', $duplicate->id_produk)
                                               ->orderBy('id_rekaman_stok')
                                               ->get();
                    
                    if ($rekaman_list->count() > 1) {
                        $first = $rekaman_list->first();
                        $total_stok_masuk = $rekaman_list->sum('stok_masuk');
                        $last_stok_sisa = $rekaman_list->last()->stok_sisa;
                        
                        $first->update([
                            'stok_masuk' => $total_stok_masuk,
                            'stok_sisa' => $last_stok_sisa,
                            'keterangan' => 'Pembelian: Consolidated dari multiple entries'
                        ]);
                        
                        $rekaman_list->skip(1)->each(function ($rekaman) {
                            $rekaman->delete();
                        });
                    }
                }
            });
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error optimizing rekaman stok: ' . $e->getMessage());
            return false;
        }
    }
}
