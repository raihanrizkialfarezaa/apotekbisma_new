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
                <h4 class="modal-title">Pilih Produk</h4>
            </div>
            <div class="modal-body">
                <div class="table-produk-responsive">
                    <table class="table table-striped table-bordered table-produk">
                        <thead>
                            <th width="5%">No</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Sisa Stok</th>
                            <th>Harga Jual</th>
                            <th><i class="fa fa-cog"></i></th>
                        </thead>
                        <tbody>
                            @foreach ($produk as $key => $item)
                                <tr class="{{ $item->stok <= 0 ? 'danger' : ($item->stok == 1 ? 'warning' : '') }}">
                                    <td width="5%">{{ $key+1 }}</td>
                                    <td><span class="label label-success">{{ $item->kode_produk }}</span></td>
                                    <td>
                                        {{ $item->nama_produk }}
                                        @if($item->stok <= 0)
                                            <span class="label label-danger">Stok Habis</span>
                                        @elseif($item->stok == 1)
                                            <span class="label label-warning">Stok Menipis</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($item->harga_jual) }}</td>
                                    <td>
                                        <span class="{{ $item->stok <= 0 ? 'text-danger' : ($item->stok == 1 ? 'text-warning' : 'text-success') }}">
                                            {{ $item->stok }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($item->stok > 0)
                                            <a href="#" class="btn btn-primary btn-xs btn-flat"
                                                onclick="pilihProduk('{{ $item->id_produk }}', '{{ $item->kode_produk }}', {{ $item->stok }})">
                                                <i class="fa fa-check-circle"></i>
                                                Pilih
                                            </a>
                                        @else
                                            <button class="btn btn-danger btn-xs btn-flat" disabled title="Stok habis, tidak dapat dijual">
                                                <i class="fa fa-ban"></i>
                                                Tidak Tersedia
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>