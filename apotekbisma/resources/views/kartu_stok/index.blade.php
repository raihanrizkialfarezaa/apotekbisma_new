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
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-archive"></i> Kartu Stok Produk</h3>
            </div>
            <div class="box-body">
                <form class="form-produk">
                    @csrf
                    <div class="form-group row">
                        <label for="kode_produk" class="col-lg-2 control-label">Pilih Produk</label>
                        <div class="col-lg-8">
                            <div class="input-group">
                                <input type="hidden" name="id_produk" id="id_produk">
                                <input type="text" class="form-control" name="kode_produk" id="kode_produk" 
                                       placeholder="Klik tombol untuk memilih produk..." readonly>
                                <span class="input-group-btn">
                                    <button onclick="tampilProduk()" class="btn btn-primary btn-flat" type="button">
                                        <i class="fa fa-search"></i> Pilih Produk
                                    </button>
                                </span>
                            </div>
                            <small class="help-block">Pilih produk untuk melihat riwayat pergerakan stok</small>
                        </div>
                    </div>
                </form>
                
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> 
                    <strong>Petunjuk:</strong> Klik tombol "Pilih Produk" untuk memilih produk yang ingin dilihat kartu stoknya.
                    Kartu stok menampilkan riwayat pergerakan stok masuk dan keluar beserta saldo akhir.
                </div>
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
        if (!$.fn.DataTable.isDataTable('.table-produk')) {
            $('.table-produk').DataTable({
                responsive: {
                    details: {
                        type: 'column',
                        target: 'tr'
                    }
                },
                searching: true,
                paging: true,
                scrollX: true,
                scrollCollapse: true,
                autoWidth: false,
                language: {
                    search: "Cari produk:",
                    lengthMenu: "Tampilkan _MENU_ data",
                    info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
                    paginate: {
                        first: "Pertama",
                        last: "Terakhir", 
                        next: "Selanjutnya",
                        previous: "Sebelumnya"
                    }
                }
            });
        }
    }

    function hideProduk() {
        $('#modal-produk').modal('hide');
    }

    function pilihProduk(id, kode, nama) {
        $('#id_produk').val(id);
        $('#kode_produk').val(kode + ' - ' + nama);
        
        // Use Laravel route helper
        window.location.href = "{{ route('kartu_stok.detail', ':id') }}".replace(':id', id);
        
        hideProduk();
    }
</script>
@endpush