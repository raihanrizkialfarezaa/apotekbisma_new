@extends('layouts.master')

@section('title')
    Transaksi Aktif
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

    .draft-action-wrap {
        min-width: 212px;
        max-width: 228px;
    }

    .draft-stock-summary {
        margin-top: 6px;
        max-width: 100%;
    }

    .draft-stock-card {
        background: #f7fbff;
        border: 1px solid #cfe0f2;
        border-left: 4px solid #5a88b5;
        border-radius: 7px;
        padding: 8px 10px;
        color: #27405c;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .draft-stock-card--warning {
        background: #fff7cf;
        border-color: #e4cc64;
        border-left-color: #d3aa12;
        color: #5a4700;
    }

    .draft-stock-card__title {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.2px;
        line-height: 1.25;
    }

    .draft-stock-card__title .fa {
        margin-right: 4px;
    }

    .draft-stock-card__notice {
        margin-top: 6px;
        padding: 5px 7px;
        border-radius: 6px;
        background: rgba(211, 170, 18, 0.16);
        font-size: 11px;
        font-weight: 700;
        line-height: 1.3;
    }

    .draft-stock-card__notice .fa {
        margin-right: 4px;
    }

    .draft-stock-card__equation {
        margin-top: 7px;
        padding: 6px 7px;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.78);
    }

    .draft-stock-card__equation-main {
        font-size: 17px;
        font-weight: 800;
        line-height: 1.15;
        color: inherit;
    }

    .draft-stock-card__equation-note {
        margin-top: 3px;
        font-size: 10px;
        line-height: 1.35;
        color: #60758d;
    }

    .draft-stock-card--warning .draft-stock-card__equation-note {
        color: #7a6926;
    }

    .draft-stock-card__rows {
        margin-top: 8px;
    }

    .draft-stock-card__row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 5px 0;
        border-top: 1px dashed rgba(90, 136, 181, 0.25);
    }

    .draft-stock-card__row:first-child {
        border-top: 0;
        padding-top: 0;
    }

    .draft-stock-card__row--after {
        margin-top: 1px;
        padding-top: 6px;
        font-weight: 700;
    }

    .draft-stock-card__row--warning {
        background: rgba(255, 224, 130, 0.45);
        border-radius: 6px;
        padding: 6px 7px;
        border-top: 0;
    }

    .draft-stock-card__label {
        font-size: 11px;
        font-weight: 600;
        color: inherit;
    }

    .draft-stock-card__value {
        font-size: 13px;
        font-weight: 800;
        color: inherit;
        white-space: nowrap;
    }

    @media(max-width: 768px) {
        .tampil-bayar {
            font-size: 3em;
            height: 70px;
            padding-top: 5px;
        }

        .draft-action-wrap {
            min-width: 0;
            max-width: none;
        }

        .draft-stock-card {
            padding: 8px 9px;
        }

        .draft-stock-card__equation-main {
            font-size: 16px;
        }

        .draft-stock-card__equation-note {
            font-size: 10px;
        }

        .draft-stock-card__row {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 4px 0;
        }

        .draft-stock-card__label {
            font-size: 10px;
        }

        .draft-stock-card__value {
            display: block;
            margin-top: 0;
            font-size: 12px;
            text-align: right;
        }

        .draft-stock-card__row--warning {
            padding: 6px;
        }
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Transaksi Aktif</li>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-12">
        <div class="box">
            <div class="box-body">
                    
                <form class="form-produk">
                    @csrf
                    <div class="form-group row">
                        <label for="kode_produk" class="col-lg-2">Kode Produk</label>
                        <div class="col-lg-5">
                            <div class="input-group">
                                <input type="hidden" name="id_penjualan" id="id_penjualan" value="{{ $id_penjualan }}">
                                <input type="hidden" name="id_produk" id="id_produk">
                                <input type="text" class="form-control" name="kode_produk" id="kode_produk">
                                <span class="input-group-btn">
                                    <button onclick="tampilProduk()" class="btn btn-info btn-flat" type="button"><i class="fa fa-arrow-right"></i></button>
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
                        <form action="{{ route('transaksi.updates', $id_penjualan) }}" class="form-penjualan" method="post" autocomplete="off">
                            @csrf
                            @method('PUT');
                            <input type="hidden" name="id_penjualan" value="{{ $id_penjualan }}">
                            <input type="hidden" name="total" id="total">
                            <input type="hidden" name="total_item" id="total_item">
                            <input type="hidden" name="bayar" id="bayar">
                            <input type="hidden" name="id_member" id="id_member" value="{{ $memberSelected->id_member }}">
                            <input type="hidden" name="waktu" id="waktu_transaksi_submit">
                            <input type="hidden" name="browser_now_iso" id="browser_now_iso">
                            <input type="hidden" name="browser_timezone_offset_minutes" id="browser_timezone_offset_minutes">

                            <div class="form-group row">
                                <label for="totalrp" class="col-lg-2 control-label">Total</label>
                                <div class="col-lg-8">
                                    <input type="text" id="totalrp" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="waktu_transaksi" class="col-lg-2 control-label">Tanggal Transaksi</label>
                                <div class="col-lg-8">
                                    <input type="date" id="waktu_transaksi" class="form-control waktu" name="waktu_tanggal" autocomplete="off" data-default-value="" data-preserve-server-time="0" data-server-date="" data-server-time="" value="">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="kode_member" class="col-lg-2 control-label">Member</label>
                                <div class="col-lg-8">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="kode_member" value="{{ $memberSelected->kode_member }}">
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
    let userEditedDiterima = false;
    let userEditedWaktuTransaksi = false;

    function padDateSegment(value) {
        return String(value).padStart(2, '0');
    }

    function formatBrowserDateOnly(date) {
        return date.getFullYear() + '-'
            + padDateSegment(date.getMonth() + 1) + '-'
            + padDateSegment(date.getDate());
    }

    function formatBrowserTimeOnly(date) {
        return padDateSegment(date.getHours()) + ':'
            + padDateSegment(date.getMinutes()) + ':'
            + padDateSegment(date.getSeconds());
    }

    function refreshBrowserClockContext() {
        const browserNow = new Date();
        $('#browser_now_iso').val(browserNow.toISOString());
        $('#browser_timezone_offset_minutes').val(browserNow.getTimezoneOffset());
        return browserNow;
    }

    function resolvePreferredTransactionDate(browserNow) {
        const waktuInput = document.getElementById('waktu_transaksi');
        if (!waktuInput) {
            return formatBrowserDateOnly(browserNow);
        }

        const shouldPreserveServerTime = waktuInput.getAttribute('data-preserve-server-time') === '1';
        if (!shouldPreserveServerTime) {
            return formatBrowserDateOnly(browserNow);
        }

        const serverDate = (waktuInput.getAttribute('data-server-date') || '').trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(serverDate)) {
            return serverDate;
        }

        return formatBrowserDateOnly(browserNow);
    }

    function resolveSubmittedTransactionDateTime(selectedDate, browserNow) {
        const waktuInput = document.getElementById('waktu_transaksi');
        if (!selectedDate) {
            return '';
        }

        let effectiveTime = formatBrowserTimeOnly(browserNow);
        if (waktuInput && waktuInput.getAttribute('data-preserve-server-time') === '1') {
            const serverTime = (waktuInput.getAttribute('data-server-time') || '').trim();
            if (/^\d{2}:\d{2}:\d{2}$/.test(serverTime)) {
                effectiveTime = serverTime;
            }
        }

        return selectedDate + 'T' + effectiveTime;
    }

    function syncSubmittedTransactionTime() {
        const waktuInput = document.getElementById('waktu_transaksi');
        const waktuSubmitInput = document.getElementById('waktu_transaksi_submit');
        if (!waktuInput || !waktuSubmitInput) {
            return;
        }

        const browserNow = refreshBrowserClockContext();
        const selectedDate = waktuInput.value || resolvePreferredTransactionDate(browserNow);
        waktuInput.value = selectedDate;
        waktuInput.setAttribute('data-default-value', selectedDate);
        waktuSubmitInput.value = resolveSubmittedTransactionDateTime(selectedDate, browserNow);
    }

    function syncTransactionInputWithBrowserNow(force = false) {
        const waktuInput = document.getElementById('waktu_transaksi');
        if (!waktuInput) {
            return;
        }

        const storedDefaultValue = waktuInput.getAttribute('data-default-value');
        if (!force && !waktuInput.value && storedDefaultValue) {
            refreshBrowserClockContext();
            waktuInput.value = storedDefaultValue;
            syncSubmittedTransactionTime();
            return;
        }

        const browserNow = refreshBrowserClockContext();
        const preferredDate = resolvePreferredTransactionDate(browserNow);

        if (force || !waktuInput.value) {
            waktuInput.value = preferredDate;
            waktuInput.setAttribute('data-default-value', preferredDate);
            waktuInput.setAttribute('data-server-date', preferredDate);
        }

        syncSubmittedTransactionTime();
    }

    function shouldForceBrowserNowPrefill() {
        const waktuInput = document.getElementById('waktu_transaksi');
        if (!waktuInput) {
            return false;
        }

        return waktuInput.getAttribute('data-preserve-server-time') !== '1';
    }

    function forceBrowserNowPrefillAfterReload() {
        if (userEditedWaktuTransaksi || !shouldForceBrowserNowPrefill()) {
            return;
        }

        syncTransactionInputWithBrowserNow(true);
    }

    function scheduleBrowserNowPrefillRefresh() {
        forceBrowserNowPrefillAfterReload();
        window.requestAnimationFrame(forceBrowserNowPrefillAfterReload);
        setTimeout(forceBrowserNowPrefillAfterReload, 0);
        setTimeout(forceBrowserNowPrefillAfterReload, 150);
        setTimeout(forceBrowserNowPrefillAfterReload, 600);
    }

    function resetTransactionInputElementForActiveDraft() {
        const waktuInput = document.getElementById('waktu_transaksi');
        if (!waktuInput || !shouldForceBrowserNowPrefill()) {
            return;
        }

        const replacementInput = waktuInput.cloneNode(true);
        replacementInput.value = '';
        replacementInput.setAttribute('value', '');
        replacementInput.setAttribute('data-default-value', '');
        replacementInput.setAttribute('data-server-date', '');
        replacementInput.setAttribute('data-server-time', '');
        waktuInput.parentNode.replaceChild(replacementInput, waktuInput);

        const waktuSubmitInput = document.getElementById('waktu_transaksi_submit');
        if (waktuSubmitInput) {
            waktuSubmitInput.value = '';
        }
    }
    
    function formatUang(angka) {
        return new Intl.NumberFormat('id-ID').format(angka);
    }

    function computeTotalsInDetail() {
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

        $('#totalrp').val('Rp. ' + formatUang(newTotal));
        $('#bayarrp').val('Rp. ' + formatUang(bayar));
        $('#bayar').val(bayar);

        let currentDiterima = parseFloat($('#diterima').val()) || 0;
        if (!userEditedDiterima) {
            $('#diterima').val(bayar);
        }

        let diterimaVal = parseFloat($('#diterima').val()) || 0;
        let kembali = diterimaVal - bayar;
        $('#kembali').val('Rp.' + formatUang(kembali));
        if (kembali > 0) {
            $('.tampil-bayar').text('Kembali: Rp. ' + formatUang(kembali));
        } else {
            $('.tampil-bayar').text('Bayar: Rp. ' + formatUang(bayar));
        }
    }

    $(function () {
        $('body').addClass('sidebar-collapse');
        resetTransactionInputElementForActiveDraft();
        
        // Fungsi untuk memastikan tanggal selalu terisi
        function ensureDateFilled() {
            const waktuInput = document.getElementById('waktu_transaksi');
            if (waktuInput) {
                if (!waktuInput.value || waktuInput.value === '') {
                    syncTransactionInputWithBrowserNow(false);
                }
            }
        }
        
        // Set tanggal saat halaman dimuat
        syncTransactionInputWithBrowserNow(true);
        scheduleBrowserNowPrefillRefresh();
        
        // Set tanggal setiap 2 detik untuk memastikan tidak kosong
        setInterval(ensureDateFilled, 2000);

        window.addEventListener('pageshow', function () {
            scheduleBrowserNowPrefillRefresh();
        });

        $('#waktu_transaksi').on('input change', function () {
            userEditedWaktuTransaksi = true;
            refreshBrowserClockContext();
            syncSubmittedTransactionTime();
        });

        $('.form-penjualan').on('submit', function () {
            refreshBrowserClockContext();
            if (!userEditedWaktuTransaksi && (!$('#waktu_transaksi').val() || $('#waktu_transaksi').val() === '')) {
                syncTransactionInputWithBrowserNow(true);
            }

            syncSubmittedTransactionTime();
        });

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
            computeTotalsInDetail();
            loadForm($('#diskon').val(), parseFloat($('#diterima').val()) || 0);
        });

        table.ajax.reload(function() {
            userEditedDiterima = false;
            computeTotalsInDetail();
            loadForm($('#diskon').val(), parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0);
        });
        table2 = $('.table-produk').DataTable({
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            scrollCollapse: true,
            ajax: {
                url: '{{ route('transaksi.produk_data') }}'
            },
            columns: [
                {data: 'no', searchable: false, sortable: false},
                {
                    data: 'kode_produk',
                    render: function(data) {
                        return '<span class="label label-success">' + (data || '-') + '</span>';
                    }
                },
                {data: 'nama_produk'},
                {
                    data: null,
                    render: function(data) {
                        const stok = parseInt(data.stok, 10) || 0;
                        let badgeHtml = '<span class="badge ' + data.stok_badge_class + '">' +
                                        formatUang(stok) + ' unit</span>';

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
                        const stok = parseInt(data.stok, 10) || 0;
                        return '<a href="#" class="btn btn-primary btn-xs btn-flat" ' +
                               'onclick="pilihProduk(\'' + data.id + '\', \'' + data.kode_produk + '\', ' + stok + ')">' +
                               '<i class="fa fa-check-circle"></i> Pilih</a>';
                    },
                    searchable: false,
                    sortable: false
                }
            ],
            order: [[2, 'asc']],
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

            $.post(`{{ url('/transaksi/updateEdit') }}/${id}`, {
                    '_token': $('[name=csrf-token]').attr('content'),
                    '_method': 'put',
                    'jumlah': jumlah
                })
                .done(response => {
                    // After successful server update, update totals client-side and refresh row data
                    userEditedDiterima = false;
                    table.ajax.reload(null, false);
                    if (table2) {
                        table2.ajax.reload(null, false);
                    }
                    computeTotalsInDetail();
                    loadForm($('#diskon').val(), parseFloat($('#diterima').val()) || 0);
                })
                .fail(errors => {
                    if(errors.status == 500){
                        alert('Stok barang tidak cukup');
                    } else {
                        alert('Tidak dapat menyimpan data');
                    }
                    table.ajax.reload(null, false);
                    computeTotalsInDetail();
                    loadForm($('#diskon').val(), parseFloat($('#diterima').val()) || 0);
                    return;
                });
        });

        $(document).on('input', '#diskon', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }

            userEditedDiterima = false;
            computeTotalsInDetail();
            loadForm($(this).val(), parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0);
        });

        $('#diterima').on('input', function () {
            if ($(this).val() == "") {
                $(this).val(0).select();
            }
            userEditedDiterima = true;
            computeTotalsInDetail();
            loadForm($('#diskon').val(), parseFloat($('#total').val()) || 0, parseFloat($(this).val()) || 0);
        }).focus(function () {
            $(this).select();
        });

        $('.btn-simpan').on('click', function () {
            const waktuInput = document.getElementById('waktu_transaksi');
            if (waktuInput && (!waktuInput.value || waktuInput.value === '')) {
                syncTransactionInputWithBrowserNow(true);
            }

            refreshBrowserClockContext();

            computeTotalsInDetail();

            const currentTotal = parseFloat($('#total').val()) || 0;
            if (currentTotal <= 0) {
                alert('Transaksi kosong. Tambahkan produk sebelum menyimpan.');
                return false;
            }

            $('input[name="total"]').val($('#total').val());
            $('input[name="total_item"]').val($('#total_item').val());
            $('input[name="bayar"]').val($('#bayar').val());

            $('.form-penjualan').submit();
        });
    });

    function tampilProduk() {
        if (table2) {
            table2.ajax.reload(null, false);
        }
        $('#modal-produk').modal('show');
    }

    function hideProduk() {
        $('#modal-produk').modal('hide');
    }

    function pilihProduk(id, kode, stok = null) {
        if (stok !== null && parseInt(stok, 10) <= 0) {
            alert('❌ STOK HABIS!\n\nProduk tidak dapat dijual karena stok sudah habis (0).\nSilakan lakukan pembelian terlebih dahulu.');
            return;
        }

        $('#id_produk').val(id);
        $('#kode_produk').val(kode);
        hideProduk();
        tambahProduk();
    }

    function tambahProduk() {
        var idProduk = $('#id_produk').val();
        var kodeProduk = $('#kode_produk').val();
        console.log('[tambahProduk] called, id_produk:', idProduk, 'kode_produk:', kodeProduk);
        
        if (!idProduk || idProduk === '') {
            alert('Silakan pilih produk terlebih dahulu');
            return;
        }
        
        const waktuInput = document.getElementById('waktu_transaksi');
        if (waktuInput && (!waktuInput.value || waktuInput.value === '')) {
            waktuInput.value = waktuInput.getAttribute('data-default-value') || waktuInput.defaultValue || '';
        }
        
        $.post('{{ route('transaksi.store') }}', $('.form-produk').serialize())
            .done(response => {
                console.log('[tambahProduk] success:', response);
                $('#kode_produk').val('');
                $('#id_produk').val('');
                $('#kode_produk').focus();
                userEditedDiterima = false;
                table.ajax.reload(null, false);
                if (table2) {
                    table2.ajax.reload(null, false);
                }
                computeTotalsInDetail();
                loadForm($('#diskon').val(), parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0);
            })
            .fail(xhr => {
                console.error('[tambahProduk] error:', xhr.status, xhr.responseText);
                var errorMsg = 'Tidak dapat menyimpan data';
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.message) {
                        errorMsg = response.message;
                    } else if (typeof response === 'string') {
                        errorMsg = response;
                    }
                } catch(e) {
                    if (xhr.responseText) {
                        errorMsg = xhr.responseText.substring(0, 200);
                    }
                }
                alert(errorMsg);
            });
    }

    function tampilMember() {
        $('#modal-member').modal('show');
    }

    function pilihMember(id, kode) {
        $('#id_member').val(id);
        $('#kode_member').val(kode);
        $('#diskon').val('{{ $diskon }}');
        loadForm($('#diskon').val(), 0, function() {
            // Update diterima immediately after member selection
            $('#diterima').val($('#bayar').val());
            $('#diterima').focus().select();
        });
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
                    userEditedDiterima = false;
                    table.ajax.reload(function() {
                        computeTotalsInDetail();
                        loadForm($('#diskon').val(), parseFloat($('#total').val()) || 0, parseFloat($('#diterima').val()) || 0);
                    });
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
        $('#total').val($('#total').val() || 0);
        $('#total_item').val($('#total_item').val() || 0);

        $.get(`{{ url('/transaksi/loadform') }}/${diskon}/${total}/${diterima}`)
            .done(response => {
                $('#totalrp').val('Rp. '+ response.totalrp);
                $('#bayarrp').val('Rp. '+ response.bayarrp);
                $('#bayar').val(response.bayar);
                $('.tampil-bayar').text('Bayar: Rp. '+ response.bayarrp);
                $('.tampil-terbilang').text(response.terbilang);

                if (!userEditedDiterima && ($('#diterima').val() == 0 || ($('#diterima').val() == ''))) {
                    $('#diterima').val(response.bayar);
                }

                $('#kembali').val('Rp.'+ response.kembalirp);
                if (parseFloat($('#diterima').val()) != 0) {
                    $('.tampil-bayar').text('Kembali: Rp. '+ response.kembalirp);
                    $('.tampil-terbilang').text(response.kembali_terbilang);
                }

                if (callback && typeof callback === 'function') {
                    callback();
                }
            })
            .fail(errors => {
                alert('Tidak dapat menampilkan data');
                return;
            })
    }
</script>
@endpush