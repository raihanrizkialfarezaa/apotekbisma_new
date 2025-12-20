<?php

namespace App\Http\Controllers;

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Models\PembelianDetail;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class KartuStokController extends Controller
{
    public function index()
    {
        $produk = Produk::with('kategori')->orderBy('nama_produk', 'asc')->get();
        return view('kartu_stok.index', compact('produk'));
    }

    public function detail($id)
    {
        $produk = Produk::where('id_produk', $id)->first();
        
        if (!$produk) {
            return redirect()->route('kartu_stok.index')
                           ->with('error', 'Produk tidak ditemukan');
        }
        
        $nama_barang = $produk->nama_produk;
        $produk_id = $id;
        
        // Data untuk grafik dan ringkasan
        $stok_data = $this->getStockData($id);
        
        return view('kartu_stok.detail', compact('produk_id', 'nama_barang', 'produk', 'stok_data'));
    }
    
    public function getData($id)
    {
        $produk = Produk::find($id);
        if (!$produk) {
            return [];
        }

        $no = 1;
        $data = array();
        
    // Get all stock records for this product, ordered by date for chronological display
    // This ensures audit-friendly display with dates in proper order
    $stok = RekamanStok::with(['produk', 'pembelian.supplier', 'penjualan'])
               ->where('id_produk', $id)
               ->orderBy('waktu', 'asc')
               ->orderBy('created_at', 'asc')
               ->orderBy('id_rekaman_stok', 'asc')
               ->get();

        foreach ($stok as $item) {
            $row = array();
            $row['DT_RowIndex'] = $no++;
            
            // Use transaction time from Penjualan or Pembelian when available
            // to preserve original transaction dates in display
            $tanggal_source = $item->waktu;
            if (!empty($item->id_penjualan)) {
                $penjualan = $item->penjualan ?? Penjualan::find($item->id_penjualan);
                if ($penjualan && $penjualan->waktu) {
                    $tanggal_source = $penjualan->waktu;
                }
            } elseif (!empty($item->id_pembelian)) {
                $pembelian = $item->pembelian ?? Pembelian::find($item->id_pembelian);
                if ($pembelian && $pembelian->waktu) {
                    $tanggal_source = $pembelian->waktu;
                }
            }

            $row['tanggal'] = tanggal_indonesia($tanggal_source, false);
            
            // Format stock movements
            $row['stok_masuk'] = ($item->stok_masuk != NULL && $item->stok_masuk > 0) 
                               ? format_uang($item->stok_masuk) 
                               : '-';
            
            $row['stok_keluar'] = ($item->stok_keluar != NULL && $item->stok_keluar > 0) 
                                ? format_uang($item->stok_keluar) 
                                : '-';
            
            $row['stok_awal'] = $item->stok_awal < 0 
                              ? '<span class="text-danger" title="Kondisi oversold - stok tidak mencukupi pada saat transaksi">' . format_uang($item->stok_awal) . '</span>' 
                              : format_uang($item->stok_awal);
            $row['stok_sisa'] = format_uang($item->stok_sisa);
            // Ensure these keys always exist for DataTables columns (defaults)
            $row['expired_date'] = '';
            $row['supplier'] = '';
            
            // Determine transaction type and add reference with detailed information
            $keterangan = '';
            
            // Cek apakah ada keterangan dari database terlebih dahulu
            if (!empty($item->keterangan)) {
                $keterangan = $item->keterangan;
                
                // Tambahkan referensi transaksi jika ada
                if ($item->id_pembelian) {
                    $pembelian = Pembelian::find($item->id_pembelian);
                    if ($pembelian && $pembelian->no_faktur && $pembelian->no_faktur != 'o') {
                        $keterangan .= ' (Faktur: ' . $pembelian->no_faktur . ')';
                    }
                } elseif ($item->id_penjualan) {
                    $penjualan = Penjualan::find($item->id_penjualan);
                    if ($penjualan) {
                        $keterangan .= ' (ID Transaksi: ' . $penjualan->id_penjualan . ')';
                    }
                }
            } else {
                // Fallback untuk data lama yang belum ada keterangan
                if ($item->stok_masuk > 0) {
                    $keterangan = 'Pembelian';
                    if ($item->id_pembelian) {
                        $pembelian = Pembelian::find($item->id_pembelian);
                        if ($pembelian && $pembelian->no_faktur && $pembelian->no_faktur != 'o') {
                            $keterangan .= ' - Faktur: ' . $pembelian->no_faktur;
                        }
                    }
                } elseif ($item->stok_keluar > 0) {
                    $keterangan = 'Penjualan';
                    if ($item->id_penjualan) {
                        $penjualan = Penjualan::find($item->id_penjualan);
                        if ($penjualan) {
                            $keterangan .= ' - ID: ' . $penjualan->id_penjualan;
                        }
                    }
                } else {
                    $keterangan = 'Penyesuaian Stok';
                }
            }
            
            $row['keterangan'] = $keterangan;
            // Populate expired_date and supplier when this record is linked to a pembelian
            if (!empty($item->id_pembelian)) {
                try {
                    // use eager-loaded pembelian when available
                    $pembelian = $item->pembelian ?? Pembelian::find($item->id_pembelian);
                    if ($pembelian) {
                        $row['supplier'] = optional($pembelian->supplier)->nama ?? '';
                        // Try to find pembelian_detail for this product to get expired_date
                        $pd = \App\Models\PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)
                                              ->where('id_produk', $item->id_produk)
                                              ->first();
                        if ($pd && !empty($pd->expired_date)) {
                            // normalize to Y-m-d for consistent client parsing
                            try {
                                $row['expired_date'] = \Carbon\Carbon::parse($pd->expired_date)->toDateString();
                            } catch (\Exception $e) {
                                $row['expired_date'] = (string) $pd->expired_date;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Do not break the response if lookup fails
                    $row['expired_date'] = '';
                    $row['supplier'] = '';
                }
            }
            $data[] = $row;
        }

        // Add current stock summary as last row
        if (!empty($data)) {
            $data[] = [
                'DT_RowIndex' => '',
                'tanggal' => '<strong>STOK SAAT INI</strong>',
                'stok_masuk' => '',
                'stok_keluar' => '',
                'stok_awal' => '',
                'stok_sisa' => '<strong>' . format_uang($produk->stok) . '</strong>',
                // keep keys present even for summary row
                'expired_date' => '',
                'supplier' => '',
                'keterangan' => '<strong>Stok Aktual</strong>',
            ];
        }

        return $data;
    }

    public function getStockData($id)
    {
        $produk = Produk::find($id);
        if (!$produk) {
            return [
                'chart_data' => [],
                'summary' => []
            ];
        }

        // Data untuk grafik (30 hari terakhir)
        $chart_data = [];
        $summary = [
            'total_masuk' => 0,
            'total_keluar' => 0,
            'total_transaksi' => 0,
            'periode_minggu' => [
                'masuk' => 0,
                'keluar' => 0
            ],
            'periode_bulan' => [
                'masuk' => 0,
                'keluar' => 0
            ],
            'periode_tahun' => [
                'masuk' => 0,
                'keluar' => 0
            ]
        ];

        // Ambil data 30 hari terakhir untuk grafik
        $stok_records = RekamanStok::where('id_produk', $id)
                                  ->where('waktu', '>=', Carbon::now()->subDays(30))
                                  ->orderBy('waktu', 'asc')
                                  ->get();

        // Generate data untuk chart
        foreach ($stok_records as $record) {
            $date = date('Y-m-d', strtotime($record->waktu));
            if (!isset($chart_data[$date])) {
                $chart_data[$date] = [
                    'masuk' => 0,
                    'keluar' => 0,
                    'sisa' => $record->stok_sisa
                ];
            }
            $chart_data[$date]['masuk'] += $record->stok_masuk ?? 0;
            $chart_data[$date]['keluar'] += $record->stok_keluar ?? 0;
            $chart_data[$date]['sisa'] = $record->stok_sisa ?? 0;
        }

        // Summary data untuk periode berbeda
        $now = Carbon::now();
        
        // Total keseluruhan
        $all_records = RekamanStok::where('id_produk', $id)->get();
        $summary['total_masuk'] = $all_records->sum('stok_masuk');
        $summary['total_keluar'] = $all_records->sum('stok_keluar');
        $summary['total_transaksi'] = $all_records->count();

        // Minggu ini
        $week_records = RekamanStok::where('id_produk', $id)
                                  ->where('waktu', '>=', $now->copy()->startOfWeek())
                                  ->get();
        $summary['periode_minggu']['masuk'] = $week_records->sum('stok_masuk');
        $summary['periode_minggu']['keluar'] = $week_records->sum('stok_keluar');

        // Bulan ini
        $month_records = RekamanStok::where('id_produk', $id)
                                   ->where('waktu', '>=', $now->copy()->startOfMonth())
                                   ->get();
        $summary['periode_bulan']['masuk'] = $month_records->sum('stok_masuk');
        $summary['periode_bulan']['keluar'] = $month_records->sum('stok_keluar');

        // Tahun ini
        $year_records = RekamanStok::where('id_produk', $id)
                                  ->where('waktu', '>=', $now->copy()->startOfYear())
                                  ->get();
        $summary['periode_tahun']['masuk'] = $year_records->sum('stok_masuk');
        $summary['periode_tahun']['keluar'] = $year_records->sum('stok_keluar');

        return [
            'chart_data' => $chart_data,
            'summary' => $summary
        ];
    }

    public function data($id, Request $request)
    {
        $data = $this->getDataFiltered($id, $request);

        return datatables()
            ->of($data)
            ->rawColumns(['tanggal', 'stok_sisa', 'keterangan'])
            ->make(true);
    }

    public function getDataFiltered($id, Request $request)
    {
        $produk = Produk::find($id);
        if (!$produk) {
            return [];
        }

        $no = 1;
        $data = array();
        
    // Build query with date filters
    // Eager-load related models to avoid N+1 and ensure relations available for lookups
    $query = RekamanStok::with(['produk', 'pembelian.supplier', 'penjualan'])
                  ->where('id_produk', $id);

        // Get all records first, then filter based on effective transaction date
        // This ensures filter matches the displayed date (from penjualan/pembelian.waktu)
        $stok = $query->orderBy('rekaman_stoks.waktu', 'asc')
                     ->orderBy('id_rekaman_stok', 'asc')
                     ->get();

        // Apply date filter based on effective transaction date (display date)
        if ($request->has('date_filter') && $request->date_filter && $request->date_filter != 'all') {
            $filter = $request->date_filter;
            $now = Carbon::now();
            
            $stok = $stok->filter(function($item) use ($filter, $request, $now) {
                // Get the effective date (same logic as display)
                $effectiveDate = $item->waktu;
                if (!empty($item->id_penjualan) && $item->penjualan && $item->penjualan->waktu) {
                    $effectiveDate = $item->penjualan->waktu;
                } elseif (!empty($item->id_pembelian) && $item->pembelian && $item->pembelian->waktu) {
                    $effectiveDate = $item->pembelian->waktu;
                }
                
                $effectiveDate = Carbon::parse($effectiveDate);
                
                switch ($filter) {
                    case 'today':
                        return $effectiveDate->isSameDay($now);
                    case 'week':
                        return $effectiveDate->between(
                            $now->copy()->startOfWeek(),
                            $now->copy()->endOfWeek()
                        );
                    case 'month':
                        return $effectiveDate->month === $now->month && $effectiveDate->year === $now->year;
                    case 'year':
                        return $effectiveDate->year === $now->year;
                    case 'custom':
                        if ($request->start_date && $request->end_date) {
                            $startDate = Carbon::parse($request->start_date)->startOfDay();
                            $endDate = Carbon::parse($request->end_date)->endOfDay();
                            return $effectiveDate->between($startDate, $endDate);
                        }
                        return true;
                    default:
                        return true;
                }
            })->values();
        }

        foreach ($stok as $item) {
            $row = array();
            $row['DT_RowIndex'] = $no++;
            
            // Use transaction time from Penjualan or Pembelian when available
            // to preserve original transaction dates in display
            $tanggal_source = $item->waktu;
            if (!empty($item->id_penjualan)) {
                $penjualan = $item->penjualan ?? Penjualan::find($item->id_penjualan);
                if ($penjualan && $penjualan->waktu) {
                    $tanggal_source = $penjualan->waktu;
                }
            } elseif (!empty($item->id_pembelian)) {
                $pembelian = $item->pembelian ?? Pembelian::find($item->id_pembelian);
                if ($pembelian && $pembelian->waktu) {
                    $tanggal_source = $pembelian->waktu;
                }
            }

            $row['tanggal'] = tanggal_indonesia($tanggal_source, false);
            
            // Format stock movements
            $row['stok_masuk'] = ($item->stok_masuk != NULL && $item->stok_masuk > 0) 
                               ? format_uang($item->stok_masuk) 
                               : '-';
            
            $row['stok_keluar'] = ($item->stok_keluar != NULL && $item->stok_keluar > 0) 
                                ? format_uang($item->stok_keluar) 
                                : '-';
            
            $row['stok_awal'] = $item->stok_awal < 0 
                              ? '<span class="text-danger" title="Kondisi oversold - stok tidak mencukupi pada saat transaksi">' . format_uang($item->stok_awal) . '</span>' 
                              : format_uang($item->stok_awal);
            $row['stok_sisa'] = format_uang($item->stok_sisa);
            // ensure new fields exist for every row
            $row['expired_date'] = '';
            $row['supplier'] = '';
            
            // Determine transaction type and add reference with styling
            $keterangan = '';
            
            // Cek apakah ada keterangan dari database terlebih dahulu
            if (!empty($item->keterangan)) {
                // Parse jenis transaksi dari keterangan untuk styling
                if (strpos($item->keterangan, 'Pembelian') !== false) {
                    $keterangan = '<span class="label label-success"><i class="fa fa-arrow-up"></i> ' . $item->keterangan . '</span>';
                    
                    // Tambahkan referensi faktur jika ada
                    if ($item->id_pembelian) {
                        $pembelian = Pembelian::find($item->id_pembelian);
                        if ($pembelian && $pembelian->no_faktur && $pembelian->no_faktur != 'o') {
                            $keterangan .= '<br><small class="text-muted">Faktur: ' . $pembelian->no_faktur . '</small>';
                        }
                    }
                    
                } elseif (strpos($item->keterangan, 'Penjualan') !== false) {
                    $keterangan = '<span class="label label-warning"><i class="fa fa-arrow-down"></i> ' . $item->keterangan . '</span>';
                    
                    // Tambahkan referensi ID transaksi jika ada
                    if ($item->id_penjualan) {
                        $penjualan = Penjualan::find($item->id_penjualan);
                        if ($penjualan) {
                            $keterangan .= '<br><small class="text-muted">ID Transaksi: ' . $penjualan->id_penjualan . '</small>';
                        }
                    }
                    
                } elseif (strpos($item->keterangan, 'Perubahan Stok Manual') !== false) {
                    $keterangan = '<span class="label label-info"><i class="fa fa-edit"></i> ' . $item->keterangan . '</span>';
                    
                } else {
                    // Keterangan lainnya
                    $keterangan = '<span class="label label-default"><i class="fa fa-cog"></i> ' . $item->keterangan . '</span>';
                }
            } else {
                // Fallback untuk data lama yang belum ada keterangan
                if ($item->stok_masuk > 0) {
                    $keterangan = '<span class="label label-success"><i class="fa fa-arrow-up"></i> Pembelian</span>';
                    if ($item->id_pembelian) {
                        $pembelian = Pembelian::find($item->id_pembelian);
                        if ($pembelian && $pembelian->no_faktur && $pembelian->no_faktur != 'o') {
                            $keterangan .= '<br><small class="text-muted">Faktur: ' . $pembelian->no_faktur . '</small>';
                        }
                    }
                } elseif ($item->stok_keluar > 0) {
                    $keterangan = '<span class="label label-warning"><i class="fa fa-arrow-down"></i> Penjualan</span>';
                    if ($item->id_penjualan) {
                        $penjualan = Penjualan::find($item->id_penjualan);
                        if ($penjualan) {
                            $keterangan .= '<br><small class="text-muted">ID Transaksi: ' . $penjualan->id_penjualan . '</small>';
                        }
                    }
                } else {
                    $keterangan = '<span class="label label-info"><i class="fa fa-cog"></i> Penyesuaian Stok</span>';
                }
            }
            
            $row['keterangan'] = $keterangan;
            // Populate supplier and expired_date when possible
            if (!empty($item->id_pembelian)) {
                try {
                    // prefer eager-loaded pembelian
                    $pembelian = $item->pembelian ?? Pembelian::find($item->id_pembelian);
                    if ($pembelian) {
                        $row['supplier'] = optional($pembelian->supplier)->nama ?? '';
                        // Prefer expired_date from pembelian_detail if available, otherwise use product-level expired_date
                        $pd = \App\Models\PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)
                                              ->where('id_produk', $item->id_produk)
                                              ->first();
                        if ($pd && !empty($pd->expired_date)) {
                            try {
                                $row['expired_date'] = \Carbon\Carbon::parse($pd->expired_date)->toDateString();
                            } catch (\Exception $e) {
                                $row['expired_date'] = (string) $pd->expired_date;
                            }
                        } elseif (!empty($item->id_produk) && $item->produk && !empty($item->produk->expired_date)) {
                            try {
                                $row['expired_date'] = \Carbon\Carbon::parse($item->produk->expired_date)->toDateString();
                            } catch (\Exception $e) {
                                $row['expired_date'] = (string) $item->produk->expired_date;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // keep defaults if lookup fails
                }
            } else {
                // If not tied to pembelian, still try product-level expired_date
                if (!empty($item->id_produk) && $item->produk && !empty($item->produk->expired_date)) {
                    try {
                        $row['expired_date'] = \Carbon\Carbon::parse($item->produk->expired_date)->toDateString();
                    } catch (\Exception $e) {
                        $row['expired_date'] = (string) $item->produk->expired_date;
                    }
                }
            }
            $data[] = $row;
        }

        // Add current stock summary as last row if not filtered
        if (!$request->has('date_filter') || !$request->date_filter) {
            if (!empty($data)) {
                $data[] = [
                    'DT_RowIndex' => '',
                    'tanggal' => '<strong class="text-primary">STOK SAAT INI</strong>',
                    'stok_masuk' => '',
                    'stok_keluar' => '',
                    'stok_awal' => '',
                    'stok_sisa' => '<strong class="text-primary">' . format_uang($produk->stok) . ' unit</strong>',
                    'expired_date' => '',
                    'supplier' => '',
                    'keterangan' => '<strong class="text-primary">Stok Aktual Saat Ini</strong>',
                ];
            }
        }

        return $data;
    }

    public function exportPDF($id)
    {
        $produk = Produk::with('kategori')->find($id);
        
        if (!$produk) {
            return redirect()->route('kartu_stok.index')
                           ->with('error', 'Produk tidak ditemukan');
        }
        
        $data = $this->getData($id);
        $nama_obat = $produk->nama_produk;
        $satuan = $produk->kategori ? $produk->kategori->nama_kategori : 'N/A';
        $kode_produk = $produk->kode_produk;
        
        $pdf = PDF::loadView('kartu_stok.pdf', compact('data', 'nama_obat', 'satuan', 'kode_produk', 'produk'));
        $pdf->setPaper('a4', 'portrait');
        
        return $pdf->stream('Kartu-Stok-' . $nama_obat . '-' . date('Y-m-d-His') . '.pdf');
    }
    
    /**
     * Rebuild all stock records - ROBUST VERSION
     * Compatible with hosting environments
     * Returns JSON response for AJAX calls
     */
    public function fixRecords(Request $request)
    {
        // Set execution time and memory for large datasets
        set_time_limit(600); // 10 minutes
        ini_set('memory_limit', '512M');
        
        $startTime = microtime(true);
        
        try {
            // Disable query log to save memory
            DB::connection()->disableQueryLog();
            
            $result = [
                'success' => true,
                'steps' => [],
                'stats' => []
            ];
            
            $result['steps'][] = 'Mengumpulkan data transaksi...';
            
            $allTransactions = [];
            
            // Get all penjualan data with chunking for large datasets
            $totalPenjualan = 0;
            DB::table('penjualan_detail')
                ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                ->select(
                    'penjualan_detail.id_penjualan_detail',
                    'penjualan_detail.id_penjualan',
                    'penjualan_detail.id_produk',
                    'penjualan.waktu',
                    'penjualan_detail.jumlah as qty'
                )
                ->orderBy('penjualan.waktu', 'asc')
                ->orderBy('penjualan_detail.id_penjualan', 'asc')
                ->orderBy('penjualan_detail.id_penjualan_detail', 'asc')
                ->chunk(1000, function ($records) use (&$allTransactions, &$totalPenjualan) {
                    foreach ($records as $p) {
                        $key = $p->id_produk;
                        if (!isset($allTransactions[$key])) {
                            $allTransactions[$key] = [];
                        }
                        $allTransactions[$key][] = [
                            'detail_id' => 'P' . $p->id_penjualan_detail,
                            'waktu' => $p->waktu,
                            'id_penjualan' => $p->id_penjualan,
                            'id_pembelian' => null,
                            'stok_masuk' => 0,
                            'stok_keluar' => $p->qty,
                            'keterangan' => 'Penjualan - ID: ' . $p->id_penjualan,
                            'tipe' => 'penjualan',
                            'sort_order' => 1
                        ];
                        $totalPenjualan++;
                    }
                });
            
            $result['stats']['penjualan_records'] = $totalPenjualan;
            
            // Get all pembelian data with chunking
            $totalPembelian = 0;
            DB::table('pembelian_detail')
                ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                ->select(
                    'pembelian_detail.id_pembelian_detail',
                    'pembelian_detail.id_pembelian',
                    'pembelian_detail.id_produk',
                    'pembelian.waktu',
                    'pembelian_detail.jumlah as qty',
                    'pembelian.no_faktur'
                )
                ->orderBy('pembelian.waktu', 'asc')
                ->orderBy('pembelian_detail.id_pembelian', 'asc')
                ->orderBy('pembelian_detail.id_pembelian_detail', 'asc')
                ->chunk(1000, function ($records) use (&$allTransactions, &$totalPembelian) {
                    foreach ($records as $b) {
                        $key = $b->id_produk;
                        if (!isset($allTransactions[$key])) {
                            $allTransactions[$key] = [];
                        }
                        $allTransactions[$key][] = [
                            'detail_id' => 'B' . $b->id_pembelian_detail,
                            'waktu' => $b->waktu,
                            'id_penjualan' => null,
                            'id_pembelian' => $b->id_pembelian,
                            'stok_masuk' => $b->qty,
                            'stok_keluar' => 0,
                            'keterangan' => 'Pembelian - Faktur: ' . ($b->no_faktur ?: $b->id_pembelian),
                            'tipe' => 'pembelian',
                            'sort_order' => 0
                        ];
                        $totalPembelian++;
                    }
                });
            
            $result['stats']['pembelian_records'] = $totalPembelian;
            
            $result['steps'][] = 'Membersihkan data lama...';
            
            // Use delete in chunks instead of truncate (more compatible with some hosts)
            DB::table('rekaman_stoks')->delete();
            
            $result['steps'][] = 'Membangun ulang kartu stok...';
            
            $totalProducts = count($allTransactions);
            $totalRecordsCreated = 0;
            $processedProducts = 0;
            
            foreach ($allTransactions as $produkId => $transactions) {
                // Sort transactions chronologically
                usort($transactions, function($a, $b) {
                    $cmp = strcmp($a['waktu'], $b['waktu']);
                    if ($cmp !== 0) return $cmp;
                    $cmp = $a['sort_order'] - $b['sort_order'];
                    if ($cmp !== 0) return $cmp;
                    return strcmp($a['detail_id'], $b['detail_id']);
                });
                
                // Calculate minimum stock to determine initial stock
                $simStock = 0;
                $minStock = 0;
                
                foreach ($transactions as $t) {
                    $simStock = $simStock + $t['stok_masuk'] - $t['stok_keluar'];
                    if ($simStock < $minStock) {
                        $minStock = $simStock;
                    }
                }
                
                $initialStock = ($minStock < 0) ? abs($minStock) : 0;
                
                $runningStock = $initialStock;
                $insertBatch = [];
                $now = now();
                
                foreach ($transactions as $t) {
                    $stokAwal = $runningStock;
                    $stokSisa = $runningStock + $t['stok_masuk'] - $t['stok_keluar'];
                    
                    $insertBatch[] = [
                        'id_produk' => $produkId,
                        'id_penjualan' => $t['id_penjualan'],
                        'id_pembelian' => $t['id_pembelian'],
                        'stok_awal' => $stokAwal,
                        'stok_masuk' => $t['stok_masuk'],
                        'stok_keluar' => $t['stok_keluar'],
                        'stok_sisa' => $stokSisa,
                        'waktu' => $t['waktu'],
                        'keterangan' => $t['keterangan'],
                        'created_at' => $now,
                        'updated_at' => $now
                    ];
                    
                    $runningStock = $stokSisa;
                }
                
                // Insert in batches of 500 records
                if (!empty($insertBatch)) {
                    foreach (array_chunk($insertBatch, 500) as $chunk) {
                        DB::table('rekaman_stoks')->insert($chunk);
                    }
                    $totalRecordsCreated += count($insertBatch);
                }
                
                // Update product stock
                DB::table('produk')
                    ->where('id_produk', $produkId)
                    ->update(['stok' => $runningStock]);
                
                $processedProducts++;
                
                // Free memory periodically
                if ($processedProducts % 100 === 0) {
                    gc_collect_cycles();
                }
            }
            
            $result['steps'][] = 'Memperbarui produk tanpa transaksi...';
            
            // Update products without transactions
            $productsWithoutTrans = DB::table('produk')
                ->whereNotIn('id_produk', array_keys($allTransactions))
                ->count();
            
            DB::table('produk')
                ->whereNotIn('id_produk', array_keys($allTransactions))
                ->update(['stok' => 0]);
            
            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);
            
            $result['stats']['total_products'] = $totalProducts;
            $result['stats']['total_records_created'] = $totalRecordsCreated;
            $result['stats']['products_without_transactions'] = $productsWithoutTrans;
            $result['stats']['execution_time'] = $executionTime . ' detik';
            $result['steps'][] = 'Selesai!';
            $result['message'] = "Berhasil memperbaiki kartu stok untuk {$totalProducts} produk dengan {$totalRecordsCreated} record dalam {$executionTime} detik";
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ], 500);
        }
    }
    
    /**
     * Rebuild stock records for a specific product - ROBUST VERSION
     */
    public function fixRecordsForProduct($id)
    {
        set_time_limit(120); // 2 minutes for single product
        
        $startTime = microtime(true);
        
        try {
            $produk = Produk::find($id);
            if (!$produk) {
                return response()->json([
                    'success' => false,
                    'error' => 'Produk tidak ditemukan'
                ], 404);
            }
            
            $result = [
                'success' => true,
                'steps' => [],
                'stats' => []
            ];
            
            $result['steps'][] = 'Mengumpulkan data transaksi untuk produk ' . $produk->nama_produk . '...';
            
            $transactions = [];
            
            // Get penjualan data for this product
            $penjualanData = DB::table('penjualan_detail')
                ->join('penjualan', 'penjualan_detail.id_penjualan', '=', 'penjualan.id_penjualan')
                ->where('penjualan_detail.id_produk', $id)
                ->select('penjualan_detail.*', 'penjualan.waktu')
                ->orderBy('penjualan.waktu', 'asc')
                ->get();
            
            foreach ($penjualanData as $p) {
                $transactions[] = [
                    'waktu' => $p->waktu,
                    'id_penjualan' => $p->id_penjualan,
                    'id_pembelian' => null,
                    'stok_masuk' => 0,
                    'stok_keluar' => $p->jumlah,
                    'keterangan' => 'Penjualan - ID: ' . $p->id_penjualan,
                    'sort_order' => 1
                ];
            }
            
            // Get pembelian data for this product
            $pembelianData = DB::table('pembelian_detail')
                ->join('pembelian', 'pembelian_detail.id_pembelian', '=', 'pembelian.id_pembelian')
                ->where('pembelian_detail.id_produk', $id)
                ->select('pembelian_detail.*', 'pembelian.waktu', 'pembelian.no_faktur')
                ->orderBy('pembelian.waktu', 'asc')
                ->get();
            
            foreach ($pembelianData as $b) {
                $transactions[] = [
                    'waktu' => $b->waktu,
                    'id_penjualan' => null,
                    'id_pembelian' => $b->id_pembelian,
                    'stok_masuk' => $b->jumlah,
                    'stok_keluar' => 0,
                    'keterangan' => 'Pembelian - Faktur: ' . ($b->no_faktur ?: $b->id_pembelian),
                    'sort_order' => 0
                ];
            }
            
            $result['stats']['penjualan_records'] = count($penjualanData);
            $result['stats']['pembelian_records'] = count($pembelianData);
            
            // Sort transactions chronologically
            usort($transactions, function($a, $b) {
                $cmp = strcmp($a['waktu'], $b['waktu']);
                if ($cmp !== 0) return $cmp;
                return $a['sort_order'] - $b['sort_order'];
            });
            
            // Delete old records for this product
            $result['steps'][] = 'Membersihkan data lama...';
            DB::table('rekaman_stoks')->where('id_produk', $id)->delete();
            
            // Calculate initial stock
            $simStock = 0;
            $minStock = 0;
            foreach ($transactions as $t) {
                $simStock = $simStock + $t['stok_masuk'] - $t['stok_keluar'];
                if ($simStock < $minStock) {
                    $minStock = $simStock;
                }
            }
            $initialStock = ($minStock < 0) ? abs($minStock) : 0;
            
            // Rebuild stock records
            $result['steps'][] = 'Membangun ulang kartu stok...';
            $runningStock = $initialStock;
            $now = now();
            $insertBatch = [];
            
            foreach ($transactions as $t) {
                $stokAwal = $runningStock;
                $stokSisa = $runningStock + $t['stok_masuk'] - $t['stok_keluar'];
                
                $insertBatch[] = [
                    'id_produk' => $id,
                    'id_penjualan' => $t['id_penjualan'],
                    'id_pembelian' => $t['id_pembelian'],
                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $t['stok_masuk'],
                    'stok_keluar' => $t['stok_keluar'],
                    'stok_sisa' => $stokSisa,
                    'waktu' => $t['waktu'],
                    'keterangan' => $t['keterangan'],
                    'created_at' => $now,
                    'updated_at' => $now
                ];
                
                $runningStock = $stokSisa;
            }
            
            // Batch insert
            if (!empty($insertBatch)) {
                foreach (array_chunk($insertBatch, 500) as $chunk) {
                    DB::table('rekaman_stoks')->insert($chunk);
                }
            }
            
            // Update product stock
            DB::table('produk')->where('id_produk', $id)->update(['stok' => $runningStock]);
            
            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);
            
            $result['stats']['total_records_created'] = count($transactions);
            $result['stats']['final_stock'] = $runningStock;
            $result['stats']['execution_time'] = $executionTime . ' detik';
            $result['steps'][] = 'Selesai!';
            $result['message'] = "Berhasil memperbaiki kartu stok untuk {$produk->nama_produk} dengan " . count($transactions) . " record dalam {$executionTime} detik";
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ], 500);
        }
    }

}
