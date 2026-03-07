@extends('layouts.master')

@section('title')
    Daftar Penjualan
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/select2/dist/css/select2.min.css') }}">
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

    .penjualan-filter-wrap {
        padding: 10px;
        margin-bottom: 10px;
        border: 1px solid #f4f4f4;
        background: #fafafa;
        border-radius: 3px;
    }

    .penjualan-filter-wrap .form-group {
        margin-bottom: 10px;
    }

    .penjualan-filter-wrap label {
        font-size: 12px;
        margin-bottom: 4px;
        color: #666;
        display: block;
    }

    .filter-actions {
        margin-top: 4px;
    }

    .filter-note {
        font-size: 12px;
        color: #777;
        margin-top: 6px;
    }

    .penjualan-filter-wrap .select2-container {
        width: 100% !important;
    }

    .penjualan-filter-wrap .select2-container--default .select2-selection--multiple {
        min-height: 31px;
        border-color: #d2d6de;
        border-radius: 0;
        padding: 1px 4px;
    }

    .penjualan-filter-wrap .select2-container--default.select2-container--focus .select2-selection--multiple {
        border-color: #3c8dbc;
    }

    .penjualan-filter-wrap .select2-container--default .select2-selection--multiple .select2-selection__choice {
        margin-top: 4px;
        margin-right: 4px;
        margin-bottom: 0;
    }

    .select2-results > .select2-results__options {
        max-height: 280px;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .product-filter-hint {
        font-size: 11px;
        color: #888;
        margin-top: 4px;
    }
    
    /* Mobile responsive fixes */
    .table-responsive-mobile {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    @media (max-width: 768px) {
        .table-responsive-mobile {
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 15px;
        }
        
        .table-penjualan {
            min-width: 800px;
            margin-bottom: 0;
        }
        
        .table-penjualan td, 
        .table-penjualan th {
            white-space: nowrap;
            padding: 8px 4px;
            font-size: 12px;
        }
        
        .table-penjualan td:last-child,
        .table-penjualan th:last-child {
            position: sticky;
            right: 0;
            background-color: #fff;
            border-left: 2px solid #ddd;
            z-index: 10;
            box-shadow: -2px 0 5px rgba(0,0,0,0.1);
        }
        
        .box-header .btn {
            margin-bottom: 5px;
            font-size: 12px;
            padding: 5px 10px;
        }

        .penjualan-filter-wrap {
            padding: 8px;
        }

        .filter-actions .btn {
            width: 100%;
            margin-bottom: 6px;
        }
        
        .box-header .pull-right {
            float: none !important;
            margin-top: 10px;
        }
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
                <div class="penjualan-filter-wrap">
                    <div class="row">
                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="date_preset">Filter Tanggal</label>
                                <select id="date_preset" class="form-control input-sm">
                                    <option value="all">Semua Tanggal</option>
                                    <option value="today">Hari Ini</option>
                                    <option value="yesterday">Kemarin</option>
                                    <option value="last_7_days">7 Hari Terakhir</option>
                                    <option value="last_30_days">30 Hari Terakhir</option>
                                    <option value="this_week">Minggu Ini</option>
                                    <option value="last_week">Minggu Lalu</option>
                                    <option value="this_month">Bulan Ini</option>
                                    <option value="last_month">Bulan Lalu</option>
                                    <option value="this_year">Tahun Ini</option>
                                    <option value="custom">Custom Range</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="start_date">Tanggal Mulai</label>
                                <input type="date" id="start_date" class="form-control input-sm">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="end_date">Tanggal Akhir</label>
                                <input type="date" id="end_date" class="form-control input-sm">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="id_produk_filter">Filter Produk</label>
                                <select id="id_produk_filter" class="form-control input-sm" multiple>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id_produk }}">{{ $product->nama_produk }}</option>
                                    @endforeach
                                </select>
                                <div class="product-filter-hint">Ketik untuk cari, scroll untuk melihat produk lainnya, bisa pilih banyak item.</div>
                            </div>
                        </div>
                    </div>

                    <div class="row filter-actions">
                        <div class="col-md-12">
                            <button type="button" id="btnApplyFilter" class="btn btn-info btn-sm btn-flat">
                                <i class="fa fa-filter"></i> Terapkan Filter
                            </button>
                            <button type="button" id="btnResetFilter" class="btn btn-default btn-sm btn-flat">
                                <i class="fa fa-undo"></i> Reset Filter
                            </button>
                            <button onclick="syncStock()" class="btn btn-primary btn-sm btn-flat pull-right">
                                <i class="fa fa-refresh"></i> Cocokkan data Stok Produk
                            </button>
                        </div>
                    </div>

                    <div class="filter-note">
                        Gunakan preset tanggal untuk filter cepat, atau pilih <strong>Custom Range</strong> untuk rentang tanggal spesifik.
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>
            <div class="box-body">
                <div class="table-responsive-mobile">
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
</div>

@includeIf('penjualan.detail')
@endsection

@push('scripts')
<script src="{{ asset('AdminLTE-2/bower_components/select2/dist/js/select2.full.min.js') }}"></script>
<script>
    let table, table1;
    const filterDefaults = @json($filterDefaults ?? []);
    const productFilterConfig = {
        pageSize: 40
    };
    let productFilterData = [];

    function normalizeProductFilterValues(rawValue) {
        if (Array.isArray(rawValue)) {
            return rawValue
                .map((value) => String(value || '').trim())
                .filter((value) => value !== '');
        }

        if (rawValue === null || rawValue === undefined || rawValue === '') {
            return [];
        }

        return String(rawValue)
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');
    }

    function buildProductFilterData() {
        productFilterData = [];

        $('#id_produk_filter option').each(function () {
            const id = String($(this).val() || '').trim();
            const text = String($(this).text() || '').trim();

            if (id !== '' && text !== '') {
                productFilterData.push({
                    id: id,
                    text: text
                });
            }
        });
    }

    function getPaginatedProductResults(searchTerm, page) {
        const term = String(searchTerm || '').toLowerCase().trim();
        const currentPage = Math.max(parseInt(page, 10) || 1, 1);
        const startIndex = (currentPage - 1) * productFilterConfig.pageSize;
        const endIndex = startIndex + productFilterConfig.pageSize;

        const filtered = term === ''
            ? productFilterData
            : productFilterData.filter((product) => product.text.toLowerCase().indexOf(term) !== -1);

        return {
            items: filtered.slice(startIndex, endIndex),
            more: endIndex < filtered.length
        };
    }

    function ensureSelectedProductOptions(productIds) {
        productIds.forEach((productId) => {
            const selector = '#id_produk_filter option[value="' + productId.replace(/"/g, '\\"') + '"]';
            if ($(selector).length > 0) {
                return;
            }

            const found = productFilterData.find((product) => product.id === productId);
            if (found) {
                const option = new Option(found.text, found.id, true, true);
                $('#id_produk_filter').append(option);
            }
        });
    }

    function initializeProductFilterSelect() {
        buildProductFilterData();

        $('#id_produk_filter').select2({
            width: '100%',
            placeholder: 'Cari & pilih produk',
            multiple: true,
            allowClear: true,
            closeOnSelect: false,
            minimumInputLength: 0,
            ajax: {
                delay: 100,
                transport: function (params, success) {
                    const data = params.data || {};
                    const result = getPaginatedProductResults(data.term || '', data.page || 1);

                    success({
                        items: result.items,
                        more: result.more
                    });

                    return {
                        abort: function () {}
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;

                    return {
                        results: data.items,
                        pagination: {
                            more: data.more
                        }
                    };
                }
            },
            language: {
                inputTooShort: function () {
                    return 'Ketik nama produk';
                },
                searching: function () {
                    return 'Mencari...';
                },
                loadingMore: function () {
                    return 'Memuat produk berikutnya...';
                },
                noResults: function () {
                    return 'Produk tidak ditemukan';
                }
            }
        });
    }

    function collectFilters() {
        const selectedPreset = $('#date_preset').val() || 'all';
        const startDate = $('#start_date').val() || '';
        const endDate = $('#end_date').val() || '';
        const selectedProducts = normalizeProductFilterValues($('#id_produk_filter').val());

        const effectivePreset = (selectedPreset !== 'custom' && (startDate !== '' || endDate !== ''))
            ? 'custom'
            : selectedPreset;

        return {
            date_preset: effectivePreset,
            start_date: startDate,
            end_date: endDate,
            id_produk: selectedProducts
        };
    }

    function setCustomDateInputsState() {
        const isCustom = $('#date_preset').val() === 'custom';
        $('#start_date, #end_date').toggleClass('input-sm', true);
        $('#start_date, #end_date').attr('title', isCustom ? 'Pilih rentang tanggal custom' : 'Bisa dipilih kapan saja; saat diisi otomatis dianggap custom range');
    }

    function updateFilterUrl(filters) {
        const params = new URLSearchParams(window.location.search);

        Object.keys(filters).forEach((key) => {
            const value = filters[key];

            params.delete(key);
            params.delete(key + '[]');

            if (Array.isArray(value)) {
                if (value.length > 0) {
                    value.forEach((item) => {
                        params.append(key + '[]', item);
                    });
                }
                return;
            }

            if (value === '' || value === 'all') {
                return;
            } else {
                params.set(key, value);
            }
        });

        const query = params.toString();
        const newUrl = window.location.pathname + (query ? ('?' + query) : '');
        window.history.replaceState({}, '', newUrl);
    }

    function applyInitialFilters() {
        const preset = filterDefaults.date_preset || 'all';
        const selectedProductIds = normalizeProductFilterValues(filterDefaults.id_produk);

        $('#date_preset').val(preset);
        $('#start_date').val(filterDefaults.start_date || '');
        $('#end_date').val(filterDefaults.end_date || '');

        ensureSelectedProductOptions(selectedProductIds);
        $('#id_produk_filter').val(selectedProductIds).trigger('change');

        setCustomDateInputsState();
    }

    $(function () {
        initializeProductFilterSelect();
        applyInitialFilters();

        table = $('.table-penjualan').DataTable({
            order: [],
            responsive: {
                details: {
                    type: 'column',
                    target: 'tr'
                }
            },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            scrollCollapse: true,
            ajax: {
                url: '{{ route('penjualan.data') }}',
                data: function (d) {
                    const filters = collectFilters();
                    d.date_preset = filters.date_preset;
                    d.start_date = filters.start_date;
                    d.end_date = filters.end_date;
                    d.id_produk = filters.id_produk.length > 0 ? filters.id_produk : '';
                },
                error: function (xhr) {
                    console.error('Gagal memuat data penjualan:', xhr.responseText || xhr.statusText);
                    alert('Gagal memuat data penjualan. Silakan refresh halaman.');
                }
            },
            columns: [
                {data: 'DT_RowIndex', name: 'penjualan.id_penjualan', searchable: false, orderable: false},
                {data: 'tanggal', name: 'penjualan.waktu', searchable: false, orderable: true},
                {data: 'kode_member', name: 'penjualan.id_member', searchable: false, orderable: false},
                {data: 'total_item', name: 'penjualan.total_item', searchable: false, orderable: true},
                {data: 'total_harga', name: 'penjualan.total_harga', searchable: false, orderable: true},
                {data: 'diskon', name: 'penjualan.diskon', searchable: false, orderable: true},
                {data: 'bayar', name: 'penjualan.bayar', searchable: false, orderable: true},
                {data: 'kasir', name: 'penjualan.id_user', searchable: false, orderable: false},
                {data: 'aksi', name: 'aksi', searchable: false, orderable: false},
            ]
        }).on('draw.dt', function () {
            // Inisialisasi tooltip setelah datatable di-draw
            $('[data-toggle="tooltip"]').tooltip();
        }).on('error.dt', function (e, settings, techNote, message) {
            console.error('DataTables error:', message);
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
        });

        $('#date_preset').on('change', function () {
            setCustomDateInputsState();
        });

        $('#start_date, #end_date').on('focus change', function () {
            if ($('#date_preset').val() !== 'custom') {
                $('#date_preset').val('custom');
                setCustomDateInputsState();
            }
        });

        $('#btnApplyFilter').on('click', function () {
            const filters = collectFilters();
            updateFilterUrl(filters);
            table.ajax.reload();
        });

        $('#btnResetFilter').on('click', function () {
            $('#date_preset').val('all');
            $('#start_date').val('');
            $('#end_date').val('');
            $('#id_produk_filter').val(null).trigger('change');
            setCustomDateInputsState();

            const filters = collectFilters();
            updateFilterUrl(filters);
            table.ajax.reload();
        });
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