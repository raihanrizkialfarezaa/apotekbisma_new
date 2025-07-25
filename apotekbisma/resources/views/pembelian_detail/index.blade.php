@extends('layouts.master')

@section('title')
    Transaksi Pembelian
@endsection

@push('css')
<style>
    .tampil-bayar {
        font-size: 5em;
        text-align: center;
        height: 100px;
    }

    .tampil-terbilang {
        padding: 10px;
        background: #f0f0f0;
    }

    .table-pembelian tbody tr:last-child {
        display: none;
    }

    @media(max-width: 768px) {
        .tampil-bayar {
            font-size: 3em;
            height: 70px;
            padding-top: 5px;
        }
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Transaksi Pembelian</li>
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
                <table>
                    <tr>
                        <td>Supplier</td>
                        <td>: {{ $supplier->nama }}</td>
                    </tr>
                    <tr>
                        <td>Telepon</td>
                        <td>: {{ $supplier->telepon }}</td>
                    </tr>
                    <tr>
                        <td>Alamat</td>
                        <td>: {{ $supplier->alamat }}</td>
                    </tr>
                </table>
            </div>
            <div class="box-body">
                    
                <form class="form-produk">
                    @csrf
                    <div class="form-group row">
                        <label for="kode_produk" class="col-lg-2">Kode Produk</label>
                        <div class="col-lg-5">
                            <div class="input-group">
                                <input type="hidden" name="id_pembelian" id="id_pembelian" value="{{ $id_pembelian }}">
                                <input type="hidden" name="id_produk" id="id_produk">
                                <input type="text" class="form-control" name="kode_produk" id="kode_produk">
                                <span class="input-group-btn">
                                    <button onclick="tampilProduk()" class="btn btn-info btn-flat" type="button"><i class="fa fa-arrow-right"></i></button>
                                </span>
                            </div>
                        </div>
                    </div>
                </form>

                <table class="table table-stiped table-bordered table-pembelian">
                    <thead>
                        <th width="5%">No</th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Harga Beli</th>
                        <th>Harga Jual</th>
                        <th>Expired Date</th>
                        <th>Batch</th>
                        <th width="15%">Jumlah</th>
                        <th>Subtotal</th>
                        <th width="15%"><i class="fa fa-cog"></i></th>
                    </thead>
                </table>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="tampil-bayar bg-primary"></div>
                        <div class="tampil-terbilang"></div>
                    </div>
                    <div class="col-lg-4">
                        <form action="{{ route('pembelian.store') }}" class="form-pembelian" method="post">
                            @csrf
                            <input type="hidden" name="id_pembelian" value="{{ $id_pembelian }}">
                            <input type="hidden" name="total" id="total">
                            <input type="hidden" name="total_item" id="total_item">
                            <input type="hidden" name="bayar" id="bayar">

                            <div class="form-group row">
                                <label for="totalrp" class="col-lg-2 control-label">Total</label>
                                <div class="col-lg-8">
                                    <input type="text" id="totalrp" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="totalrp" class="col-lg-2 control-label">Tanggal Faktur Dibuat</label>
                                <div class="col-lg-8">
                                    <input type="date" name="waktu" id="totalrp" class="form-control waktu">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="nomor_faktur" class="col-lg-2 control-label">Nomor Faktur</label>
                                <div class="col-lg-8">
                                    <input type="text" name="nomor_faktur" id="nomor_faktur" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="diskon" class="col-lg-2 control-label">Diskon</label>
                                <div class="col-lg-8">
                                    <input type="number" name="diskon" id="diskon" class="form-control" value="{{ $diskon }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="bayar" class="col-lg-2 control-label">Bayar</label>
                                <div class="col-lg-8">
                                    <input type="text" id="bayarrp" class="form-control">
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="box-footer">
                <button type="button" class="btn btn-info btn-sm btn-flat pull-left btn-cetak" onclick="printReceipt()" style="display: none;"><i class="fa fa-print"></i> Cetak Bukti</button>
                <button type="submit" class="btn btn-primary btn-sm btn-flat pull-right btn-simpan"><i class="fa fa-floppy-o"></i> Simpan Transaksi</button>
            </div>
        </div>
    </div>
</div>

@includeIf('pembelian_detail.produk')
@endsection

@push('scripts')
<script>
    const date = new Date();
    const today = date.toISOString().substring(0, 10);
    console.log(today);
    document.querySelector('.waktu').value = today; 
    let table, table2;

    $(function () {
        $('body').addClass('sidebar-collapse');

        table = $('.table-pembelian').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            ajax: {
                url: '{{ route('pembelian_detail.data', $id_pembelian) }}',
            },
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'harga_beli'},
                {data: 'harga_jual'},
                {data: 'expired_date'},
                {data: 'batch'},
                {data: 'jumlah'},
                {data: 'subtotal'},
                {data: 'aksi', searchable: false, sortable: false},
            ],
            dom: 'Brt',
            bSort: false,
            paginate: false
        })
        .on('draw.dt', function () {
            loadForm($('#diskon').val());
        });
        table2 = $('.table-produk').DataTable();

        $(document).on('input', '.quantity', function () {
            let id = $(this).data('id');
            let jumlah = parseInt($(this).val());

            if (jumlah < 1) {
                $(this).val(1);
                alert('Jumlah tidak boleh kurang dari 1');
                return;
            }
            if (jumlah > 10000) {
                $(this).val(10000);
                alert('Jumlah tidak boleh lebih dari 10000');
                return;
            }

            $.post(`{{ url('/pembelian_detail') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'jumlah': jumlah
                })
                .done(response => {
                    $(this).on('mouseout', function () {
                        console.log(id);
                        table.ajax.reload(() => loadForm($('#diskon').val()));
                    });
                })
                .fail(errors => {
                    alert('Tidak dapat menyimpan data');
                    return;
                });
        });
        $(document).on('input', '.harga_jual', function () {
            let id = $(this).data('id');
            let harga_jual = parseInt($(this).val());
            let id_pembelian_detail = $(this).data('uid');
            let jumlah = parseInt($('.quantity').val());
            console.log(id_pembelian_detail);

            if (harga_jual < 1) {
                $(this).val(1);
                alert('Harga tidak boleh kurang dari Rp. 1');
                return;
            }

            $.post(`{{ url('/updateHargaJual') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'harga_jual': harga_jual,
                    'id_pembayaran_detail': id_pembelian_detail,
                    'jumlah': jumlah
                })
                .done(response => {
                    $(this).on('mouseout', function () {
                        table.ajax.reload(() => loadForm($('#diskon').val()));
                    });
                })
                .fail(errors => {
                    alert('Tidak dapat menyimpan data');
                    return;
                });
        });
        $(document).on('input', '.harga_beli', function () {
            let id = $(this).data('id');
	        console.log($(this).data('id'));
            let id_pembelian_detail = $(this).data('uid');
            console.log(id_pembelian_detail);
            let harga_beli = parseInt($(this).val());
            let jumlah = parseInt($('.quantity').val());

            if (harga_beli < 1) {
                $(this).val(1);
                alert('Harga tidak boleh kurang dari Rp. 1');
                return;
            }

            $.post(`{{ url('/updateHargaBeli') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'harga_beli': harga_beli,
                    'id_pembayaran_detail': id_pembelian_detail,
                    'jumlah': jumlah
                })
                .done(response => {
                    $(this).on('mouseout', function () {
                        table.ajax.reload(() => loadForm($('#diskon').val()));
                    });
                })
                .fail(errors => {
                    alert('Tidak dapat menyimpan data');
                    return;
                });
        });
        $(document).on('input', '.expired_date', function () {
            let id = $(this).data('id');
	        console.log($(this).data('id'));
            let id_pembelian_detail = $(this).data('uid');
            console.log(id_pembelian_detail);
            let expired_date = $(this).val().toString();
            console.log(expired_date);
            let jumlah = parseInt($('.quantity').val());
            if(expired_date == null) {
                console.log("cant update");
                return;
            }
            $.post(`{{ url('/updateExpiredDate') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'expired_date': expired_date,
                })
                .done(response => {
                    $(this).on('mouseout', function () {
                        table.ajax.reload(() => loadForm($('#diskon').val()));
                    });
                })
                .fail(errors => {
                    alert('Tidak dapat menyimpan data');
                    return;
                });
        });
        $(document).on('input', '.batch', function () {
            let id = $(this).data('id');
	        console.log($(this).data('id'));
            let id_pembelian_detail = $(this).data('uid');
            console.log(id_pembelian_detail);
            let batch = $(this).val();
            let jumlah = parseInt($('.quantity').val());
            $.post(`{{ url('/updateBatch') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'batch': batch,
                })
                .done(response => {
                    $(this).on('mouseout', function () {
                        table.ajax.reload(() => loadForm($('#diskon').val()));
                    });
                })
                .fail(errors => {
                    alert('Tidak dapat menyimpan data');
                    return;
                });
        });

        $(document).on('input', '#diskon', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }

            loadForm($(this).val());
        });

        $('.btn-simpan').on('click', function () {
            // Validasi form sebelum submit
            if (!validateForm()) {
                return false;
            }
            // Set flag bahwa form sudah di-submit
            window.isFormSubmitted = true;
            $('.form-pembelian').submit();
        });

        // Hanya hapus transaksi jika benar-benar kosong dan user keluar
        window.addEventListener('beforeunload', function (e) {
            // Jangan hapus jika form sudah di-submit atau user baru saja menambah produk
            if (!window.isFormSubmitted && isTransactionEmpty()) {
                deleteIncompleteTransaction();
            }
        });

        // Tambahkan event listener untuk navigasi browser
        window.addEventListener('pagehide', function (e) {
            if (!window.isFormSubmitted && isTransactionEmpty()) {
                deleteIncompleteTransaction();
            }
        });
    });

    function validateForm() {
        let isValid = true;
        let errorMessage = '';
        
        // Cek apakah ada produk yang ditambahkan
        let totalItem = parseInt($('#total_item').val()) || 0;
        if (totalItem === 0) {
            errorMessage += '- Minimal harus ada 1 produk yang ditambahkan\n';
            isValid = false;
        }
        
        // Cek nomor faktur
        let nomorFaktur = $('input[name="nomor_faktur"]').val().trim();
        if (!nomorFaktur) {
            errorMessage += '- Nomor faktur harus diisi\n';
            isValid = false;
        }
        
        // Cek apakah semua produk memiliki jumlah > 0
        let hasZeroQuantity = false;
        $('.quantity').each(function() {
            if (parseInt($(this).val()) <= 0) {
                hasZeroQuantity = true;
                return false;
            }
        });
        
        if (hasZeroQuantity) {
            errorMessage += '- Semua produk harus memiliki jumlah lebih dari 0\n';
            isValid = false;
        }
        
        if (!isValid) {
            alert('Transaksi tidak dapat disimpan:\n' + errorMessage);
        }
        
        return isValid;
    }

    function isTransactionComplete() {
        let totalItem = parseInt($('#total_item').val()) || 0;
        let nomorFaktur = $('input[name="nomor_faktur"]').val().trim();
        let totalHarga = parseFloat($('#total').val()) || 0;
        
        // Transaksi dianggap lengkap jika semua syarat terpenuhi
        return totalItem > 0 && nomorFaktur !== '' && totalHarga > 0;
    }

    function isTransactionEmpty() {
        let totalItem = parseInt($('#total_item').val()) || 0;
        let nomorFaktur = $('input[name="nomor_faktur"]').val().trim();
        let totalHarga = parseFloat($('#total').val()) || 0;
        
        // Transaksi dianggap kosong jika tidak ada produk DAN tidak ada nomor faktur
        return totalItem === 0 && nomorFaktur === '' && totalHarga === 0;
    }

    function deleteIncompleteTransaction() {
        let idPembelian = $('#id_pembelian').val();
        if (idPembelian) {
            // Gunakan route khusus untuk menghapus transaksi kosong
            fetch('{{ route("pembelian.destroyEmpty", "") }}/' + idPembelian, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': $('[name=csrf-token]').attr('content')
                }
            }).catch(function(error) {
                console.log('Error deleting incomplete transaction:', error);
            });
        }
    }

    function tampilProduk() {
        $('#modal-produk').modal('show');
    }

    function hideProduk() {
        $('#modal-produk').modal('hide');
    }

    function pilihProduk(id, kode) {
        $('#id_produk').val(id);
        $('#kode_produk').val(kode);
        hideProduk();
        tambahProduk();
    }

    function tambahProduk() {
        $.post('{{ route('pembelian_detail.store') }}', $('.form-produk').serialize())
            .done(response => {
                $('#kode_produk').focus();
                table.ajax.reload(() => loadForm($('#diskon').val()));
            })
            .fail(errors => {
                alert('Tidak dapat menyimpan data');
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
                    table.ajax.reload(() => loadForm($('#diskon').val()));
                })
                .fail((errors) => {
                    alert('Tidak dapat menghapus data');
                    return;
                });
        }
    }

    function loadForm(diskon = 0) {
        $('#total').val($('.total').text());
        $('#total_item').val($('.total_item').text());

        $.get(`{{ url('/pembelian_detail/loadform') }}/${diskon}/${$('.total').text()}`)
            .done(response => {
                $('#totalrp').val('Rp. '+ response.totalrp);
                $('#bayarrp').val('Rp. '+ response.bayarrp);
                $('#bayar').val(response.bayar);
                $('.tampil-bayar').text('Rp. '+ response.bayarrp);
                $('.tampil-terbilang').text(response.terbilang);
                
                // Tampilkan tombol cetak jika transaksi sudah lengkap
                if (isTransactionComplete()) {
                    $('.btn-cetak').show();
                } else {
                    $('.btn-cetak').hide();
                }
            })
            .fail(errors => {
                alert('Tidak dapat menampilkan data');
                return;
            })
    }

    function printReceipt() {
        let idPembelian = $('#id_pembelian').val();
        if (idPembelian && isTransactionComplete()) {
            if (confirm('Cetak bukti pembelian?')) {
                window.open('{{ route("pembelian.print", "") }}/' + idPembelian, '_blank');
            }
        } else {
            alert('Transaksi belum lengkap. Pastikan ada produk dan nomor faktur sudah diisi.');
        }
    }
</script>
@endpush