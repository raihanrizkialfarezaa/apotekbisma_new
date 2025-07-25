<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Laporan Keuangan</title>

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
        .statistics {
            margin-bottom: 20px;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .stat-box {
            width: 23%;
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
            background-color: #f9f9f9;
        }
        .stat-title {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .stat-value {
            font-size: 14px;
            color: #2c3e50;
            font-weight: bold;
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
            text-align: right;
        }
        .table td.center {
            text-align: center;
        }
        .table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .table tr:last-child {
            background-color: #e8f5e8;
            font-weight: bold;
        }
        .summary {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        .summary-section {
            width: 48%;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #ddd;
            padding: 6px;
            font-size: 11px;
        }
        .summary-table th {
            background-color: #f2f2f2;
            text-align: center;
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
        <h2>LAPORAN KEUANGAN</h2>
        <h3>APOTEK BISMA</h3>
        <p>
            Periode: {{ tanggal_indonesia($awal, false) }} s/d {{ tanggal_indonesia($akhir, false) }}
        </p>
    </div>

    <div class="statistics">
        <h3>Ringkasan Keuangan</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td class="stat-box">
                    <div class="stat-title">Total Penjualan</div>
                    <div class="stat-value">Rp. {{ format_uang($statistics['totalPenjualan']) }}</div>
                    <small>{{ $statistics['jumlahTransaksiPenjualan'] }} transaksi</small>
                </td>
                <td style="width: 2%;"></td>
                <td class="stat-box">
                    <div class="stat-title">Total Pembelian</div>
                    <div class="stat-value">Rp. {{ format_uang($statistics['totalPembelian']) }}</div>
                    <small>{{ $statistics['jumlahTransaksiPembelian'] }} transaksi</small>
                </td>
                <td style="width: 2%;"></td>
                <td class="stat-box">
                    <div class="stat-title">Total Pengeluaran</div>
                    <div class="stat-value">Rp. {{ format_uang($statistics['totalPengeluaran']) }}</div>
                    <small>Biaya operasional</small>
                </td>
                <td style="width: 2%;"></td>
                <td class="stat-box">
                    <div class="stat-title">{{ $statistics['totalPendapatan'] >= 0 ? 'Keuntungan' : 'Kerugian' }}</div>
                    <div class="stat-value">Rp. {{ format_uang($statistics['totalPendapatan']) }}</div>
                    <small>Pendapatan bersih</small>
                </td>
            </tr>
        </table>
    </div>

    <h3>Detail Laporan Harian</h3>
    <table class="table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="15%">Tanggal</th>
                <th width="20%">Penjualan</th>
                <th width="20%">Pembelian</th>
                <th width="20%">Pengeluaran</th>
                <th width="20%">Pendapatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data as $row)
                <tr>
                    <td class="center">{{ $row['DT_RowIndex'] }}</td>
                    <td class="center">{{ strip_tags($row['tanggal']) }}</td>
                    <td class="number">{{ strip_tags($row['penjualan']) }}</td>
                    <td class="number">{{ strip_tags($row['pembelian']) }}</td>
                    <td class="number">{{ strip_tags($row['pengeluaran']) }}</td>
                    <td class="number">{{ strip_tags($row['pendapatan']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div class="summary-section">
            <h4>Produk Terlaris (Top 5)</h4>
            @if($statistics['produkTerlaris']->count() > 0)
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Terjual</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statistics['produkTerlaris'] as $produk)
                        <tr>
                            <td>{{ $produk->nama_produk }}</td>
                            <td style="text-align: center;">{{ format_uang($produk->total_terjual) }}</td>
                            <td style="text-align: right;">Rp. {{ format_uang($produk->total_revenue) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>Tidak ada data penjualan dalam periode ini.</p>
            @endif
        </div>

        <div class="summary-section">
            <h4>Stok Menipis (≤ 5)</h4>
            @if($statistics['stokMenupis']->count() > 0)
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Produk</th>
                            <th>Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statistics['stokMenupis'] as $produk)
                        <tr>
                            <td style="text-align: center;">{{ $produk->kode_produk }}</td>
                            <td>{{ $produk->nama_produk }}</td>
                            <td style="text-align: center;">{{ format_uang($produk->stok) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p>Semua produk memiliki stok yang cukup.</p>
            @endif
        </div>
    </div>

    <div class="footer">
        <p>
            Laporan ini digenerate secara otomatis pada {{ date('d-m-Y H:i:s') }}
            <br>
            Rata-rata penjualan harian: Rp. {{ format_uang($statistics['rataRataPenjualanHarian']) }} | 
            Member aktif: {{ $statistics['memberAktif'] }}/{{ $statistics['totalMember'] }}
        </p>
    </div>
</body>
</html>