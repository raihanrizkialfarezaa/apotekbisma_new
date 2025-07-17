@extends('layouts.master')

@section('title')
    Kartu Stok
@endsection

@section('breadcrumb')
    @parent
    <li class="active">Kartu Stok</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-12">
        <div class="box">
            <div class="box-header with-border">
                <form class="form-produk">
                    @csrf
                    <div class="form-group row">
                        <label for="kode_produk" class="col-lg-2">Kode Produk</label>
                        <div class="col-lg-5">
                            <div class="input-group">
                                <input type="hidden" name="id_pembelian" id="id_pembelian" value="">
                                <input type="hidden" name="id_produk" id="id_produk">
                                <input type="text" class="form-control" name="kode_produk" id="kode_produk">
                                <span class="input-group-btn">
                                    <button onclick="tampilProduk()" class="btn btn-info btn-flat" type="button"><i class="fa fa-arrow-right"></i></button>
                                </span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@includeIf('kartu_stok.produk')
@endsection

@push('scripts')
<script>
    let table;

        function tampilProduk() {
            $('#modal-produk').modal('show');
            $('.table-produk').DataTable()
        }
    
        function hideProduk() {
            $('#modal-produk').modal('hide');
        }
    
        function pilihProduk(id, kode) {
            $('#id_produk').val(id);
            $('#kode_produk').val(kode);
            // window.location.href = `http://localhost/apotekbisma/kartustok/detail/${id}`;
            window.location.href = `http://localhost/apotekbisma/kartustok/detail/${id}`
            hideProduk();
        }
</script>
@endpush