<?php
// Bridge file untuk menjalankan fix_kartu_stok_perfect.php dari public webroot
// Menukar direktori kerja ke root project sehingga require relatif di script utuh bekerja
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../fix_kartu_stok_perfect.php';
