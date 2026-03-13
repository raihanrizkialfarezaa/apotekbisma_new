<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Penjualan;
use App\Models\PenjualanDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade as PDF;
use Carbon\Carbon;
use App\Services\StockDraftCleanupService;
use App\Services\TransactionDateMutationService;

class PenjualanController extends Controller
{
    public function index(Request $request)
    {
        $products = Produk::select('id_produk', 'nama_produk')
            ->orderBy('nama_produk', 'asc')
            ->get();

        $filterDefaults = [
            'date_preset' => $request->get('date_preset', 'all'),
            'start_date' => $request->get('start_date'),
            'end_date' => $request->get('end_date'),
            'id_produk' => $request->get('id_produk'),
        ];

        return view('penjualan.index', compact('products', 'filterDefaults'));
    }

    public function data(Request $request)
    {
        $penjualan = Penjualan::query()
            ->with(['member', 'user'])
            ->orderBy('id_penjualan', 'desc');

        [$resolvedStartDate, $resolvedEndDate] = $this->resolveDateRange(
            $request->input('date_preset', 'all'),
            $request->input('start_date'),
            $request->input('end_date')
        );

        if ($resolvedStartDate && $resolvedEndDate) {
            $penjualan->whereDate(DB::raw('COALESCE(penjualan.waktu, penjualan.created_at)'), '>=', $resolvedStartDate)
                ->whereDate(DB::raw('COALESCE(penjualan.waktu, penjualan.created_at)'), '<=', $resolvedEndDate);
        }

        $productFilter = $request->input('id_produk');
        $productIds = collect(is_array($productFilter) ? $productFilter : [$productFilter])
            ->flatMap(function ($value) {
                return explode(',', (string) $value);
            })
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter(function ($value) {
                return $value !== '' && is_numeric($value) && (int) $value > 0;
            })
            ->map(function ($value) {
                return (int) $value;
            })
            ->unique()
            ->values();

        if ($productIds->isNotEmpty()) {
            $penjualan->whereHas('detail', function ($query) use ($productIds) {
                $query->whereIn('id_produk', $productIds->all());
            });
        }

        return datatables()
            ->of($penjualan)
            ->addIndexColumn()
            ->addColumn('total_item', function ($penjualan) {
                return format_uang($penjualan->total_item);
            })
            ->addColumn('total_harga', function ($penjualan) {
                $totalHarga = 'Rp. '. format_uang($penjualan->total_harga);

                if ($this->isPenjualanIncomplete($penjualan)) {
                    $totalHarga .= ' <span class="label label-warning">Belum Selesai</span>';
                } else {
                    $totalHarga .= ' <span class="label label-success">Selesai</span>';
                }
                
                return $totalHarga;
            })
            ->addColumn('bayar', function ($penjualan) {
                return 'Rp. '. format_uang($penjualan->bayar);
            })
            ->addColumn('tanggal', function ($penjualan) {
                if ($penjualan->waktu != null) {
                    return tanggal_indonesia($penjualan->waktu, false);
                } else {
                    return tanggal_indonesia($penjualan->created_at, false);
                }
                
            })
            ->addColumn('kode_member', function ($penjualan) {
                $member = $penjualan->member->kode_member ?? '';
                return '<span class="label label-success">'. $member .'</spa>';
            })
            ->editColumn('diskon', function ($penjualan) {
                return $penjualan->diskon . '%';
            })
            ->editColumn('kasir', function ($penjualan) {
                return $penjualan->user->name ?? '';
            })
            ->addColumn('aksi', function ($penjualan) {
                $isIncomplete = $this->isPenjualanIncomplete($penjualan);
                $isCompleted = !$isIncomplete;
                
                $buttons = '
                <div class="btn-group">
                    <button onclick="showDetail(`'. route('penjualan.show', $penjualan->id_penjualan) .'`)" class="btn btn-xs btn-info btn-flat" title="Lihat Detail"><i class="fa fa-eye"></i></button>';
                
                // Tambahkan button berdasarkan status transaksi
                if ($isIncomplete) {
                    $buttons .= '
                    <button onclick="lanjutkanTransaksi('. $penjualan->id_penjualan .')" class="btn btn-xs btn-warning btn-flat" title="Lanjutkan Transaksi" data-toggle="tooltip"><i class="fa fa-play"></i></button>';
                } elseif ($isCompleted) {
                    $buttons .= '
                    <button onclick="editTransaksi('. $penjualan->id_penjualan .')" class="btn btn-xs btn-success btn-flat" title="Edit Transaksi" data-toggle="tooltip"><i class="fa fa-edit"></i></button>';
                }
                
                // Tambahkan tombol print untuk semua transaksi yang memiliki detail
                if ((int) $penjualan->total_item > 0) {
                    $buttons .= '
                    <button onclick="printReceipt('. $penjualan->id_penjualan .')" class="btn btn-xs btn-primary btn-flat" title="Cetak Struk" data-toggle="tooltip"><i class="fa fa-print"></i></button>';
                }
                
                $buttons .= '
                    <button onclick="deleteData(`'. route('penjualan.destroy', $penjualan->id_penjualan) .'`)" class="btn btn-xs btn-danger btn-flat" title="Hapus Transaksi"><i class="fa fa-trash"></i></button>
                </div>';
                
                return $buttons;
            })
            ->rawColumns(['aksi', 'kode_member', 'total_harga'])
            ->make(true);
    }

