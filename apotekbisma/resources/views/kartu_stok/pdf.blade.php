<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Kartu Stok - {{ $nama_obat }}</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .product-info {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
        }
        .info-section {
            width: 48%;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 5px;
            border: 1px solid #ddd;
        }
        .info-table .label {
            background-color: #f2f2f2;
            font-weight: bold;
            width: 40%;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .table th, .table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .table th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }
        .table td.number {
            text-align: center;
        }
        .table td.text-left {
            text-align: left;
        }
        .table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .table tr:last-child {
            background-color: #e8f5e8;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>KARTU STOK</h2>
        <h3>APOTEK BISMA</h3>
        <p>Tanggal Cetak: {{ date('d-m-Y H:i:s') }}</p>
    </div>

    <div class="product-info">
        <div class="info-section">
            <h4>Informasi Produk</h4>
            <table class="info-table">
                <tr>
                    <td class="label">Kode Produk</td>
                    <td>{{ $kode_produk }}</td>
                </tr>
                <tr>
                    <td class="label">Nama Produk</td>
                    <td>{{ $nama_obat }}</td>
                </tr>
                <tr>
                    <td class="label">Kategori</td>
                    <td>{{ $satuan }}</td>
                </tr>
            </table>
        </div>

        <div class="info-section">
            <h4>Status Stok</h4>
            <table class="info-table">
                <tr>
                    <td class="label">Stok Saat Ini</td>
                    <td>{{ format_uang($produk->stok) }}</td>
                </tr>
                <tr>
                    <td class="label">Harga Beli</td>
                    <td>Rp. {{ format_uang($produk->harga_beli) }}</td>
                </tr>
                <tr>
                    <td class="label">Harga Jual</td>
                    <td>Rp. {{ format_uang($produk->harga_jual) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <h4>Riwayat Pergerakan Stok</h4>
    <table class="table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="15%">Tanggal</th>
                <th width="12%">Stok Masuk</th>
                <th width="12%">Stok Keluar</th>
                <th width="12%">Stok Awal</th>
                <th width="12%">Stok Sisa</th>
                <th width="32%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data as $row)
                <tr>
                    <td class="number">{{ strip_tags($row['DT_RowIndex']) }}</td>
                    <td class="number">{{ strip_tags($row['tanggal']) }}</td>
                    <td class="number">{{ strip_tags($row['stok_masuk']) }}</td>
                    <td class="number">{{ strip_tags($row['stok_keluar']) }}</td>
                    <td class="number">{{ strip_tags($row['stok_awal']) }}</td>
                    <td class="number">{{ strip_tags($row['stok_sisa']) }}</td>
                    <td class="text-left">{{ strip_tags($row['keterangan']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>
            Laporan ini digenerate secara otomatis pada {{ date('d-m-Y H:i:s') }}
            <br>
            <strong>APOTEK BISMA</strong> - Sistem Manajemen Apotek
        </p>
    </div>
</body>
</html>