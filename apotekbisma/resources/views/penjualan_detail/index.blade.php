@extends('layouts.master')

@section('title')
    Transaksi Penjualan
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
        border: 1px solid #ccc;
        margin-top: 5px;
    }
        
    .table-penjualan tbody tr:last-child {
        display: none;
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
    <li class="active">Transaksi Penjualan</li>
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
            <div class="box-body">
                    
                <form class="form-produk">
                    @csrf
                    <div class="form-group row">
                        <label for="kode_produk" class="col-lg-2">Kode Produk</label>
                        <div class="col-lg-5">
                            <div class="input-group">
                                <input type="hidden" name="id_penjualan" value="{{ $id_penjualan }}">
                                <input type="hidden" name="id_produk" id="id_produk">
                                <input type="text" class="form-control" name="kode_produk" id="kode_produk">
                                <span class="input-group-btn">
                                    <button onclick="tampilProduk()" class="btn btn-info btn-flat" type="button"><i class="fa fa-arrow-down"></i></button>
                                </span>
                            </div>
                        </div>
                    </div>
                </form>

                <table class="table table-stiped table-bordered table-penjualan">
                    <thead>
                        <th width="5%">No</th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Harga</th>
                        <th width="15%">Jumlah</th>
                        <th>Diskon</th>
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
                        <form action="{{ route('transaksi.simpan') }}" class="form-penjualan" method="post">
                            @csrf
                            <input type="hidden" name="id_penjualan" value="{{ $id_penjualan }}">
                            <input type="hidden" name="total" id="total">
                            <input type="hidden" name="total_item" id="total_item">
                            <input type="hidden" name="bayar" id="bayar">
                            <input type="hidden" name="id_member" id="id_member" value="{{ $memberSelected->id_member ?? '' }}">

                            <div class="form-group row">
                                <label for="totalrp" class="col-lg-2 control-label">Total</label>
                                <div class="col-lg-8">
                                    <input type="text" id="totalrp" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="waktu_transaksi" class="col-lg-2 control-label">Waktu Transaksi</label>
                                <div class="col-lg-8">
                                    <input type="date" id="waktu_transaksi" class="form-control waktu" name="waktu" value="{{ isset($penjualan->waktu) && $penjualan->waktu ? \Carbon\Carbon::parse($penjualan->waktu)->format('Y-m-d') : date('Y-m-d') }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="kode_member" class="col-lg-2 control-label">Member</label>
                                <div class="col-lg-8">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="kode_member" value="{{ $memberSelected->kode_member ?? '' }}">
                                        <span class="input-group-btn">
                                            <button onclick="tampilMember()" class="btn btn-info btn-flat" type="button"><i class="fa fa-arrow-right"></i></button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="diskon" class="col-lg-2 control-label">Diskon</label>
                                <div class="col-lg-8">
                                    <input type="number" name="diskon" id="diskon" class="form-control" 
                                        value="{{ ! empty($memberSelected->id_member) ? $diskon : 0 }}" 
                                        readonly>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="bayar" class="col-lg-2 control-label">Bayar</label>
                                <div class="col-lg-8">
                                    <input type="text" id="bayarrp" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="diterima" class="col-lg-2 control-label">Diterima</label>
                                <div class="col-lg-8">
                                    <input type="number" id="diterima" class="form-control" name="diterima" value="{{ $penjualan->diterima ?? 0 }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="kembali" class="col-lg-2 control-label">Kembali</label>
                                <div class="col-lg-8">
                                    <input type="text" id="kembali" name="kembali" class="form-control" value="Rp. 0" readonly>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="box-footer">
                <button type="submit" class="btn btn-primary btn-sm btn-flat pull-right btn-simpan"><i class="fa fa-floppy-o"></i> Simpan Transaksi</button>
            </div>
        </div>
    </div>
</div>

@includeIf('penjualan_detail.produk')
@includeIf('penjualan_detail.member')
@endsection

@push('scripts')
<script>
    let table, table2;
    let userEditedDiterima = false;
    let totalUpdateTimeout = null;
    let loadFormTimeout = null;
    let isLoadingForm = false;

    $(function () {
        $('body').addClass('sidebar-collapse');
        
        // Fungsi untuk memastikan tanggal selalu terisi
        function ensureDateFilled() {
            const waktuInput = document.getElementById('waktu_transaksi');
            if (waktuInput) {
                console.log('Current date value:', waktuInput.value);
                if (!waktuInput.value || waktuInput.value === '') {
                    const today = new Date();
                    const todayString = today.getFullYear() + '-' + 
                        String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                        String(today.getDate()).padStart(2, '0');
                    waktuInput.value = todayString;
                    console.log('Date set to:', todayString);
                } else {
                    console.log('Date already filled:', waktuInput.value);
                }
            }
        }
        
        // Set tanggal saat halaman dimuat
        ensureDateFilled();
        
        // Set tanggal setiap 2 detik untuk memastikan tidak kosong
        setInterval(ensureDateFilled, 2000);

    // Helper function untuk format angka seperti format_uang di PHP
    function formatUang(angka) {
        return new Intl.NumberFormat('id-ID').format(angka);
    }

    // Fungsi untuk menghitung ulang total dari tabel - definisikan lebih awal (exposed globally)
    window.updateTotalFromTable = function() {
        // Hitung segera di client untuk responsifitas (synchronous)
        let newTotal = 0;
        let newTotalItem = 0;

        $('.table-penjualan tbody tr').each(function(index) {
            let $row = $(this);
            if ($row.find('.total').length > 0) {
                return;
            }
            let quantity = parseInt($row.find('.quantity').val()) || 0;
            newTotalItem += quantity;

            let subtotalText = $row.find('td').eq(6).text();
            if (subtotalText && subtotalText.includes('Rp. ')) {
                let cleanText = subtotalText.replace('Rp. ', '').replace(/\./g, '').replace(',', '.');
                let subtotalValue = parseFloat(cleanText) || 0;
                newTotal += subtotalValue;
            }
        });

    $('#total').val(newTotal);
    $('#total_item').val(newTotalItem);

    let currentDiskon = parseFloat($('#diskon').val()) || 0;

    let bayar = newTotal - (currentDiskon / 100 * newTotal);

    bayar = Number(bayar) || 0;

    if (!userEditedDiterima) {
        $('#diterima').val(bayar);
    }

    let diterimaVal = Number($('#diterima').val()) || 0;

    let kembali = diterimaVal - bayar;

    $('#totalrp').val('Rp. ' + new Intl.NumberFormat('id-ID').format(newTotal));
    $('#bayarrp').val('Rp. ' + new Intl.NumberFormat('id-ID').format(bayar));
    $('#bayar').val(bayar);
    $('#kembali').val('Rp. ' + new Intl.NumberFormat('id-ID').format(kembali));
    if (kembali > 0) {
        $('.tampil-bayar').text('Kembali: Rp. ' + new Intl.NumberFormat('id-ID').format(kembali));
    } else {
        $('.tampil-bayar').text('Bayar: Rp. ' + new Intl.NumberFormat('id-ID').format(bayar));
    }

    // Debounce pemanggilan server untuk terbilang/format agar tidak berulang-ulang
    if (loadFormTimeout) {
        clearTimeout(loadFormTimeout);
    }
    loadFormTimeout = setTimeout(() => {
        loadForm(currentDiskon, parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0, function() {
            syncDiterimaWithBayar();
        });

        loadFormTimeout = null;

    }, 60);
}

    // Fungsi untuk auto-update diterima mengikuti bayar (exposed globally)
    window.syncDiterimaWithBayar = function() {
        let currentBayar = parseFloat($('#bayar').val()) || 0;
        let currentDiterima = parseFloat($('#diterima').val()) || 0;

        if (isNaN(currentDiterima) || currentDiterima === 0 || currentDiterima < currentBayar) {
            $('#diterima').val(currentBayar);
        }
    };

        @if($id_penjualan)
        table = $('.table-penjualan').DataTable({
            responsive: true,
            processing: false,
            serverSide: false,
            autoWidth: false,
            ajax: {
                url: '{{ route('transaksi.data', $id_penjualan) }}',
                dataSrc: 'data'
            },
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'harga_jual'},
                {data: 'jumlah'},
                {data: 'diskon'},
                {data: 'subtotal'},
                {data: 'aksi', searchable: false, sortable: false},
            ],
            dom: 'Brt',
            bSort: false,
            paginate: false
        })
        .on('draw.dt', function () {
            updateTotalFromTable();
        });

        // Ensure totals are correct after initial load; sometimes DataTables async load
        // can race with other scripts, so force a reload and update in the callback.
        table.ajax.reload(function() {
            userEditedDiterima = false;
            updateTotalFromTable();
            // Pastikan total dikalkulasi terlebih dahulu, lalu kirim (diskon, total, diterima)
            loadForm($('#diskon').val(), parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0);
        });
        @else
        // Inisialisasi tabel kosong untuk transaksi baru
        table = $('.table-penjualan').DataTable({
            responsive: true,
            data: [],
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'harga_jual'},
                {data: 'jumlah'},
                {data: 'diskon'},
                {data: 'subtotal'},
                {data: 'aksi', searchable: false, sortable: false},
            ],
            dom: 'Brt',
            bSort: false,
            paginate: false
        });
        @endif

        table2 = $('.table-produk').DataTable({
            responsive: true,
            processing: true,
            serverSide: false,
            autoWidth: false,
            ajax: {
                url: '{{ route('transaksi.produk_data') }}',
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
                    data: 'harga_jual',
                    render: function(data) {
                        return 'Rp. ' + formatUang(data);
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        return '<a href="#" class="btn btn-primary btn-xs btn-flat" ' +
                               'onclick="pilihProduk(\'' + data.id + '\', \'' + data.kode_produk + '\', \'' + data.stok + '\')">' +
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

        // Inisialisasi form berdasarkan kondisi transaksi
        @if($id_penjualan)
            loadForm($('#diskon').val());
        @else
            $('.btn-simpan').prop('disabled', true).addClass('disabled');
            $('.btn-simpan').html('<i class="fa fa-plus"></i> Tambahkan Produk Terlebih Dahulu');
        @endif

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
            
            $.post(`{{ url('/transaksi') }}/${id}`, {
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
                    
                    // Reset flag user edit sehingga nilai diterima/kembali otomatis mengikuti perubahan keranjang
                    userEditedDiterima = false;
                    // Update total dan total_item secara manual setelah perubahan subtotal
                    updateTotalFromTable();
                    
                    // Refresh data produk di modal untuk update stok
                    if (table2) {
                        table2.ajax.reload(null, false);
                    }
                })
                .fail(errors => {
                    // Kembalikan ke nilai asli jika gagal
                    let originalValue = $input.data('original-value') || 1;
                    $input.val(originalValue);
                    
                    let errorMessage = 'Tidak dapat menyimpan data';
                    
                    if (errors.status === 400 || errors.status === 500) {
                        if (errors.responseJSON && errors.responseJSON.message) {
                            if (errors.responseJSON.message.includes('Tidak dapat mengubah jumlah') ||
                                errors.responseJSON.message.includes('Stok tersedia') ||
                                errors.responseJSON.message.includes('Maksimal untuk item ini')) {
                                errorMessage = '❌ JUMLAH MELEBIHI STOK!\n\n' + errors.responseJSON.message;
                            } else {
                                errorMessage = errors.responseJSON.message;
                            }
                        } else if (errors.responseText) {
                            try {
                                let errorObj = JSON.parse(errors.responseText);
                                errorMessage = errorObj.message || errorMessage;
                            } catch (e) {
                                errorMessage = errors.responseText;
                            }
                        }
                    } else if (errors.responseText) {
                        try {
                            let errorObj = JSON.parse(errors.responseText);
                            errorMessage = errorObj.message || errorMessage;
                        } catch (e) {
                            errorMessage = errors.responseText;
                        }
                    }
                    
                    alert(errorMessage);
                    
                    // Reload form dan update total untuk memastikan konsistensi tanpa reload tabel
                    updateTotalFromTable();
                })
                .always(() => {
                    // Enable input kembali dan hapus flag updating
                    $input.prop('disabled', false).removeClass('updating');
                    $input.data('updating', false);
                    isUpdating = false;
                });
        }

        $(document).on('input', '#diskon', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }

            // Pastikan total ter-update sebelum loadForm
            updateTotalFromTable();
        });

        $('#diterima').on('input', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }
            userEditedDiterima = true;
            let currentDiskon = parseFloat($('#diskon').val()) || 0;
            let currentTotal = parseFloat($('#total').val()) || 0;
            let val = parseFloat($(this).val()) || 0;
            loadForm(currentDiskon, currentTotal, val);
        }).focus(function () {
            $(this).select();
        });

        $('.btn-simpan').on('click', function (e) {
            e.preventDefault();
            
            // Jika tombol disabled, jangan lakukan apa-apa
            if ($(this).prop('disabled')) {
                return false;
            }
            
            // Pastikan tanggal terisi sebelum submit
            const waktuInput = document.getElementById('waktu_transaksi');
            if (waktuInput && (!waktuInput.value || waktuInput.value === '')) {
                const today = new Date();
                const todayString = today.getFullYear() + '-' + 
                    String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(today.getDate()).padStart(2, '0');
                waktuInput.value = todayString;
            }
            
            // Validasi apakah ada item di transaksi
            if ($('.total_item').text() == '' || $('.total_item').text() == '0') {
                alert('Minimal harus ada 1 produk yang ditambahkan ke transaksi');
                return false;
            }
            
            // Validasi apakah jumlah diterima sudah diisi
            if ($('#diterima').val() == '' || $('#diterima').val() == '0') {
                alert('Jumlah yang diterima harus diisi dan tidak boleh 0');
                $('#diterima').focus();
                return false;
            }
            
            // Validasi apakah jumlah diterima cukup
            let total_bayar = parseInt($('#bayar').val());
            let diterima = parseInt($('#diterima').val());
            
            if (diterima < total_bayar) {
                alert('Jumlah yang diterima tidak boleh kurang dari total bayar (Rp. ' + $('#bayarrp').val() + ')');
                $('#diterima').focus();
                return false;
            }
            
            // Jika semua validasi berhasil, submit form
            $('.form-penjualan').submit();
        });
    });

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

    function pilihProduk(id, kode, stok) {
        // Validasi stok sebelum menambahkan produk
        if (stok <= 0) {
            alert('❌ STOK HABIS!\n\nProduk tidak dapat dijual karena stok sudah habis (0).\nSilakan lakukan pembelian terlebih dahulu.');
            return;
        }
        
        // Peringatan jika stok menipis
        if (stok <= 3) {
            const confirmation = confirm(`⚠️ PERINGATAN STOK MENIPIS!\n\nStok tersisa: ${stok} unit\n\nApakah Anda yakin ingin menambahkan produk ini ke keranjang?`);
            if (!confirmation) {
                return;
            }
        }
        
        $('#id_produk').val(id);
        $('#kode_produk').val(kode);
        hideProduk();
        tambahProduk();
    }

    function tambahProduk() {
        console.log('[tambahProduk] called, id_produk:', $('#id_produk').val(), 'kode_produk:', $('#kode_produk').val());
        // Pastikan tanggal terisi sebelum menambah produk
        const waktuInput = document.getElementById('waktu_transaksi');
        if (waktuInput && (!waktuInput.value || waktuInput.value === '')) {
            const today = new Date();
            const todayString = today.getFullYear() + '-' + 
                String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                String(today.getDate()).padStart(2, '0');
            waktuInput.value = todayString;
        }
        
        $.post('{{ route('transaksi.store') }}', $('.form-produk').serialize())
            .done(response => {
                console.log('[tambahProduk].done response:', response);
                $('#kode_produk').val('').focus();
                
                @if($id_penjualan)
                    table.ajax.reload(() => {
                        userEditedDiterima = false;
                        updateTotalFromTable();
                    });
                @else
                    if (response.id_penjualan && typeof table !== 'undefined' && table) {
                        console.log('Reinitializing table for new id_penjualan', response.id_penjualan);
                        // Update hidden input dengan ID penjualan baru di semua form
                        $('input[name="id_penjualan"]').val(response.id_penjualan);
                        $('.form-penjualan input[name="id_penjualan"]').val(response.id_penjualan);
                        
                        try {
                            table.destroy();
                        } catch(e) {
                            console.warn('table.destroy failed', e);
                        }
                        
                        table = $('.table-penjualan').DataTable({
                            responsive: true,
                            processing: false,
                            serverSide: false,
                            autoWidth: false,
                            ajax: {
                                url: '{{ url('transaksi_detail') }}/' + response.id_penjualan + '/data',
                                dataSrc: 'data'
                            },
                            columns: [
                                {data: 'DT_RowIndex', searchable: false, sortable: false},
                                {data: 'kode_produk'},
                                {data: 'nama_produk'},
                                {data: 'harga_jual'},
                                {data: 'jumlah'},
                                {data: 'diskon'},
                                {data: 'subtotal'},
                                {data: 'aksi', searchable: false, sortable: false},
                            ],
                            dom: 'Brt',
                            bSort: false,
                            paginate: false
                        })
                        .on('draw.dt', function () {
                            updateTotalFromTable();
                        });
                        // Force initial load and update after table creation
                        table.ajax.reload(function() {
                            console.log('table.ajax.reload callback after create');
                            userEditedDiterima = false;
                            updateTotalFromTable();
                            // Pass total explicitly to loadForm: (diskon, total, diterima)
                            loadForm($('#diskon').val(), parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0);
                        });
                        
                        if ($('.btn-simpan').prop('disabled')) {
                            $('.btn-simpan').prop('disabled', false).removeClass('disabled');
                            $('.btn-simpan').html('<i class="fa fa-floppy-o"></i> Simpan Transaksi');
                        }
                    }
                @endif
                
                if (table2) {
                    table2.ajax.reload(null, false);
                }
                
                if ($('.btn-simpan').prop('disabled')) {
                    $('.btn-simpan').prop('disabled', false).removeClass('disabled');
                    $('.btn-simpan').html('<i class="fa fa-floppy-o"></i> Simpan Transaksi');
                }
            })
            .fail(errors => {
                let errorMessage = 'Tidak dapat menyimpan data';
                
                if (errors.responseText) {
                    const responseText = errors.responseText;
                    if (responseText.includes('Stok habis') || 
                        responseText.includes('Stok tidak cukup') || 
                        responseText.includes('Tidak dapat menambah produk') ||
                        responseText.includes('Maksimal dapat ditambah')) {
                        errorMessage = '❌ GAGAL MENAMBAH PRODUK\n\n' + responseText;
                    }
                }
                
                alert(errorMessage);
                return;
            });
    }

    function tampilMember() {
        $('#modal-member').modal('show');
    }

    function pilihMember(id, kode) {
        $('#id_member').val(id);
        $('#kode_member').val(kode);
        $('#diskon').val('{{ $diskon }}');
        // Pastikan total ter-update sebelum loadForm
        updateTotalFromTable();
        hideMember();
    }

    function hideMember() {
        $('#modal-member').modal('hide');
    }

    function deleteData(url) {
        if (confirm('Yakin ingin menghapus data terpilih?')) {
            $.post(url, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'delete'
                })
                .done((response) => {
                    try {
                        // Extract id from URL suffix (last segment)
                        const parts = url.split('/');
                        const id = parts[parts.length - 1];
                        // Find the row that contains input.quantity with data-id == id
                        let row = null;
                        $('.table-penjualan tbody tr').each(function() {
                            const $q = $(this).find('.quantity');
                            if ($q.length && $q.data('id') == id) {
                                row = this;
                            }
                        });
                        if (row) {
                            table.row(row).remove().draw(false);
                            // After draw completes, update totals
                            table.on('draw', function handler() {
                                updateTotalFromTable();
                                table.off('draw', handler);
                            });
                        } else {
                            // Fallback: reload full table then update totals
                            table.ajax.reload(function() {
                                updateTotalFromTable();
                            });
                        }
                    } catch (e) {
                        table.ajax.reload();
                    }

                    userEditedDiterima = false;
                    // If draw/reload callbacks didn't run, ensure totals update once after a short tick
                    setTimeout(() => {
                        updateTotalFromTable();
                    }, 0);

                    // Refresh data produk di modal untuk update stok
                    if (table2) {
                        table2.ajax.reload(null, false);
                    }
                })
                .fail((errors) => {
                    alert('Tidak dapat menghapus data');
                    return;
                });
        }
    }

    function loadForm(diskon = 0, total = 0, diterima = 0, callback = null) {
        console.log('[loadForm] called with:', {diskon, total, diterima, callback});
        if (total == 0) {
            total = parseInt($('#total').val()) || 0;
        }
        let totalItem = parseInt($('#total_item').val()) || 0;
        
        $('#total').val(total);
        $('#total_item').val(totalItem);

        // Hitung dan tampilkan segera di client untuk responsifitas
        if (total > 0) {
            let bayar = total - (diskon / 100 * total);
            bayar = Number(bayar) || 0;
            let diterimaVal = Number(diterima) || Number($('#diterima').val()) || 0;
            if (!userEditedDiterima && (diterimaVal === 0 || diterimaVal < bayar)) {
                diterimaVal = bayar;
                $('#diterima').val(diterimaVal);
            }
            let kembali = diterimaVal - bayar;

            $('#totalrp').val('Rp. ' + new Intl.NumberFormat('id-ID').format(total));
            $('#bayarrp').val('Rp. ' + new Intl.NumberFormat('id-ID').format(bayar));
            $('#bayar').val(bayar);
            $('#kembali').val('Rp. ' + new Intl.NumberFormat('id-ID').format(kembali));
            if (kembali > 0) {
                $('.tampil-bayar').text('Kembali: Rp. ' + new Intl.NumberFormat('id-ID').format(kembali));
            } else {
                $('.tampil-bayar').text('Bayar: Rp. ' + new Intl.NumberFormat('id-ID').format(bayar));
            }
        } else {
            $('#totalrp').val('Rp. 0');
            $('#bayarrp').val('Rp. 0');
            $('#bayar').val(0);
            $('.tampil-bayar').text('Bayar: Rp. 0');
            $('.tampil-terbilang').text('Nol Rupiah');
            $('#kembali').val('Rp. 0');
            $('#diterima').val(0);
        }

        // Ambil terbilang dan format dari server tanpa mengandalkannya untuk logika utama
        @if($id_penjualan)
        if (!isLoadingForm) {
            isLoadingForm = true;
            $.get(`{{ url('/transaksi/loadform') }}/${diskon}/${total}/${$('#diterima').val() || 0}`)
                .done(response => {
                $('#totalrp').val('Rp. '+ response.totalrp);
                $('#bayarrp').val('Rp. '+ response.bayarrp);
                $('#bayar').val(response.bayar);
                $('.tampil-terbilang').text(response.terbilang);

                let currentDiterima = parseFloat($('#diterima').val()) || 0;
                let newBayar = parseFloat(response.bayar) || 0;

                if (!userEditedDiterima && (currentDiterima == 0 || currentDiterima == '' || diterima == 0)) {
                    $('#diterima').val(response.bayar);
                }

                let kembaliServer = parseFloat(response.kembali) || 0;
                $('#kembali').val('Rp. '+ response.kembalirp);
                if (kembaliServer > 0) {
                    $('.tampil-bayar').text('Kembali: Rp. '+ response.kembalirp);
                    $('.tampil-terbilang').text(response.kembali_terbilang);
                }

                if (callback && typeof callback === 'function') {
                    callback();
                }
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                })
                .fail(errors => {
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                })
                .always(() => {
                    isLoadingForm = false;
                });
        } else {
            if (callback && typeof callback === 'function') {
                callback();
            }
        }
        @else
        if (callback && typeof callback === 'function') {
            callback();
        }
        @endif
    }
</script>
@endpush