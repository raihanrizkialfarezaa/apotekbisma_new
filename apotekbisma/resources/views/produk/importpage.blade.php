<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <form action="{{ route('importobat') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="excel_file" id="file">
        <button type="submit" class="btn btn-primary">Submit</button>
    </form>
</body>
</html>