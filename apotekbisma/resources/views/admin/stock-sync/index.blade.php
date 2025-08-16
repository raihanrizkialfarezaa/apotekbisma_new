@extends('layouts.master')

@section('title')
Sinkronisasi Stok
@endsection

@section('breadcrumb')
@parent
<li class="active">Sinkronisasi Stok</li>
@endsection

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box">
            <div class="box-header">
                <h3 class="box-title">
                    <i class="fa fa-refresh"></i> Sinkronisasi Rekaman Stok
                </h3>
            </div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-box">
                            <span class="info-box-icon bg-blue"><i class="fa fa-database"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Health Score</span>
                                <span class="info-box-number" id="health-score">{{ $analysis['health_score'] }}%</span>
                                <div class="progress">
                                    <div class="progress-bar" style="width: {{ $analysis['health_score'] }}%"></div>
                                </div>
                                <span class="progress-description">
                                    @if($analysis['health_score'] >= 80)
                                        <text class="text-green">Sistem Sehat</text>
                                    @elseif($analysis['health_score'] >= 60)
                                        <text class="text-yellow">Perlu Perhatian</text>
                                    @else
                                        <text class="text-red">Perlu Sinkronisasi</text>
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-box">
                            <span class="info-box-icon bg-orange"><i class="fa fa-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Rekaman Tidak Konsisten</span>
                                <span class="info-box-number">{{ count($analysis['inconsistent_products']) }}</span>
                                <span class="progress-description">
                                    Memerlukan sinkronisasi
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="nav-tabs-custom">
                            <ul class="nav nav-tabs">
                                <li class="active"><a href="#overview" data-toggle="tab">Overview</a></li>
                                <li><a href="#inconsistent" data-toggle="tab">Rekaman Tidak Konsisten</a></li>
                                <li><a href="#actions" data-toggle="tab">Aksi Sinkronisasi</a></li>
                                <li><a href="#history" data-toggle="tab">Riwayat</a></li>
                            </ul>
                            <div class="tab-content">
                                <div class="active tab-pane" id="overview">
                                    <div class="row">
                                        <div class="col-md-3 col-sm-6 col-xs-12">
                                            <div class="info-box">
                                                <span class="info-box-icon bg-aqua"><i class="fa fa-cubes"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">Total Produk</span>
                                                    <span class="info-box-number">{{ number_format($analysis['summary']['total_produk']) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3 col-sm-6 col-xs-12">
                                            <div class="info-box">
                                                <span class="info-box-icon bg-red"><i class="fa fa-minus-circle"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">Stok Minus</span>
                                                    <span class="info-box-number">{{ $analysis['summary']['produk_stok_minus'] }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3 col-sm-6 col-xs-12">
                                            <div class="info-box">
                                                <span class="info-box-icon bg-yellow"><i class="fa fa-circle-o"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">Stok Nol</span>
                                                    <span class="info-box-number">{{ $analysis['summary']['produk_stok_nol'] }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3 col-sm-6 col-xs-12">
                                            <div class="info-box">
                                                <span class="info-box-icon bg-green"><i class="fa fa-list"></i></span>
                                                <div class="info-box-content">
                                                    <span class="info-box-text">Total Rekaman</span>
                                                    <span class="info-box-number">{{ number_format($analysis['summary']['total_rekaman']) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="box box-danger">
                                                <div class="box-header">
                                                    <h3 class="box-title">Masalah Rekaman Stok</h3>
                                                </div>
                                                <div class="box-body">
                                                    <div class="row">
                                                        <div class="col-xs-6">
                                                            <div class="description-block border-right">
                                                                <span class="description-percentage text-red">
                                                                    <i class="fa fa-caret-down"></i>
                                                                </span>
                                                                <h5 class="description-header">{{ $analysis['summary']['rekaman_awal_minus'] }}</h5>
                                                                <span class="description-text">Stok Awal Minus</span>
                                                            </div>
                                                        </div>
                                                        <div class="col-xs-6">
                                                            <div class="description-block">
                                                                <span class="description-percentage text-red">
                                                                    <i class="fa fa-caret-down"></i>
                                                                </span>
                                                                <h5 class="description-header">{{ $analysis['summary']['rekaman_sisa_minus'] }}</h5>
                                                                <span class="description-text">Stok Sisa Minus</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="box box-info">
                                                <div class="box-header">
                                                    <h3 class="box-title">Rekomendasi</h3>
                                                </div>
                                                <div class="box-body">
                                                    @if($analysis['health_score'] >= 80)
                                                        <p><i class="fa fa-check text-green"></i> Sistem dalam kondisi sehat</p>
                                                        <p><i class="fa fa-info text-blue"></i> Lakukan maintenance rutin</p>
                                                    @elseif($analysis['health_score'] >= 60)
                                                        <p><i class="fa fa-exclamation text-yellow"></i> Ada beberapa inkonsistensi</p>
                                                        <p><i class="fa fa-wrench text-blue"></i> Pertimbangkan sinkronisasi</p>
                                                    @else
                                                        <p><i class="fa fa-warning text-red"></i> Sistem perlu sinkronisasi segera</p>
                                                        <p><i class="fa fa-gear text-blue"></i> Jalankan sinkronisasi otomatis</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="tab-pane" id="inconsistent">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Nama Produk</th>
                                                    <th>Stok Produk</th>
                                                    <th>Stok Awal Rekaman</th>
                                                    <th>Stok Sisa Rekaman</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse($analysis['inconsistent_products'] as $product)
                                                <tr>
                                                    <td>{{ $product->id_produk }}</td>
                                                    <td>{{ $product->nama_produk }}</td>
                                                    <td>{{ $product->stok }}</td>
                                                    <td>{{ $product->stok_awal }}</td>
                                                    <td>{{ $product->stok_sisa }}</td>
                                                    <td>
                                                        @if($product->stok != $product->stok_awal || $product->stok != $product->stok_sisa)
                                                            <span class="label label-warning">Tidak Konsisten</span>
                                                        @else
                                                            <span class="label label-success">Konsisten</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                @empty
                                                <tr>
                                                    <td colspan="6" class="text-center">Tidak ada rekaman yang tidak konsisten</td>
                                                </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="tab-pane" id="actions">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="callout callout-info">
                                                <h4><i class="icon fa fa-info"></i> Tentang Sinkronisasi Stok</h4>
                                                <p>Fitur ini akan menyinkronkan data stok produk dengan rekaman transaksi yang ada. Proses ini akan:</p>
                                                <ul>
                                                    <li>Memperbaiki stok produk yang tidak sesuai dengan transaksi</li>
                                                    <li>Mengoreksi rekaman stok yang bernilai minus</li>
                                                    <li>Membuat rekaman audit untuk setiap perubahan</li>
                                                </ul>
                                            </div>
                                            
                                            <div class="box box-primary">
                                                <div class="box-header">
                                                    <h3 class="box-title">Aksi Sinkronisasi</h3>
                                                </div>
                                                <div class="box-body">
                                                    <div class="text-center">
                                                        <button type="button" class="btn btn-warning btn-lg btn-block" id="btn-sync">
                                                            <i class="fa fa-refresh"></i> Sinkronisasi Sekarang
                                                        </button>
                                                        <p class="text-muted" style="margin-top: 10px;">
                                                            <small>Klik untuk menyinkronkan data rekaman stok dengan stok produk</small>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="box box-warning">
                                                <div class="box-header">
                                                    <h3 class="box-title">Peringatan</h3>
                                                </div>
                                                <div class="box-body">
                                                    <p><i class="fa fa-warning text-red"></i> <strong>Backup Database</strong></p>
                                                    <p>Pastikan Anda telah membackup database sebelum melakukan sinkronisasi.</p>
                                                    
                                                    <p><i class="fa fa-clock-o text-blue"></i> <strong>Waktu Proses</strong></p>
                                                    <p>Proses sinkronisasi mungkin memakan waktu beberapa menit tergantung jumlah data.</p>
                                                    
                                                    <p><i class="fa fa-users text-green"></i> <strong>Akses Sistem</strong></p>
                                                    <p>Sistem tetap dapat diakses selama proses sinkronisasi.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row" id="sync-result" style="display: none;">
                                        <div class="col-md-12">
                                            <div class="box" id="result-box">
                                                <div class="box-header">
                                                    <h3 class="box-title">Hasil Sinkronisasi</h3>
                                                </div>
                                                <div class="box-body" id="result-content">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="tab-pane" id="history">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Waktu</th>
                                                    <th>Keterangan</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse($analysis['recent_sync_records'] as $record)
                                                <tr>
                                                    <td>{{ $record->waktu->format('d/m/Y H:i:s') }}</td>
                                                    <td>{{ $record->keterangan }}</td>
                                                    <td><span class="label label-success">Berhasil</span></td>
                                                </tr>
                                                @empty
                                                <tr>
                                                    <td colspan="3" class="text-center">Belum ada riwayat sinkronisasi</td>
                                                </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
$(document).ready(function() {
    $('#btn-sync').click(function() {
        if (confirm('Apakah Anda yakin ingin melakukan sinkronisasi stok?\n\nProses ini akan menyamakan data rekaman stok dengan stok produk saat ini.')) {
            performSync();
        }
    });
    
    function performSync() {
        const btn = $('#btn-sync');
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Sedang Sinkronisasi...');
        
        $.ajax({
            url: '{{ route("admin.stock-sync.perform") }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}'
            },
            timeout: 60000,
            success: function(response) {
                if (response.success) {
                    alert('✅ Sinkronisasi berhasil!\n\n📊 Data yang diperbaiki: ' + response.data.fixed_count + ' produk\n\n✔️ Halaman akan dimuat ulang untuk menampilkan data terbaru.');
                    location.reload();
                } else {
                    alert('❌ Sinkronisasi gagal: ' + response.message);
                }
            },
            error: function(xhr) {
                alert('❌ Terjadi kesalahan: ' + (xhr.responseJSON ? xhr.responseJSON.message : 'Server error'));
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    }
});
</script>
@endpush
