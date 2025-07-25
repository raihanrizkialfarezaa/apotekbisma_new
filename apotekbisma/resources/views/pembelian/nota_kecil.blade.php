<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Bukti Pembelian</title>

    <?php
    $style = '
    <style>
        * {
            font-family: "consolas", sans-serif;
        }
        p {
            display: block;
            margin: 3px;
            font-size: 10pt;
        }
        table td {
            font-size: 9pt;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }

        @media print {
            @page {
                margin: 0;
                size: 75mm 
    ';
    ?>
    <?php 
    $style .= 
        ! empty($_COOKIE['innerHeight'])
            ? $_COOKIE['innerHeight'] .'mm; }'
            : '}';
    ?>
    <?php
    $style .= '
            html, body {
                width: 70mm;
            }
            .btn-print {
                display: none;
            }
        }
    </style>
    ';
    ?>

    {!! $style !!}
</head>
<body onload="window.print()">
    <button class="btn-print" style="position: absolute; right: 1rem; top: rem;" onclick="window.print()">Print</button>
    <div class="text-center">
        <h3 style="margin-bottom: 5px;">{{ strtoupper($setting->nama_perusahaan) }}</h3>
        <p>{{ strtoupper($setting->alamat) }}</p>
        <h4 style="margin: 5px 0;">BUKTI PEMBELIAN</h4>
    </div>
    <br>
    <div>
        <p style="float: left;">{{ date('d-m-Y') }}</p>
        <p style="float: right">{{ strtoupper(auth()->user()->name) }}</p>
    </div>
    <div class="clear-both" style="clear: both;"></div>
    <p>No: {{ tambah_nol_didepan($pembelian->id_pembelian, 10) }}</p>
    <p>No Faktur: {{ $pembelian->no_faktur }}</p>
    <p>Supplier: {{ $pembelian->supplier->nama ?? 'N/A' }}</p>
    <p>Tanggal: {{ tanggal_indonesia($pembelian->waktu ?? $pembelian->created_at, false) }}</p>
    <p class="text-center">===================================</p>
    
    <br>
    <table width="100%" style="border: 0;">
        @foreach ($detail as $item)
            <tr>
                <td colspan="3">{{ $item->produk->nama_produk }}</td>
            </tr>
            <tr>
                <td>{{ $item->jumlah }} x {{ format_uang($item->harga_beli) }}</td>
                <td></td>
                <td class="text-right">{{ format_uang($item->jumlah * $item->harga_beli) }}</td>
            </tr>
        @endforeach
    </table>
    <p class="text-center">-----------------------------------</p>

    <table width="100%" style="border: 0;">
        <tr>
            <td>Total Harga:</td>
            <td class="text-right">{{ format_uang($pembelian->total_harga) }}</td>
        </tr>
        <tr>
            <td>Total Item:</td>
            <td class="text-right">{{ format_uang($pembelian->total_item) }}</td>
        </tr>
        <tr>
            <td>Diskon:</td>
            <td class="text-right">{{ format_uang($pembelian->diskon) }}%</td>
        </tr>
        <tr>
            <td>Total Bayar:</td>
            <td class="text-right">{{ format_uang($pembelian->bayar) }}</td>
        </tr>
    </table>
    <p class="text-center">===================================</p>
    <br>
    <p class="text-center">Terima kasih</p>

    <script>
        let body = document.body;
        let html = document.documentElement;
        let height = Math.max(
            body.scrollHeight, body.offsetHeight,
            html.clientHeight, html.scrollHeight, html.offsetHeight
        );

        document.cookie = "innerHeight=" + ((height * 0.265) + 50);
    </script>
</body>
</html>
