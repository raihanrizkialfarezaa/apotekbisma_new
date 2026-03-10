@extends('layouts.master')

@section('title')
    Daftar Pembelian
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/select2/dist/css/select2.min.css') }}">
<style>
    .pembelian-filter-wrap {
        padding: 10px;
        margin-bottom: 10px;
        border: 1px solid #f4f4f4;
        background: #fafafa;
        border-radius: 3px;
    }

    .pembelian-filter-wrap .form-group {
        margin-bottom: 10px;
    }

    .pembelian-filter-wrap label {
        font-size: 12px;
        margin-bottom: 4px;
        color: #666;
        display: block;
    }

    .pembelian-filter-wrap .select2-container {
        width: 100% !important;
    }

    .pembelian-filter-wrap .select2-container--default .select2-selection--multiple {
        min-height: 31px;
        border-color: #d2d6de;
        border-radius: 0;
        padding: 1px 4px;
    }

    .pembelian-filter-wrap .select2-container--default.select2-container--focus .select2-selection--multiple {
        border-color: #3c8dbc;
    }

    .pembelian-filter-wrap .select2-container--default .select2-selection--multiple .select2-selection__choice {
        margin-top: 4px;
        margin-right: 4px;
        margin-bottom: 0;
    }

    .select2-results > .select2-results__options {
        max-height: 280px;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .filter-actions {
        margin-top: 4px;
    }

    .filter-note {
        font-size: 12px;
        color: #777;
        margin-top: 6px;
    }

    .product-filter-hint {
        font-size: 11px;
        color: #888;
        margin-top: 4px;
    }

    .audit-search-input {
        position: relative;
    }

    .audit-search-input .fa-search {
        position: absolute;
        left: 10px;
        top: 9px;
        color: #999;
        z-index: 2;
    }

    .audit-search-input input {
        padding-left: 30px;
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
        
        .table-pembelian {
            min-width: 800px;
            margin-bottom: 0;
        }
        
        .table-pembelian td, 
        .table-pembelian th {
            white-space: nowrap;
            padding: 8px 4px;
            font-size: 12px;
        }
        
        .table-pembelian td:last-child,
        .table-pembelian th:last-child {
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

        .pembelian-filter-wrap {
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
                <div class="pembelian-filter-wrap">
                    <div class="row">
                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="purchase_search">Search Audit</label>
                                <div class="audit-search-input">
                                    <i class="fa fa-search"></i>
                                    <input type="text" id="purchase_search" class="form-control input-sm" placeholder="Cari faktur, supplier, atau produk...">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="id_supplier_filter">Filter Supplier</label>
                                <select id="id_supplier_filter" class="form-control input-sm" multiple>
                                    @foreach($supplier as $item)
                                        <option value="{{ $item->id_supplier }}">{{ $item->nama }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="id_produk_filter">Filter Produk Dibeli</label>
                                <select id="id_produk_filter" class="form-control input-sm" multiple>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id_produk }}">{{ $product->nama_produk }} ({{ $product->kode_produk }})</option>
                                    @endforeach
                                </select>
                                <div class="product-filter-hint">Bisa pilih banyak produk; transaksi akan muncul jika faktur memuat salah satu produk tersebut.</div>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="arrival_date_preset">Filter Tanggal Obat Datang</label>
                                <select id="arrival_date_preset" class="form-control input-sm">
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
                    </div>

                    <div class="row">
                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="arrival_start_date">Tanggal Datang Mulai</label>
                                <input type="date" id="arrival_start_date" class="form-control input-sm">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="arrival_end_date">Tanggal Datang Sampai</label>
                                <input type="date" id="arrival_end_date" class="form-control input-sm">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="invoice_start_datetime">Waktu Faktur Dibuat Mulai</label>
                                <input type="datetime-local" id="invoice_start_datetime" class="form-control input-sm" step="1">
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="invoice_end_datetime">Waktu Faktur Dibuat Sampai</label>
                                <input type="datetime-local" id="invoice_end_datetime" class="form-control input-sm" step="1">
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
                            <button onclick="addForm()" class="btn btn-success btn-sm btn-flat pull-right"><i class="fa fa-plus-circle"></i> Transaksi Baru</button>
                            <button onclick="syncStock()" class="btn btn-primary btn-sm btn-flat pull-right" style="margin-right: 8px;"><i class="fa fa-refresh"></i> Cocokkan data Stok Produk</button>
                        </div>
                    </div>

                    <div class="filter-note">
                        Filter audit ini mendukung pagination server-side, sorting, pencarian faktur/supplier/produk, serta kombinasi tanggal obat datang dan waktu faktur dibuat.
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>
            <div class="box-body">
                <div class="table-responsive-mobile">
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
</div>

@includeIf('pembelian.supplier')
@includeIf('pembelian.detail')
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

    function normalizeFilterValues(rawValue) {
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
                productFilterData.push({ id: id, text: text });
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

    function ensureSelectedOptions(selector, values, sourceData) {
        values.forEach((value) => {
            const optionSelector = selector + ' option[value="' + value.replace(/"/g, '\\"') + '"]';
            if ($(optionSelector).length > 0) {
                return;
            }

            const found = sourceData.find((item) => item.id === value);
            if (found) {
                const option = new Option(found.text, found.id, true, true);
                $(selector).append(option);
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

    function initializeSupplierFilterSelect() {
        $('#id_supplier_filter').select2({
            width: '100%',
            placeholder: 'Pilih supplier',
            multiple: true,
            allowClear: true,
            closeOnSelect: false,
            language: {
                noResults: function () {
                    return 'Supplier tidak ditemukan';
                }
            }
        });
    }

    function collectFilters() {
        const selectedPreset = $('#arrival_date_preset').val() || 'all';
        const arrivalStartDate = $('#arrival_start_date').val() || '';
        const arrivalEndDate = $('#arrival_end_date').val() || '';

        return {
            search_text: $('#purchase_search').val() || '',
            arrival_date_preset: (selectedPreset !== 'custom' && (arrivalStartDate !== '' || arrivalEndDate !== ''))
                ? 'custom'
                : selectedPreset,
            arrival_start_date: arrivalStartDate,
            arrival_end_date: arrivalEndDate,
            invoice_start_datetime: $('#invoice_start_datetime').val() || '',
            invoice_end_datetime: $('#invoice_end_datetime').val() || '',
            id_supplier: normalizeFilterValues($('#id_supplier_filter').val()),
            id_produk: normalizeFilterValues($('#id_produk_filter').val())
        };
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
            }

            params.set(key, value);
        });

        const query = params.toString();
        const newUrl = window.location.pathname + (query ? ('?' + query) : '');
        window.history.replaceState({}, '', newUrl);
    }

    function applyInitialFilters() {
        const selectedSupplierIds = normalizeFilterValues(filterDefaults.id_supplier);
        const selectedProductIds = normalizeFilterValues(filterDefaults.id_produk);
        const supplierFilterData = $('#id_supplier_filter option').map(function () {
            return {
                id: String($(this).val() || '').trim(),
                text: String($(this).text() || '').trim()
            };
        }).get();

        $('#purchase_search').val(filterDefaults.search_text || '');
        $('#arrival_date_preset').val(filterDefaults.arrival_date_preset || 'all');
        $('#arrival_start_date').val(filterDefaults.arrival_start_date || '');
        $('#arrival_end_date').val(filterDefaults.arrival_end_date || '');
        $('#invoice_start_datetime').val(filterDefaults.invoice_start_datetime || '');
        $('#invoice_end_datetime').val(filterDefaults.invoice_end_datetime || '');

        ensureSelectedOptions('#id_supplier_filter', selectedSupplierIds, supplierFilterData);
        ensureSelectedOptions('#id_produk_filter', selectedProductIds, productFilterData);

        $('#id_supplier_filter').val(selectedSupplierIds).trigger('change');
        $('#id_produk_filter').val(selectedProductIds).trigger('change');
    }

    function debounce(callback, delay) {
        let timerId = null;

        return function () {
            const args = arguments;
            const context = this;

            window.clearTimeout(timerId);
            timerId = window.setTimeout(function () {
                callback.apply(context, args);
            }, delay);
        };
    }

    $(function () {
        initializeSupplierFilterSelect();
        initializeProductFilterSelect();
        applyInitialFilters();

        table = $('.table-pembelian').DataTable({
            dom: 'lrtip',
            order: [[1, 'desc']],
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
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            search: {
                search: filterDefaults.search_text || ''
            },
            ajax: {
                url: '{{ route('pembelian.data') }}',
                data: function (d) {
                    const filters = collectFilters();
                    d.arrival_date_preset = filters.arrival_date_preset;
                    d.arrival_start_date = filters.arrival_start_date;
                    d.arrival_end_date = filters.arrival_end_date;
                    d.invoice_start_datetime = filters.invoice_start_datetime;
                    d.invoice_end_datetime = filters.invoice_end_datetime;
                    d.id_supplier = filters.id_supplier.length > 0 ? filters.id_supplier : '';
                    d.id_produk = filters.id_produk.length > 0 ? filters.id_produk : '';
                },
                error: function(xhr, error, code) {
                    console.log('DataTables Error:', error);
                    console.log('Status:', xhr.status);
                    console.log('Response:', xhr.responseText);
                }
            },
            columns: [
                {data: 'DT_RowIndex', name: 'pembelian.id_pembelian', searchable: false, orderable: false},
                {data: 'tanggal', name: 'pembelian.created_at', searchable: false, orderable: true},
                {data: 'supplier', name: 'supplier', searchable: false, orderable: true},
                {data: 'total_item', name: 'pembelian.total_item', searchable: false, orderable: true},
                {data: 'total_harga', name: 'pembelian.total_harga', searchable: false, orderable: true},
                {data: 'diskon', name: 'pembelian.diskon', searchable: false, orderable: true},
                {data: 'bayar', name: 'pembelian.bayar', searchable: false, orderable: true},
                {data: 'waktu', name: 'waktu', searchable: false, orderable: true},
                {data: 'aksi', name: 'aksi', searchable: false, orderable: false},
            ]
        }).on('draw.dt', function () {
            $('[data-toggle="tooltip"]').tooltip();
        }).on('error.dt', function (e, settings, techNote, message) {
            console.error('DataTables error:', message);
        });

        $('.table-supplier').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 'tr'
                }
            },
            scrollX: true,
            scrollCollapse: true,
            autoWidth: false,
            order: [[1, 'asc']],
            language: {
                search: "Cari supplier:",
                lengthMenu: "Tampilkan _MENU_ supplier",
                info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ supplier",
                paginate: {
                    first: "Pertama",
                    last: "Terakhir", 
                    next: "Selanjutnya",
                    previous: "Sebelumnya"
                }
            }
        });
        table1 = $('.table-detail').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 'tr'
                }
            },
            processing: true,
            bSort: false,
            dom: 'fplrt',
            searchable: true,
            scrollX: true,
            scrollCollapse: true,
            autoWidth: false,
            columns: [
                {data: 'DT_RowIndex', searchable: false, sortable: false},
                {data: 'kode_produk'},
                {data: 'nama_produk'},
                {data: 'harga_beli'},
                {data: 'jumlah'},
                {data: 'subtotal'},
            ]
        });

        $('#arrival_date_preset').on('change', function () {
            $('#arrival_start_date, #arrival_end_date').attr('title', 'Pilih rentang tanggal obat datang untuk audit pembelian');
        });

        $('#arrival_start_date, #arrival_end_date').on('focus change', function () {
            if ($('#arrival_date_preset').val() !== 'custom') {
                $('#arrival_date_preset').val('custom');
            }
        });

        $('#btnApplyFilter').on('click', function () {
            table.search($('#purchase_search').val() || '');
            const filters = collectFilters();
            updateFilterUrl(filters);
            table.ajax.reload();
        });

        $('#btnResetFilter').on('click', function () {
            $('#purchase_search').val('');
            $('#arrival_date_preset').val('all');
            $('#arrival_start_date').val('');
            $('#arrival_end_date').val('');
            $('#invoice_start_datetime').val('');
            $('#invoice_end_datetime').val('');
            $('#id_supplier_filter').val(null).trigger('change');
            $('#id_produk_filter').val(null).trigger('change');

            table.search('');
            const filters = collectFilters();
            updateFilterUrl(filters);
            table.ajax.reload();
        });

        $('#purchase_search').on('input', debounce(function () {
            const value = $(this).val() || '';
            table.search(value);
            updateFilterUrl(collectFilters());
            table.draw();
        }, 350));
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