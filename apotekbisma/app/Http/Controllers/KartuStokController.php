<?php

namespace App\Http\Controllers;

use App\Models\Produk;
use App\Models\RekamanStok;
use App\Models\Pembelian;
use App\Models\Penjualan;
use App\Models\PembelianDetail;
use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Http\Request;
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

        // Apply date filters based on request
        // Filter considers actual transaction time from penjualan/pembelian tables
        if ($request->has('date_filter') && $request->date_filter && $request->date_filter != 'all') {
            $filter = $request->date_filter;
            $now = Carbon::now();
            
            switch ($filter) {
                case 'today':
                    $query->where(function($q) use ($now) {
                        $dateStr = $now->toDateString();
                        $q->whereDate('rekaman_stoks.waktu', $dateStr)
                          ->orWhereHas('penjualan', function($pq) use ($dateStr) {
                              $pq->whereDate('waktu', $dateStr);
                          })
                          ->orWhereHas('pembelian', function($pq) use ($dateStr) {
                              $pq->whereDate('waktu', $dateStr);
                          });
                    });
                    break;
                case 'week':
                    $query->where(function($q) use ($now) {
                        $startDate = $now->copy()->startOfWeek()->toDateString();
                        $endDate = $now->copy()->endOfWeek()->toDateString();
                        $q->whereBetween('rekaman_stoks.waktu', [$startDate, $endDate])
                          ->orWhereHas('penjualan', function($pq) use ($startDate, $endDate) {
                              $pq->whereBetween('waktu', [$startDate, $endDate]);
                          })
                          ->orWhereHas('pembelian', function($pq) use ($startDate, $endDate) {
                              $pq->whereBetween('waktu', [$startDate, $endDate]);
                          });
                    });
                    break;
                case 'month':
                    $query->where(function($q) use ($now) {
                        $month = $now->month;
                        $year = $now->year;
                        $q->where(function($qq) use ($month, $year) {
                            $qq->whereMonth('rekaman_stoks.waktu', $month)
                               ->whereYear('rekaman_stoks.waktu', $year);
                          })
                          ->orWhereHas('penjualan', function($pq) use ($month, $year) {
                              $pq->whereMonth('waktu', $month)
                                 ->whereYear('waktu', $year);
                          })
                          ->orWhereHas('pembelian', function($pq) use ($month, $year) {
                              $pq->whereMonth('waktu', $month)
                                 ->whereYear('waktu', $year);
                          });
                    });
                    break;
                case 'year':
                    $query->where(function($q) use ($now) {
                        $year = $now->year;
                        $q->whereYear('rekaman_stoks.waktu', $year)
                          ->orWhereHas('penjualan', function($pq) use ($year) {
                              $pq->whereYear('waktu', $year);
                          })
                          ->orWhereHas('pembelian', function($pq) use ($year) {
                              $pq->whereYear('waktu', $year);
                          });
                    });
                    break;
                case 'custom':
                    if ($request->has('start_date') && $request->has('end_date')) {
                        $query->where(function($q) use ($request) {
                            $startDate = $request->start_date . ' 00:00:00';
                            $endDate = $request->end_date . ' 23:59:59';
                            $q->whereBetween('rekaman_stoks.waktu', [$startDate, $endDate])
                              ->orWhereHas('penjualan', function($pq) use ($startDate, $endDate) {
                                  $pq->whereBetween('waktu', [$startDate, $endDate]);
                              })
                              ->orWhereHas('pembelian', function($pq) use ($startDate, $endDate) {
                                  $pq->whereBetween('waktu', [$startDate, $endDate]);
                              });
                        });
                    }
                    break;
            }
        }

        // Order by waktu ASC for chronological display (audit-friendly)
        // This ensures dates appear in proper sequence
        $stok = $query->orderBy('rekaman_stoks.waktu', 'asc')
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
    
    public function fixRecords()
    {
        // Redirect ke script perbaikan robust
        return redirect('/fix_kartu_stok_robust.php');
    }
    
    public function fixRecordsForProduct($id)
    {
        // Redirect ke script perbaikan untuk produk tertentu
        return redirect('/fix_kartu_stok_robust.php?product_id=' . $id);
    }

}
