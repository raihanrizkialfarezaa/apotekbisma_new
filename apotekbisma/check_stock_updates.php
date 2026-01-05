<?php
// check_stock_updates.php
// Cari produk yang memiliki entri keterangan EXACTLY:
//  - Stock Opname (Penyesuaian Stok Manual)
//  - Perubahan Stok Manual via Edit Produk
// Hanya untuk DATE(rs.waktu) = provided date (YYYY-MM-DD)

function loadEnv($path) {
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        $v = preg_replace('/(^"|"$|^\'\'|\'\'$)/', '', $v);
        $env[$k] = $v;
    }
    return $env;
}

$rootEnv = __DIR__ . DIRECTORY_SEPARATOR . '.env';
$env = loadEnv($rootEnv);

// CLI args: required date filter (YYYY-MM-DD) and optional output CSV path
$cliDate = $argv[1] ?? null;
$outCsvPath = $argv[2] ?? null;
$filterDate = $cliDate ? trim($cliDate) : null;

if (!$filterDate) {
    echo "Usage: php check_stock_updates.php YYYY-MM-DD [output.csv]\n";
    echo "This script requires a date (YYYY-MM-DD) and will only return rows where DATE(waktu)=that date.\n";
    exit(1);
}

$dbHost = $env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$dbName = $env['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: null;
$dbUser = $env['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: null;
$dbPass = $env['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: null;

if (!$dbName || !$dbUser) {
    echo "Could not find DB credentials. Please ensure .env or environment variables contain DB_DATABASE and DB_USERNAME.\n";
    exit(1);
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Use LIKE matching for the two keterangan patterns, but only for the requested date
$k1like = '%Stock Opname%';
$k2like = '%Perubahan Stok Manual%';

$sql = "SELECT rs.id_produk, COALESCE(p.nama_produk, '') AS nama_produk,
             COUNT(*) AS cnt,
             MIN(rs.waktu) AS first_time,
             MAX(rs.waktu) AS last_time,
             GROUP_CONCAT(DISTINCT rs.keterangan ORDER BY rs.keterangan SEPARATOR ' | ') AS keterangan_samples
FROM rekaman_stoks rs
LEFT JOIN produk p ON p.id_produk = rs.id_produk
WHERE DATE(rs.waktu) = :filterDate
    AND (rs.keterangan LIKE :k1like OR rs.keterangan LIKE :k2like)
GROUP BY rs.id_produk
ORDER BY cnt DESC";

$params = [
        ':filterDate' => $filterDate,
        ':k1like' => $k1like,
        ':k2like' => $k2like,
];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "No products found with the requested keterangan on {$filterDate}.\n";
    exit(0);
}

// Output CSV: if an output path is provided, save to that file; otherwise write to stdout
if ($outCsvPath) {
    $fp = @fopen($outCsvPath, 'w');
    if (!$fp) {
        echo "Cannot open output file: {$outCsvPath}\n";
        exit(1);
    }
    fputcsv($fp, ['id_produk','nama_produk','count','first_time','last_time','keterangan_samples']);
    foreach ($rows as $r) {
        fputcsv($fp, [$r['id_produk'], $r['nama_produk'], $r['cnt'], $r['first_time'], $r['last_time'], $r['keterangan_samples']]);
    }
    fclose($fp);
    echo "Saved CSV to: {$outCsvPath}\n";
} else {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id_produk','nama_produk','count','first_time','last_time','keterangan_samples']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id_produk'], $r['nama_produk'], $r['cnt'], $r['first_time'], $r['last_time'], $r['keterangan_samples']]);
    }
    fclose($out);
}

// Also print a short human-friendly table below
echo "\nSummary: \n";
foreach ($rows as $r) {
    echo sprintf("- %s | %s | %s rows | %s -> %s\n", $r['id_produk'], $r['nama_produk'], $r['cnt'], $r['first_time'], $r['last_time']);
}

echo "\nDone.\n";
