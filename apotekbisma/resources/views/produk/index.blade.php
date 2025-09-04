@extends('layouts.master')

@section('title')
    Daftar Produk
@endsection

@push('css')
<style>
    .box-header .label {
        margin-left: 5px;
        font-size: 12px;
        padding: 4px 8px;
    }
    .table td {
        vertical-align: middle !important;
        font-size: 13px;
    }
    .table th {
        font-size: 13px;
        font-weight: bold;
    }
    .btn-group .btn-xs {
        padding: 3px 8px;
        font-size: 11px;
        margin-right: 2px;
    }
    #filter_stok {
        height: 30px;
        font-size: 13px;
        border-radius: 4px;
        padding: 5px 10px;
    }
    .box-header .form-control {
        margin-top: -2px;
    }
    
    /* Improved button styling */
    .btn-group .btn-xs.btn-success {
        background-color: #28a745;
        border-color: #28a745;
        color: white;
    }
    .btn-group .btn-xs.btn-success:hover {
        background-color: #218838;
        border-color: #1e7e34;
    }
    .btn-group .btn-xs.btn-info {
        background-color: #17a2b8;
        border-color: #17a2b8;
    }
    .btn-group .btn-xs.btn-warning {
        background-color: #ffc107;
        border-color: #ffc107;
        color: #212529;
    }
    .btn-group .btn-xs.btn-danger {
        background-color: #dc3545;
        border-color: #dc3545;
    }
    
    /* Style untuk modal update stok */
    #modal-update-stok .modal-header {
        background-color: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
    }
    #modal-update-stok .modal-title {
        font-size: 18px;
        font-weight: 600;
        color: #495057;
    }
    #modal-update-stok .alert-info {
        margin-bottom: 20px;
        border-left: 4px solid #5bc0de;
        background-color: #d1ecf1;
        border-color: #bee5eb;
    }
    #modal-update-stok .form-control-static {
        padding-top: 7px;
        padding-bottom: 7px;
        margin-bottom: 0;
        font-weight: bold;
        font-size: 14px;
    }
    #modal-update-stok .form-control {
        font-size: 14px;
        padding: 8px 12px;
    }
    #modal-update-stok .btn {
        font-size: 14px;
        padding: 8px 16px;
    }
    
    /* Improved table styling */
    .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #f8f9fa;
    }
    .table > thead > tr > th {
        background-color: #e9ecef;
        color: #495057;
        border-bottom: 2px solid #dee2e6;
    }
    
    /* Better visibility for stock status */
    .text-danger {
        font-weight: bold;
        font-size: 14px;
    }
    .text-warning {
        font-weight: bold;
        font-size: 14px;
    }
    .text-success {
        font-weight: bold;
        font-size: 14px;
    }
    
    /* Header button improvements */
    .box-header .btn {
        font-size: 13px;
        padding: 6px 12px;
        margin-right: 5px;
    }
    
    /* Mobile responsive fixes */
    .table-responsive-mobile {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Hide mobile elements on desktop */
    .mobile-scroll-hint {
        display: none;
    }
    
    .mobile-header-section .btn-group {
        display: block;
    }
    
    .mobile-header-section .btn-group .btn {
        display: inline-block;
        width: auto;
        margin-right: 5px;
        margin-bottom: 5px;
    }
    
    .mobile-filter-section .filter-container {
        background: none;
        padding: 0;
        border: none;
        display: inline-block;
        margin-left: 15px;
    }
    
    .mobile-filter-section .control-label {
        display: inline-block;
        margin-right: 8px;
        margin-bottom: 0;
        line-height: 30px;
    }
    
    .mobile-filter-section #filter_stok {
        display: inline-block;
        width: 180px !important;
        height: 30px;
    }
    
    .mobile-summary-section .stock-alerts {
        background: none;
        border: none;
        padding: 0;
        text-align: right;
        float: right;
    }
    
    @media (max-width: 768px) {
        /* Show mobile elements only on mobile */
        .mobile-scroll-hint {
            display: block !important;
        }
        
        /* Enhanced mobile layout for produk page */
        .table-responsive-mobile {
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            position: relative;
        }
        
        /* Mobile scroll hint */
        .mobile-scroll-hint {
            display: block;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 8px 12px;
            text-align: center;
            font-size: 12px;
            margin-bottom: 0;
            border-radius: 4px 4px 0 0;
            animation: pulse 2s infinite;
        }
        
        .mobile-scroll-hint i {
            margin-right: 5px;
            animation: slideRight 1.5s infinite ease-in-out;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        @keyframes slideRight {
            0%, 100% { transform: translateX(0); }
            50% { transform: translateX(5px); }
        }
        
        /* Hide hint after first scroll */
        .table-responsive-mobile.scrolled .mobile-scroll-hint {
            display: none;
        }
        
        .table {
            min-width: 1200px;
            margin-bottom: 0;
            font-size: 11px;
        }
        
        /* Optimized column widths for mobile */
        .table td, 
        .table th {
            white-space: nowrap;
            padding: 4px 2px;
            font-size: 10px;
            border: 1px solid #ddd;
        }
        
        /* Specific column styling */
        .table th:nth-child(1), .table td:nth-child(1) { width: 30px; } /* Checkbox */
        .table th:nth-child(2), .table td:nth-child(2) { width: 30px; } /* No */
        .table th:nth-child(3), .table td:nth-child(3) { width: 60px; } /* Kode */
        .table th:nth-child(4), .table td:nth-child(4) { width: 120px; } /* Nama */
        .table th:nth-child(5), .table td:nth-child(5) { width: 70px; } /* Kategori */
        .table th:nth-child(6), .table td:nth-child(6) { width: 60px; } /* Merk */
        .table th:nth-child(7), .table td:nth-child(7) { width: 70px; } /* Harga Beli */
        .table th:nth-child(8), .table td:nth-child(8) { width: 70px; } /* Harga Jual */
        .table th:nth-child(9), .table td:nth-child(9) { width: 80px; } /* Expired */
        .table th:nth-child(10), .table td:nth-child(10) { width: 60px; } /* Batch */
        .table th:nth-child(11), .table td:nth-child(11) { width: 40px; } /* Stok */
        
        /* Enhanced sticky action column */
        .table td:last-child,
        .table th:last-child {
            position: sticky;
            right: 0;
            background-color: #fff;
            border-left: 2px solid #007bff;
            z-index: 10;
            box-shadow: -3px 0 8px rgba(0,0,0,0.2);
            min-width: 120px;
            max-width: 120px;
        }
        
        /* Improved button layout in header */
        .mobile-header-section .btn-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .mobile-header-section .btn-group .btn {
            width: 100%;
            margin-bottom: 0;
            font-size: 13px;
            padding: 10px 15px;
            text-align: left;
            border-radius: 4px;
        }
        
        /* Filter section improvements */
        .mobile-filter-section {
            margin-bottom: 15px;
        }
        
        .mobile-filter-section .filter-container {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .mobile-filter-section .control-label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 14px;
            color: #495057;
        }
        
        .mobile-filter-section #filter_stok {
            width: 100% !important;
            height: 42px;
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid #ced4da;
        }
        
        /* Stock summary section */
        .mobile-summary-section {
            margin-bottom: 15px;
        }
        
        .mobile-summary-section .stock-alerts {
            text-align: center;
            padding: 10px;
            background: #fff3cd;
            border-radius: 6px;
            border: 1px solid #ffeaa7;
        }
        
        .mobile-summary-section .label {
            display: inline-block;
            margin: 3px;
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 4px;
        }
        
        /* Table action buttons */
        .btn-group .btn-xs {
            padding: 2px 4px;
            font-size: 9px;
            margin: 1px;
            min-width: 25px;
        }
        
        /* Compact checkboxes */
        input[type="checkbox"] {
            transform: scale(1.2);
        }
        
        /* Improved text truncation for product names */
        .table td:nth-child(4) {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Enhanced DataTable controls for mobile */
        .dataTables_wrapper .dataTables_length {
            margin-bottom: 10px;
        }
        
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 10px;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            width: 100% !important;
            max-width: none !important;
            margin-left: 0;
        }
        
        .dataTables_wrapper .dataTables_info {
            font-size: 11px;
            margin-top: 10px;
        }
        
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 10px;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 4px 8px;
            margin: 0 1px;
            font-size: 11px;
        }
        
        /* Form improvements */
        .form-produk {
            overflow-x: auto;
        }
        
        /* Box content spacing */
        .box-body {
            padding: 8px;
        }
        
        .box-header {
            padding: 10px;
        }
    }
    
    /* Extra small screens (portrait phones, less than 480px) */
    @media (max-width: 479px) {
        .table {
            min-width: 1100px;
            font-size: 9px;
        }
        
        .table td, 
        .table th {
            padding: 3px 1px;
            font-size: 9px;
        }
        
        .mobile-header-section .btn-group .btn {
            font-size: 12px;
            padding: 8px 12px;
        }
        
        .mobile-filter-section #filter_stok {
            height: 38px;
            font-size: 13px;
        }
        
        .btn-group .btn-xs {
            padding: 1px 3px;
            font-size: 8px;
            min-width: 20px;
        }
        
        /* Hide less critical columns on very small screens */
        .table th:nth-child(6), .table td:nth-child(6), /* Merk */
        .table th:nth-child(10), .table td:nth-child(10) { /* Batch */
            display: none;
        }
        
        /* Adjust remaining columns */
        .table {
            min-width: 900px;
        }
    }
    
    /* Touch device optimizations */
    @media (hover: none) and (pointer: coarse) {
        /* Larger touch targets for mobile devices */
        .mobile-header-section .btn {
            min-height: 48px;
            padding: 12px 16px;
        }
        
        .mobile-filter-section #filter_stok {
            min-height: 48px;
            padding: 12px;
        }
        
        input[type="checkbox"] {
            min-width: 20px;
            min-height: 20px;
        }
        
        .btn-group .btn-xs {
            min-height: 32px;
            min-width: 32px;
            padding: 4px 6px;
        }
        
        /* Better scrollbar for touch devices */
        .table-responsive-mobile::-webkit-scrollbar {
            height: 12px;
        }
        
        .table-responsive-mobile::-webkit-scrollbar-track {
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .table-responsive-mobile::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #007bff, #0056b3);
            border-radius: 6px;
            border: 2px solid #f8f9fa;
        }
        
        .table-responsive-mobile::-webkit-scrollbar-thumb:active {
            background: linear-gradient(135deg, #0056b3, #004085);
        }
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
                <!-- Action buttons section -->
                <div class="mobile-header-section">
                    <div class="btn-group" role="group">
                        <button onclick="addForm('{{ route('produk.store') }}')" class="btn btn-success btn-sm btn-flat">
                            <i class="fa fa-plus-circle"></i> 
                            <span class="hidden-xs">Tambah Produk</span>
                            <span class="visible-xs-inline">Tambah</span>
                        </button>
                        <button onclick="deleteSelected('{{ route('produk.delete_selected') }}')" class="btn btn-danger btn-sm btn-flat">
                            <i class="fa fa-trash"></i> 
                            <span class="hidden-xs">Hapus Terpilih</span>
                            <span class="visible-xs-inline">Hapus</span>
                        </button>
                        <button onclick="cetakBarcode('{{ route('produk.cetak_barcode') }}')" class="btn btn-info btn-sm btn-flat">
                            <i class="fa fa-barcode"></i> 
                            <span class="hidden-xs">Cetak Barcode</span>
                            <span class="visible-xs-inline">Barcode</span>
                        </button>
                        <a href="{{ route('importview') }}" class="btn btn-warning btn-sm btn-flat">
                            <i class="fa fa-upload"></i> 
                            <span class="hidden-xs">Import Excel</span>
                            <span class="visible-xs-inline">Import</span>
                        </a>
                    </div>
                </div>
                
                <!-- Filter section -->
                <div class="mobile-filter-section">
                    <div class="filter-container">
                        <label for="filter_stok" class="control-label">
                            <i class="fa fa-filter"></i> Filter Stok:
                        </label>
                        <select id="filter_stok" class="form-control input-sm">
                            <option value="">Semua Produk</option>
                            <option value="habis">Stok Habis (≤0)</option>
                            <option value="menipis">Stok Menipis (=1)</option>
                            <option value="kritis">Stok Kritis (≤1)</option>
                            <option value="normal">Stok Normal (>1)</option>
                        </select>
                    </div>
                </div>
                
                <!-- Stock summary section -->
                @php
                    $produkMenipis = \App\Models\Produk::where('stok', '=', 1)->count();
                    $produkHabis = \App\Models\Produk::where('stok', '<=', 0)->count();
                @endphp
                
                @if($produkMenipis > 0 || $produkHabis > 0)
                <div class="mobile-summary-section">
                    <div class="stock-alerts">
                        @if($produkHabis > 0)
                            <span class="label label-danger">
                                <i class="fa fa-exclamation-triangle"></i> 
                                {{ $produkHabis }} Produk Stok Habis
                            </span>
                        @endif
                        @if($produkMenipis > 0)
                            <span class="label label-warning">
                                <i class="fa fa-warning"></i> 
                                {{ $produkMenipis }} Produk Stok Menipis
                            </span>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            <div class="box-body">
                <div class="table-responsive-mobile" id="produk-table-container">
                    <div class="mobile-scroll-hint">
                        <i class="fa fa-hand-o-right"></i> Geser tabel ke kanan untuk melihat kolom lainnya
                    </div>
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
</div>

@includeIf('produk.form')

{{-- Modal untuk Update Stok Manual --}}
<div class="modal fade" id="modal-update-stok" tabindex="-1" role="dialog" aria-labelledby="modal-update-stok">
    <div class="modal-dialog" role="document">
        <form id="form-update-stok" action="" method="post">
            @csrf
            @method('PUT')
            
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">
                        <i class="fa fa-refresh"></i> Update Stok Manual
                    </h4>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i>
                        <strong>Perhatian:</strong> Fitur ini untuk penyesuaian stok dengan kondisi fisik barang. 
                        Semua perubahan akan tercatat dalam sistem dengan format keterangan: <br>
                        <em>"Perubahan Stok Manual"</em> atau <em>"Perubahan Stok Manual: [keterangan Anda]"</em>
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Produk:</strong></label>
                        <p id="produk_info" class="form-control-static text-primary"></p>
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Stok Saat Ini:</strong></label>
                        <p id="stok_saat_ini" class="form-control-static text-info"></p>
                    </div>
                    
                    <div class="form-group">
                        <label for="stok_baru">Stok Baru <span class="text-danger">*</span></label>
                        <input type="number" name="stok" id="stok_baru" class="form-control" required min="0" 
                               placeholder="Masukkan jumlah stok sesuai kondisi fisik barang">
                        <small class="help-block">Masukkan jumlah stok yang sesuai dengan kondisi fisik barang di apotek</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="keterangan_stok">Keterangan/Alasan Perubahan (Opsional)</label>
                        <textarea name="keterangan" id="keterangan_stok" class="form-control" rows="3"
                                  placeholder="Contoh: Stok opname bulanan, barang rusak/expired, penyesuaian fisik, dll."></textarea>
                        <small class="help-block">
                            <strong>Opsional:</strong> Jika diisi, akan muncul di kartu stok sebagai: <br>
                            <code>"Perubahan Stok Manual: [keterangan Anda]"</code><br>
                            Jika kosong, akan tercatat sebagai: <code>"Perubahan Stok Manual"</code>
                        </small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>Catatan:</strong> 
                        <ul class="mb-0" style="margin-bottom: 0; padding-left: 20px;">
                            <li>Untuk penambahan stok hasil pembelian, gunakan menu <strong>Pembelian</strong></li>
                            <li>Perubahan ini akan tercatat di kartu stok untuk audit</li>
                            <li>Pastikan jumlah stok sesuai dengan kondisi fisik barang</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update Stok
                    </button>
                    <button type="button" class="btn btn-default" data-dismiss="modal">
                        <i class="fa fa-times"></i> Batal
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

{{-- Modal Update Stok Manual --}}
<div class="modal fade" id="modal-update-stok" tabindex="-1" role="dialog" aria-labelledby="modal-update-stok">
    <div class="modal-dialog" role="document">
        <form id="form-update-stok" method="post" class="form-horizontal">
            @csrf
            @method('put')

            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">Update Stok Manual</h4>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fa fa-warning"></i> <strong>Perhatian!</strong><br>
                        Fitur ini akan mengubah stok produk secara langsung dan membuat rekaman stok untuk tracking. 
                        Gunakan hanya untuk penyesuaian stok dengan kondisi fisik barang di toko.
                    </div>
                    
                    <div class="form-group">
                        <label for="produk_info" class="col-lg-3 control-label">Produk</label>
                        <div class="col-lg-9">
                            <p id="produk_info" class="form-control-static"><strong></strong></p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="stok_saat_ini" class="col-lg-3 control-label">Stok Saat Ini</label>
                        <div class="col-lg-9">
                            <p id="stok_saat_ini" class="form-control-static badge badge-info"></p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="stok_baru" class="col-lg-3 control-label">Stok Baru</label>
                        <div class="col-lg-9">
                            <input type="number" name="stok" id="stok_baru" class="form-control" min="0" required>
                            <span class="help-block">Masukkan jumlah stok yang sesuai dengan kondisi fisik barang</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="keterangan_stok" class="col-lg-3 control-label">Keterangan</label>
                        <div class="col-lg-9">
                            <textarea name="keterangan" id="keterangan_stok" class="form-control" rows="3" 
                                placeholder="Opsional: Alasan penyesuaian stok (contoh: Stok opname, barang rusak, dll)"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Update Stok
                    </button>
                    <button type="button" class="btn btn-default" data-dismiss="modal">
                        <i class="fa fa-times"></i> Batal
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    let table;

    $(function () {
        // Mobile scroll hint functionality
        function initMobileScrollHint() {
            if (window.innerWidth <= 768) {
                const container = $('#produk-table-container');
                let hasScrolled = false;
                
                container.on('scroll', function() {
                    if (!hasScrolled) {
                        hasScrolled = true;
                        container.addClass('scrolled');
                        // Hide hint after 3 seconds of first scroll
                        setTimeout(() => {
                            container.find('.mobile-scroll-hint').fadeOut(500);
                        }, 3000);
                    }
                });
                
                // Auto-hide hint after 10 seconds if no interaction
                setTimeout(() => {
                    if (!hasScrolled) {
                        container.find('.mobile-scroll-hint').fadeOut(500);
                    }
                }, 10000);
            } else {
                // Hide hint on desktop
                $('.mobile-scroll-hint').hide();
            }
        }
        
        // Initialize mobile enhancements
        initMobileScrollHint();
        
        // Re-initialize on window resize
        $(window).on('resize', function() {
            initMobileScrollHint();
        });
        
        table = $('.table').DataTable({
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

    function updateStokManual(id, namaProduk, stokSaatIni) {
        $('#modal-update-stok').modal('show');
        $('#form-update-stok').attr('action', '{{ route("produk.update_stok_manual", ":id") }}'.replace(':id', id));
        $('#produk_info').html('<strong>' + namaProduk + '</strong>');
        $('#stok_saat_ini').text(stokSaatIni + ' unit');
        $('#stok_baru').val(stokSaatIni);
        $('#keterangan_stok').val('');
        
        // Focus pada input stok baru
        setTimeout(function() {
            $('#stok_baru').focus().select();
        }, 500);
    }

    // Handle form submit untuk update stok manual
    $('#form-update-stok').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this);
        const url = form.attr('action');
        const stokBaru = $('#stok_baru').val();
        const keterangan = $('#keterangan_stok').val().trim();
        
        // Validasi stok tidak boleh kosong
        if (!stokBaru || stokBaru < 0) {
            alert('Stok harus diisi dengan angka yang valid (≥ 0)!');
            $('#stok_baru').focus();
            return false;
        }
        
        const formData = form.serialize();
        
        $.ajax({
            url: url,
            method: 'PUT',
            data: formData,
            beforeSend: function() {
                form.find('button[type="submit"]').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
            },
            success: function(response) {
                $('#modal-update-stok').modal('hide');
                table.ajax.reload();
                
                if (response.success) {
                    const data = response.data;
                    let message = 'Stok berhasil diperbarui dan disinkronkan!\n\n';
                    message += 'Produk: ' + $('#produk_info').text() + '\n';
                    message += 'Stok lama: ' + data.stok_lama + ' unit\n';
                    message += 'Stok baru: ' + data.stok_baru + ' unit\n';
                    message += 'Selisih: ' + (data.selisih >= 0 ? '+' : '') + data.selisih + ' unit\n';
                    
                    if (keterangan) {
                        message += 'Keterangan: ' + keterangan;
                    } else {
                        message += 'Keterangan: Update stok manual (tanpa keterangan khusus)';
                    }
                    
                    message += '\n\n✓ Rekaman stok telah disinkronkan otomatis';
                    
                    alert(message);
                }
            },
            error: function(xhr) {
                let errorMsg = 'Terjadi kesalahan saat memperbarui stok';
                
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (xhr.responseJSON && xhr.responseJSON.errors) {
                    const errors = xhr.responseJSON.errors;
                    errorMsg = Object.values(errors).flat().join('\n');
                }
                
                alert('Error: ' + errorMsg);
            },
            complete: function() {
                form.find('button[type="submit"]').prop('disabled', false).html('<i class="fa fa-save"></i> Update Stok');
            }
        });
    });
</script>
@endpush