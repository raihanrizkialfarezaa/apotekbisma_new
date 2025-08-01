<?php
$pdo = new PDO('mysql:host=localhost;dbname=apotekbisma', 'root', '');
$stmt = $pdo->query('SHOW TABLES');

echo "Tables in database:\n";
while($row = $stmt->fetch()) {
    echo "- " . $row[0] . "\n";
    if(strpos($row[0], 'rekaman') !== false) {
        echo "  Found rekaman table!\n";
    }
}
