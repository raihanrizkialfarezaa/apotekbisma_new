@extends('layouts.master')

@section('title')
    Dashboard Kasir
@endsection

@push('css')
<style>
    .kasir-metric-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        text-align: center;
    }
    .kasir-welcome {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: white;
        border-radius: 15px;
        padding: 40px;
        text-align: center;
        margin-bottom: 30px;
    }
    .quick-action {
        margin: 10px 0;
    }
    .chart-container {
        height: 250px;
        padding: 10px;
    }
</style>
@endpush

@section('breadcrumb')
    @parent
    <li class="active">Dashboard</li>
@endsection

@section('content')
<!-- Welcome Section -->
<div class="row">
    <div class="col-lg-12">
        <div class="kasir-welcome">
            <h1><i class="fa fa-user"></i> Selamat Datang, {{ auth()->user()->name }}!</h1>
            <h3>Dashboard Kasir - {{ date('d F Y') }}</h3>
            <p>Siap melayani pelanggan dengan sistem kasir yang mudah dan cepat</p>
            <div class="quick-action">
                <a href="{{ route('penjualan.create') }}" class="btn btn-success btn-lg">
                    <i class="fa fa-shopping-cart"></i> Transaksi Baru
                </a>
                <a href="{{ route('penjualan.index') }}" class="btn btn-info btn-lg">
                    <i class="fa fa-list"></i> Lihat Transaksi
                </a>
            </div>
        </div>
    </div>
</div>

@if(isset($analytics))
<!-- Daily Metrics for Kasir -->
<div class="row">
    <div class="col-lg-3 col-md-6">
        <div class="kasir-metric-card">
            <h4><i class="fa fa-shopping-cart"></i> Penjualan Hari Ini</h4>
            <h2>Rp. {{ format_uang($analytics['total_penjualan']) }}</h2>
            <p>{{ $analytics['total_transaksi'] }} transaksi</p>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="kasir-metric-card" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333;">
            <h4><i class="fa fa-cubes"></i> Produk Terjual</h4>
            <h2>{{ format_uang($analytics['total_qty_terjual']) }}</h2>
            <p>Unit terjual</p>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="kasir-metric-card" style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); color: #333;">
            <h4><i class="fa fa-line-chart"></i> Laba Kotor</h4>
            <h2>Rp. {{ format_uang($analytics['laba_kotor']) }}</h2>
            <p>Profit hari ini</p>
        </div>
    </div>

    <div class="col-lg-3 col-md-6">
        <div class="kasir-metric-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: #333;">
            <h4><i class="fa fa-clock-o"></i> Rata-rata/Transaksi</h4>
            <h2>Rp. {{ $analytics['total_transaksi'] > 0 ? format_uang($analytics['total_penjualan'] / $analytics['total_transaksi']) : '0' }}</h2>
            <p>Per transaksi</p>
        </div>
    </div>
</div>

<!-- Charts for Kasir -->
<div class="row">
    <div class="col-lg-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-bar-chart"></i> Grafik Penjualan Hari Ini
                </h3>
            </div>
            <div class="box-body">
                <div class="chart-container">
                    <canvas id="kasirSalesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-star"></i> Produk Terlaris
                </h3>
            </div>
            <div class="box-body">
                @if($analytics['produk_terlaris']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-condensed">
                            <tbody>
                                @foreach($analytics['produk_terlaris']->take(5) as $index => $produk)
                                <tr>
                                    <td>
                                        <span class="badge bg-yellow">#{{ $index + 1 }}</span>
                                    </td>
                                    <td>
                                        <strong>{{ $produk->nama_produk }}</strong><br>
                                        <small class="text-muted">{{ $produk->kode_produk }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-blue">{{ format_uang($produk->total_terjual) }}</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center">Belum ada penjualan hari ini.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions for Kasir -->
<div class="row">
    <div class="col-lg-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-clock-o"></i> Transaksi Terbaru
                </h3>
                <div class="box-tools pull-right">
                    <a href="{{ route('penjualan.index') }}" class="btn btn-primary btn-sm">
                        <i class="fa fa-list"></i> Lihat Semua
                    </a>
                </div>
            </div>
            <div class="box-body">
                @if($analytics['transaksi_terbaru']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID Transaksi</th>
                                    <th>Waktu</th>
                                    <th>Total Items</th>
                                    <th>Total Bayar</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($analytics['transaksi_terbaru']->take(8) as $transaksi)
                                <tr>
                                    <td>
                                        <span class="label label-primary">#{{ $transaksi->id_penjualan }}</span>
                                    </td>
                                    <td>{{ $transaksi->created_at->format('H:i:s') }}</td>
                                    <td>{{ $transaksi->total_item ?? 0 }} items</td>
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
                    <div class="text-center">
                        <p class="text-muted">Belum ada transaksi hari ini.</p>
                        <a href="{{ route('penjualan.create') }}" class="btn btn-success">
                            <i class="fa fa-plus"></i> Mulai Transaksi Pertama
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

<!-- Quick Actions -->
<div class="row">
    <div class="col-lg-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-bolt"></i> Aksi Cepat
                </h3>
            </div>
            <div class="box-body text-center">
                <a href="{{ route('penjualan.create') }}" class="btn btn-success btn-lg" style="margin: 10px;">
                    <i class="fa fa-plus"></i><br>Transaksi Baru
                </a>
                <a href="{{ route('penjualan.index') }}" class="btn btn-info btn-lg" style="margin: 10px;">
                    <i class="fa fa-list"></i><br>Riwayat Transaksi
                </a>
                <a href="{{ route('produk.index') }}" class="btn btn-warning btn-lg" style="margin: 10px;">
                    <i class="fa fa-search"></i><br>Cari Produk
                </a>
                <a href="{{ route('member.index') }}" class="btn btn-purple btn-lg" style="margin: 10px;">
                    <i class="fa fa-users"></i><br>Data Member
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@if(isset($analytics))
@push('scripts')
<script src="{{ asset('AdminLTE-2/bower_components/chart.js/Chart.js') }}"></script>
<script>
$(function() {
    // Debug chart data for kasir
    console.log('Kasir Chart Data:', {!! json_encode($analytics['chart_data']) !!});
    
    // Kasir Sales Chart (hourly for today) - Chart.js v1.x syntax
    var kasirCtx = $('#kasirSalesChart').get(0).getContext('2d');
    var kasirData = {
        labels: {!! json_encode($analytics['chart_data']['labels']) !!},
        datasets: [{
            label: 'Penjualan per Jam',
            fillColor: 'rgba(60,141,188,0.1)',
            strokeColor: 'rgba(60,141,188,1)',
            pointColor: 'rgba(60,141,188,1)',
            pointStrokeColor: '#fff',
            pointHighlightFill: '#fff',
            pointHighlightStroke: 'rgba(60,141,188,1)',
            data: {!! json_encode($analytics['chart_data']['penjualan']) !!}
        }]
    };
    
    var kasirOptions = {
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
        datasetFill: true,
        showTooltips: true,
        tooltipTemplate: "<%if (label){%><%=label%>: <%}%>Rp <%= value.toLocaleString() %>"
    };
    
    var kasirChart = new Chart(kasirCtx).Line(kasirData, kasirOptions);

    // Auto refresh every 2 minutes for kasir
    setInterval(function() {
        location.reload();
    }, 120000);
});
</script>
@endpush
@endif