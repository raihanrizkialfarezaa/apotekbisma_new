<?php
$db = mysqli_connect('localhost', 'root', '', 'apotekbisma');
$result = mysqli_query($db, 'SELECT p.id_produk, p.nama_produk, p.stok, rs.stok_awal, rs.stok_sisa FROM produk p JOIN rekaman_stoks rs ON p.id_produk = rs.id_produk WHERE p.id_produk = 2 AND rs.id_rekaman_stok = (SELECT MAX(id_rekaman_stok) FROM rekaman_stoks WHERE id_produk = 2)');
$row = mysqli_fetch_assoc($result);
if ($row) {
  echo "After web sync - Product ID 2:\n";
  echo "Product Stock: " . $row['stok'] . "\n";
  echo "Rekaman stok_awal: " . $row['stok_awal'] . "\n";
  echo "Rekaman stok_sisa: " . $row['stok_sisa'] . "\n";
  if ($row['stok'] == $row['stok_awal'] && $row['stok'] == $row['stok_sisa']) {
    echo "Status: CONSISTENT (Fixed by web sync)\n";
  } else {
    echo "Status: STILL INCONSISTENT\n";
  }
}
mysqli_close($db);
?>
