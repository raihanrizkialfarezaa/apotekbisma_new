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
use Barryvdh\DomPDF\Facade as PDF;
use Carbon\Carbon;

class PenjualanController extends Controller
{
    public function index()
    {
        return view('penjualan.index');
    }

    public function data()
    {
        $penjualan = Penjualan::with('member')->orderBy('id_penjualan', 'desc')->get();

        return datatables()
            ->of($penjualan)
            ->addIndexColumn()
            ->addColumn('total_item', function ($penjualan) {
                return format_uang($penjualan->total_item);
            })
            ->addColumn('total_harga', function ($penjualan) {
                $totalHarga = 'Rp. '. format_uang($penjualan->total_harga);
                
                // Transaksi sebelum hari ini dianggap selesai semua
                $today = date('Y-m-d');
                $transactionDate = date('Y-m-d', strtotime($penjualan->created_at));
                
                if ($transactionDate < $today) {
                    $totalHarga .= ' <span class="label label-success">Selesai</span>';
                } else {
                    // Untuk transaksi hari ini dan seterusnya, cek status sebenarnya
                    $hasDetail = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->exists();
                    $hasIncompleteDetail = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)
                                                                      ->where('jumlah', '<=', 0)
                                                                      ->exists();
                    
                    $isIncomplete = (!$hasDetail || 
                                    $hasIncompleteDetail || 
                                    $penjualan->total_harga == 0 || 
                                    $penjualan->diterima == 0);
                    
                    $isCompleted = ($hasDetail && 
                                   !$hasIncompleteDetail && 
                                   $penjualan->total_harga > 0 && 
                                   $penjualan->diterima > 0);
                    
                    if ($isIncomplete) {
                        $totalHarga .= ' <span class="label label-warning">Belum Selesai</span>';
                    } elseif ($isCompleted) {
                        $totalHarga .= ' <span class="label label-success">Selesai</span>';
                    }
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
                // Transaksi sebelum hari ini dianggap selesai semua
                $today = date('Y-m-d');
                $transactionDate = date('Y-m-d', strtotime($penjualan->created_at));
                
                if ($transactionDate < $today) {
                    // Transaksi lama - selalu tampilkan tombol edit
                    $buttons = '
                    <div class="btn-group">
                        <button onclick="showDetail(`'. route('penjualan.show', $penjualan->id_penjualan) .'`)" class="btn btn-xs btn-info btn-flat" title="Lihat Detail"><i class="fa fa-eye"></i></button>
                        <button onclick="editTransaksi('. $penjualan->id_penjualan .')" class="btn btn-xs btn-success btn-flat" title="Edit Transaksi" data-toggle="tooltip"><i class="fa fa-edit"></i></button>
                        <button onclick="printReceipt('. $penjualan->id_penjualan .')" class="btn btn-xs btn-primary btn-flat" title="Cetak Struk" data-toggle="tooltip"><i class="fa fa-print"></i></button>
                        <button onclick="deleteData(`'. route('penjualan.destroy', $penjualan->id_penjualan) .'`)" class="btn btn-xs btn-danger btn-flat" title="Hapus Transaksi"><i class="fa fa-trash"></i></button>
                    </div>';
                    
                    return $buttons;
                }
                
                // Untuk transaksi hari ini dan seterusnya, cek status sebenarnya
                $hasDetail = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->exists();
                $hasIncompleteDetail = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)
                                                                  ->where('jumlah', '<=', 0)
                                                                  ->exists();
                
                $isIncomplete = (!$hasDetail || 
                                $hasIncompleteDetail || 
                                $penjualan->total_harga == 0 || 
                                $penjualan->diterima == 0);
                
                $isCompleted = ($hasDetail && 
                               !$hasIncompleteDetail && 
                               $penjualan->total_harga > 0 && 
                               $penjualan->diterima > 0);
                
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
                if ($hasDetail) {
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

    public function create()
    {
        // SELALU bersihkan session untuk memastikan transaksi baru yang benar-benar bersih
        session()->forget('id_penjualan');
        
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

                return view('penjualan_detail.detail', compact('produk', 'member', 'diskon', 'id_penjualan', 'penjualan', 'memberSelected'));
            }
        }

        return redirect()->route('transaksi.baru');
    }

    public function update(Request $request, $id)
    {
        $penjualan = Penjualan::findOrFail($id);
        $penjualan->id_member = $request->id_member;
        $penjualan->total_item = $request->total_item;
        $penjualan->total_harga = $request->total;
        $penjualan->diskon = $request->diskon;
        $penjualan->bayar = $request->bayar;
        $penjualan->waktu = $request->waktu;
        $penjualan->update();

        return redirect()->route('transaksi.selesai');
    }

    public function store(Request $request)
    {
        // Validasi input
        $request->validate([
            'id_penjualan' => 'required',
            'diterima' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
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

        $penjualan = Penjualan::findOrFail($request->id_penjualan);
        $penjualan->id_member = $request->id_member;
        $penjualan->total_item = $request->total_item;
        $penjualan->total_harga = $request->total;
        $penjualan->diskon = $request->diskon;
        $penjualan->bayar = $request->bayar;
        $penjualan->diterima = $request->diterima;
        // Pastikan waktu transaksi adalah tanggal hari ini jika tidak diisi
        $penjualan->waktu = $request->waktu ?? date('Y-m-d');
        $penjualan->update();

        $id_penjualan = $penjualan->id_penjualan;
        
        // Proses setiap item dalam transaksi
        foreach ($detail as $item) {
            $item->diskon = $request->diskon;
            $item->update();

            $produk = Produk::find($item->id_produk);
            
            $existing_rekaman = RekamanStok::where('id_penjualan', $id_penjualan)
                                          ->where('id_produk', $item->id_produk)
                                          ->first();
            
            if (!$existing_rekaman) {
                RekamanStok::create([
                    'id_produk' => $item->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_keluar' => $item->jumlah,
                    'id_penjualan' => $id_penjualan,
                    'stok_awal' => $produk->stok + $item->jumlah,
                    'stok_sisa' => $produk->stok,
                ]);
            }
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
        DB::beginTransaction();
        
        try {
            $penjualan = Penjualan::find($id);
            
            if (!$penjualan) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Transaksi tidak ditemukan'], 404);
            }

            // Ambil detail transaksi untuk mengembalikan stok
            $detail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
            
            foreach ($detail as $item) {
                $produk = Produk::find($item->id_produk);
                if ($produk) {
                    // Catat stok sebelum perubahan
                    $stokSebelum = $produk->stok;
                    
                    // Kembalikan stok produk sesuai jumlah yang dijual
                    $produk->stok = $stokSebelum + $item->jumlah;
                    $produk->save();
                    
                    // Buat rekaman audit untuk pengembalian stok
                    \App\Models\RekamanStok::create([
                        'id_produk' => $item->id_produk,
                        'waktu' => now(),
                        'stok_masuk' => $item->jumlah,
                        'stok_awal' => $stokSebelum,
                        'stok_sisa' => $produk->stok,
                        'keterangan' => 'Penghapusan transaksi penjualan: Pengembalian stok'
                    ]);
                }
                
                // Hapus detail transaksi
                $item->delete();
            }

            // Hapus semua rekaman stok yang terkait dengan transaksi ini
            \App\Models\RekamanStok::where('id_penjualan', $penjualan->id_penjualan)->delete();

            // Hapus transaksi
            $penjualan->delete();

            DB::commit();
            
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
        // Hapus transaksi kosong yang tidak memiliki detail
        $emptyTransactions = Penjualan::whereDoesntHave('detail')->get();
        foreach ($emptyTransactions as $transaction) {
            $transaction->delete();
        }
        
        // Hapus session jika transaksi yang ada di session sudah dihapus
        if (session('id_penjualan')) {
            $currentTransaction = Penjualan::find(session('id_penjualan'));
            if (!$currentTransaction) {
                session()->forget('id_penjualan');
            }
        }
        
        return response()->json(['message' => 'Empty transactions cleaned up'], 200);
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
}
