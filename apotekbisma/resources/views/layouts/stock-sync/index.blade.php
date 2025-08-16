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
                                <span class="info-box-text">Produk Tidak Konsisten</span>
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
                                <li><a href="#inconsistent" data-toggle="tab">Produk Inkonsisten</a></li>
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
                                                    <th>Stok DB</th>
                                                    <th>Stok Kalkulasi</th>
                                                    <th>Selisih</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse($analysis['inconsistent_products'] as $product)
                                                <tr>
                                                    <td>{{ $product->id_produk }}</td>
                                                    <td>{{ $product->nama_produk }}</td>
                                                    <td>{{ $product->stok }}</td>
                                                    <td>{{ $product->calculated_stock }}</td>
                                                    <td>
                                                        <span class="label {{ $product->difference > 0 ? 'label-success' : 'label-danger' }}">
                                                            {{ $product->difference > 0 ? '+' : '' }}{{ $product->difference }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        @if(abs($product->difference) > 10)
                                                            <span class="label label-danger">Kritis</span>
                                                        @elseif(abs($product->difference) > 5)
                                                            <span class="label label-warning">Perhatian</span>
                                                        @else
                                                            <span class="label label-info">Minor</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                @empty
                                                <tr>
                                                    <td colspan="6" class="text-center">Tidak ada produk yang inkonsisten</td>
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
                                                    <div class="btn-group-vertical btn-block">
                                                        <button type="button" class="btn btn-info btn-lg" id="btn-analyze">
                                                            <i class="fa fa-search"></i> Analisis (Tanpa Perubahan)
                                                        </button>
                                                        <button type="button" class="btn btn-warning btn-lg" id="btn-sync" 
                                                                {{ count($analysis['inconsistent_products']) == 0 ? 'disabled' : '' }}>
                                                            <i class="fa fa-refresh"></i> Sinkronisasi Sekarang
                                                        </button>
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

<div class="modal fade" id="confirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Konfirmasi Sinkronisasi</h4>
            </div>
            <div class="modal-body">
                <p>Anda akan melakukan sinkronisasi stok yang akan mengubah data produk dan rekaman stok.</p>
                <p><strong>Pastikan Anda telah membackup database!</strong></p>
                <p>Lanjutkan proses sinkronisasi?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning" id="confirm-sync">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function() {
    $('#btn-analyze').click(function() {
        performSync(true);
    });
    
    $('#btn-sync').click(function() {
        $('#confirmModal').modal('show');
    });
    
    $('#confirm-sync').click(function() {
        $('#confirmModal').modal('hide');
        performSync(false);
    });
    
    function performSync(dryRun) {
        const btn = dryRun ? $('#btn-analyze') : $('#btn-sync');
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
        
        $.ajax({
            url: '{{ route("admin.stock-sync.perform") }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                dry_run: dryRun ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    showResult(response, dryRun);
                    if (!dryRun) {
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    }
                } else {
                    showError(response.message);
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                showError(response ? response.message : 'Terjadi kesalahan sistem');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    }
    
    function showResult(response, dryRun) {
        let content = '';
        
        if (dryRun) {
            content = '<div class="alert alert-info"><h4>Hasil Analisis</h4><pre>' + response.output + '</pre></div>';
        } else {
            content = '<div class="alert alert-success">';
            content += '<h4>Sinkronisasi Berhasil</h4>';
            content += '<p>Produk yang disinkronkan: ' + response.stats.products_synced + '</p>';
            content += '<p>Rekaman minus diperbaiki: ' + response.stats.negative_records_fixed + '</p>';
            content += '</div>';
        }
        
        $('#result-content').html(content);
        $('#result-box').removeClass('box-primary box-success box-danger').addClass(dryRun ? 'box-info' : 'box-success');
        $('#sync-result').show();
        
        $('html, body').animate({
            scrollTop: $("#sync-result").offset().top
        }, 1000);
    }
    
    function showError(message) {
        const content = '<div class="alert alert-danger"><h4>Error</h4><p>' + message + '</p></div>';
        $('#result-content').html(content);
        $('#result-box').removeClass('box-primary box-success box-info').addClass('box-danger');
        $('#sync-result').show();
    }
});
</script>
@endpush
