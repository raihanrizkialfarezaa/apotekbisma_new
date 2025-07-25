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
                <div class="table-responsive">
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