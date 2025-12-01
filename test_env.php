<?php
echo 'DB_PASS from getenv: ';
$val = getenv('DB_PASS');
echo $val === false || $val === '' ? '[EMPTY]' : 'SET';
