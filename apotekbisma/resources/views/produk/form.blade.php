<div class="modal fade" id="modal-form" tabindex="-1" role="dialog" aria-labelledby="modal-form">
    <div class="modal-dialog modal-lg" role="document">
        <form action="" method="post" class="form-horizontal">
            @csrf
            @method('post')

            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"></h4>
                </div>
                <div class="modal-body">
                    <div class="form-group row">
                        <label for="nama_produk" class="col-lg-2 col-lg-offset-1 control-label">Nama</label>
                        <div class="col-lg-6">
                            <input type="text" name="nama_produk" id="nama_produk" class="form-control" required autofocus>
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="id_kategori" class="col-lg-2 col-lg-offset-1 control-label">Kategori</label>
                        <div class="col-lg-6">
                            <select name="id_kategori" id="id_kategori" class="form-control" required>
                                <option value="">Pilih Kategori</option>
                                @foreach ($kategori as $key => $item)
                                <option value="{{ $key }}">{{ $item }}</option>
                                @endforeach
                            </select>
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="merk" class="col-lg-2 col-lg-offset-1 control-label">Merk</label>
                        <div class="col-lg-6">
                            <input type="text" name="merk" id="merk" class="form-control">
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="harga_beli" class="col-lg-2 col-lg-offset-1 control-label">Harga Beli</label>
                        <div class="col-lg-6">
                            <input type="number" name="harga_beli" id="harga_beli" class="form-control" required>
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="harga_jual" class="col-lg-2 col-lg-offset-1 control-label">Harga Jual</label>
                        <div class="col-lg-6">
                            <input type="number" name="harga_jual" id="harga_jual" class="form-control" required>
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    {{-- Warning harga --}}
                    <div class="form-group row" id="harga-warning-container" style="display: none;">
                        <div class="col-lg-6 col-lg-offset-3">
                            <div id="harga-warning-box" style="padding: 8px 12px; border-radius: 4px; font-size: 13px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-group row">
                        <div class="col-lg-6">
                            <input type="hidden" name="diskon" id="diskon" class="form-control" value="0">
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="expired_date" class="col-lg-2 col-lg-offset-1 control-label">Expired Date</label>
                        <div class="col-lg-6">
                            <input type="date" name="expired_date" id="expired_date" class="form-control">
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="batch" class="col-lg-2 col-lg-offset-1 control-label">Batch</label>
                        <div class="col-lg-6">
                            <input type="text" name="batch" id="batch" class="form-control">
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="stok" class="col-lg-2 col-lg-offset-1 control-label">Stok</label>
                        <div class="col-lg-6">
                            <input type="number" name="stok" id="stok" class="form-control" required value="0" min="0">
                            <span class="help-block with-errors"></span>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="keterangan_stok" class="col-lg-2 col-lg-offset-1 control-label">Catatan Stok</label>
                        <div class="col-lg-6">
                            <textarea name="keterangan_stok" id="keterangan_stok_edit" class="form-control" rows="2" placeholder="Wajib diisi bila stok diubah melalui Edit Produk"></textarea>
                            <small class="text-muted">Catatan ini akan masuk ke rekaman stock opname jika nilai stok berubah.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-sm btn-flat btn-primary"><i class="fa fa-save"></i> Simpan</button>
                    <button type="button" class="btn btn-sm btn-flat btn-default" data-dismiss="modal"><i class="fa fa-times"></i> Batal</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(function() {
    // === Price Warning System ===
    function checkHargaWarning() {
        var hargaBeli = parseInt($('#harga_beli').val()) || 0;
        var hargaJual = parseInt($('#harga_jual').val()) || 0;
        var container = $('#harga-warning-container');
        var box = $('#harga-warning-box');

        if (hargaBeli <= 0 || hargaJual <= 0) {
            container.hide();
            return;
        }

        if (hargaJual < hargaBeli) {
            var selisih = hargaBeli - hargaJual;
            box.html('<i class="fa fa-exclamation-triangle"></i> <strong>Peringatan:</strong> Harga jual (Rp' + hargaJual.toLocaleString('id') + ') <strong>lebih rendah</strong> dari harga beli (Rp' + hargaBeli.toLocaleString('id') + '). Selisih rugi: <strong>Rp' + selisih.toLocaleString('id') + '</strong> per unit.')
                .css({ 'background': '#f2dede', 'color': '#a94442', 'border': '1px solid #ebccd1' });
            container.show();
        } else if (hargaJual > hargaBeli * 10 && hargaBeli > 0) {
            box.html('<i class="fa fa-info-circle"></i> <strong>Info:</strong> Harga jual (Rp' + hargaJual.toLocaleString('id') + ') sangat tinggi dibanding harga beli (Rp' + hargaBeli.toLocaleString('id') + '). Pastikan ini sudah benar.')
                .css({ 'background': '#fcf8e3', 'color': '#8a6d3b', 'border': '1px solid #faebcc' });
            container.show();
        } else {
            container.hide();
        }
    }

    $('#harga_beli, #harga_jual').on('input change', checkHargaWarning);

    // Initialize on modal show
    $('#modal-form').on('shown.bs.modal', function() {
        checkHargaWarning();
    });
});
</script>