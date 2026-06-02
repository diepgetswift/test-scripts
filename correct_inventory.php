<?php
require "../auth.php";

$toRestore = 20;
$restored = 0;
$productid = 12;

$orders = func_query("SELECT o.orderid, od.itemid, od.amount from dbp_order_details od inner join dbp_orders o on od.orderid = o.orderid WHERE od.amount > 0 AND o.date > unix_timestamp() + 5 * 86400 AND od.productid = {$productid} order by o.date desc");
foreach ($orders as $row) {
    $orderObject = OrderFactory::get_by_orderid($row['orderid']);
    $orderObject->setOutOfStock($row['itemid']);

    $restored += $row['amount'];
    echo $restored . ' ';
    if ($restored >= $toRestore) {
        break;
    }
}
exit;

$productIds = func_query("SELECT distinct od.productid from dbp_order_details od inner join dbp_orders o on od.orderid = o.orderid WHERE od.amount > 0 AND o.date > unix_timestamp()");

foreach ($productIds as $p) {
    echo $p['productid'] . ' ';

    func_update_product_inventory($p['productid'], 0, true, true);
    //break;
}
