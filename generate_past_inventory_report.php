<?php
require "../auth.php";

$today = func_set_time_on_date(time(), 23, 59, 59);
$monthStart = strtotime('first day of this month');
$lastMonthStart = strtotime('first day of last month');

$products = func_query("SELECT productid, productcode, product from dbp_products where no_inv_track != 'Y' AND forsale in ('Y', 'H') ORDER BY productcode ASC LIMIT 10");

foreach ($products as $p) {
    $inventory = func_query_first_cell("SELECT avail from dbp_inventory where productid = {$p['productid']}");
    $thisMonthSale = func_query_first_cell("SELECT sum(od.amount) from dbp_orders o inner join dbp_order_details od on o.orderid = od.orderid WHERE od.productid = {$p['productid']} AND o.date > {$monthStart} AND o.date < $today");
    $lastMonthSale = func_query_first_cell("SELECT sum(od.amount) from dbp_orders o inner join dbp_order_details od on o.orderid = od.orderid WHERE od.productid = {$p['productid']} AND o.date > {$lastMonthStart} AND o.date < $monthStart");
    $p = array_merge($p, [$inventory, $inventory + $thisMonthSale, $inventory + $thisMonthSale + $lastMonthSale]);
    echo implode(',', $p) . PHP_EOL;
}
