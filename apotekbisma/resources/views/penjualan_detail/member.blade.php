<style>
@media (max-width: 768px) {
    .modal-member .modal-dialog {
        width: 95%;
        margin: 10px auto;
    }
    
    .table-member-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 15px;
    }
    
    .table-member {
        min-width: 600px;
        margin-bottom: 0;
    }
    
    .table-member td, 
    .table-member th {
        white-space: nowrap;
        padding: 8px 4px;
        font-size: 12px;
        vertical-align: middle;
    }
    
    .table-member td:last-child,
    .table-member th:last-child {
        position: sticky;
        right: 0;
        background-color: #fff;
        border-left: 2px solid #ddd;
        z-index: 10;
        box-shadow: -2px 0 5px rgba(0,0,0,0.1);
    }
    
    .table-member .btn {
        font-size: 10px;
        padding: 4px 8px;
    }
}
</style>

<div class="modal fade" id="modal-member" tabindex="-1" role="dialog" aria-labelledby="modal-member">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                        aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Pilih Member</h4>
            </div>
            <div class="modal-body">
                <div class="table-member-responsive">
                    <table class="table table-striped table-bordered table-member">
                        <thead>
                            <th width="5%">No</th>
                            <th>Nama</th>
                            <th>Telepon</th>
                            <th>Alamat</th>
                            <th><i class="fa fa-cog"></i></th>
                        </thead>
                        <tbody>
                            @foreach ($member as $key => $item)
                                <tr>
                                    <td width="5%">{{ $key+1 }}</td>
                                    <td>{{ $item->nama }}</td>
                                    <td>{{ $item->telepon }}</td>
                                    <td>{{ $item->alamat }}</td>
                                    <td>
                                        <a href="#" class="btn btn-primary btn-xs btn-flat"
                                            onclick="pilihMember('{{ $item->id_member }}', '{{ $item->kode_member }}')">
                                            <i class="fa fa-check-circle"></i>
                                            Pilih
                                        </a>
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