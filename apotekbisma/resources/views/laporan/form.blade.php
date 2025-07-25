<div class="modal fade" id="modal-form" tabindex="-1" role="dialog" aria-labelledby="modal-form">
    <div class="modal-dialog modal-lg" role="document">
        <form action="{{ route('laporan.index') }}" method="get" data-toggle="validator" class="form-horizontal">
            <div class="modal-content">
                <div class="modal-header bg-primary">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">
                        <i class="fa fa-calendar"></i> Ubah Periode Laporan
                    </h4>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="fa fa-info-circle"></i> 
                                Pilih tanggal awal dan akhir untuk melihat laporan pada periode tertentu.
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group row">
                        <label for="tanggal_awal" class="col-lg-3 control-label">
                            <i class="fa fa-calendar-o"></i> Tanggal Awal
                        </label>
                        <div class="col-lg-8">
                            <div class="input-group">
                                <input type="text" name="tanggal_awal" id="tanggal_awal" 
                                       class="form-control datepicker" required autofocus
                                       value="{{ request('tanggal_awal', $tanggalAwal) }}"
                                       placeholder="Pilih tanggal awal..."
                                       style="border-radius: 0 !important;">
                                <span class="input-group-addon">
                                    <i class="fa fa-calendar"></i>
                                </span>
                            </div>
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    
                    <div class="form-group row">
                        <label for="tanggal_akhir" class="col-lg-3 control-label">
                            <i class="fa fa-calendar-check-o"></i> Tanggal Akhir
                        </label>
                        <div class="col-lg-8">
                            <div class="input-group">
                                <input type="text" name="tanggal_akhir" id="tanggal_akhir" 
                                       class="form-control datepicker" required
                                       value="{{ request('tanggal_akhir', $tanggalAkhir) }}"
                                       placeholder="Pilih tanggal akhir..."
                                       style="border-radius: 0 !important;">
                                <span class="input-group-addon">
                                    <i class="fa fa-calendar"></i>
                                </span>
                            </div>
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="panel panel-default">
                                <div class="panel-heading">
                                    <strong><i class="fa fa-clock-o"></i> Periode Cepat</strong>
                                </div>
                                <div class="panel-body">
                                    <div class="btn-group btn-group-justified" role="group">
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-default btn-sm" onclick="setPeriod('today')">
                                                Hari Ini
                                            </button>
                                        </div>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-default btn-sm" onclick="setPeriod('week')">
                                                7 Hari Terakhir
                                            </button>
                                        </div>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-default btn-sm" onclick="setPeriod('month')">
                                                Bulan Ini
                                            </button>
                                        </div>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-default btn-sm" onclick="setPeriod('year')">
                                                Tahun Ini
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-flat">
                        <i class="fa fa-search"></i> Tampilkan Laporan
                    </button>
                    <button type="button" class="btn btn-default btn-flat" data-dismiss="modal">
                        <i class="fa fa-times"></i> Batal
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function setPeriod(period) {
    var today = new Date();
    var startDate, endDate;
    
    switch(period) {
        case 'today':
            startDate = endDate = formatDate(today);
            break;
        case 'week':
            endDate = formatDate(today);
            startDate = formatDate(new Date(today.getTime() - 6 * 24 * 60 * 60 * 1000));
            break;
        case 'month':
            endDate = formatDate(today);
            startDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            break;
        case 'year':
            endDate = formatDate(today);
            startDate = formatDate(new Date(today.getFullYear(), 0, 1));
            break;
    }
    
    $('#tanggal_awal').val(startDate);
    $('#tanggal_akhir').val(endDate);
}

function formatDate(date) {
    var d = new Date(date),
        month = '' + (d.getMonth() + 1),
        day = '' + d.getDate(),
        year = d.getFullYear();

    if (month.length < 2) month = '0' + month;
    if (day.length < 2) day = '0' + day;

    return [year, month, day].join('-');
}
</script>