<style>
@media (max-width: 768px) {
    .modal-produk .modal-dialog {
        width: 95%;
        margin: 10px auto;
    }
    
    .table-produk-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 15px;
    }
    
    .table-produk {
        min-width: 700px;
        margin-bottom: 0;
    }
    
    .table-produk td, 
    .table-produk th {
        white-space: nowrap;
        padding: 8px 4px;
        font-size: 12px;
        vertical-align: middle;
    }
    
    .table-produk td:last-child,
    .table-produk th:last-child {
        position: sticky;
        right: 0;
        background-color: #fff;
        border-left: 2px solid #ddd;
        z-index: 10;
        box-shadow: -2px 0 5px rgba(0,0,0,0.1);
    }
    
    .table-produk .btn {
        font-size: 10px;
        padding: 4px 8px;
    }
    
    .table-produk .badge {
        font-size: 10px;
        padding: 2px 6px;
    }
    
    .table-produk .label {
        font-size: 10px;
        padding: 2px 6px;
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