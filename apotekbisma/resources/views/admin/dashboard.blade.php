@extends('layouts.master')

@section('title')
    Dashboard Analytics
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css') }}">
<style>
    .info-box-icon {
        border-radius: 50%;
        color: #fff !important;
    }
    .info-box-content {
        padding: 5px 10px;
        margin-left: 90px;
    }
    .small-box .icon {
        position: absolute;
        top: auto;
        bottom: 5px;
        right: 5px;
        z-index: 0;
        font-size: 70px;
        color: rgba(0,0,0,0.15);
    }
    .chart-container {
        height: 300px;
        padding: 10px;
    }
    .period-selector {
        margin-bottom: 20px;
    }
    .period-buttons .btn {
        margin-right: 5px;
        margin-bottom: 5px;
    }
    .metric-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .profit-positive {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    }
    .profit-negative {
        background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    }
    .revenue-card {
        background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        color: #333;
    }
    .transaction-card {
        background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
        color: #333;
    }
    .supplier-section {
        border-left: 4px solid #3c8dbc;
        background: #f9f9f9;
    }
    .supplier-section h5 {
        margin-top: 0;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 8px;
    }
    .bg-gold {
        background-color: #f39c12 !important;
    }
    .bg-light-blue {
        background-color: #3c8dbc !important;
    }
    .bg-orange {
        background-color: #ff851b !important;
    }
    .bg-gray {
        background-color: #95a5a6 !important;
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Dashboard</li>
@endsection

@section('content')
<!-- Period Selection -->
<div class="row period-selector">
    <div class="col-lg-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-calendar"></i> Periode Analisis</h3>
            </div>
            <div class="box-body">
                <form method="GET" action="{{ route('dashboard') }}" class="form-inline">
                    <div class="form-group period-buttons">
                        <button type="submit" name="period" value="today" 
                                class="btn {{ $period == 'today' ? 'btn-primary' : 'btn-default' }}">
                            Hari Ini
                        </button>
                        <button type="submit" name="period" value="week" 
                                class="btn {{ $period == 'week' ? 'btn-primary' : 'btn-default' }}">
                            Minggu Ini
                        </button>
                        <button type="submit" name="period" value="month" 
                                class="btn {{ $period == 'month' ? 'btn-primary' : 'btn-default' }}">
                            Bulan Ini
                        </button>
                        <button type="submit" name="period" value="year" 
                                class="btn {{ $period == 'year' ? 'btn-primary' : 'btn-default' }}">
                            Tahun Ini
                        </button>
                    </div>
                    
                    <div class="form-group" style="margin-left: 20px;">
                        <label>Custom:</label>
                        <input type="date" name="start_date" class="form-control input-sm" 
                               value="{{ request('start_date') }}" style="width: 140px; display: inline-block;">
                        <span style="margin: 0 5px;">s/d</span>
                        <input type="date" name="end_date" class="form-control input-sm" 
                               value="{{ request('end_date') }}" style="width: 140px; display: inline-block;">
                        <button type="submit" name="period" value="custom" class="btn btn-success btn-sm">
                            <i class="fa fa-search"></i> Tampilkan
                        </button>
                    </div>
                </form>
                
                <div class="mt-10">
                    <small class="text-muted">
                        <i class="fa fa-info-circle"></i> 
                        Periode saat ini: <strong>{{ tanggal_indonesia($tanggal_awal, false) }}</strong> 
                        s/d <strong>{{ tanggal_indonesia($tanggal_akhir, false) }}</strong>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Key Metrics Row -->
<div class="row">
    <div class="col-lg-3 col-md-6">
        <div class="metric-card revenue-card">
            <div class="metric-content">
                <h4><i class="fa fa-shopping-cart"></i> Total Penjualan</h4>
                <h2>Rp. {{ format_uang($analytics['total_penjualan']) }}</h2>
                <p class="mb-0">{{ $analytics['total_transaksi'] }} transaksi</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="metric-card {{ $analytics['laba_bersih'] >= 0 ? 'profit-positive' : 'profit-negative' }}">
            <div class="metric-content">
                <h4><i class="fa fa-line-chart"></i> Laba Bersih</h4>
                <h2>Rp. {{ format_uang($analytics['laba_bersih']) }}</h2>
                <p class="mb-0">{{ $analytics['laba_bersih'] >= 0 ? 'Untung' : 'Rugi' }}</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="metric-card transaction-card">
            <div class="metric-content">
                <h4><i class="fa fa-cubes"></i> Produk Terjual</h4>
                <h2>{{ format_uang($analytics['total_qty_terjual']) }}</h2>
                <p class="mb-0">Unit</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="metric-card">
            <div class="metric-content">
                <h4><i class="fa fa-money"></i> Laba Kotor</h4>
                <h2>Rp. {{ format_uang($analytics['laba_kotor']) }}</h2>
                <p class="mb-0">Sebelum pengeluaran</p>
            </div>
        </div>
    </div>
</div>

<!-- Basic Stats Row -->
<div class="row">
    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3>{{ $kategori }}</h3>
                <p>Total Kategori</p>
            </div>
            <div class="icon">
                <i class="fa fa-cube"></i>
            </div>
            <a href="{{ route('kategori.index') }}" class="small-box-footer">
                Lihat <i class="fa fa-arrow-circle-right"></i>
            </a>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-green">
            <div class="inner">
                <h3>{{ $produk }}</h3>
                <p>Total Produk</p>
            </div>
            <div class="icon">
                <i class="fa fa-cubes"></i>
            </div>
            <a href="{{ route('produk.index') }}" class="small-box-footer">
                Lihat <i class="fa fa-arrow-circle-right"></i>
            </a>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3>{{ $member }}</h3>
                <p>Total Member</p>
            </div>
            <div class="icon">
                <i class="fa fa-id-card"></i>
            </div>
            <a href="{{ route('member.index') }}" class="small-box-footer">
                Lihat <i class="fa fa-arrow-circle-right"></i>
            </a>
        </div>
    </div>

    <div class="col-lg-3 col-xs-6">
        <div class="small-box bg-red">
            <div class="inner">
                <h3>{{ $supplier }}</h3>
                <p>Total Supplier</p>
            </div>
            <div class="icon">
                <i class="fa fa-truck"></i>
            </div>
            <a href="{{ route('supplier.index') }}" class="small-box-footer">
                Lihat <i class="fa fa-arrow-circle-right"></i>
            </a>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row">
    <div class="col-lg-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-bar-chart"></i> Grafik Penjualan & Pendapatan
                </h3>
                <div class="box-tools pull-right">
                    <button type="button" class="btn btn-box-tool" data-widget="collapse">
                        <i class="fa fa-minus"></i>
                    </button>
                </div>
            </div>
            <div class="box-body">
                <div class="chart-container">
                    <canvas id="combinedChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-pie-chart"></i> Grafik Transaksi
                </h3>
            </div>
            <div class="box-body">
                <div class="chart-container">
                    <canvas id="transactionChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Analysis Tables Row -->
<div class="row">
    <div class="col-lg-6">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-star"></i> Produk Terlaris
                </h3>
            </div>
            <div class="box-body">
                @if($analytics['produk_terlaris']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Produk</th>
                                    <th>Terjual</th>
                                    <th>Revenue</th>
                                    <th>Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($analytics['produk_terlaris'] as $index => $produk)
                                <tr>
                                    <td>
                                        <span class="badge bg-{{ $index < 3 ? 'yellow' : 'gray' }}">
                                            #{{ $index + 1 }}
                                        </span>
                                    </td>
                                    <td>
                                        <strong>{{ $produk->nama_produk }}</strong><br>
                                        <small class="text-muted">{{ $produk->kode_produk }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-blue">{{ format_uang($produk->total_terjual) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-green">
                                            Rp. {{ format_uang($produk->total_revenue) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-{{ $produk->total_profit >= 0 ? 'green' : 'red' }}">
                                            Rp. {{ format_uang($produk->total_profit) }}
                                        </span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center">Tidak ada data penjualan dalam periode ini.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-exclamation-triangle"></i> Produk Kurang Laris
                </h3>
            </div>
            <div class="box-body">
                @if($analytics['produk_kurang_laris']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th>Terjual</th>
                                    <th>Revenue</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($analytics['produk_kurang_laris'] as $produk)
                                <tr>
                                    <td>
                                        <strong>{{ $produk->nama_produk }}</strong><br>
                                        <small class="text-muted">{{ $produk->kode_produk }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-orange">{{ format_uang($produk->total_terjual) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-blue">
                                            Rp. {{ format_uang($produk->total_revenue) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="label label-warning">Perlu Promosi</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center">Semua produk terjual dengan baik.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Additional Info Row -->
<div class="row">
    <div class="col-lg-6">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-clock-o"></i> Transaksi Terbaru
                </h3>
            </div>
            <div class="box-body">
                @if($analytics['transaksi_terbaru']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Waktu</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($analytics['transaksi_terbaru'] as $transaksi)
                                <tr>
                                    <td>
                                        <span class="label label-primary">#{{ $transaksi->id_penjualan }}</span>
                                    </td>
                                    <td>
                                        <small>{{ $transaksi->created_at->format('d/m H:i') }}</small>
                                    </td>
                                    <td>
                                        <strong>Rp. {{ format_uang($transaksi->bayar) }}</strong>
                                    </td>
                                    <td>
                                        <span class="label label-success">Selesai</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center">Belum ada transaksi dalam periode ini.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-warning"></i> Stok Menipis
                </h3>
            </div>
            <div class="box-body">
                @if($analytics['stok_menipis']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th>Kode</th>
                                    <th>Stok</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($analytics['stok_menipis'] as $produk)
                                <tr>
                                    <td>{{ $produk->nama_produk }}</td>
                                    <td>
                                        <span class="label label-default">{{ $produk->kode_produk }}</span>
                                    </td>
                                    <td>
                                        <span class="badge {{ $produk->stok == 0 ? 'bg-red' : ($produk->stok <= 1 ? 'bg-yellow' : 'bg-orange') }}">
                                            {{ format_uang($produk->stok) }}
                                        </span>
                                    </td>
                                    <td>
                                        <a href="{{ route('produk.index') }}" class="btn btn-xs btn-primary">
                                            <i class="fa fa-shopping-cart"></i> Beli
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center">Semua produk memiliki stok yang cukup.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Supplier Analytics Section -->
<div class="row">
    <div class="col-lg-6">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-truck"></i> Supplier dengan Pemasok Produk Terbanyak
                </h3>
                <div class="box-tools pull-right">
                    <a href="{{ route('supplier.index') }}" class="btn btn-success btn-sm">
                        <i class="fa fa-list"></i> Lihat Semua
                    </a>
                </div>
            </div>
            <div class="box-body">
                @if($analytics['supplier_terbanyak']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Ranking</th>
                                    <th>Nama Supplier</th>
                                    <th>Total Barang</th>
                                    <th>Jenis Produk</th>
                                    <th>Total Pembelian</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($analytics['supplier_terbanyak'] as $key => $supplier)
                                <tr>
                                    <td>
                                        <span class="badge {{ $key == 0 ? 'bg-gold' : ($key == 1 ? 'bg-light-blue' : ($key == 2 ? 'bg-orange' : 'bg-gray')) }}">
                                            #{{ $key + 1 }}
                                        </span>
                                    </td>
                                    <td>
                                        <strong>{{ $supplier->nama }}</strong>
                                        @if($supplier->telepon)
                                            <br><small class="text-muted"><i class="fa fa-phone"></i> {{ $supplier->telepon }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="label {{ $key < 3 ? 'label-success' : 'label-default' }}">
                                            {{ format_uang($supplier->total_barang_dibeli) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-blue">{{ $supplier->jenis_produk }} produk</span>
                                    </td>
                                    <td>
                                        <strong class="text-green">Rp. {{ format_uang($supplier->total_pembelian) }}</strong>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center">Belum ada data pembelian dari supplier.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-shopping-cart"></i> Produk Favorit per Supplier
                </h3>
            </div>
            <div class="box-body" style="max-height: 400px; overflow-y: auto;">
                @if(!empty($analytics['produk_per_supplier']))
                    @foreach($analytics['produk_per_supplier'] as $supplier_id => $data)
                        <div class="supplier-section" style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px;">
                            <h5 class="text-primary">
                                <i class="fa fa-truck"></i> {{ $data['supplier_info']->nama }}
                                <small class="text-muted">({{ format_uang($data['supplier_info']->total_barang_dibeli) }} barang)</small>
                            </h5>
                            
                            @if($data['produk_terlaris']->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-condensed table-hover">
                                        <thead>
                                            <tr class="bg-light-blue">
                                                <th>Produk</th>
                                                <th>Total Dibeli</th>
                                                <th>Frekuensi</th>
                                                <th>Nilai</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($data['produk_terlaris'] as $index => $produk)
                                            <tr>
                                                <td>
                                                    <strong>{{ $produk->nama_produk }}</strong>
                                                    <br><span class="label label-default">{{ $produk->kode_produk }}</span>
                                                </td>
                                                <td>
                                                    <span class="badge {{ $index == 0 ? 'bg-green' : ($index == 1 ? 'bg-blue' : 'bg-orange') }}">
                                                        {{ format_uang($produk->total_dibeli) }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-muted">{{ $produk->frekuensi_beli }}x beli</small>
                                                </td>
                                                <td>
                                                    <strong class="text-green">Rp. {{ format_uang($produk->total_nilai) }}</strong>
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted">Belum ada data produk untuk supplier ini.</p>
                            @endif
                        </div>
                    @endforeach
                @else
                    <p class="text-muted text-center">Belum ada data produk per supplier.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Produk Favorit per Supplier Berdasarkan Penjualan -->
<div class="row">
    <div class="col-lg-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-star text-yellow"></i> Produk Favorit per Supplier (Berdasarkan Penjualan Terbanyak)
                </h3>
                <div class="box-tools pull-right">
                    <small class="text-muted">Data berdasarkan periode yang dipilih</small>
                </div>
            </div>
            <div class="box-body" style="max-height: 500px; overflow-y: auto;">
                @if(!empty($analytics['produk_favorit_penjualan_per_supplier']))
                    @foreach($analytics['produk_favorit_penjualan_per_supplier'] as $supplier_id => $data)
                        <div class="supplier-section" style="margin-bottom: 20px; padding: 15px; border: 1px solid #d2d6de; border-radius: 5px; background: #f9f9f9;">
                            <h5 class="text-success">
                                <i class="fa fa-industry"></i> {{ $data['supplier_info']->nama }}
                                <small class="text-muted">
                                    (Total {{ format_uang($data['supplier_info']->total_qty_terjual) }} qty terjual | 
                                     {{ $data['supplier_info']->jenis_produk_terjual }} jenis produk)
                                </small>
                            </h5>
                            
                            @if($data['produk_terlaris']->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-condensed table-hover">
                                        <thead>
                                            <tr class="bg-green">
                                                <th style="color: white;">Produk</th>
                                                <th style="color: white;">Qty Terjual</th>
                                                <th style="color: white;">Frekuensi Jual</th>
                                                <th style="color: white;">Revenue</th>
                                                <th style="color: white;">Profit</th>
                                                <th style="color: white;">Ranking</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($data['produk_terlaris'] as $index => $produk)
                                            <tr>
                                                <td>
                                                    <strong>{{ $produk->nama_produk }}</strong>
                                                    <br><span class="label label-primary">{{ $produk->kode_produk }}</span>
                                                </td>
                                                <td>
                                                    <span class="badge {{ $index == 0 ? 'bg-gold' : ($index == 1 ? 'bg-green' : 'bg-blue') }}">
                                                        {{ format_uang($produk->total_terjual) }} pcs
                                                    </span>
                                                </td>
                                                <td>
                                                    <small class="text-info">
                                                        <i class="fa fa-refresh"></i> {{ $produk->frekuensi_terjual }}x transaksi
                                                    </small>
                                                </td>
                                                <td>
                                                    <strong class="text-green">Rp. {{ format_uang($produk->total_revenue) }}</strong>
                                                </td>
                                                <td>
                                                    <strong class="text-blue">Rp. {{ format_uang($produk->total_profit) }}</strong>
                                                </td>
                                                <td>
                                                    @if($index == 0)
                                                        <span class="badge bg-yellow"><i class="fa fa-trophy"></i> #1</span>
                                                    @elseif($index == 1)
                                                        <span class="badge bg-gray"><i class="fa fa-medal"></i> #2</span>
                                                    @else
                                                        <span class="badge bg-orange"><i class="fa fa-award"></i> #3</span>
                                                    @endif
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted">Belum ada data penjualan untuk supplier ini.</p>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="alert alert-info text-center">
                        <i class="fa fa-info-circle"></i> Belum ada data penjualan untuk supplier dalam periode ini.
                        <br><small>Silakan pilih periode yang berbeda atau tambahkan data penjualan.</small>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('AdminLTE-2/bower_components/chart.js/Chart.js') }}"></script>
<script src="{{ asset('/AdminLTE-2/bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js') }}"></script>
<script>
$(function() {
    // Debug chart data
    console.log('Chart Data:', {!! json_encode($analytics['chart_data']) !!});
    
    // Combined Sales & Revenue Chart (Chart.js v1.x syntax)
    var combinedCtx = $('#combinedChart').get(0).getContext('2d');
    var combinedData = {
        labels: {!! json_encode($analytics['chart_data']['labels']) !!},
        datasets: [
            {
                label: 'Penjualan',
                fillColor: 'rgba(60,141,188,0.1)',
                strokeColor: 'rgba(60,141,188,1)',
                pointColor: 'rgba(60,141,188,1)',
                pointStrokeColor: '#fff',
                pointHighlightFill: '#fff',
                pointHighlightStroke: 'rgba(60,141,188,1)',
                data: {!! json_encode($analytics['chart_data']['penjualan']) !!}
            },
            {
                label: 'Pembelian',
                fillColor: 'rgba(210, 214, 222, 0.1)',
                strokeColor: 'rgba(210, 214, 222, 1)',
                pointColor: 'rgba(210, 214, 222, 1)',
                pointStrokeColor: '#fff',
                pointHighlightFill: '#fff',
                pointHighlightStroke: 'rgba(210, 214, 222, 1)',
                data: {!! json_encode($analytics['chart_data']['pembelian']) !!}
            },
            {
                label: 'Pendapatan',
                fillColor: 'rgba(0,166,90,0.1)',
                strokeColor: 'rgba(0,166,90,1)',
                pointColor: 'rgba(0,166,90,1)',
                pointStrokeColor: '#fff',
                pointHighlightFill: '#fff',
                pointHighlightStroke: 'rgba(0,166,90,1)',
                data: {!! json_encode($analytics['chart_data']['pendapatan']) !!}
            }
        ]
    };
    
    var combinedOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scaleBeginAtZero: true,
        scaleShowGridLines: true,
        scaleGridLineColor: "rgba(0,0,0,.05)",
        scaleGridLineWidth: 1,
        scaleShowHorizontalLines: true,
        scaleShowVerticalLines: true,
        bezierCurve: true,
        bezierCurveTension: 0.4,
        pointDot: true,
        pointDotRadius: 4,
        pointDotStrokeWidth: 1,
        pointHitDetectionRadius: 20,
        datasetStroke: true,
        datasetStrokeWidth: 2,
        datasetFill: false,
        showTooltips: true,
        tooltipTemplate: "<%if (label){%><%=label%>: <%}%>Rp <%= value.toLocaleString() %>",
        multiTooltipTemplate: "<%=datasetLabel%>: Rp <%= value.toLocaleString() %>"
    };
    
    var combinedChart = new Chart(combinedCtx).Line(combinedData, combinedOptions);

    // Transaction Count Chart (Chart.js v1.x syntax)
    var transactionCtx = $('#transactionChart').get(0).getContext('2d');
    var transactionData = {
        labels: {!! json_encode($analytics['chart_data']['labels']) !!},
        datasets: [{
            label: 'Jumlah Transaksi',
            fillColor: 'rgba(60,141,188,0.8)',
            strokeColor: 'rgba(60,141,188,1)',
            pointColor: 'rgba(60,141,188,1)',
            pointStrokeColor: '#fff',
            pointHighlightFill: '#fff',
            pointHighlightStroke: 'rgba(60,141,188,1)',
            data: {!! json_encode($analytics['chart_data']['transaksi']) !!}
        }]
    };
    
    var transactionOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scaleBeginAtZero: true,
        scaleShowGridLines: true,
        scaleGridLineColor: "rgba(0,0,0,.05)",
        scaleGridLineWidth: 1,
        showTooltips: true,
        tooltipTemplate: "<%if (label){%><%=label%>: <%}%><%= value %> transaksi"
    };
    
    var transactionChart = new Chart(transactionCtx).Bar(transactionData, transactionOptions);

    // Auto refresh every 5 minutes
    setInterval(function() {
        location.reload();
    }, 300000);
});
</script>
@endpush