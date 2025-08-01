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

    /* Styling untuk badge stok */
    .badge.bg-green {
        background-color: #00a65a !important;
        color: white;
    }
    
    .badge.bg-yellow {
        background-color: #f39c12 !important;
        color: white;
    }
    
    .badge.bg-red {
        background-color: #dd4b39 !important;
        color: white;
    }
    
    .table-produk td {
        vertical-align: middle;
    }

    .quantity.updating {
        background-color: #fff3cd !important;
        border-color: #ffeaa7 !important;
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

    // Helper function untuk format angka seperti format_uang di PHP
    function formatUang(angka) {
        return new Intl.NumberFormat('id-ID').format(angka);
    }

    // Helper function untuk format angka seperti format_uang di PHP
    function formatUang(angka) {
        return new Intl.NumberFormat('id-ID').format(angka);
    }

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
        table2 = $('.table-produk').DataTable({
            responsive: true,
            processing: true,
            serverSide: false,
            autoWidth: false,
            ajax: {
                url: '{{ route('pembelian_detail.produk_data') }}',
                dataSrc: ''
            },
            columns: [
                {data: 'no', searchable: false, sortable: false},
                {
                    data: 'kode_produk',
                    render: function(data) {
                        return '<span class="label label-success">' + data + '</span>';
                    }
                },
                {data: 'nama_produk'},
                {
                    data: null,
                    render: function(data) {
                        let badgeHtml = '<span class="badge ' + data.stok_badge_class + '">' + 
                                       formatUang(data.stok) + ' unit</span>';
                        
                        if (data.stok_text) {
                            badgeHtml += '<small class="' + data.stok_text_class + '"><br><i class="fa ' + 
                                        data.stok_icon + '"></i> ' + data.stok_text + '</small>';
                        }
                        
                        return badgeHtml;
                    }
                },
                {
                    data: 'harga_beli',
                    render: function(data) {
                        return 'Rp. ' + formatUang(data);
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        return '<a href="#" class="btn btn-primary btn-xs btn-flat" ' +
                               'onclick="pilihProduk(\'' + data.id + '\', \'' + data.kode_produk + '\')">' +
                               '<i class="fa fa-check-circle"></i> Pilih</a>';
                    },
                    searchable: false,
                    sortable: false
                }
            ],
            order: [[2, 'asc']], // Sort by nama_produk
            language: {
                processing: "Memuat data produk...",
                search: "Cari produk:",
                lengthMenu: "Tampilkan _MENU_ produk",
                info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ produk",
                paginate: {
                    first: "Pertama",
                    last: "Terakhir",
                    next: "Selanjutnya",
                    previous: "Sebelumnya"
                }
            }
        });

        // Variable untuk debouncing
        let quantityTimeout;
        let isUpdating = false; // Global flag untuk mencegah multiple updates
        
        // Event change untuk update yang lebih stabil (hanya saat user selesai edit)
        $(document).on('change', '.quantity', function () {
            let $input = $(this);
            let id = $input.data('id');
            let inputValue = $input.val();
            
            // Clear timeout jika ada
            clearTimeout(quantityTimeout);
            
            // Jika input kosong, set ke 1
            if (inputValue === '' || inputValue === '0') {
                $input.val(1);
                inputValue = '1';
            }
            
            let jumlah = parseInt(inputValue);

            if (isNaN(jumlah) || jumlah < 1) {
                $input.val(1);
                jumlah = 1;
                alert('Jumlah tidak boleh kurang dari 1');
            }
            if (jumlah > 10000) {
                $input.val(10000);
                jumlah = 10000;
                alert('Jumlah tidak boleh lebih dari 10000');
            }

            // Update jika nilai berbeda dari nilai asli
            let originalValue = $input.data('original-value') || 1;
            if (jumlah !== originalValue && !isUpdating) {
                updateQuantity($input, id, jumlah);
            }
        });
        
        // Event input hanya untuk validasi real-time tanpa update
        $(document).on('input', '.quantity', function () {
            let $input = $(this);
            let inputValue = $input.val();
            
            // Biarkan input kosong atau angka yang sedang diketik
            if (inputValue === '' || inputValue === '0') {
                return; // Biarkan user mengetik
            }
            
            let jumlah = parseInt(inputValue);

            // Validasi visual tanpa mengirim request
            if (jumlah > 10000) {
                $input.val(10000);
                alert('Jumlah tidak boleh lebih dari 10000');
                return;
            }
        });

        // Event focus untuk menyimpan nilai asli
        $(document).on('focus', '.quantity', function () {
            $(this).data('original-value', parseInt($(this).val()) || 1);
            $(this).select(); // Select all text saat focus untuk memudahkan edit
        });

        // Mencegah form submission saat menekan Enter di input quantity
        $(document).on('keypress', '.quantity', function (e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                $(this).blur(); // Trigger blur untuk validasi dan update
                return false;
            }
        });

        // Fungsi terpisah untuk update quantity
        function updateQuantity($input, id, jumlah) {
            // Cek apakah sedang ada request yang berjalan
            if ($input.data('updating') || isUpdating) {
                return;
            }
            
            // Set flag bahwa sedang update
            $input.data('updating', true);
            isUpdating = true;
            
            // Disable input sementara dan beri indikator visual
            $input.prop('disabled', true).addClass('updating');
            
            $.post(`{{ url('/pembelian_detail') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'jumlah': jumlah
                })
                .done(response => {
                    // Update nilai asli setelah berhasil
                    $input.data('original-value', jumlah);
                    
                    // Update hanya subtotal di kolom yang sama tanpa reload
                    if (response.data && response.data.subtotal) {
                        let $row = $input.closest('tr');
                        let $subtotalCell = $row.find('td').eq(6); // Kolom subtotal (index 6)
                        if ($subtotalCell.length) {
                            $subtotalCell.text('Rp. ' + formatUang(response.data.subtotal));
                        }
                    }
                    
                    // Update form summary tanpa reload tabel
                    setTimeout(() => {
                        loadForm($('#diskon').val());
                    }, 100);
                })
                .fail(errors => {
                    // Kembalikan ke nilai asli jika gagal
                    let originalValue = $input.data('original-value') || 1;
                    $input.val(originalValue);
                    
                    let errorMessage = 'Tidak dapat menyimpan data';
                    
                    if (errors.status === 500) {
                        errorMessage = 'Terjadi kesalahan pada server';
                    } else if (errors.responseJSON && errors.responseJSON.message) {
                        errorMessage = errors.responseJSON.message;
                    } else if (errors.responseText) {
                        try {
                            let errorObj = JSON.parse(errors.responseText);
                            errorMessage = errorObj.message || errorMessage;
                        } catch (e) {
                            errorMessage = errors.responseText;
                        }
                    }
                    
                    alert(errorMessage);
                    
                    // Reload form untuk memastikan konsistensi tanpa reload tabel
                    setTimeout(() => {
                        loadForm($('#diskon').val());
                    }, 100);
                })
                .always(() => {
                    // Enable input kembali dan hapus flag updating
                    $input.prop('disabled', false).removeClass('updating');
                    $input.data('updating', false);
                    isUpdating = false;
                });
        }
        
        // ===== EXISTING HANDLERS FOR OTHER FIELDS =====
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
        // Refresh data produk untuk mendapatkan stok terbaru
        if (table2) {
            table2.ajax.reload(null, false); // Reload tanpa reset halaman
        }
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
                // Reload tabel pembelian detail
                table.ajax.reload(() => loadForm($('#diskon').val()));
                
                // Refresh data produk di modal untuk update stok dengan delay kecil
                setTimeout(() => {
                    if (table2) {
                        table2.ajax.reload(null, false); // false = keep current page
                    }
                }, 100);
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
                    // Refresh data produk di modal untuk update stok
                    if (table2) {
                        table2.ajax.reload();
                    }
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