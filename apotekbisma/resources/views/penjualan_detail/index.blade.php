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
    }

    .table-penjualan tbody tr:last-child {
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
                                    <input type="text" id="kembali" name="kembali" class="form-control" value="0" readonly>
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

        @if($id_penjualan)
        table = $('.table-penjualan').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            autoWidth: false,
            ajax: {
                url: '{{ route('transaksi.data', $id_penjualan) }}',
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
            loadForm($('#diskon').val());
            setTimeout(() => {
                $('#diterima').trigger('input');
            }, 300);
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

        table2 = $('.table-produk').DataTable();

        // Inisialisasi form berdasarkan kondisi transaksi
        @if($id_penjualan)
            loadForm($('#diskon').val());
        @else
            $('.btn-simpan').prop('disabled', true).addClass('disabled');
            $('.btn-simpan').html('<i class="fa fa-plus"></i> Tambahkan Produk Terlebih Dahulu');
        @endif

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

            $.post(`{{ url('/transaksi') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'jumlah': jumlah
                })
                .done(response => {
                    $(this).on('mouseout', function () {
                        table.ajax.reload(() => {
                            loadForm($('#diskon').val());
                            // Update diterima with new total after quantity change
                            setTimeout(() => {
                                $('#diterima').val($('#bayar').val());
                                $('#diterima').trigger('input');
                            }, 100);
                        });
                    });
                })
                .fail(errors => {
                    if(errors.status == 500){
                        alert('Stok barang tidak cukup');
                    } else {
                        alert('Tidak dapat menyimpan data');
                    }
                    $(this).on('mouseout', function () {
                        table.ajax.reload(() => {
                            loadForm($('#diskon').val());
                            // Update diterima with new total after quantity change
                            setTimeout(() => {
                                $('#diterima').val($('#bayar').val());
                                $('#diterima').trigger('input');
                            }, 100);
                        });
                    })
                    return;
                });
        });

        $(document).on('input', '#diskon', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }

            loadForm($(this).val());
            // Update diterima with new total after discount change
            setTimeout(() => {
                $('#diterima').val($('#bayar').val());
                $('#diterima').trigger('input');
            }, 100);
        });

        $('#diterima').on('input', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }

            loadForm($('#diskon').val(), $(this).val());
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
                $('#kode_produk').val('').focus();
                
                @if(!$id_penjualan)
                    // Jika ini adalah produk pertama, reload halaman untuk mendapatkan ID transaksi
                    location.reload();
                @else
                    // Jika sudah ada transaksi, reload tabel saja
                    table.ajax.reload(() => {
                        loadForm($('#diskon').val());
                        // Update diterima with new total after adding product
                        setTimeout(() => {
                            if ($('#diterima').val() != 0) {
                                $('#diterima').val($('#bayar').val());
                                $('#diterima').trigger('input');
                            }
                        }, 100);
                    });
                    
                    // Aktifkan tombol simpan jika belum aktif
                    if ($('.btn-simpan').prop('disabled')) {
                        $('.btn-simpan').prop('disabled', false).removeClass('disabled');
                        $('.btn-simpan').html('<i class="fa fa-floppy-o"></i> Simpan Transaksi');
                    }
                @endif
            })
            .fail(errors => {
                alert('Tidak dapat menyimpan data');
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
        loadForm($('#diskon').val());
        // Update diterima with new total after member selection
        setTimeout(() => {
            $('#diterima').val($('#bayar').val());
            $('#diterima').focus().select();
        }, 100);
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
                    table.ajax.reload(() => {
                        loadForm($('#diskon').val());
                        // Update diterima with new total after deletion
                        setTimeout(() => {
                            $('#diterima').val($('#bayar').val());
                            $('#diterima').trigger('input');
                        }, 100);
                    });
                })
                .fail((errors) => {
                    alert('Tidak dapat menghapus data');
                    return;
                });
        }
    }

    function loadForm(diskon = 0, diterima = 0) {
        let total = $('.total').text() || 0;
        let totalItem = $('.total_item').text() || 0;
        
        $('#total').val(total);
        $('#total_item').val(totalItem);

        @if($id_penjualan)
        $.get(`{{ url('/transaksi/loadform') }}/${diskon}/${total}/${diterima}`)
            .done(response => {
                $('#totalrp').val('Rp. '+ response.totalrp);
                $('#bayarrp').val('Rp. '+ response.bayarrp);
                $('#bayar').val(response.bayar);
                $('.tampil-bayar').text('Bayar: Rp. '+ response.bayarrp);
                $('.tampil-terbilang').text(response.terbilang);

                // Auto-fill diterima dengan nilai bayar jika diterima masih 0 atau kosong
                if ($('#diterima').val() == 0 || $('#diterima').val() == '') {
                    $('#diterima').val(response.bayar);
                }

                $('#kembali').val('Rp.'+ response.kembalirp);
                if ($('#diterima').val() != 0) {
                    $('.tampil-bayar').text('Kembali: Rp. '+ response.kembalirp);
                    $('.tampil-terbilang').text(response.kembali_terbilang);
                }
            })
            .fail(errors => {
                alert('Tidak dapat menampilkan data');
                return;
            });
        @else
        // Untuk transaksi baru yang belum ada ID
        $('#totalrp').val('Rp. 0');
        $('#bayarrp').val('Rp. 0');
        $('#bayar').val(0);
        $('.tampil-bayar').text('Bayar: Rp. 0');
        $('.tampil-terbilang').text('Nol Rupiah');
        $('#kembali').val('Rp. 0');
        $('#diterima').val(0);
        @endif
    }
</script>
@endpush