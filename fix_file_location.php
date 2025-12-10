<?php
require "../auth.php";

$images = func_query("select * from dbp_thumbnails");

$count = 1;
foreach ($images as $i) {
    echo $count ++ . " ";
    $path = $i['image_path'];
    if (file_exists($dbp_dir . $path)) {
        echo 'file existed';
    } else {
        $newPath = str_replace('/files', '/files/Product Tile Images', $path);
        if (file_exists($dbp_dir . $newPath)) {
            echo 'file moved';
            db_query("update dbp_thumbnails set image_path = '{$newPath}' where productid = {$i['productid']}");
        } else {
            echo 'file deleted';
        }
    }
    
    echo PHP_EOL;
}
