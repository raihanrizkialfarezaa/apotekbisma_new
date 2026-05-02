<?php

namespace App\Http\Controllers;

use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Produk;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\PembelianBatchService;
use App\Services\PembelianStockSyncService;
use App\Services\StockDraftCleanupService;
use App\Services\TransactionLogicalClockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PembelianDetailController extends Controller
{
    private const IDEMPOTENCY_TTL = 10;
    
    public function index()
    {
        session()->forget('pembelian_edit_mode');
        session()->forget('pembelian_edit_snapshot');

        $id_pembelian = session('id_pembelian');
        
        if (!$id_pembelian) {
            app(StockDraftCleanupService::class)->cleanupStalePembelianDrafts();
            session()->forget(['id_pembelian', 'id_supplier']);
            return redirect()->route('pembelian.index')->with('info', 'Silakan pilih supplier terlebih dahulu untuk memulai pembelian.');
        }
        
        $pembelian = Pembelian::find($id_pembelian);
        if (!$pembelian) {
            session()->forget(['id_pembelian', 'id_supplier']);
            return redirect()->route('pembelian.index')->with('error', 'Transaksi pembelian tidak ditemukan atau sudah tidak valid.');
        }
        
        $session_supplier = session('id_supplier');
        if ($session_supplier != $pembelian->id_supplier) {
            session(['id_supplier' => $pembelian->id_supplier]);
        }
        
        $supplier = Supplier::find($pembelian->id_supplier);
        $diskon = $pembelian->diskon ?? 0;

        if (! $supplier) {
            session()->forget(['id_pembelian', 'id_supplier']);
            return redirect()->route('pembelian.index')->with('error', 'Supplier tidak ditemukan. Silakan mulai transaksi baru.');
        }

        return view('pembelian_detail.index', compact('id_pembelian', 'supplier', 'diskon', 'pembelian'));
    }
    
    public function editBayar($id)
    {
        $id_pembelian = $id;
        $pembelian = Pembelian::where('id_pembelian', $id)->first();
        
        if (!$pembelian) {
            return redirect()->route('pembelian.index')->with('error', 'Transaksi pembelian tidak ditemukan.');
        }
        
        session(['id_pembelian' => $pembelian->id_pembelian]);
        session(['id_supplier' => $pembelian->id_supplier]);
        session(['pembelian_edit_mode' => true]);
        session(['pembelian_edit_snapshot' => $this->buildPembelianEditSnapshot($pembelian->id_pembelian)]);
        
        $detail_pembelian = PembelianDetail::where('id_pembelian', $id)->get();
        $supplier = Supplier::find($pembelian->id_supplier);
        $diskon = $pembelian->diskon ?? 0;
        $tanggal = $pembelian;

        if (! $supplier) {
            abort(404);
        }

        return view('pembelian_detail.editBayar', compact('id_pembelian', 'pembelian', 'tanggal', 'detail_pembelian', 'supplier', 'diskon'));
    }

    private function buildPembelianEditSnapshot(int $idPembelian): array
    {
        $header = DB::table('pembelian')
            ->where('id_pembelian', $idPembelian)
            ->first();

        $details = DB::table('pembelian_detail')
            ->where('id_pembelian', $idPembelian)
            ->orderBy('id_pembelian_detail', 'asc')
            ->get();

        $rekamans = DB::table('rekaman_stoks')
            ->where('id_pembelian', $idPembelian)
            ->orderBy('id_rekaman_stok', 'asc')
            ->get();

        return [
            'id_pembelian' => $idPembelian,
            'captured_at' => now()->format('Y-m-d H:i:s'),
            'header' => $header ? (array) $header : null,
            'details' => $details->map(function ($row) {
                return (array) $row;
            })->values()->all(),
            'rekamans' => $rekamans->map(function ($row) {
                return (array) $row;
            })->values()->all(),
        ];
    }

    public function data($id)
    {
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            // Keep row order aligned with insertion sequence in this transaction.
            ->orderBy('pembelian_detail.id_pembelian_detail', 'asc')
            ->select('pembelian_detail.*')
            ->get();
        $data = array();
        $total = 0;
        $total_item = 0;

        foreach ($detail as $item) {
            $lineSubtotal = (int) ($item->subtotal ?? 0);
            $row = array();
            $row['kode_produk'] = '<span class="label label-primary">ID: '. intval($item->produk['id_produk']) .'</span>';
            $row['nama_produk'] = $item->produk['nama_produk'];
            $row['harga_jual']  = '<input type="number" class="form-control input-sm harga_jual" data-id="'. $item->produk['id_produk'] .'" value="'. $item->produk['harga_jual'] .'">';
            $row['harga_beli']  = '<input type="number" class="form-control input-sm harga_beli" data-id="'. $item->produk['id_produk'] .'" data-uid="'. $item->id_pembelian_detail .'" value="'. $item->harga_beli .'">';
            $row['jumlah']      = '<input type="number" class="form-control input-sm quantity" data-id="'. $item->id_pembelian_detail .'" value="'. $item->jumlah .'">';
            $row['expired_date']      = '<input type="date" class="form-control input-sm expired_date" data-id="'. $item->produk['id_produk'] .'" value="'. $item->produk['expired_date'] .'">';
            $row['batch']      = '<input type="text" class="form-control input-sm batch" data-id="'. $item->produk['id_produk'] .'" value="'. $item->produk['batch'] .'">';
            $row['subtotal']    = 'Rp. '. format_uang($lineSubtotal);
            $row['aksi']        = '<div class="btn-group">
                                    <button onclick="deleteData(`'. route('pembelian_detail.destroy', $item->id_pembelian_detail) .'`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                                </div>';
            $data[] = $row;

            $total += $lineSubtotal;
            $total_item += $item->jumlah;
        }
        $data[] = [
            'kode_produk' => '
                <div class="total hide">'. $total .'</div>
                <div class="total_item hide">'. $total_item .'</div>',
            'nama_produk' => '',
            'harga_beli'  => '',
            'harga_jual'  => '',
            'jumlah'      => '',
            'expired_date'      => '',
            'batch'      => '',
            'subtotal'    => '',
            'aksi'        => '',
        ];

        return datatables()
            ->of($data)
            ->addIndexColumn()
            ->rawColumns(['aksi', 'kode_produk', 'jumlah', 'harga_beli', 'harga_jual', 'expired_date', 'batch'])
            ->make(true);
    }

    public function store(Request $request)
    {
        $idempotencyKey = 'pembelian_store_' . $request->id_pembelian . '_' . $request->id_produk . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json('Request sedang diproses, mohon tunggu...', 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        set_time_limit(60);
        ini_set('memory_limit', '256M');
        
        try {
            $result = DB::transaction(function () use ($request) {
                $pembelian = Pembelian::where('id_pembelian', $request->id_pembelian)
                    ->lockForUpdate()
                    ->first();

                if (!$pembelian) {
                    throw new \Exception('Transaksi pembelian tidak ditemukan');
                }

                $this->ensurePembelianHasWaktu($pembelian);
                $useCommittedReflow = $this->shouldUseCommittedStockReflow($pembelian);

                $produk = Produk::where('id_produk', $request->id_produk)
                                ->lockForUpdate()
                                ->first();
                
                if (!$produk) {
                    throw new \Exception('Data produk tidak ditemukan');
                }
                
                $existing_detail = PembelianDetail::where('id_pembelian', $request->id_pembelian)
                                                  ->where('id_produk', $request->id_produk)
                                                  ->lockForUpdate()
                                                  ->first();
                
                $jumlah_tambahan = 1;
                
                if ($existing_detail) {
                    $old_jumlah = intval($existing_detail->jumlah);
                    $new_jumlah = $old_jumlah + $jumlah_tambahan;
                    
                    $existing_detail->jumlah = $new_jumlah;
                    $existing_detail->subtotal = $existing_detail->harga_beli * $new_jumlah;
                    $existing_detail->save();

                    if ($useCommittedReflow) {
                        $this->synchronizeFinalizedPembelianMutation($pembelian, [$produk->id_produk]);

                        return [
                            'stok_baru' => $this->resolveCurrentProductStock($produk->id_produk),
                            'produk_id' => $produk->id_produk,
                            'used_committed_reflow' => true,
                        ];
                    }

                    $stok_sebelum = intval($produk->stok);
                    $waktuTransaksi = $this->resolvePembelianStockWaktu($pembelian);
                    
                    $stok_baru = $stok_sebelum + $jumlah_tambahan;
                    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
                    
                    $existing_rekaman = DB::table('rekaman_stoks')
                        ->where('id_pembelian', $request->id_pembelian)
                        ->where('id_produk', $request->id_produk)
                        ->lockForUpdate()
                        ->first();
                    
                    if ($existing_rekaman) {
                        $originalStokAwal = intval($existing_rekaman->stok_awal);
                        $newStokMasuk = intval($existing_rekaman->stok_masuk) + $jumlah_tambahan;
                        $newStokSisa = $originalStokAwal + $newStokMasuk;
                        
                        DB::table('rekaman_stoks')
                            ->where('id_rekaman_stok', $existing_rekaman->id_rekaman_stok)
                            ->update([
                                'stok_masuk' => $newStokMasuk,
                                'stok_sisa' => $newStokSisa,
                                'updated_at' => now()
                            ]);
                    } else {
                        DB::table('rekaman_stoks')->insert([
                            'id_produk' => $produk->id_produk,
                            'id_pembelian' => $request->id_pembelian,
                            'waktu' => $waktuTransaksi,
                            'stok_masuk' => $new_jumlah,
                            'stok_keluar' => 0,
                            'stok_awal' => $stok_sebelum,
                            'stok_sisa' => $stok_baru,
                            'keterangan' => 'Pembelian: Penambahan stok dari supplier',
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    
                    return [
                        'stok_baru' => $stok_baru,
                        'produk_id' => $produk->id_produk,
                        'used_committed_reflow' => false,
                    ];
                } else {
                    $detail = new PembelianDetail();
                    $detail->id_pembelian = $request->id_pembelian;
                    $detail->id_produk = $produk->id_produk;
                    $detail->harga_beli = $produk->harga_beli;
                    $detail->jumlah = $jumlah_tambahan;
                    $detail->subtotal = $produk->harga_beli * $jumlah_tambahan;
                    $detail->save();

                    if ($useCommittedReflow) {
                        $this->synchronizeFinalizedPembelianMutation($pembelian, [$produk->id_produk]);

                        return [
                            'stok_baru' => $this->resolveCurrentProductStock($produk->id_produk),
                            'produk_id' => $produk->id_produk,
                            'used_committed_reflow' => true,
                        ];
                    }

                    $stok_sebelum = intval($produk->stok);
                    $waktuTransaksi = $this->resolvePembelianStockWaktu($pembelian);
                    
                    $stok_baru = $stok_sebelum + $jumlah_tambahan;
                    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stok_baru]);
                    
                    DB::table('rekaman_stoks')->insert([
                        'id_produk' => $produk->id_produk,
                        'id_pembelian' => $request->id_pembelian,
                        'waktu' => $waktuTransaksi,
                        'stok_masuk' => $jumlah_tambahan,
                        'stok_keluar' => 0,
                        'stok_awal' => $stok_sebelum,
                        'stok_sisa' => $stok_baru,
                        'keterangan' => 'Pembelian: Penambahan stok dari supplier',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    return [
                        'stok_baru' => $stok_baru,
                        'produk_id' => $produk->id_produk,
                        'used_committed_reflow' => false,
                    ];
                }
            }, 3);
            
            Cache::forget($idempotencyKey);

            if (empty($result['used_committed_reflow'])) {
                $this->syncAffectedProdukHistory([
                    $result['produk_id'] ?? null,
                ], intval($request->id_pembelian));
            }
            
            return response()->json('Data berhasil disimpan', 200);
            
        } catch (\Illuminate\Database\QueryException $e) {
            Cache::forget($idempotencyKey);
            Log::error('Database error in pembelian detail store: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'sql' => $e->getSql() ?? 'N/A',
                'bindings' => $e->getBindings() ?? []
            ]);
            
            if (strpos($e->getMessage(), 'Deadlock') !== false) {
                return response()->json('Database sedang sibuk. Silakan coba lagi.', 503);
            } elseif (strpos($e->getMessage(), 'Lock wait timeout') !== false) {
                return response()->json('Request timeout. Silakan coba lagi.', 503);
            } else {
                return response()->json('Terjadi kesalahan database', 500);
            }
            
        } catch (\Exception $e) {
            Cache::forget($idempotencyKey);
            Log::error('General error in pembelian detail store: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json('Error: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, $id)
    {
        return $this->handleQuantityUpdate($request, intval($id), 'pembelian_update');
    }

    public function updateEdit(Request $request, $id)
    {
        return $this->handleQuantityUpdate($request, intval($id), 'pembelian_updateedit');
    }

    public function destroy($id)
    {
        $idempotencyKey = 'pembelian_destroy_' . $id . '_' . auth()->id();
        
        if (Cache::has($idempotencyKey)) {
            return response()->json(['success' => false, 'message' => 'Request sedang diproses...'], 429);
        }
        
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);
        
        try {
            Log::info('PembelianDetailController@destroy called', ['id' => $id, 'user_id' => auth()->id()]);
        } catch (\Exception $e) {
        }
        
        DB::beginTransaction();
        
        try {
            $detail = PembelianDetail::where('id_pembelian_detail', $id)
                ->lockForUpdate()
                ->first();
            
            if (!$detail) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['success' => false, 'message' => 'Detail tidak ditemukan'], 404);
            }

            $pembelian = Pembelian::where('id_pembelian', $detail->id_pembelian)
                ->lockForUpdate()
                ->first();

            if (!$pembelian) {
                DB::rollBack();
                Cache::forget($idempotencyKey);
                return response()->json(['success' => false, 'message' => 'Transaksi pembelian tidak ditemukan'], 404);
            }

            $this->ensurePembelianHasWaktu($pembelian);
            
            $produkId = $detail->id_produk;
            $idPembelian = intval($detail->id_pembelian);
            $produk = Produk::where('id_produk', $detail->id_produk)
                ->lockForUpdate()
                ->first();

            if ($this->shouldUseCommittedStockReflow($pembelian)) {
                $detail->delete();
                $this->synchronizeFinalizedPembelianMutation($pembelian, [$produkId]);

                DB::commit();

                Cache::forget($idempotencyKey);

                return response(null, 204);
            }
            
            if ($produk) {
                $stokSebelum = intval($produk->stok);
                $stokBaru = $stokSebelum - intval($detail->jumlah);
                
                if ($stokBaru < 0) {
                    DB::rollBack();
                    Cache::forget($idempotencyKey);
                    return response()->json([
                        'success' => false, 
                        'message' => 'Tidak dapat menghapus pembelian! Stok produk saat ini: ' . $stokSebelum . ', akan dikurangi: ' . intval($detail->jumlah) . '. Hasil akan minus. Produk mungkin sudah terjual.'
                    ], 400);
                }
                
                DB::table('produk')->where('id_produk', $produkId)->update(['stok' => $stokBaru]);
                
                DB::table('rekaman_stoks')
                   ->where('id_pembelian', $detail->id_pembelian)
                   ->where('id_produk', $detail->id_produk)
                   ->delete();
            }
            
            $detail->delete();
            
            DB::commit();
            
            Cache::forget($idempotencyKey);

            $this->syncAffectedProdukHistory([
                $produkId ?? null,
            ], $idPembelian);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Cache::forget($idempotencyKey);
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response(null, 204);
    }

    public function getProdukData(Request $request)
    {
        if ($request->has('refresh_token')) {
            return response()->json([
                'csrf_token' => csrf_token()
            ]);
        }
        
        if ($request->has('ping')) {
            return response()->json(['status' => 'ok']);
        }
        
        $produk = Produk::orderBy('nama_produk')->get();
        
        $data = [];
        foreach ($produk as $key => $item) {
            $data[] = [
                'id' => $item->id_produk,
                'no' => $key + 1,
                'kode_produk' => $item->kode_produk,
                'nama_produk' => $item->nama_produk,
                'stok' => $item->stok,
                'harga_beli' => $item->harga_beli,
                'stok_badge_class' => $item->stok == 0 ? 'bg-red' : ($item->stok <= 5 ? 'bg-yellow' : 'bg-green'),
                'stok_text' => $item->stok == 0 ? 'Stok Habis - Harus Beli Dulu' : ($item->stok <= 5 ? 'Stok Menipis' : ''),
                'stok_icon' => $item->stok == 0 ? 'fa-exclamation-triangle' : ($item->stok <= 5 ? 'fa-warning' : ''),
                'stok_text_class' => $item->stok == 0 ? 'text-danger' : ($item->stok <= 5 ? 'text-warning' : '')
            ];
        }
        
        return response()->json($data)
               ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
               ->header('Pragma', 'no-cache')
               ->header('Expires', '0');
    }

    public function loadForm($diskon, $total)
    {
        $summary = Pembelian::calculateFinancialSummary($total, $diskon);

        $data  = [
            'total' => $summary['total_harga'],
            'totalrp' => format_uang($summary['total_harga']),
            'diskon_persen' => $summary['diskon_persen'],
            'diskon_nominal' => $summary['diskon_nominal'],
            'diskon_nominal_rp' => format_uang($summary['diskon_nominal']),
            'dpp' => $summary['dpp'],
            'dpprp' => format_uang($summary['dpp']),
            'ppn_persen' => $summary['ppn_persen'],
            'ppn_nominal' => $summary['ppn_nominal'],
            'ppnrp' => format_uang($summary['ppn_nominal']),
            'bayar' => $summary['grand_total'],
            'bayarrp' => format_uang($summary['grand_total']),
            'terbilang' => ucwords(terbilang($summary['grand_total']). ' Rupiah')
        ];

        return response()->json($data);
    }

    private function ensurePembelianHasWaktu($pembelian)
    {
        if (!$pembelian) {
            return;
        }

        $shouldSave = false;

        if (!$pembelian->waktu) {
            $pembelian->waktu = $pembelian->created_at ?? app(TransactionLogicalClockService::class)->now();
            $shouldSave = true;
        }

        if (!$pembelian->waktu_datang) {
            $pembelian->waktu_datang = $pembelian->created_at ?? $pembelian->waktu ?? app(TransactionLogicalClockService::class)->now();
            $shouldSave = true;
        }

        if ($shouldSave) {
            $pembelian->save();
        } else {
            // Selalu update 'updated_at' agar draft tidak dihapus oleh cleanup service saat aktif diinput
            $pembelian->touch();
        }
    }

    private function resolvePembelianStockWaktu($pembelian): string
    {
        if (!$pembelian) {
            return app(TransactionLogicalClockService::class)->now()->format('Y-m-d H:i:s');
        }

        $candidate = $pembelian->waktu_datang
            ?? $pembelian->waktu
            ?? $pembelian->created_at
            ?? app(TransactionLogicalClockService::class)->now();

        return Carbon::parse($candidate)->format('Y-m-d H:i:s');
    }

    private function syncAffectedProdukHistory(array $produkIds, ?int $idPembelian = null): void
    {
        try {
            app(PembelianStockSyncService::class)->syncAffectedProducts($produkIds, $idPembelian);
        } catch (\Throwable $e) {
            Log::warning('Sinkronisasi stok gagal setelah mutasi detail pembelian', [
                'id_pembelian' => $idPembelian,
                'product_ids' => array_values($produkIds),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function syncAffectedProdukHistoryOrFail(array $produkIds, ?int $idPembelian = null, array $options = []): array
    {
        return app(PembelianStockSyncService::class)->syncAffectedProducts($produkIds, $idPembelian, $options);
    }

    private function handleQuantityUpdate(Request $request, int $id, string $idempotencyPrefix)
    {
        $idempotencyKey = $idempotencyPrefix . '_' . $id . '_' . auth()->id();

        if (Cache::has($idempotencyKey)) {
            return response()->json(['message' => 'Request sedang diproses, mohon tunggu...'], 429);
        }

        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

        set_time_limit(90);
        ini_set('memory_limit', '256M');

        try {
            $detail = PembelianDetail::where('id_pembelian_detail', $id)->first();

            if (!$detail) {
                Cache::forget($idempotencyKey);
                return response()->json(['message' => 'Detail pembelian tidak ditemukan'], 404);
            }

            $sessionIdPembelian = session('id_pembelian');
            if (!$sessionIdPembelian || intval($sessionIdPembelian) !== intval($detail->id_pembelian)) {
                session(['id_pembelian' => $detail->id_pembelian]);

                $pembelian = Pembelian::find($detail->id_pembelian);
                if ($pembelian) {
                    session(['id_supplier' => $pembelian->id_supplier]);
                }
            }

            $newJumlah = $this->validatePembelianJumlahInput($request->input('jumlah'));
            $oldJumlah = intval($detail->jumlah);

            if ($newJumlah === $oldJumlah) {
                Cache::forget($idempotencyKey);

                return response()->json([
                    'message' => 'Data berhasil diperbarui',
                    'data' => [
                        'jumlah' => $newJumlah,
                        'subtotal' => $detail->subtotal,
                        'stok_tersisa' => $detail->produk->stok ?? 0,
                    ],
                ], 200);
            }

            $result = DB::transaction(function () use ($detail, $request, $newJumlah, $oldJumlah) {
                $lockedDetail = PembelianDetail::where('id_pembelian_detail', $detail->id_pembelian_detail)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedDetail) {
                    throw new \Exception('Detail pembelian tidak ditemukan');
                }

                $this->ensureRequestedProductUnchanged($request, $lockedDetail);

                $pembelian = Pembelian::where('id_pembelian', $lockedDetail->id_pembelian)
                    ->lockForUpdate()
                    ->first();

                if (!$pembelian) {
                    throw new \Exception('Transaksi pembelian tidak ditemukan');
                }

                $this->ensurePembelianHasWaktu($pembelian);

                $produk = Produk::where('id_produk', $lockedDetail->id_produk)
                    ->lockForUpdate()
                    ->first();

                if (!$produk) {
                    throw new \Exception('Produk tidak ditemukan');
                }

                if ($this->shouldUseCommittedStockReflow($pembelian)) {
                    $lockedDetail->jumlah = $newJumlah;
                    $lockedDetail->subtotal = $lockedDetail->harga_beli * $newJumlah;
                    $lockedDetail->save();

                    $this->synchronizeFinalizedPembelianMutation($pembelian, [$produk->id_produk]);

                    return [
                        'jumlah' => $newJumlah,
                        'subtotal' => $lockedDetail->subtotal,
                        'stok_tersisa' => $this->resolveCurrentProductStock($produk->id_produk),
                        'produk_id' => $produk->id_produk,
                        'used_committed_reflow' => true,
                    ];
                }

                $selisih = $newJumlah - $oldJumlah;
                $stokSebelum = intval($produk->stok);
                $stokBaru = $stokSebelum + $selisih;

                if ($stokBaru > 2147483647) {
                    throw new \Exception('Stok hasil akan melebihi batas maksimum');
                }

                if ($stokBaru < 0) {
                    Log::warning("Stok hasil koreksi pembelian menjadi negatif ({$stokBaru}). Penyesuaian stok akan ditangani oleh auto-recalculate (stok akhir tidak boleh kurang dari 0).", [
                        'id_produk' => $lockedDetail->id_produk,
                        'id_pembelian' => $lockedDetail->id_pembelian,
                        'new_jumlah' => $newJumlah,
                    ]);
                }

                DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);

                $lockedDetail->jumlah = $newJumlah;
                $lockedDetail->subtotal = $lockedDetail->harga_beli * $newJumlah;
                $lockedDetail->save();

                $waktuTransaksi = $this->resolvePembelianStockWaktu($pembelian);

                $rekamanStok = DB::table('rekaman_stoks')
                    ->where('id_pembelian', $lockedDetail->id_pembelian)
                    ->where('id_produk', $lockedDetail->id_produk)
                    ->lockForUpdate()
                    ->first();

                if ($rekamanStok) {
                    $originalStokAwal = intval($rekamanStok->stok_awal);
                    $newStokSisa = $originalStokAwal + $newJumlah;

                    DB::table('rekaman_stoks')
                        ->where('id_rekaman_stok', $rekamanStok->id_rekaman_stok)
                        ->update([
                            'stok_masuk' => $newJumlah,
                            'stok_sisa' => $newStokSisa,
                            'keterangan' => 'Pembelian: Update jumlah transaksi',
                            'updated_at' => now(),
                        ]);
                } else {
                    $stokAwal = $stokBaru - $newJumlah;

                    DB::table('rekaman_stoks')->insert([
                        'id_produk' => $produk->id_produk,
                        'id_pembelian' => $lockedDetail->id_pembelian,
                        'waktu' => $waktuTransaksi,
                        'stok_masuk' => $newJumlah,
                        'stok_keluar' => 0,
                        'stok_awal' => $stokAwal,
                        'stok_sisa' => $stokBaru,
                        'keterangan' => 'Pembelian: Update jumlah transaksi',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return [
                    'jumlah' => $newJumlah,
                    'subtotal' => $lockedDetail->subtotal,
                    'stok_tersisa' => $stokBaru,
                    'produk_id' => $produk->id_produk,
                    'used_committed_reflow' => false,
                ];
            }, 5);

            Cache::forget($idempotencyKey);

            if (empty($result['used_committed_reflow'])) {
                $this->syncAffectedProdukHistory([
                    $result['produk_id'] ?? null,
                ], intval($detail->id_pembelian));
            }

            unset($result['used_committed_reflow']);

            return response()->json([
                'message' => 'Data berhasil diperbarui',
                'data' => $result,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            Cache::forget($idempotencyKey);

            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Illuminate\Database\QueryException $e) {
            Cache::forget($idempotencyKey);
            Log::error('Database error in pembelian detail quantity update: ' . $e->getMessage(), [
                'detail_id' => $id,
                'sql' => $e->getSql() ?? null,
                'bindings' => $e->getBindings() ?? [],
            ]);

            if (strpos($e->getMessage(), 'Deadlock') !== false) {
                return response()->json(['message' => 'Database sedang sibuk. Silakan coba lagi.'], 503);
            }

            if (strpos($e->getMessage(), 'Lock wait timeout') !== false) {
                return response()->json(['message' => 'Request timeout. Silakan coba lagi.'], 503);
            }

            return response()->json(['message' => 'Terjadi kesalahan database. Silakan coba lagi.'], 500);
        } catch (\Exception $e) {
            Cache::forget($idempotencyKey);
            Log::error('General error in pembelian detail quantity update: ' . $e->getMessage(), [
                'detail_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Terjadi kesalahan sistem. Silakan coba lagi.'], 500);
        }
    }

    private function validatePembelianJumlahInput($input): int
    {
        if ($input === null || $input === '') {
            throw new \InvalidArgumentException('Jumlah harus diisi');
        }

        if (!is_numeric($input)) {
            throw new \InvalidArgumentException('Jumlah harus berupa angka');
        }

        $jumlah = (int) $input;

        if ($jumlah < 1) {
            throw new \InvalidArgumentException('Jumlah harus minimal 1');
        }

        if ($jumlah > 10000) {
            throw new \InvalidArgumentException('Jumlah tidak boleh lebih dari 10000');
        }

        return $jumlah;
    }

    private function ensureRequestedProductUnchanged(Request $request, PembelianDetail $detail): void
    {
        if (!$request->has('id_produk')) {
            return;
        }

        $requestedProductId = intval($request->input('id_produk'));
        if ($requestedProductId <= 0) {
            return;
        }

        if ($requestedProductId !== intval($detail->id_produk)) {
            throw new \InvalidArgumentException('Produk pada detail pembelian tidak dapat diganti lewat edit jumlah. Hapus baris lama lalu tambah produk yang benar.');
        }
    }

    private function shouldUseCommittedStockReflow(?Pembelian $pembelian): bool
    {
        if (!$pembelian) {
            return false;
        }

        $noFaktur = trim((string) ($pembelian->no_faktur ?? ''));
        if ($noFaktur === '' || strtolower($noFaktur) === 'o') {
            return false;
        }

        if (intval($pembelian->total_harga ?? 0) <= 0 || intval($pembelian->bayar ?? 0) <= 0) {
            return false;
        }

        return $this->resolvePembelianStockWaktu($pembelian) > $this->resolveStockCutoff();
    }

    private function resolveStockCutoff(): string
    {
        return (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
    }

    private function buildPembelianSyncSnapshot(?Pembelian $pembelian): ?array
    {
        if (!$pembelian) {
            return null;
        }

        return [
            'id_pembelian' => intval($pembelian->id_pembelian),
            'no_faktur' => $pembelian->no_faktur,
            'total_harga' => $pembelian->total_harga,
            'bayar' => $pembelian->bayar,
            'waktu' => $pembelian->waktu,
            'waktu_datang' => $pembelian->waktu_datang,
            'created_at' => $pembelian->created_at,
        ];
    }

    private function synchronizeFinalizedPembelianMutation(Pembelian $pembelian, array $produkIds): array
    {
        return $this->syncAffectedProdukHistoryOrFail($produkIds, intval($pembelian->id_pembelian), [
            'force_reflow' => true,
            'pembelian_snapshot' => $this->buildPembelianSyncSnapshot($pembelian),
        ]);
    }

    private function resolveCurrentProductStock(int $productId): int
    {
        return intval(DB::table('produk')->where('id_produk', $productId)->value('stok') ?? 0);
    }

    private function syncBatchAffectedProdukHistory(array $successRows): void
    {
        $grouped = [];

        foreach ($successRows as $row) {
            $produkId = intval($row['id_produk'] ?? 0);
            if ($produkId <= 0) {
                continue;
            }

            $idPembelian = intval($row['id_pembelian'] ?? 0);
            $bucket = $idPembelian > 0 ? $idPembelian : 0;
            $grouped[$bucket][] = $produkId;
        }

        foreach ($grouped as $idPembelian => $produkIds) {
            $this->syncAffectedProdukHistory($produkIds, $idPembelian > 0 ? $idPembelian : null);
        }
    }
    
    private function atomicRecalculateAndSync($produkId)
    {
        try {
            $lockKey = 'stock_recalc_' . $produkId;
            $lock = Cache::lock($lockKey, 30);
            
            if ($lock->get()) {
                try {
                    $stokRecords = DB::table('rekaman_stoks')
                        ->where('id_produk', $produkId)
                        ->orderBy('waktu', 'asc')
                        ->orderBy('created_at', 'asc')
                        ->orderBy('id_rekaman_stok', 'asc')
                        ->get();

                    if ($stokRecords->isEmpty()) {
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

                    foreach ($updates as $recordId => $updateData) {
                        DB::table('rekaman_stoks')
                            ->where('id_rekaman_stok', $recordId)
                            ->update($updateData);
                    }
                    
                    $finalStock = max(0, $runningStock);
                    DB::table('produk')
                        ->where('id_produk', $produkId)
                        ->update(['stok' => $finalStock]);
                        
                } finally {
                    $lock->release();
                }
            }
        } catch (\Exception $e) {
            Log::error('atomicRecalculateAndSync error: ' . $e->getMessage(), [
                'produk_id' => $produkId
            ]);
        }
    }
    
    public function batchUpdate(Request $request)
    {
        set_time_limit(180);
        ini_set('memory_limit', '1G');
        
        try {
            $updates = $request->input('updates', []);
            
            if (empty($updates)) {
                return response()->json(['message' => 'Tidak ada data untuk diupdate'], 400);
            }
            
            if (count($updates) > 100) {
                return response()->json(['message' => 'Maksimal 100 item per batch'], 400);
            }
            
            $batchService = new PembelianBatchService();
            $result = $batchService->bulkUpdateStok($updates);

            $this->syncBatchAffectedProdukHistory($result['success'] ?? []);
            
            if (!empty($result['errors'])) {
                Log::warning('Batch update completed with errors', $result['errors']);
            }
            
            return response()->json([
                'message' => 'Batch update completed',
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Batch update failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Batch update gagal: ' . $e->getMessage()
            ], 500);
        }
    }
}
