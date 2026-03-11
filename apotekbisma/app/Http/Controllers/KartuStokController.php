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
    private function assertDestructiveToolsEnabled()
    {
        if (!config('stock.enable_destructive_rebuild_tools', false)) {
            abort(403, 'Fitur rebuild destruktif dinonaktifkan demi integritas stok.');
        }
    }

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
        
        // Get ALL transaction data upfront for robust client-side DataTables
        $dummyRequest = new \Illuminate\Http\Request(['date_filter' => 'all']);
        $dataStokLengkap = $this->getDataFiltered($id, $dummyRequest);
        $preCutoffAuditPurchaseCount = collect($dataStokLengkap)
            ->filter(function ($row) {
                return !empty($row['is_audit_reference']);
            })
            ->count();
        $stockCutoff = Carbon::parse($this->getStockCutoff())->format('d-m-Y H:i:s');
        
        // SORT BY DATE DESCENDING (newest first) - Backend sorting is 100% reliable
        usort($dataStokLengkap, function($a, $b) {
            $timeA = strtotime($a['waktu_raw'] ?? '1970-01-01');
            $timeB = strtotime($b['waktu_raw'] ?? '1970-01-01');
            
            if ($timeA == $timeB) {
                // Secondary sort by ID Descending to ensure consistent order for same-second transactions
                $idA = $a['id'] ?? 0;
                $idB = $b['id'] ?? 0;
                return $idB - $idA;
            }
            
            return $timeB - $timeA; // Descending
        });

        return view('kartu_stok.detail', compact('produk_id', 'nama_barang', 'produk', 'stok_data', 'dataStokLengkap', 'preCutoffAuditPurchaseCount', 'stockCutoff'));
    }
    
    public function getData($id)
    {
        return $this->buildStockCardRows($id, new Request(['date_filter' => 'all']), true);
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
        
        // Return all data to client side
        return datatables()
            ->of($data)
            ->rawColumns(['tanggal', 'stok_sisa', 'keterangan'])
            ->make(true);
    }

    public function getDataFiltered($id, Request $request)
    {
        return $this->buildStockCardRows($id, $request);
    }

    private function buildStockCardRows(int $id, Request $request, bool $appendCurrentSummary = false): array
    {
        $produk = Produk::find($id);
        if (!$produk) {
            return [];
        }

        $stok = RekamanStok::with(['produk', 'pembelian.supplier', 'penjualan'])
            ->where('id_produk', $id)
            ->orderBy('rekaman_stoks.waktu', 'asc')
            ->orderBy('id_rekaman_stok', 'asc')
            ->get()
            ->filter(function ($item) use ($request) {
                return $this->matchesStockCardDateFilter($item->waktu, $request);
            })
            ->values();

        $data = [];
        foreach ($stok as $item) {
            $data[] = $this->formatRekamanStockCardRow($item);
        }

        $data = array_merge($data, $this->buildPreCutoffPembelianAuditRows($id, $request));

        usort($data, function ($left, $right) {
            $timeCompare = strcmp((string) ($left['waktu_raw'] ?? ''), (string) ($right['waktu_raw'] ?? ''));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        foreach ($data as $index => &$row) {
            $row['DT_RowIndex'] = $index + 1;
        }
        unset($row);

        if ($appendCurrentSummary && !empty($data)) {
            $data[] = [
                'id' => PHP_INT_MAX,
                'DT_RowIndex' => '',
                'tanggal' => '<strong class="text-primary">STOK SAAT INI</strong>',
                'waktu_raw' => '9999-12-31 23:59:59',
                'stok_masuk' => '',
                'stok_keluar' => '',
                'stok_awal' => '',
                'stok_sisa' => '<strong class="text-primary">' . format_uang($produk->stok) . ' unit</strong>',
                'expired_date' => '',
                'supplier' => '',
                'keterangan' => '<strong class="text-primary">Stok Aktual Saat Ini</strong>',
                'is_audit_reference' => false,
            ];
        }

        return $data;
    }

    private function formatRekamanStockCardRow(RekamanStok $item): array
    {
        $row = [];
        $row['id'] = $item->id_rekaman_stok;
        $row['tanggal'] = tanggal_indonesia($item->waktu, false);

        try {
            $row['waktu_raw'] = Carbon::parse($item->waktu)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $row['waktu_raw'] = (string) $item->waktu;
        }

        $row['stok_masuk'] = ($item->stok_masuk != null && $item->stok_masuk > 0)
            ? format_uang($item->stok_masuk)
            : '-';
        $row['stok_keluar'] = ($item->stok_keluar != null && $item->stok_keluar > 0)
            ? format_uang($item->stok_keluar)
            : '-';
        $row['stok_awal'] = $item->stok_awal < 0
            ? '<span class="text-danger" title="Kondisi oversold - stok tidak mencukupi pada saat transaksi">' . format_uang($item->stok_awal) . '</span>'
            : format_uang($item->stok_awal);
        $row['stok_sisa'] = format_uang($item->stok_sisa);
        $row['expired_date'] = '';
        $row['supplier'] = '';
        $row['is_audit_reference'] = false;

        $normalizedKeterangan = $this->normalizeStockCardKeterangan($item->keterangan);
        if (!empty($normalizedKeterangan)) {
            if (stripos($normalizedKeterangan, 'Pembelian') !== false) {
                $keterangan = '<span class="label label-success"><i class="fa fa-arrow-up"></i> ' . $normalizedKeterangan . '</span>';

                if ($item->id_pembelian) {
                    $pembelian = Pembelian::find($item->id_pembelian);
                    if ($pembelian && $pembelian->no_faktur && $pembelian->no_faktur != 'o') {
                        $keterangan .= '<br><small class="text-muted">Faktur: ' . $pembelian->no_faktur . '</small>';
                    }
                }
            } elseif (stripos($normalizedKeterangan, 'Penjualan') !== false) {
                $keterangan = '<span class="label label-warning"><i class="fa fa-arrow-down"></i> ' . $normalizedKeterangan . '</span>';

                if ($item->id_penjualan) {
                    $penjualan = Penjualan::find($item->id_penjualan);
                    if ($penjualan) {
                        $keterangan .= '<br><small class="text-muted">ID Transaksi: ' . $penjualan->id_penjualan . '</small>';
                    }
                }
            } elseif (
                stripos($normalizedKeterangan, 'Perubahan Stok Manual') !== false
                || stripos($normalizedKeterangan, 'Stock Opname') !== false
                || stripos($normalizedKeterangan, 'Penyesuaian Stok') !== false
            ) {
                $keterangan = '<span class="label label-info"><i class="fa fa-edit"></i> ' . $normalizedKeterangan . '</span>';
            } elseif (stripos($normalizedKeterangan, 'Saldo Awal Stok') !== false) {
                $keterangan = '<span class="label label-default"><i class="fa fa-archive"></i> ' . $normalizedKeterangan . '</span>';
            } else {
                $keterangan = '<span class="label label-default"><i class="fa fa-cog"></i> ' . $normalizedKeterangan . '</span>';
            }
        } else {
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

        if (!empty($item->id_pembelian)) {
            try {
                $pembelian = $item->pembelian ?? Pembelian::find($item->id_pembelian);
                if ($pembelian) {
                    $row['supplier'] = optional($pembelian->supplier)->nama ?? '';
                    $pd = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)
                        ->where('id_produk', $item->id_produk)
                        ->first();
                    if ($pd && !empty($pd->expired_date)) {
                        try {
                            $row['expired_date'] = Carbon::parse($pd->expired_date)->toDateString();
                        } catch (\Exception $e) {
                            $row['expired_date'] = (string) $pd->expired_date;
                        }
                    } elseif (!empty($item->id_produk) && $item->produk && !empty($item->produk->expired_date)) {
                        try {
                            $row['expired_date'] = Carbon::parse($item->produk->expired_date)->toDateString();
                        } catch (\Exception $e) {
                            $row['expired_date'] = (string) $item->produk->expired_date;
                        }
                    }
                }
            } catch (\Exception $e) {
            }
        } elseif (!empty($item->id_produk) && $item->produk && !empty($item->produk->expired_date)) {
            try {
                $row['expired_date'] = Carbon::parse($item->produk->expired_date)->toDateString();
            } catch (\Exception $e) {
                $row['expired_date'] = (string) $item->produk->expired_date;
            }
        }

        return $row;
    }

    private function buildPreCutoffPembelianAuditRows(int $productId, Request $request): array
    {
        $cutoff = $this->getStockCutoff();
        $effectiveWaktuSql = $this->getPembelianEffectiveWaktuSql();
        $existingPurchaseIds = RekamanStok::where('id_produk', $productId)
            ->whereNotNull('id_pembelian')
            ->pluck('id_pembelian')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = DB::table('pembelian_detail as pd')
            ->join('pembelian as p', 'p.id_pembelian', '=', 'pd.id_pembelian')
            ->leftJoin('supplier as s', 's.id_supplier', '=', 'p.id_supplier')
            ->where('pd.id_produk', $productId)
            ->where('pd.jumlah', '>', 0)
            ->whereRaw($effectiveWaktuSql . ' <= ?', [$cutoff])
            ->where(function ($builder) {
                $builder->whereNull('p.no_faktur')
                    ->orWhere('p.no_faktur', '!=', 'o');
            })
            ->orderByRaw($effectiveWaktuSql . ' asc')
            ->orderBy('pd.id_pembelian_detail', 'asc')
            ->selectRaw('pd.id_pembelian_detail, pd.id_pembelian, pd.jumlah, p.no_faktur, s.nama as supplier_nama, ' . $effectiveWaktuSql . ' as waktu_efektif');

        if (!empty($existingPurchaseIds)) {
            $query->whereNotIn('p.id_pembelian', $existingPurchaseIds);
        }

        return $query->get()
            ->filter(function ($item) use ($request) {
                return $this->matchesStockCardDateFilter($item->waktu_efektif, $request);
            })
            ->map(function ($item) {
                $formattedWaktu = Carbon::parse($item->waktu_efektif)->format('Y-m-d H:i:s');

                return [
                    'id' => -1 * ((int) $item->id_pembelian_detail),
                    'tanggal' => tanggal_indonesia($formattedWaktu, false),
                    'waktu_raw' => $formattedWaktu,
                    'stok_masuk' => format_uang($item->jumlah),
                    'stok_keluar' => '-',
                    'stok_awal' => '-',
                    'stok_sisa' => '-',
                    'expired_date' => '',
                    'supplier' => $item->supplier_nama ?? '',
                    'keterangan' => '<span class="label label-primary"><i class="fa fa-book"></i> Pembelian Pra-Cutoff (Audit)</span><br><small class="text-muted">Faktur: ' . ($item->no_faktur ?: $item->id_pembelian) . '</small><br><small class="text-muted">Referensi audit. Kuantitas ini sudah tercermin pada saldo awal 31-12-2025.</small>',
                    'is_audit_reference' => true,
                ];
            })
            ->values()
            ->all();
    }

    private function matchesStockCardDateFilter($waktu, Request $request): bool
    {
        $filter = $request->input('date_filter');
        if (!$filter || $filter === 'all') {
            return true;
        }

        $effectiveDate = Carbon::parse($waktu);
        $now = Carbon::now();

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
    }

    private function getPembelianEffectiveWaktuSql(): string
    {
        return 'COALESCE(p.waktu_datang, p.waktu, p.created_at)';
    }

    private function getStockCutoff(): string
    {
        return (string) config('stock.cutoff_datetime', '2025-12-31 23:59:59');
    }

    private function normalizeStockCardKeterangan(?string $keterangan): string
    {
        $value = trim((string) $keterangan);
        if ($value === '') {
            return '';
        }

        if (stripos($value, 'rebuild baseline') !== false) {
            if (stripos($value, 'Pembelian') !== false) {
                return 'Pembelian';
            }

            if (stripos($value, 'Penjualan') !== false) {
                return 'Penjualan';
            }
        }

        if (
            stripos($value, 'BASELINE CSV') !== false
            || stripos($value, 'source of truth') !== false
            || stripos($value, 'SEED NON-BASELINE') !== false
        ) {
            return 'Saldo Awal Stok';
        }

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    public function exportPDF($id)
    {
        $produk = Produk::with('kategori')->find($id);
        
        if (!$produk) {
            return redirect()->route('kartu_stok.index')
                           ->with('error', 'Produk tidak ditemukan');
        }
        
        $data = $this->getData($id);
        $preCutoffAuditPurchaseCount = collect($data)
            ->filter(function ($row) {
                return !empty($row['is_audit_reference']);
            })
            ->count();
        $stockCutoff = Carbon::parse($this->getStockCutoff())->format('d-m-Y H:i:s');
        $nama_obat = $produk->nama_produk;
        $satuan = $produk->kategori ? $produk->kategori->nama_kategori : 'N/A';
        $kode_produk = $produk->kode_produk;
        
        $pdf = PDF::loadView('kartu_stok.pdf', compact('data', 'nama_obat', 'satuan', 'kode_produk', 'produk', 'preCutoffAuditPurchaseCount', 'stockCutoff'));
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
        $this->assertDestructiveToolsEnabled();

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
        $this->assertDestructiveToolsEnabled();

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