    private function resolveDateRange(string $preset, ?string $startDate, ?string $endDate): array
    {
        $today = Carbon::today();
        $start = null;
        $end = null;

        switch ($preset) {
            case 'today':
                $start = $today->copy();
                $end = $today->copy();
                break;
            case 'yesterday':
                $start = $today->copy()->subDay();
                $end = $today->copy()->subDay();
                break;
            case 'last_7_days':
                $start = $today->copy()->subDays(6);
                $end = $today->copy();
                break;
            case 'last_30_days':
                $start = $today->copy()->subDays(29);
                $end = $today->copy();
                break;
            case 'this_week':
                $start = $today->copy()->startOfWeek(Carbon::MONDAY);
                $end = $today->copy()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'last_week':
                $start = $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
                $end = $today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'this_month':
                $start = $today->copy()->startOfMonth();
                $end = $today->copy()->endOfMonth();
                break;
            case 'last_month':
                $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
                $end = $today->copy()->subMonthNoOverflow()->endOfMonth();
                break;
            case 'this_year':
                $start = $today->copy()->startOfYear();
                $end = $today->copy()->endOfYear();
                break;
            case 'custom':
            case 'all':
            default:
                break;
        }

        if ($preset === 'custom') {
            $parsedStart = $this->parseDateInput($startDate);
            $parsedEnd = $this->parseDateInput($endDate);

            if ($parsedStart) {
                $start = $parsedStart;
            }

            if ($parsedEnd) {
                $end = $parsedEnd;
            }

            if ($start && !$end) {
                $end = $start->copy();
            }

            if ($end && !$start) {
                $start = $end->copy();
            }

            if ($start && $end && $start->greaterThan($end)) {
                [$start, $end] = [$end, $start];
            }
        }

        return [
            $start?->toDateString(),
            $end?->toDateString(),
        ];
    }

    private function parseDateInput(?string $date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);
            return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function create()
    {
        $currentDraftId = session('id_penjualan');
        app(StockDraftCleanupService::class)->cleanupStalePenjualanDrafts($currentDraftId ? intval($currentDraftId) : null);

        // Pastikan saat membuka halaman 'Transaksi Baru' kita mulai dengan transaksi baru
        // sehingga tidak otomatis diarahkan ke transaksi aktif yang tersimpan di session.
        if (session('id_penjualan')) {
            session()->forget('id_penjualan');
        }

        // Tampilkan halaman kosong untuk transaksi baru tanpa membuat record di database
        $produk = Produk::orderBy('nama_produk')->get();
        $member = Member::orderBy('nama')->get();
        $diskon = Setting::first()->diskon ?? 0;
        $id_penjualan = null;
        $penjualan = new Penjualan();
        $memberSelected = new Member();

        return view('penjualan_detail.index', compact('produk', 'member', 'diskon', 'id_penjualan', 'penjualan', 'memberSelected'));
    }

