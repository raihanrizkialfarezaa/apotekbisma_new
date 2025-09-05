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
        padding: 6px 4px;
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
            <div class="modal-header bg-primary">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">
                    <i class="fa fa-search"></i> Pilih Produk untuk Kartu Stok
                </h4>
            </div>
            <div class="modal-body">
                <div class="table-produk-responsive">
                    <table class="table table-striped table-bordered table-hover table-produk">
                        <thead class="bg-light">
                            <tr>
                                <th width="5%" class="text-center">No</th>
                                <th>Kode Produk</th>
                                <th>Nama Produk</th>
                                <th>Kategori</th>
                                <th>Stok Saat Ini</th>
                                <th>Harga Beli</th>
                                <th width="10%" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($produk as $key => $item)
                                <tr>
                                    <td class="text-center">{{ $key+1 }}</td>
                                    <td>
                                        <span class="label label-primary">{{ $item->kode_produk }}</span>
                                    </td>
                                    <td>{{ $item->nama_produk }}</td>
                                    <td>{{ $item->kategori->nama_kategori ?? 'N/A' }}</td>
                                    <td class="text-center">
                                        <span class="badge {{ $item->stok <= 1 ? 'bg-red' : ($item->stok <= 5 ? 'bg-yellow' : 'bg-green') }}">
                                            {{ format_uang($item->stok) }}
                                        </span>
                                    </td>
                                    <td class="text-right">Rp. {{ format_uang($item->harga_beli) }}</td>
                                    <td class="text-center">
                                        <button class="btn btn-success btn-xs btn-flat" 
                                                onclick="pilihProduk('{{ $item->id_produk }}', '{{ $item->kode_produk }}', '{{ addslashes($item->nama_produk) }}')"
                                                title="Pilih produk ini">
                                            <i class="fa fa-check-circle"></i>
                                            Pilih
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-flat" data-dismiss="modal">
                    <i class="fa fa-times"></i> Tutup
                </button>
            </div>
        </div>
    </div>
</div>