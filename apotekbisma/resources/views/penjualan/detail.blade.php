<style>
@media (max-width: 768px) {
    .modal-detail .modal-dialog {
        width: 95%;
        margin: 10px auto;
    }
    
    .table-detail-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 15px;
    }
    
    .table-detail {
        min-width: 600px;
        margin-bottom: 0;
    }
    
    .table-detail td, 
    .table-detail th {
        white-space: nowrap;
        padding: 8px 4px;
        font-size: 12px;
        vertical-align: middle;
    }
}
</style>

<div class="modal fade" id="modal-detail" tabindex="-1" role="dialog" aria-labelledby="modal-detail">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                        aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Detail Penjualan</h4>
            </div>
            <div class="modal-body">
                <div class="table-detail-responsive">
                    <table class="table table-striped table-bordered table-detail">
                        <thead>
                            <th width="5%">No</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Harga</th>
                            <th>Jumlah</th>
                            <th>Subtotal</th>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>