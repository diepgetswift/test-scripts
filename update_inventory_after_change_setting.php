<?php

require "../admin/auth.php";

$date = func_set_time_on_date(time());

$productIds = db_query_builder()
            ->select('od.productid')
            ->from('order_details', 'od')
            ->innerJoin('od', 'orders', 'o', 'od.orderid = o.orderid')
            ->where('o.date > ' . $date)
            ->andWhere('od.amount > 0')
            ->groupBy('od.productid')
            ->fetchColumn();
            
foreach($productIds as $productid) {
    echo $productid . PHP_EOL;
    die;
    func_update_product_inventory($productid, 0, true, true);
}
