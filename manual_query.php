<?php
require "../auth.php";

$query = $argv[1];
$query = str_replace('{quote}', "'", $query);
$query = str_replace('{slash}', "\\", $query);

if ($argv[2] === 'update') {
    db_query($query);
    echo 'OK';
} elseif ($argv[2] === 'simple') {
    $result = func_query($query);
    $result = func_table2column($result);
    var_dump($result);
} else {
    echo $argv[1];
    $result = func_query($query);
    var_dump($result);
}
