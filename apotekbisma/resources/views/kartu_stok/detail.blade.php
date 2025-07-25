@extends('layouts.master')

@section('title')
    Kartu Stok - {{ $nama_barang }}
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css') }}">
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
                            <th class="text-center">Stok Sisa</th>
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

    $(function () {
        table = $('#kartu-stok-table').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            ajax: {
                url: '{{ route('kartu_stok.data', $produk_id) }}',
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
                    className: 'btn btn-success btn-sm'
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
                zeroRecords: 'Tidak ada riwayat pergerakan stok untuk produk ini',
                emptyTable: 'Belum ada riwayat pergerakan stok',
                paginate: {
                    first: 'Pertama',
                    last: 'Terakhir',
                    next: 'Selanjutnya',
                    previous: 'Sebelumnya'
                }
            },
            order: [[1, 'desc']], // Order by date descending
            drawCallback: function(settings) {
                // Add styling to the summary row
                $(this.api().table().node()).find('tbody tr:last-child').addClass('bg-light-blue');
            }
        });

        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            autoclose: true,
            todayHighlight: true
        });
    });
</script>
@endpush