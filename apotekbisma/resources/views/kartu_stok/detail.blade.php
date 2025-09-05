@extends('layouts.master')

@section('title')
    Kartu Stok - {{ $nama_barang }}
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css') }}">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .info-box-content {
        padding: 5px 10px;
        margin-left: 90px;
    }
    .info-box {
        display: block;
        min-height: 90px;
        background: #fff;
        width: 100%;
        box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        border-radius: 2px;
        margin-bottom: 15px;
    }
    .filter-card {
        background: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .chart-container {
        position: relative;
        height: 300px;
        margin-bottom: 20px;
    }
    .summary-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        text-align: center;
    }
    .summary-item {
        background: rgba(255,255,255,0.2);
        border-radius: 5px;
        padding: 10px;
        margin: 5px 0;
    }
    
    @media (max-width: 768px) {
        .info-box {
            margin-bottom: 10px;
        }
        
        .info-box-content {
            margin-left: 70px;
            padding: 5px;
        }
        
        .info-box-icon {
            width: 70px;
            height: 70px;
            line-height: 70px;
        }
        
        .filter-card {
            padding: 10px;
        }
        
        .btn-group .btn {
            font-size: 11px;
            padding: 4px 8px;
            margin: 2px;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table td, .table th {
            white-space: nowrap;
            font-size: 12px;
            padding: 6px 4px;
        }
        
        .box-tools .btn {
            margin: 2px;
            font-size: 11px;
            padding: 4px 8px;
        }
        
        .form-group label {
            font-size: 12px;
        }
        
        .input-group .form-control {
            font-size: 12px;
        }
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li><a href="{{ route('kartu_stok.index') }}">Kartu Stok</a></li>
    <li class="active">{{ $nama_barang }}</li>
@endsection

@section('content')
<!-- Product Information Row -->
<div class="row">
    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-aqua"><i class="fa fa-barcode"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Kode Produk</span>
                <span class="info-box-number">{{ $produk->kode_produk }}</span>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-green"><i class="fa fa-cubes"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Stok Saat Ini</span>
                <span class="info-box-number">{{ format_uang($produk->stok) }}</span>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-yellow"><i class="fa fa-tags"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Kategori</span>
                <span class="info-box-number">{{ $produk->kategori->nama_kategori ?? 'N/A' }}</span>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-red"><i class="fa fa-money"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Harga Beli</span>
                <span class="info-box-number">{{ format_uang($produk->harga_beli) }}</span>
            </div>
        </div>
    </div>
</div>

<!-- Summary & Chart Row -->
<div class="row">
    <div class="col-md-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-line-chart"></i> Grafik Pergerakan Stok (30 Hari Terakhir)
                </h3>
            </div>
            <div class="box-body">
                <div class="chart-container">
                    <canvas id="stockChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="summary-card">
            <h4><i class="fa fa-chart-pie"></i> Ringkasan Periode</h4>
            
            <div class="summary-item">
                <strong>Minggu Ini</strong><br>
                <small>Masuk: {{ $stok_data['summary']['periode_minggu']['masuk'] }} | Keluar: {{ $stok_data['summary']['periode_minggu']['keluar'] }}</small>
            </div>
            
            <div class="summary-item">
                <strong>Bulan Ini</strong><br>
                <small>Masuk: {{ $stok_data['summary']['periode_bulan']['masuk'] }} | Keluar: {{ $stok_data['summary']['periode_bulan']['keluar'] }}</small>
            </div>
            
            <div class="summary-item">
                <strong>Tahun Ini</strong><br>
                <small>Masuk: {{ $stok_data['summary']['periode_tahun']['masuk'] }} | Keluar: {{ $stok_data['summary']['periode_tahun']['keluar'] }}</small>
            </div>
            
            <div class="summary-item">
                <strong>Total Keseluruhan</strong><br>
                <small>{{ $stok_data['summary']['total_transaksi'] }} transaksi</small>
            </div>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="row">
    <div class="col-lg-12">
        <div class="filter-card">
            <h4><i class="fa fa-filter"></i> Filter Data</h4>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Filter Cepat:</label>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-default filter-btn" data-filter="all">Semua</button>
                            <button type="button" class="btn btn-default filter-btn" data-filter="today">Hari Ini</button>
                            <button type="button" class="btn btn-default filter-btn" data-filter="week">Minggu Ini</button>
                            <button type="button" class="btn btn-primary filter-btn" data-filter="month">Bulan Ini</button>
                            <button type="button" class="btn btn-default filter-btn" data-filter="year">Tahun Ini</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Filter Kustom:</label>
                        <div class="input-group">
                            <input type="date" class="form-control" id="start_date" placeholder="Tanggal Mulai">
                            <span class="input-group-addon">s/d</span>
                            <input type="date" class="form-control" id="end_date" placeholder="Tanggal Akhir">
                            <span class="input-group-btn">
                                <button class="btn btn-primary" type="button" id="apply-custom-filter">
                                    <i class="fa fa-search"></i> Terapkan
                                </button>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stock Movement Table -->
<div class="row">
    <div class="col-lg-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-history"></i> Riwayat Pergerakan Stok - {{ $nama_barang }}
                </h3>
                <div class="box-tools pull-right">
                    <a href="{{ route('kartu_stok.export_pdf', $produk_id) }}" target="_blank" class="btn btn-success btn-sm btn-flat">
                        <i class="fa fa-file-pdf-o"></i> Export PDF
                    </a>
                    <a href="{{ route('kartu_stok.index') }}" class="btn btn-default btn-sm btn-flat">
                        <i class="fa fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-striped table-bordered table-hover" id="kartu-stok-table">
                    <thead class="bg-primary">
                        <tr>
                            <th width="5%" class="text-center">No</th>
                            <th class="text-center">Tanggal</th>
                            <th class="text-center">Stok Masuk</th>
                            <th class="text-center">Stok Keluar</th>
                            <th class="text-center">Stok Awal</th>
                            <th class="text-center">Stok Saat ini</th>
                            <th class="text-center">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via DataTables -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js') }}"></script>
<script>
    let table;
    let currentFilter = 'month'; // Default filter

    $(function () {
        // Initialize DataTable
        table = $('#kartu-stok-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 'tr'
                }
            },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            scrollCollapse: true,
            ajax: {
                url: '{{ route('kartu_stok.data', $produk_id) }}',
                data: function(d) {
                    d.date_filter = currentFilter;
                    if (currentFilter === 'custom') {
                        d.start_date = $('#start_date').val();
                        d.end_date = $('#end_date').val();
                    }
                }
            },
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false, className: 'text-center'},
                {data: 'tanggal', className: 'text-center'},
                {data: 'stok_masuk', className: 'text-center'},
                {data: 'stok_keluar', className: 'text-center'},
                {data: 'stok_awal', className: 'text-center'},
                {data: 'stok_sisa', className: 'text-center'},
                {data: 'keterangan', className: 'text-left'}
            ],
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'excel',
                    text: '<i class="fa fa-file-excel-o"></i> Excel',
                    className: 'btn btn-success btn-sm',
                    title: 'Kartu Stok - {{ $nama_barang }}'
                },
                {
                    extend: 'print',
                    text: '<i class="fa fa-print"></i> Print',
                    className: 'btn btn-primary btn-sm',
                    title: 'Kartu Stok - {{ $nama_barang }}'
                }
            ],
            language: {
                processing: '<i class="fa fa-spinner fa-spin"></i> Memuat data...',
                search: 'Pencarian:',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
                infoEmpty: 'Menampilkan 0 sampai 0 dari 0 data',
                infoFiltered: '(disaring dari _MAX_ total data)',
                zeroRecords: 'Tidak ada riwayat pergerakan stok untuk periode ini',
                emptyTable: 'Belum ada riwayat pergerakan stok',
                paginate: {
                    first: 'Pertama',
                    last: 'Terakhir',
                    next: 'Selanjutnya',
                    previous: 'Sebelumnya'
                }
            },
            order: [[1, 'desc']], // Order by date descending
            pageLength: 25
        });

        // Initialize Chart
        initStockChart();

        // Filter button handlers
        $('.filter-btn').click(function() {
            $('.filter-btn').removeClass('btn-primary').addClass('btn-default');
            $(this).removeClass('btn-default').addClass('btn-primary');
            
            currentFilter = $(this).data('filter');
            table.ajax.reload();
        });

        // Custom filter handler
        $('#apply-custom-filter').click(function() {
            if ($('#start_date').val() && $('#end_date').val()) {
                $('.filter-btn').removeClass('btn-primary').addClass('btn-default');
                currentFilter = 'custom';
                table.ajax.reload();
            } else {
                alert('Mohon pilih tanggal mulai dan tanggal akhir');
            }
        });

        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });
    });

    function initStockChart() {
        const ctx = document.getElementById('stockChart').getContext('2d');
        const chartData = @json($stok_data['chart_data']);
        
        // Prepare data for chart
        const labels = [];
        const stokMasukData = [];
        const stokKeluarData = [];
        const stokSisaData = [];

        // Sort dates and prepare data
        const sortedDates = Object.keys(chartData).sort();
        
        sortedDates.forEach(date => {
            labels.push(new Date(date).toLocaleDateString('id-ID'));
            stokMasukData.push(chartData[date].masuk || 0);
            stokKeluarData.push(chartData[date].keluar || 0);
            stokSisaData.push(chartData[date].sisa || 0);
        });

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Stok Masuk',
                        data: stokMasukData,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    },
                    {
                        label: 'Stok Keluar',
                        data: stokKeluarData,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.1
                    },
                    {
                        label: 'Stok Tersisa',
                        data: stokSisaData,
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        tension: 0.1,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Tanggal'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Jumlah Masuk/Keluar'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Stok Tersisa'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Pergerakan Stok {{ $nama_barang }}'
                    },
                    legend: {
                        display: true,
                        position: 'top'
                    }
                }
            }
        });
    }
</script>
@endpush