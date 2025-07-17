<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Laporan Pendapatan</title>

    <link rel="stylesheet" href="{{ public_path().'/AdminLTE-2/bower_components/bootstrap/dist/css/bootstrap.min.css' }}">
</head>
<body>
    <h3 class="text-center">KARTU STOK</h3>
    <br>
    <h3>Nama Obat : {{ $nama_obat }}</h3>
    <h3>Satuan : {{ $satuan }}</h3>

    <table class="table table-striped">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th>Tanggal</th>
                <th>Stok Masuk</th>
                <th>Stok Keluar</th>
                <th>Stok Awal</th>
                <th>Stok Sisa</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data as $row)
                <tr>
                    @foreach ($row as $col)
                        <td>{{ $col }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>