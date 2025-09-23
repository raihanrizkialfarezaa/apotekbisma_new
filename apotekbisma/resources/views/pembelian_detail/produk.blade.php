<style>
@media (max-width: 768px) {
    .modal-produk .modal-dialog {
        width: 96%;
        margin: 8px auto;
        max-width: 720px;
    }
    
    .table-produk-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 10px;
    }
    
    .table-produk {
        min-width: 480px;
        margin-bottom: 0;
        font-size: 13px;
    }
    
    .table-produk td, 
    .table-produk th {
        white-space: nowrap;
        padding: 8px 6px;
        font-size: 13px;
        vertical-align: middle;
    }
    
    .table-produk td:last-child,
    .table-produk th:last-child {
        position: sticky;
        right: 0;
        background-color: #ffffff;
        border-left: 1px solid #e6e6e6;
        z-index: 10;
    }
    
    .table-produk .btn {
        font-size: 12px;
        padding: 6px 10px;
        border-radius: 4px;
    }
    
    .table-produk .badge {
        font-size: 12px;
        padding: 4px 8px;
    }
    
    .table-produk .label {
        font-size: 12px;
        padding: 4px 8px;
    }
}
</style>

<div class="modal fade" id="modal-produk" tabindex="-1" role="dialog" aria-labelledby="modal-produk">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                        aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Pilih Produk - <small class="text-info">Stok ditampilkan secara real-time</small></h4>
            </div>
            <div class="modal-body">
                <div class="table-produk-responsive">
                    <table class="table table-striped table-bordered table-produk" id="table-produk-pembelian">
                        <thead>
                            <th width="5%">No</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Stok Saat Ini</th>
                            <th>Harga Beli</th>
                            <th><i class="fa fa-cog"></i></th>
                        </thead>
                        <tbody>
                            <!-- Data akan dimuat secara dinamis via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>