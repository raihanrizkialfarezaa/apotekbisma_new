<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Bukti Pembelian PDF</title>

    <style>
        table td {
            font-size: 14px;
        }
        table.data td,
        table.data th {
            border: 1px solid #ccc;
            padding: 5px;
        }
        table.data {
            border-collapse: collapse;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <table width="100%">
        <tr>
            <td rowspan="5" width="60%">
                <img src="{{ public_path($setting->path_logo) }}" alt="{{ $setting->path_logo }}" width="120">
                <br>
                {{ $setting->alamat }}
                <br>
                <br>
            </td>
            <td>Tanggal</td>
            <td>: {{ tanggal_indonesia(date('Y-m-d')) }}</td>
        </tr>
        <tr>
            <td>No Faktur</td>
            <td>: {{ $pembelian->no_faktur }}</td>
        </tr>
        <tr>
            <td>Supplier</td>
            <td>: {{ $pembelian->supplier->nama ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td>Tanggal Faktur</td>
            <td>: {{ tanggal_indonesia($pembelian->waktu ?? $pembelian->created_at, false) }}</td>
        </tr>
    </table>

    <h3 class="text-center">BUKTI PEMBELIAN</h3>

    <table class="data" width="100%">
        <thead>
            <tr>
                <th>No</th>
                <th>Kode</th>
                <th>Nama</th>
                <th>Harga Beli</th>
                <th>Jumlah</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($detail as $key => $item)
                <tr>
                    <td class="text-center">{{ $key+1 }}</td>
                    <td>{{ $item->produk->kode_produk }}</td>
                    <td>{{ $item->produk->nama_produk }}</td>
                    <td class="text-right">{{ format_uang($item->harga_beli) }}</td>
                    <td class="text-right">{{ format_uang($item->jumlah) }}</td>
                    <td class="text-right">{{ format_uang($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="text-right"><b>Total Harga</b></td>
                <td class="text-right"><b>{{ format_uang($pembelian->total_harga) }}</b></td>
            </tr>
            <tr>
                <td colspan="5" class="text-right"><b>Diskon (%)</b></td>
                <td class="text-right"><b>{{ format_uang($pembelian->diskon) }}%</b></td>
            </tr>
            <tr>
                <td colspan="5" class="text-right"><b>Total Bayar</b></td>
                <td class="text-right"><b>{{ format_uang($pembelian->bayar) }}</b></td>
            </tr>
            <tr>
                <td colspan="5" class="text-right"><b>Total Item</b></td>
                <td class="text-right"><b>{{ format_uang($pembelian->total_item) }}</b></td>
            </tr>
        </tfoot>
    </table>

    <table width="100%">
        <tr>
            <td><b>Dokumen ini adalah bukti pembelian yang sah</b></td>
            <td width="200px">
                <table width="100%" style="border: 0;">
                    <tr>
                        <td class="text-center">Mengetahui</td>
                    </tr>
                    <tr>
                        <td style="height: 80px;"></td>
                    </tr>
                    <tr>
                        <td class="text-center">{{ strtoupper(auth()->user()->name) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
