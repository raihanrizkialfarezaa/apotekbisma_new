@extends('layouts.master')

@section('title')
    Daftar Penjualan
@endsection

@push('css')
<style>
    .label-warning {
        background-color: #f0ad4e;
    }
    
    .label-success {
        background-color: #5cb85c;
    }
    
    .btn-warning {
        color: #fff;
        background-color: #f0ad4e;
        border-color: #eea236;
    }
    
    .btn-warning:hover {
        color: #fff;
        background-color: #ec971f;
        border-color: #d58512;
    }
    
    .btn-success {
        color: #fff;
        background-color: #5cb85c;
        border-color: #4cae4c;
    }
    
    .btn-success:hover {
        color: #fff;
        background-color: #449d44;
        border-color: #398439;
    }
    
    .btn-primary {
        color: #fff;
        background-color: #337ab7;
        border-color: #2e6da4;
    }
    
    .btn-primary:hover {
        color: #fff;
        background-color: #286090;
        border-color: #204d74;
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Daftar Penjualan</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-12">
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                <h4><i class="icon fa fa-ban"></i> Error!</h4>
                {{ session('error') }}
            </div>
        @endif
        
        @if (session('success'))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                <h4><i class="icon fa fa-check"></i> Success!</h4>
                {{ session('success') }}
            </div>
        @endif
        
        <div class="box">
            <div class="box-header with-border">
                <button onclick="syncStock()" class="btn btn-primary btn-xs btn-flat pull-right"><i class="fa fa-refresh"></i> Cocokkan data Stok Produk</button>
                <div class="clearfix"></div>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-stiped table-bordered table-penjualan">
                    <thead>
                        <th width="5%">No</th>
                        <th>Tanggal</th>
                        <th>Kode Member</th>
                        <th>Total Item</th>
                        <th>Total Harga</th>
                        <th>Diskon</th>
                        <th>Total Bayar</th>
                        <th>Kasir</th>
                        <th width="15%"><i class="fa fa-cog"></i></th>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

@includeIf('penjualan.detail')
@endsection

@push('scripts')
<script>
    let table, table1;

    $(function () {
        table = $('.table-penjualan').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            ajax: {
                url: '{{ route('penjualan.data') }}',
            },
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'tanggal'},
                {data: 'kode_member'},
                {data: 'total_item'},
                {data: 'total_harga'},
                {data: 'diskon'},
                {data: 'bayar'},
                {data: 'kasir'},
                {data: 'aksi', searchable: false, sortable: false},
            ]
        }).on('draw.dt', function () {
            // Inisialisasi tooltip setelah datatable di-draw
            $('[data-toggle="tooltip"]').tooltip();
        });

        table1 = $('.table-detail').DataTable({
            processing: true,
            bSort: false,
            dom: 'flprt',
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'harga_jual'},
                {data: 'jumlah'},
                {data: 'subtotal'},
            ]
        })
    });

    function showDetail(url) {
        $('#modal-detail').modal('show');

        table1.ajax.url(url);
        table1.ajax.reload();
    }

    function deleteData(url) {
        if (confirm('Yakin ingin menghapus transaksi ini?\n\nSTOK PRODUK AKAN DIKEMBALIKAN sesuai jumlah yang dibeli pada transaksi ini.')) {
            $.post(url, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'delete'
                })
                .done((response) => {
                    if (response.success) {
                        table.ajax.reload();
                        alert('Transaksi berhasil dihapus!\n\nStok produk telah dikembalikan ke jumlah semula.');
                    } else {
                        alert('Gagal menghapus transaksi: ' + response.message);
                    }
                })
                .fail((xhr) => {
                    let errorMsg = 'Tidak dapat menghapus transaksi';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    alert(errorMsg);
                });
        }
    }

    function lanjutkanTransaksi(id) {
        if (confirm('Yakin ingin melanjutkan transaksi #' + id + '?\n\nAnda akan diarahkan ke halaman transaksi aktif untuk menyelesaikan transaksi ini.')) {
            window.location.href = '{{ url('/penjualan') }}/' + id + '/lanjutkan';
        }
    }

    function editTransaksi(id) {
        if (confirm('Yakin ingin mengedit transaksi #' + id + '?\n\nAnda akan diarahkan ke halaman transaksi aktif untuk mengedit transaksi ini.')) {
            window.location.href = '{{ url('/penjualan') }}/' + id + '/edit';
        }
    }

    function printReceipt(id) {
        if (confirm('Yakin ingin mencetak struk transaksi #' + id + '?')) {
            window.open('{{ url('/penjualan') }}/' + id + '/print', '_blank');
        }
    }

    function syncStock() {
        if (confirm('Apakah Anda yakin ingin melakukan sinkronisasi stok produk?\n\nProses ini akan mencocokkan data stok produk dengan rekaman stok terbaru.')) {
            const btn = $('button[onclick="syncStock()"]');
            const originalText = btn.html();
            
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
            
            const startTime = Date.now();
            
            $.ajax({
                url: '{{ route("admin.sync.stock") }}',
                type: 'POST',
                data: {
                    '_token': $('[name=csrf-token]').attr('content')
                },
                timeout: 300000,
                success: function(response) {
                    const duration = ((Date.now() - startTime) / 1000).toFixed(1);
                    if (response.success) {
                        alert('Sinkronisasi berhasil dalam ' + duration + ' detik!\n\n' +
                              'Produk yang diperbarui: ' + response.updated + '\n' +
                              'Produk yang sudah sinkron: ' + response.synchronized);
                        if (typeof table !== 'undefined') {
                            table.ajax.reload(null, false);
                        }
                    } else {
                        alert('Gagal melakukan sinkronisasi: ' + response.message);
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'Terjadi kesalahan saat melakukan sinkronisasi';
                    if (xhr.status === 429) {
                        errorMsg = 'Sinkronisasi sedang berlangsung, silakan tunggu beberapa saat';
                    } else if (xhr.status === 403) {
                        errorMsg = 'Anda tidak memiliki akses untuk melakukan sinkronisasi stok';
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    alert(errorMsg);
                },
                complete: function() {
                    btn.prop('disabled', false).html(originalText);
                }
            });
        }
    }
</script>
@endpush