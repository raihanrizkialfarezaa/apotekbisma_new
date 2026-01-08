<?php
$c = file_get_contents('check_dec_result.txt');
if (substr($c,0,2) === chr(255).chr(254)) {
    $c = mb_convert_encoding(substr($c,2), 'UTF-8', 'UTF-16LE');
}
echo $c;
