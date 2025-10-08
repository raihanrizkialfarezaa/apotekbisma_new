<?php
// Bridge file untuk menjalankan perbaiki_rekaman_stok.php dari public webroot
// Menukar direktori kerja ke root project sehingga require relatif di script utuh bekerja
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../perbaiki_rekaman_stok.php';
