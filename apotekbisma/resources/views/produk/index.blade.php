@extends('layouts.master')

@section('title')
    Daftar Produk
@endsection

@push('css')
<style>
    .box-header .label {
        margin-left: 5px;
        font-size: 11px;
    }
    .table td {
        vertical-align: middle !important;
    }
    .btn-group .btn-xs {
        padding: 1px 6px;
        font-size: 10px;
    }
    #filter_stok {
        height: 26px;
        font-size: 12px;
        border-radius: 3px;
    }
    .box-header .form-control {
        margin-top: -2px;
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Daftar Produk</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-12">
        <div class="box">
            <div class="box-header with-border">
                <div class="btn-group">
                    <button onclick="addForm('{{ route('produk.store') }}')" class="btn btn-success btn-xs btn-flat"><i class="fa fa-plus-circle"></i> Tambah</button>
                    <button onclick="deleteSelected('{{ route('produk.delete_selected') }}')" class="btn btn-danger btn-xs btn-flat"><i class="fa fa-trash"></i> Hapus</button>
                    <button onclick="cetakBarcode('{{ route('produk.cetak_barcode') }}')" class="btn btn-info btn-xs btn-flat"><i class="fa fa-barcode"></i> Cetak Barcode</button>
                    <a href="{{ route('importview') }}" class="btn btn-info btn-xs btn-flat">Import</a>
                </div>
                
                {{-- Filter stok --}}
                <div class="btn-group pull-left" style="margin-left: 10px;">
                    <select id="filter_stok" class="form-control input-sm" style="width: 150px; display: inline-block;">
                        <option value="">Semua Produk</option>
                        <option value="habis">Stok Habis (≤0)</option>
                        <option value="menipis">Stok Menipis (=1)</option>
                        <option value="kritis">Stok Kritis (≤1)</option>
                        <option value="normal">Stok Normal (>1)</option>
                    </select>
                </div>
                
                {{-- Summary info stok --}}
                @php
                    $produkMenipis = \App\Models\Produk::where('stok', '=', 1)->count();
                    $produkHabis = \App\Models\Produk::where('stok', '<=', 0)->count();
                @endphp
                
                @if($produkMenipis > 0 || $produkHabis > 0)
                <div class="pull-right">
                    @if($produkHabis > 0)
                        <span class="label label-danger">{{ $produkHabis }} Produk Stok Habis</span>
                    @endif
                    @if($produkMenipis > 0)
                        <span class="label label-warning">{{ $produkMenipis }} Produk Stok Menipis</span>
                    @endif
                </div>
                @endif
            </div>
            <div class="box-body table-responsive">
                <form action="" method="post" class="form-produk">
                    @csrf
                    <table class="table table-stiped table-bordered">
                        <thead>
                            <th width="5%">
                                <input type="checkbox" name="select_all" id="select_all">
                            </th>
                            <th width="5%">No</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Kategori</th>
                            <th>Merk</th>
                            <th>Harga Beli</th>
                            <th>Harga Jual</th>
                            <th>Expired Date</th>
                            <th>Nomor Batch</th>
                            <th>Stok</th>
                            <th width="15%"><i class="fa fa-cog"></i></th>
                        </thead>
                    </table>
                </form>
            </div>
        </div>
    </div>
</div>

@includeIf('produk.form')
@endsection

@push('scripts')
<script>
    let table;

    $(function () {
        table = $('.table').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            ajax: {
                url: '{{ route('produk.data') }}',
                data: function (d) {
                    d.filter_stok = $('#filter_stok').val();
                }
            },
            columns: [
                {data: 'select_all', searchable: false, sortable: false},
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'nama_kategori'},
                {data: 'merk'},
                {data: 'harga_beli'},
                {data: 'harga_jual'},
                {data: 'expired_date'},
                {data: 'batch'},
                {data: 'stok'},
                {data: 'aksi', searchable: false, sortable: false},
            ]
        });

        // Filter event handler
        $('#filter_stok').on('change', function() {
            table.ajax.reload();
        });

        $('#modal-form').validator().on('submit', function (e) {
            if (! e.preventDefault()) {
                $.post($('#modal-form form').attr('action'), $('#modal-form form').serialize())
                    .done((response) => {
                        $('#modal-form').modal('hide');
                        table.ajax.reload();
                    })
                    .fail((errors) => {
                        alert('Tidak dapat menyimpan data');
                        return;
                    });
            }
        });

        $('[name=select_all]').on('click', function () {
            $(':checkbox').prop('checked', this.checked);
        });
    });

    function addForm(url) {
        $('#modal-form').modal('show');
        $('#modal-form .modal-title').text('Tambah Produk');

        $('#modal-form form')[0].reset();
        $('#modal-form form').attr('action', url);
        $('#modal-form [name=_method]').val('post');
        $('#modal-form [name=nama_produk]').focus();
    }

    function editForm(url) {
        $('#modal-form').modal('show');
        $('#modal-form .modal-title').text('Edit Produk');

        $('#modal-form form')[0].reset();
        $('#modal-form form').attr('action', url);
        $('#modal-form [name=_method]').val('put');
        $('#modal-form [name=nama_produk]').focus();

        $.get(url)
            .done((response) => {
                $('#modal-form [name=nama_produk]').val(response.nama_produk);
                $('#modal-form [name=id_kategori]').val(response.id_kategori);
                $('#modal-form [name=merk]').val(response.merk);
                $('#modal-form [name=harga_beli]').val(response.harga_beli);
                $('#modal-form [name=harga_jual]').val(response.harga_jual);
                $('#modal-form [name=diskon]').val(response.diskon);
                $('#modal-form [name=expired_date]').val(response.expired_date);
                $('#modal-form [name=batch]').val(response.batch);
                $('#modal-form [name=stok]').val(response.stok);
            })
            .fail((errors) => {
                alert('Tidak dapat menampilkan data');
                return;
            });
    }

    function deleteData(url) {
        if (confirm('Yakin ingin menghapus data terpilih?')) {
            $.post(url, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'delete'
                })
                .done((response) => {
                    table.ajax.reload();
                })
                .fail((errors) => {
                    alert('Tidak dapat menghapus data');
                    return;
                });
        }
    }

    function deleteSelected(url) {
        if ($('input:checked').length > 1) {
            if (confirm('Yakin ingin menghapus data terpilih?')) {
                $.post(url, $('.form-produk').serialize())
                    .done((response) => {
                        table.ajax.reload();
                    })
                    .fail((errors) => {
                        alert('Tidak dapat menghapus data');
                        return;
                    });
            }
        } else {
            alert('Pilih data yang akan dihapus');
            return;
        }
    }

    function cetakBarcode(url) {
        if ($('input:checked').length < 1) {
            alert('Pilih data yang akan dicetak');
            return;
        } else if ($('input:checked').length < 3) {
            alert('Pilih minimal 3 data untuk dicetak');
            return;
        } else {
            $('.form-produk')
                .attr('target', '_blank')
                .attr('action', url)
                .submit();
        }
    }

    function beliProduk(id) {
        if (confirm('Stok produk menipis. Apakah Anda ingin melakukan pembelian sekarang?')) {
            $.post('{{ route("produk.beli", ":id") }}'.replace(':id', id), {
                    '_token': $('[name=csrf-token]').attr('content')
                })
                .done((response) => {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    }
                })
                .fail((errors) => {
                    let errorMsg = 'Tidak dapat memproses permintaan pembelian';
                    if (errors.responseJSON && errors.responseJSON.error) {
                        errorMsg = errors.responseJSON.error;
                    }
                    alert(errorMsg);
                });
        }
    }
</script>
@endpush