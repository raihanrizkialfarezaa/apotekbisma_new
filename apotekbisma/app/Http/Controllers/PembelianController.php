<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Supplier;
use App\Models\Setting;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Support\Facades\Log;

class PembelianController extends Controller
{
    public function index()
    {
        $supplier = Supplier::orderBy('nama')->get();

        return view('pembelian.index', compact('supplier'));
    }

    public function data()
    {
        $pembelian = Pembelian::with('supplier')->orderBy('id_pembelian', 'desc')->get();
        
        return datatables()
            ->of($pembelian)
            ->addIndexColumn()
            ->addColumn('total_item', function ($pembelian) {
                return format_uang($pembelian->total_item ?? 0);
            })
            ->addColumn('total_harga', function ($pembelian) {
                $totalHarga = 'Rp. '. format_uang($pembelian->total_harga ?? 0);
                
                // Transaksi sebelum hari ini dianggap selesai semua
                $today = date('Y-m-d');
                $transactionDate = date('Y-m-d', strtotime($pembelian->created_at));
                
                if ($transactionDate < $today) {
                    $totalHarga .= ' <span class="label label-success">Selesai</span>';
                } else {
                    // Untuk transaksi hari ini dan seterusnya, cek status sebenarnya
                    $hasDetail = \App\Models\PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->exists();
                    $hasIncompleteDetail = \App\Models\PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)
                                                                      ->where('jumlah', '<=', 0)
                                                                      ->exists();
                    
                    $isIncomplete = (!$hasDetail || 
                                    $hasIncompleteDetail || 
                                    $pembelian->total_harga == 0 || 
                                    $pembelian->bayar == 0 ||
                                    $pembelian->no_faktur == 'o' ||
                                    $pembelian->no_faktur == '' ||
                                    $pembelian->no_faktur == null);
                    
                    $isCompleted = ($hasDetail && 
                                   !$hasIncompleteDetail && 
                                   $pembelian->total_harga > 0 && 
                                   $pembelian->bayar > 0 &&
                                   $pembelian->no_faktur != 'o' &&
                                   $pembelian->no_faktur != '' &&
                                   $pembelian->no_faktur != null);
                    
                    if ($isIncomplete) {
                        $totalHarga .= ' <span class="label label-warning">Belum Selesai</span>';
                    } elseif ($isCompleted) {
                        $totalHarga .= ' <span class="label label-success">Selesai</span>';
                    }
                }
                
                return $totalHarga;
            })
            ->addColumn('bayar', function ($pembelian) {
                return 'Rp. '. format_uang($pembelian->bayar ?? 0);
            })
            ->addColumn('tanggal', function ($pembelian) {
                return tanggal_indonesia($pembelian->created_at, false);
            })
            ->addColumn('waktu', function ($pembelian) {
                return tanggal_indonesia(($pembelian->waktu != NULL ? $pembelian->waktu : $pembelian->created_at), false);
            })
            ->addColumn('supplier', function ($pembelian) {
                return $pembelian->supplier ? $pembelian->supplier->nama : 'N/A';
            })
            ->editColumn('diskon', function ($pembelian) {
                return ($pembelian->diskon ?? 0) . '%';
            })
            ->addColumn('aksi', function ($pembelian) {
                // Transaksi sebelum hari ini dianggap selesai semua
                $today = date('Y-m-d');
                $transactionDate = date('Y-m-d', strtotime($pembelian->created_at));
                
                if ($transactionDate < $today) {
                    // Transaksi lama - selalu tampilkan tombol edit
                    $buttons = '
                    <div class="btn-group">
                        <button onclick="showDetail(`'. route('pembelian.show', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-info btn-flat" title="Lihat Detail"><i class="fa fa-eye"></i></button>
                        <button onclick="editTransaksi('. $pembelian->id_pembelian .')" class="btn btn-xs btn-success btn-flat" title="Edit Transaksi" data-toggle="tooltip"><i class="fa fa-edit"></i></button>
                        <button onclick="printReceipt('. $pembelian->id_pembelian .')" class="btn btn-xs btn-primary btn-flat" title="Cetak Bukti" data-toggle="tooltip"><i class="fa fa-print"></i></button>
                        <button onclick="deleteData(`'. route('pembelian.destroy', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-danger btn-flat" title="Hapus Transaksi"><i class="fa fa-trash"></i></button>
                    </div>';
                    
                    return $buttons;
                }
                
                // Untuk transaksi hari ini dan seterusnya, cek status sebenarnya
                $hasDetail = \App\Models\PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->exists();
                $hasIncompleteDetail = \App\Models\PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)
                                                                  ->where('jumlah', '<=', 0)
                                                                  ->exists();
                
                $isIncomplete = (!$hasDetail || 
                                $hasIncompleteDetail || 
                                $pembelian->total_harga == 0 || 
                                $pembelian->bayar == 0 ||
                                $pembelian->no_faktur == 'o' ||
                                $pembelian->no_faktur == '' ||
                                $pembelian->no_faktur == null);
                
                $isCompleted = ($hasDetail && 
                               !$hasIncompleteDetail && 
                               $pembelian->total_harga > 0 && 
                               $pembelian->bayar > 0 &&
                               $pembelian->no_faktur != 'o' &&
                               $pembelian->no_faktur != '' &&
                               $pembelian->no_faktur != null);
                
                $buttons = '
                <div class="btn-group">
                    <button onclick="showDetail(`'. route('pembelian.show', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-info btn-flat" title="Lihat Detail"><i class="fa fa-eye"></i></button>';
                
                // Tambahkan button berdasarkan status transaksi
                if ($isIncomplete) {
                    $buttons .= '
                    <button onclick="lanjutkanTransaksi('. $pembelian->id_pembelian .')" class="btn btn-xs btn-warning btn-flat" title="Lanjutkan Transaksi" data-toggle="tooltip"><i class="fa fa-play"></i></button>';
                } elseif ($isCompleted) {
                    $buttons .= '
                    <button onclick="editTransaksi('. $pembelian->id_pembelian .')" class="btn btn-xs btn-success btn-flat" title="Edit Transaksi" data-toggle="tooltip"><i class="fa fa-edit"></i></button>';
                }
                
                // Tambahkan tombol print untuk semua transaksi yang memiliki detail dan sudah selesai
                if ($hasDetail && $isCompleted) {
                    $buttons .= '
                    <button onclick="printReceipt('. $pembelian->id_pembelian .')" class="btn btn-xs btn-primary btn-flat" title="Cetak Bukti" data-toggle="tooltip"><i class="fa fa-print"></i></button>';
                }
                
                $buttons .= '
                    <button onclick="deleteData(`'. route('pembelian.destroy', $pembelian->id_pembelian) .'`)" class="btn btn-xs btn-danger btn-flat" title="Hapus Transaksi"><i class="fa fa-trash"></i></button>
                </div>';
                
                return $buttons;
            })
            ->rawColumns(['aksi', 'total_harga'])
            ->make(true);
    }

    public function create($id = null)
    {
        // Jika ada ID, berarti ini untuk lanjutkan/edit transaksi
        if ($id && request('continue') === 'true') {
            $pembelian = Pembelian::find($id);
            if ($pembelian) {
                session(['id_pembelian' => $pembelian->id_pembelian]);
                session(['id_supplier' => $pembelian->id_supplier]);
                return redirect()->route('pembelian_detail.index');
            }
        }

        // Jika ini adalah transaksi baru (dipanggil dari pilih supplier)
        // Hapus session lama untuk memastikan transaksi baru yang bersih
        if ($id && is_numeric($id)) {
            // Clear any existing session data
            session()->forget(['id_pembelian', 'id_supplier']);
            
            // Cleanup transaksi incomplete yang mungkin tersisa dari session sebelumnya
            $this->cleanupIncompleteTransactions();
            
            // Hanya buat record baru jika supplier dipilih untuk transaksi baru
            $pembelian = new Pembelian();
            $pembelian->id_supplier = $id;
            $pembelian->total_item  = 0;
            $pembelian->total_harga = 0;
            $pembelian->diskon      = 0;
            $pembelian->bayar       = 0;
            $pembelian->waktu       = Carbon::now();
            $pembelian->no_faktur   = 'o'; // Temporary value to indicate incomplete transaction
            $pembelian->save();

            session(['id_pembelian' => $pembelian->id_pembelian]);
            session(['id_supplier' => $pembelian->id_supplier]);

            return redirect()->route('pembelian_detail.index');
        }

        // Redirect back jika tidak ada ID supplier
        return redirect()->route('pembelian.index')->with('error', 'Silakan pilih supplier terlebih dahulu.');
    }

    /**
     * Lanjutkan transaksi pembelian yang sudah ada
     */
    public function lanjutkanTransaksi($id)
    {
        $pembelian = Pembelian::find($id);
        if ($pembelian) {
            session(['id_pembelian' => $pembelian->id_pembelian]);
            session(['id_supplier' => $pembelian->id_supplier]);
            return redirect()->route('pembelian_detail.index');
        }
        
        return redirect()->route('pembelian.index')->with('error', 'Transaksi tidak ditemukan.');
    }

    public function store(Request $request)
    {
        // Validasi server-side
        $request->validate([
            'nomor_faktur' => 'required|string|max:255',
            'total_item' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required|date'
        ], [
            'nomor_faktur.required' => 'Nomor faktur harus diisi',
            'nomor_faktur.max' => 'Nomor faktur maksimal 255 karakter',
            'total_item.required' => 'Minimal harus ada 1 produk',
            'total_item.min' => 'Minimal harus ada 1 produk',
            'total.required' => 'Total harga harus diisi',
            'total.min' => 'Total harga tidak boleh negatif',
            'waktu.required' => 'Tanggal faktur harus diisi',
            'waktu.date' => 'Format tanggal tidak valid'
        ]);

        $pembelian = Pembelian::findOrFail($request->id_pembelian);
        
        // Cek apakah ada detail pembelian
        $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
        if ($detail->isEmpty()) {
            return redirect()->back()->with('error', 'Tidak dapat menyimpan transaksi tanpa produk');
        }

        // Cek apakah semua produk memiliki jumlah > 0
        $hasZeroQuantity = $detail->where('jumlah', '<=', 0)->count() > 0;
        if ($hasZeroQuantity) {
            return redirect()->back()->with('error', 'Semua produk harus memiliki jumlah lebih dari 0');
        }

        // Cek duplikasi nomor faktur
        $duplicateCheck = Pembelian::where('no_faktur', $request->nomor_faktur)
                                   ->where('id_pembelian', '!=', $pembelian->id_pembelian)
                                   ->exists();
        if ($duplicateCheck) {
            return redirect()->back()->with('error', 'Nomor faktur sudah digunakan untuk transaksi lain');
        }

        $pembelian->total_item = $request->total_item;
        $pembelian->total_harga = $request->total;
        $pembelian->diskon = $request->diskon ?? 0;
        $pembelian->bayar = $request->bayar;
        $pembelian->waktu = $request->waktu;
        $pembelian->no_faktur = $request->nomor_faktur;
        $pembelian->update();
        
        $id_pembelian = $request->id_pembelian;
        
        // Proses setiap item dalam pembelian
        foreach ($detail as $item) {
            $produk = Produk::find($item->id_produk);
            
            // TIDAK PERLU UPDATE STOK DI SINI
            // Stok sudah diupdate secara real-time di PembelianDetailController
            // Hanya pastikan rekaman stok sudah ada
            $existing_rekaman = RekamanStok::where('id_pembelian', $id_pembelian)
                                          ->where('id_produk', $item->id_produk)
                                          ->first();
            
            if (!$existing_rekaman) {
                // Jika belum ada rekaman stok, buat tanpa mengubah stok (stok sudah diupdate)
                RekamanStok::create([
                    'id_produk' => $item->id_produk,
                    'waktu' => Carbon::now(),
                    'stok_masuk' => $item->jumlah,
                    'id_pembelian' => $id_pembelian,
                    'stok_awal' => $produk->stok - $item->jumlah,
                    'stok_sisa' => $produk->stok,
                ]);
            }
        }
        
        // Hapus session setelah transaksi selesai
        session()->forget('id_pembelian');
        session()->forget('id_supplier');
        
        return redirect()->route('pembelian.index')->with('success', 'Transaksi pembelian berhasil disimpan');
    }
    public function update(Request $request, $id)
    {
        $request->validate([
            'nomor_faktur' => 'required|string|max:255',
            'total_item' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
            'waktu' => 'required|date'
        ], [
            'nomor_faktur.required' => 'Nomor faktur harus diisi',
            'nomor_faktur.max' => 'Nomor faktur maksimal 255 karakter',
            'total_item.required' => 'Minimal harus ada 1 produk',
            'total_item.min' => 'Minimal harus ada 1 produk',
            'total.required' => 'Total harga harus diisi',
            'total.min' => 'Total harga tidak boleh negatif',
            'waktu.required' => 'Tanggal faktur harus diisi',
            'waktu.date' => 'Format tanggal tidak valid'
        ]);

        DB::beginTransaction();
        
        try {
            $pembelian = Pembelian::findOrFail($request->id_pembelian);
            
            $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
            if ($detail->isEmpty()) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Tidak dapat menyimpan transaksi tanpa produk');
            }

            $hasZeroQuantity = $detail->where('jumlah', '<=', 0)->count() > 0;
            if ($hasZeroQuantity) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Semua produk harus memiliki jumlah lebih dari 0');
            }

            $duplicateCheck = Pembelian::where('no_faktur', $request->nomor_faktur)
                                       ->where('id_pembelian', '!=', $pembelian->id_pembelian)
                                       ->exists();
            if ($duplicateCheck) {
                DB::rollBack();
                return redirect()->back()->with('error', 'Nomor faktur sudah digunakan untuk transaksi lain');
            }

            $pembelian->total_item = $request->total_item;
            $pembelian->total_harga = $request->total;
            $pembelian->diskon = $request->diskon ?? 0;
            $pembelian->bayar = $request->bayar;
            $pembelian->no_faktur = $request->nomor_faktur;
            if ($request->waktu != NULL) {
                $pembelian->waktu = $request->waktu;
            }
            
            $pembelian->update();

            \App\Models\RekamanStok::where('id_pembelian', $pembelian->id_pembelian)
                ->update(['waktu' => $pembelian->waktu]);
            
            DB::commit();
            
            session()->forget('id_pembelian');
            session()->forget('id_supplier');
            
            return redirect()->route('pembelian.index')->with('success', 'Transaksi pembelian berhasil diperbarui');
            
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
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
            ->addColumn('harga_beli', function ($detail) {
                return 'Rp. '. format_uang($detail->harga_beli);
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
            $pembelian = Pembelian::find($id);
            
            if (!$pembelian) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Pembelian tidak ditemukan'], 404);
            }

            // Ambil detail pembelian untuk mengembalikan stok
            $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->get();
            
            // Proses pengembalian stok untuk setiap item
            foreach ($detail as $item) {
                $produk = Produk::find($item->id_produk);
                if ($produk) {
                    // Catat stok sebelum perubahan
                    $stokSebelum = $produk->stok;
                    
                    // Kurangi stok sesuai jumlah yang pernah ditambahkan
                    $stokBaru = $stokSebelum - $item->jumlah;
                    
                    // Update stok produk (biarkan negatif untuk audit)
                    $produk->stok = $stokBaru;
                    $produk->save();
                    
                    // Buat rekaman audit untuk pengurangan stok
                    RekamanStok::create([
                        'id_produk' => $item->id_produk,
                        'waktu' => now(),
                        'stok_keluar' => $item->jumlah,
                        'stok_awal' => $stokSebelum,
                        'stok_sisa' => $stokBaru,
                        'keterangan' => 'Penghapusan transaksi pembelian: Pengurangan stok'
                    ]);
                }
                
                // Hapus detail pembelian
                $item->delete();
            }

            // Hapus semua rekaman stok yang terkait dengan pembelian ini
            RekamanStok::where('id_pembelian', $pembelian->id_pembelian)->delete();

            // Hapus pembelian
            $pembelian->delete();

            DB::commit();
            
            return response()->json(['success' => true, 'message' => 'Pembelian berhasil dihapus dan stok disesuaikan'], 200);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function cleanupIncompleteTransactions()
    {
        // Membersihkan transaksi yang tidak lengkap (tanpa detail atau no_faktur kosong)
        $incompleteTransactions = Pembelian::where(function ($query) {
            $query->where('no_faktur', '=', 'o')
                  ->orWhere('no_faktur', '=', '')
                  ->orWhereNull('no_faktur');
        })->where('created_at', '<', Carbon::now()->subMinutes(30)) // Hanya hapus yang sudah 30 menit atau lebih
        ->get();

        foreach ($incompleteTransactions as $transaction) {
            $details = PembelianDetail::where('id_pembelian', $transaction->id_pembelian)->get();
            
            // Hapus rekaman stok jika ada
            foreach ($details as $detail) {
                $rekaman_stok = RekamanStok::where('id_pembelian', $transaction->id_pembelian)
                                           ->where('id_produk', $detail->id_produk)
                                           ->first();
                if ($rekaman_stok) {
                    $produk = Produk::find($detail->id_produk);
                    if ($produk) {
                        $produk->stok -= $rekaman_stok->stok_masuk;
                        $produk->update();
                    }
                    $rekaman_stok->delete();
                }
                $detail->delete();
            }
            
            $transaction->delete();
        }

        return response()->json(['message' => 'Cleanup completed', 'deleted' => $incompleteTransactions->count()]);
    }

    public function destroyEmpty($id)
    {
        $pembelian = Pembelian::where('id_pembelian', $id)->first();
        
        if (!$pembelian) {
            return response()->json(['error' => 'Pembelian tidak ditemukan'], 404);
        }

        // Hanya hapus jika transaksi benar-benar kosong atau belum selesai
        $pembelian_detail = PembelianDetail::where('id_pembelian', $id)->get();
        $isEmpty = ($pembelian->no_faktur === 'o' || $pembelian->no_faktur === '' || $pembelian->no_faktur === null) &&
                   $pembelian->total_harga == 0;
        
        if ($isEmpty) {
            // Hapus detail dan rekaman stok jika ada
            foreach ($pembelian_detail as $detail) {
                $rekaman_stok = RekamanStok::where('id_pembelian', $id)
                                           ->where('id_produk', $detail->id_produk)
                                           ->first();
                if ($rekaman_stok) {
                    $produk = Produk::find($detail->id_produk);
                    if ($produk) {
                        $produk->stok -= $rekaman_stok->stok_masuk;
                        $produk->update();
                    }
                    $rekaman_stok->delete();
                }
                $detail->delete();
            }
            
            $pembelian->delete();
            
            // Hapus session terkait
            session()->forget('id_pembelian');
            session()->forget('id_supplier');
            
            return response()->json(['message' => 'Empty transaction deleted']);
        }

        return response()->json(['message' => 'Transaction not empty, not deleted']);
    }

    public function notaKecil()
    {
        $setting = Setting::first();
        $pembelian = Pembelian::find(session('id_pembelian'));
        if (! $pembelian) {
            abort(404);
        }
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', session('id_pembelian'))
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();
        
        return view('pembelian.nota_kecil', compact('setting', 'pembelian', 'detail'));
    }

    public function notaBesar()
    {
        $setting = Setting::first();
        $pembelian = Pembelian::find(session('id_pembelian'));
        if (! $pembelian) {
            abort(404);
        }
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', session('id_pembelian'))
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();

        $pdf = PDF::loadView('pembelian.nota_besar', compact('setting', 'pembelian', 'detail'));
        $pdf->setPaper('a4', 'portrait');
        return $pdf->stream('Bukti-Pembelian-'. date('Y-m-d-his') .'.pdf');
    }

    public function printReceipt($id)
    {
        $setting = Setting::first();
        $pembelian = Pembelian::with('supplier')->find($id);
        if (! $pembelian) {
            abort(404);
        }
        $detail = PembelianDetail::with('produk')
            ->where('id_pembelian', $id)
            ->join('produk', 'pembelian_detail.id_produk', '=', 'produk.id_produk')
            ->orderBy('produk.nama_produk', 'asc')
            ->select('pembelian_detail.*')
            ->get();

        // Cek tipe nota dari setting
        if ($setting->tipe_nota == 1) {
            // Nota Kecil
            return view('pembelian.nota_kecil', compact('setting', 'pembelian', 'detail'));
        } else {
            // Nota Besar (PDF)
            $pdf = PDF::loadView('pembelian.nota_besar', compact('setting', 'pembelian', 'detail'));
            $pdf->setPaper('a4', 'portrait');
            return $pdf->stream('Bukti-Pembelian-'. $pembelian->id_pembelian .'-'. date('Y-m-d-His') .'.pdf');
        }
    }
}

