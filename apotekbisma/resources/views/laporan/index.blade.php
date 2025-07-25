@extends('layouts.master')

@section('title')
    Laporan Pendapatan {{ tanggal_indonesia($tanggalAwal, false) }} s/d {{ tanggal_indonesia($tanggalAkhir, false) }}
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css') }}">
<style>
    .info-box-icon {
        border-radius: 50%;
        color: #fff !important;
    }
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
    .small-box .icon {
        position: absolute;
        top: auto;
        bottom: 5px;
        right: 5px;
        z-index: 0;
        font-size: 70px;
        color: rgba(0,0,0,0.15);
    }
    .chart-container {
        height: 200px;
        padding: 10px;
        background: #fff;
        border-radius: 3px;
        box-shadow: 0 1px 1px rgba(0,0,0,0.1);
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Laporan Keuangan</li>
@endsection

@section('content')
<!-- Statistics Row -->
<div class="row">
    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3>Rp. {{ format_uang($statistics['totalPenjualan']) }}</h3>
                <p>Total Penjualan</p>
                <small>{{ $statistics['jumlahTransaksiPenjualan'] }} transaksi</small>
            </div>
            <div class="icon">
                <i class="ion ion-bag"></i>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-red">
            <div class="inner">
                <h3>Rp. {{ format_uang($statistics['totalPembelian']) }}</h3>
                <p>Total Pembelian</p>
                <small>{{ $statistics['jumlahTransaksiPembelian'] }} transaksi</small>
            </div>
            <div class="icon">
                <i class="ion ion-stats-bars"></i>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3>Rp. {{ format_uang($statistics['totalPengeluaran']) }}</h3>
                <p>Total Pengeluaran</p>
                <small>Biaya operasional</small>
            </div>
            <div class="icon">
                <i class="ion ion-card"></i>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="small-box {{ $statistics['totalPendapatan'] >= 0 ? 'bg-green' : 'bg-red' }}">
            <div class="inner">
                <h3>Rp. {{ format_uang($statistics['totalPendapatan']) }}</h3>
                <p>{{ $statistics['totalPendapatan'] >= 0 ? 'Keuntungan' : 'Kerugian' }}</p>
                <small>Pendapatan bersih</small>
            </div>
            <div class="icon">
                <i class="ion ion-pie-graph"></i>
            </div>
        </div>
    </div>
</div>

<!-- Additional Statistics Row -->
<div class="row">
    <div class="col-lg-4 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-blue"><i class="fa fa-calendar"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Rata-rata Harian</span>
                <span class="info-box-number">Rp. {{ format_uang($statistics['rataRataPenjualanHarian']) }}</span>
                <span class="progress-description">{{ number_format($statistics['rataRataTransaksiHarian'], 1) }} transaksi/hari</span>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-purple"><i class="fa fa-users"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Member Aktif</span>
                <span class="info-box-number">{{ $statistics['memberAktif'] }}</span>
                <span class="progress-description">dari {{ $statistics['totalMember'] }} total member</span>
            </div>
        </div>
    </div>

    <div class="col-lg-4 col-xs-6">
        <div class="info-box">
            <span class="info-box-icon bg-orange"><i class="fa fa-clock-o"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Periode Laporan</span>
                <span class="info-box-number">{{ $statistics['jumlahHari'] }}</span>
                <span class="progress-description">hari</span>
            </div>
        </div>
    </div>
</div>

<!-- Best Selling Products & Low Stock -->
<div class="row">
    <div class="col-lg-6">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-star"></i> Produk Terlaris</h3>
            </div>
            <div class="box-body">
                @if($statistics['produkTerlaris']->count() > 0)
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Terjual</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($statistics['produkTerlaris'] as $produk)
                            <tr>
                                <td>{{ $produk->nama_produk }}</td>
                                <td><span class="badge bg-blue">{{ format_uang($produk->total_terjual) }}</span></td>
                                <td><span class="text-green">Rp. {{ format_uang($produk->total_revenue) }}</span></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted">Tidak ada data penjualan dalam periode ini.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-exclamation-triangle"></i> Stok Menipis</h3>
            </div>
            <div class="box-body">
                @if($statistics['stokMenupis']->count() > 0)
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Kode</th>
                                <th>Stok</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($statistics['stokMenupis'] as $produk)
                            <tr>
                                <td>{{ $produk->nama_produk }}</td>
                                <td><span class="label label-default">{{ $produk->kode_produk }}</span></td>
                                <td>
                                    <span class="badge {{ $produk->stok == 0 ? 'bg-red' : ($produk->stok <= 1 ? 'bg-yellow' : 'bg-orange') }}">
                                        {{ format_uang($produk->stok) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted">Semua produk memiliki stok yang cukup.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Main Report Table -->
<div class="row">
    <div class="col-lg-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-table"></i> Detail Laporan Harian</h3>
                <div class="box-tools pull-right">
                    <button onclick="updatePeriode()" class="btn btn-info btn-sm btn-flat">
                        <i class="fa fa-calendar"></i> Ubah Periode
                    </button>
                    <a href="{{ route('laporan.export_pdf', [$tanggalAwal, $tanggalAkhir]) }}" target="_blank" class="btn btn-success btn-sm btn-flat">
                        <i class="fa fa-file-pdf-o"></i> Export PDF
                    </a>
                </div>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-striped table-bordered table-hover" id="laporan-table">
                    <thead class="bg-primary">
                        <tr>
                            <th width="5%" class="text-center">No</th>
                            <th class="text-center">Tanggal</th>
                            <th class="text-center">Penjualan</th>
                            <th class="text-center">Pembelian</th>
                            <th class="text-center">Pengeluaran</th>
                            <th class="text-center">Pendapatan</th>
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

@includeIf('laporan.form')
@endsection

@push('scripts')
<script src="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js') }}"></script>
<script>
    let table;

    $(function () {
        table = $('#laporan-table').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            ajax: {
                url: '{{ route('laporan.data', [$tanggalAwal, $tanggalAkhir]) }}',
            },
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false, className: 'text-center'},
                {data: 'tanggal', className: 'text-center'},
                {data: 'penjualan', className: 'text-right'},
                {data: 'pembelian', className: 'text-right'},
                {data: 'pengeluaran', className: 'text-right'},
                {data: 'pendapatan', className: 'text-right'}
            ],
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'excel',
                    text: '<i class="fa fa-file-excel-o"></i> Excel',
                    className: 'btn btn-success btn-sm'
                },
                {
                    extend: 'pdf',
                    text: '<i class="fa fa-file-pdf-o"></i> PDF',
                    className: 'btn btn-danger btn-sm'
                },
                {
                    extend: 'print',
                    text: '<i class="fa fa-print"></i> Print',
                    className: 'btn btn-primary btn-sm'
                }
            ],
            language: {
                processing: '<i class="fa fa-spinner fa-spin"></i> Memuat data...',
                search: 'Pencarian:',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
                infoEmpty: 'Menampilkan 0 sampai 0 dari 0 data',
                infoFiltered: '(disaring dari _MAX_ total data)',
                zeroRecords: 'Tidak ada data yang ditemukan',
                emptyTable: 'Tidak ada data tersedia',
                paginate: {
                    first: 'Pertama',
                    last: 'Terakhir',
                    next: 'Selanjutnya',
                    previous: 'Sebelumnya'
                }
            },
            bSort: false,
            bPaginate: false,
            order: [],
            drawCallback: function(settings) {
                // Add some styling to the total row
                $(this.api().table().node()).find('tbody tr:last-child').addClass('bg-light-blue-gradient');
            }
        });

        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });

        // Auto refresh every 5 minutes
        setInterval(function() {
            table.ajax.reload(null, false);
        }, 300000);
    });

    function updatePeriode() {
        $('#modal-form').modal('show');
    }

    // Add some animation effects
    $(document).ready(function() {
        $('.small-box').hover(
            function() {
                $(this).addClass('animated pulse');
            },
            function() {
                $(this).removeClass('animated pulse');
            }
        );
    });
</script>
@endpush