    public function createOrContinue()
    {
        if ($id_penjualan = session('id_penjualan')) {
            $penjualan = Penjualan::find($id_penjualan);
            if ($penjualan) {
                $produk = Produk::orderBy('nama_produk')->get();
                $member = Member::orderBy('nama')->get();
                $diskon = Setting::first()->diskon ?? 0;
                $memberSelected = $penjualan->member ?? new Member();

                return view('penjualan_detail.index', compact('produk', 'member', 'diskon', 'id_penjualan', 'penjualan', 'memberSelected'));
            } else {
                // ID penjualan di session tidak valid, bersihkan session
                session()->forget('id_penjualan');
            }
        }

        return redirect()->route('transaksi.baru');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'waktu' => 'required',
        ], [
            'waktu.required' => 'Tanggal transaksi harus diisi',
        ]);

        DB::beginTransaction();
        
        try {
            $penjualan = Penjualan::findOrFail($id);
            
            $waktu_lama = Carbon::parse($penjualan->waktu ?? $penjualan->created_at)->format('Y-m-d H:i:s');
            $waktu_baru = $this->resolveTransactionWaktu(
                $request->waktu,
                $penjualan->waktu ?? $penjualan->created_at ?? Carbon::now()
            );
            
            $penjualan->id_member = $request->id_member;
            $penjualan->total_item = $request->total_item;
            $penjualan->total_harga = $request->total;
            $penjualan->diskon = $request->diskon;
            $penjualan->bayar = $request->bayar;
            $penjualan->waktu = $waktu_baru;
            $penjualan->update();

            app(TransactionDateMutationService::class)->handlePenjualanFinalDateChange(
                $penjualan,
                $waktu_lama,
                $waktu_baru
            );

            DB::commit();
            
            return redirect()->route('transaksi.selesai');
            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors('Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function store(Request $request)
    {
        // Validasi input
        $request->validate([
            'id_penjualan' => 'required',
            'diterima' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required',
        ], [
            'waktu.required' => 'Tanggal transaksi harus diisi',
        ]);

        // Cek apakah ada detail penjualan
        $detail = PenjualanDetail::where('id_penjualan', $request->id_penjualan)->get();
        if ($detail->isEmpty()) {
            return redirect()->back()->with('error', 'Minimal harus ada 1 produk yang ditambahkan ke transaksi');
        }

        // Validasi bahwa diterima tidak boleh kurang dari total bayar
        $total_bayar = $request->total - ($request->diskon / 100 * $request->total);
        if ($request->diterima < $total_bayar) {
            return redirect()->back()->with('error', 'Jumlah yang diterima (Rp. ' . number_format($request->diterima, 0, ',', '.') . ') tidak boleh kurang dari total bayar (Rp. ' . number_format($total_bayar, 0, ',', '.') . ')');
        }

        DB::beginTransaction();

        try {
            $penjualan = Penjualan::findOrFail($request->id_penjualan);
            $penjualan->id_member = $request->id_member;
            $penjualan->total_item = $request->total_item;
            $penjualan->total_harga = $request->total;
            $penjualan->diskon = $request->diskon;
            $penjualan->bayar = $request->bayar;
            $penjualan->diterima = $request->diterima;
            $penjualan->waktu = $this->resolveTransactionWaktu(
                $request->waktu,
                $penjualan->waktu ?? $penjualan->created_at ?? Carbon::now()
            );
            $penjualan->update();

            $id_penjualan = $penjualan->id_penjualan;
            
            foreach ($detail as $item) {
                $item->diskon = $request->diskon;
                $item->update();

                $produk = Produk::find($item->id_produk);
                $existing_rekaman = RekamanStok::where('id_penjualan', $id_penjualan)
                    ->where('id_produk', $item->id_produk)
                    ->first();
                
                if (!$existing_rekaman && $produk) {
                    RekamanStok::create([
                        'id_produk' => $item->id_produk,
                        'waktu' => $penjualan->waktu ?? Carbon::now(),
                        'stok_keluar' => $item->jumlah,
                        'id_penjualan' => $id_penjualan,
                        'stok_awal' => $produk->stok + $item->jumlah,
                        'stok_sisa' => $produk->stok,
                    ]);
                }
            }

            try {
                app(TransactionDateMutationService::class)->synchronizeFinalizedPenjualan($penjualan);
            } catch (\Throwable $syncException) {
                Log::warning('Sinkronisasi finalized penjualan gagal, fallback ke recalculate per produk', [
                    'id_penjualan' => $penjualan->id_penjualan,
                    'message' => $syncException->getMessage(),
                ]);

                $affectedProductIds = $detail->pluck('id_produk')
                    ->map(function ($id) {
                        return intval($id);
                    })
                    ->filter(function ($id) {
                        return $id > 0;
                    })
                    ->unique()
                    ->values();

                foreach ($affectedProductIds as $produkId) {
                    try {
                        RekamanStok::recalculateStock($produkId);
                    } catch (\Throwable $recalcException) {
                        Log::warning('Fallback recalculate penjualan gagal', [
                            'id_penjualan' => $penjualan->id_penjualan,
                            'id_produk' => $produkId,
                            'message' => $recalcException->getMessage(),
                        ]);
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }

        // Hapus session setelah transaksi selesai
        session()->forget('id_penjualan');

        return redirect()->route('penjualan.index')->with('success', 'Transaksi berhasil disimpan!');
    }

    public function show($id)
    {
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', $id)
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();

        return datatables()
            ->of($detail)
            ->addIndexColumn()
            ->addColumn('kode_produk', function ($detail) {
                return '<span class="label label-success">'. $detail->produk->kode_produk .'</span>';
            })
            ->addColumn('nama_produk', function ($detail) {
                return $detail->produk->nama_produk;
            })
            ->addColumn('harga_jual', function ($detail) {
                return 'Rp. '. format_uang($detail->harga_jual);
            })
            ->addColumn('jumlah', function ($detail) {
                return format_uang($detail->jumlah);
            })
            ->addColumn('subtotal', function ($detail) {
                return 'Rp. '. format_uang($detail->subtotal);
            })
            ->rawColumns(['kode_produk'])
            ->make(true);
    }

    public function destroy($id)
    {
        try {
            Log::info('PenjualanController@destroy called', ['id' => $id, 'user_id' => auth()->id()]);
        } catch (\Exception $e) {
        }
        
        DB::beginTransaction();
        
        try {
            $penjualan = Penjualan::find($id);
            
            if (!$penjualan) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Transaksi tidak ditemukan'], 404);
            }

            $detail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
            $affectedProductIds = [];
            
            DB::table('rekaman_stoks')->where('id_penjualan', $penjualan->id_penjualan)->delete();
            
            foreach ($detail as $item) {
                $produk = Produk::query()
                    ->where('id_produk', $item->id_produk)
                    ->lockForUpdate()
                    ->first();
                if ($produk) {
                    $affectedProductIds[] = $produk->id_produk;
                    $stokSebelum = intval($produk->stok);
                    $stokBaru = $stokSebelum + intval($item->jumlah);
                    
                    DB::table('produk')->where('id_produk', $produk->id_produk)->update(['stok' => $stokBaru]);
                }
                
                $item->delete();
            }

            $penjualan->delete();

            DB::commit();
            
            foreach (array_unique($affectedProductIds) as $produkId) {
                try {
                    RekamanStok::recalculateStock($produkId);
                } catch (\Exception $e) {
                    Log::warning('Recalculate stock after delete warning: ' . $e->getMessage());
                }
            }
            
            return response()->json(['success' => true, 'message' => 'Transaksi berhasil dihapus dan stok dikembalikan'], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function lanjutkanTransaksi($id)
    {
        $penjualan = Penjualan::find($id);
        
        if (!$penjualan) {
            return redirect()->back()->with('error', 'Transaksi tidak ditemukan');
        }

        session(['id_penjualan' => $penjualan->id_penjualan]);
        
        return redirect()->route('transaksi.aktif')->with('success', 'Melanjutkan transaksi #' . $penjualan->id_penjualan);
    }

    public function editTransaksi($id)
    {
        $penjualan = Penjualan::find($id);
        
        if (!$penjualan) {
            return redirect()->back()->with('error', 'Transaksi tidak ditemukan');
        }

        session(['id_penjualan' => $penjualan->id_penjualan]);
        
        return redirect()->route('transaksi.aktif')->with('success', 'Mengedit transaksi #' . $penjualan->id_penjualan);
    }

    public function destroyEmpty()
    {
        $summary = app(StockDraftCleanupService::class)->cleanupStalePenjualanDrafts(session('id_penjualan') ? intval(session('id_penjualan')) : null);
        
        // Hapus session jika transaksi yang ada di session sudah dihapus
        if (session('id_penjualan')) {
            $currentTransaction = Penjualan::find(session('id_penjualan'));
            if (!$currentTransaction) {
                session()->forget('id_penjualan');
            }
        }
        
        return response()->json([
            'message' => 'Draft transactions cleaned up',
            'summary' => $summary,
        ], 200);
    }

    public function selesai()
    {
        $setting = Setting::first();

        return view('penjualan.selesai', compact('setting'));
    }

    public function notaKecil()
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find(session('id_penjualan'));
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', session('id_penjualan'))
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();
        
        return view('penjualan.nota_kecil', compact('setting', 'penjualan', 'detail'));
    }

    public function notaBesar()
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find(session('id_penjualan'));
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', session('id_penjualan'))
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();

        $pdf = PDF::loadView('penjualan.nota_besar', compact('setting', 'penjualan', 'detail'));
        $pdf->setPaper('a4', 'portrait');
        return $pdf->stream('Transaksi-'. date('Y-m-d-his') .'.pdf');
    }

    public function printReceipt($id)
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find($id);
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', $id)
            ->join('produk', 'penjualan_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('penjualan_detail.*')
            ->get();

        // Cek tipe nota dari setting
        if ($setting->tipe_nota == 1) {
            // Nota Kecil
            return view('penjualan.nota_kecil', compact('setting', 'penjualan', 'detail'));
        } else {
            // Nota Besar (PDF)
            $pdf = PDF::loadView('penjualan.nota_besar', compact('setting', 'penjualan', 'detail'));
            $pdf->setPaper('a4', 'portrait');
            return $pdf->stream('Struk-Transaksi-'. $penjualan->id_penjualan .'-'. date('Y-m-d-His') .'.pdf');
        }
    }

    private function isPenjualanIncomplete($penjualan): bool
    {
        $hasDetail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->exists();
        $hasIncompleteDetail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)
            ->where('jumlah', '<=', 0)
            ->exists();

        return !$hasDetail
            || $hasIncompleteDetail
            || intval($penjualan->total_item ?? 0) <= 0
            || intval($penjualan->total_harga ?? 0) <= 0
            || intval($penjualan->bayar ?? 0) <= 0
            || intval($penjualan->diterima ?? 0) <= 0;
    }

    private function resolveTransactionWaktu($value, $fallback = null): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            throw new \InvalidArgumentException('Tanggal transaksi harus diisi');
        }

        $fallbackCarbon = $fallback ? Carbon::parse($fallback) : Carbon::now();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m-d', $raw)
                ->setTimeFrom($fallbackCarbon)
                ->format('Y-m-d H:i:s');
        }

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $raw);
                if (in_array($format, ['Y-m-d\TH:i', 'Y-m-d H:i'], true)) {
                    $parsed->second = $fallbackCarbon->second;
                }

                return $parsed->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
            }
        }

        return Carbon::parse($raw)->format('Y-m-d H:i:s');
    }
}
