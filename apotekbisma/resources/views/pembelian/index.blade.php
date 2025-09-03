@extends('layouts.master')

@section('title')
    Daftar Pembelian
@endsection

@section('breadcrumb')
    @parent
    <li class="active">Daftar Pembelian</li>
@endsection

@section('content')
@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        <h4><i class="icon fa fa-ban"></i> Error!</h4>
        {{ session('error') }}
    </div>
@endif

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        <h4><i class="icon fa fa-check"></i> Success!</h4>
        {{ session('success') }}
    </div>
@endif

<div class="row">
    <div class="col-lg-12">
        <div class="box">
            <div class="box-header with-border">
                <button onclick="addForm()" class="btn btn-success btn-xs btn-flat"><i class="fa fa-plus-circle"></i> Transaksi Baru</button>
                <button onclick="syncStock()" class="btn btn-primary btn-xs btn-flat pull-right"><i class="fa fa-refresh"></i> Cocokkan data Stok Produk</button>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-stiped table-bordered table-pembelian">
                    <thead>
                        <th width="5%">No</th>
                        <th>Tanggal Obat Datang</th>
                        <th>Supplier</th>
                        <th>Total Item</th>
                        <th>Total Harga</th>
                        <th>Diskon</th>
                        <th>Total Bayar</th>
                        <th>Waktu Faktur Dibuat</th>
                        <th width="15%"><i class="fa fa-cog"></i></th>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

@includeIf('pembelian.supplier')
@includeIf('pembelian.detail')
@endsection

@push('scripts')
<script>
    let table, table1;

    $(function () {
        table = $('.table-pembelian').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            searchable: true,
            ajax: {
                url: '{{ route('pembelian.data') }}',
                error: function(xhr, error, code) {
                    console.log('DataTables Error:', error);
                    console.log('Status:', xhr.status);
                    console.log('Response:', xhr.responseText);
                }
            },
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'tanggal'},
                {data: 'supplier'},
                {data: 'total_item'},
                {data: 'total_harga'},
                {data: 'diskon'},
                {data: 'bayar'},
                {data: 'waktu'},
                {data: 'aksi', searchable: false, sortable: false},
            ]
        });

        $('.table-supplier').DataTable();
        table1 = $('.table-detail').DataTable({
            processing: true,
            bSort: false,
            dom: 'fplrt',
            searchable: true,
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'harga_beli'},
                {data: 'jumlah'},
                {data: 'subtotal'},
            ]
        })
    });

    function addForm() {
        $('#modal-supplier').modal('show');
    }

    function showDetail(url) {
        $('#modal-detail').modal('show');

        table1.ajax.url(url);
        table1.ajax.reload();
    }

    function deleteData(url) {
        if (confirm('Yakin ingin menghapus data terpilih?')) {
            $.post(url, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'delete'
                })
                .done((response) => {
                    if (response.success) {
                        alert(response.message);
                    }
                    table.ajax.reload();
                })
                .fail((errors) => {
                    alert('Tidak dapat menghapus data');
                    return;
                });
        }
    }

    function lanjutkanTransaksi(id) {
        window.location.href = '{{ route("pembelian.lanjutkan", ":id") }}'.replace(':id', id);
    }

    function editTransaksi(id) {
        window.location.href = '{{ route("pembelian_detail.editBayar", ":id") }}'.replace(':id', id);
    }

    function printReceipt(id) {
        if (confirm('Cetak bukti pembelian?')) {
            window.open('{{ route("pembelian.print", ":id") }}'.replace(':id', id), '_blank');
        }
    }

    // Auto cleanup untuk transaksi yang tidak selesai saat meninggalkan halaman
    $(window).on('beforeunload', function() {
        // Cleanup transaksi yang tidak lengkap
        $.post('{{ route("pembelian.cleanup") }}', {
            '_token': $('[name=csrf-token]').attr('content')
        });
    });

    function syncStock() {
        if (confirm('Apakah Anda yakin ingin melakukan sinkronisasi stok produk?\n\nProses ini akan mencocokkan data stok produk dengan rekaman stok terbaru.')) {
            // Tampilkan loading
            $('button[onclick="syncStock()"]').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
            
            $.post('{{ route("admin.sync.stock") }}', {
                '_token': $('[name=csrf-token]').attr('content')
            })
            .done(function(response) {
                if (response.success) {
                    alert('Sinkronisasi berhasil!\n\nProduk yang diperbarui: ' + response.updated + '\nProduk yang sudah sinkron: ' + response.synchronized);
                    // Reload DataTable jika diperlukan
                    if (typeof table !== 'undefined') {
                        table.ajax.reload();
                    }
                } else {
                    alert('Gagal melakukan sinkronisasi: ' + response.message);
                }
            })
            .fail(function(xhr) {
                let errorMsg = 'Terjadi kesalahan saat melakukan sinkronisasi';
                if (xhr.status === 403) {
                    errorMsg = 'Anda tidak memiliki akses untuk melakukan sinkronisasi stok';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                alert(errorMsg);
            })
            .always(function() {
                // Kembalikan button ke state normal
                $('button[onclick="syncStock()"]').prop('disabled', false).html('<i class="fa fa-refresh"></i> Cocokkan data Stok Produk');
            });
        }
    }
</script>
@endpush