<?php
require "../admin/auth.php";

$couponId = 21708;

$orders = func_query("SELECT orderid, login from dbp_coupons_used where coupon_id = {$couponId}")

echo count($orders); die;

foreach ($orders as $row) {
    $orderObject = new CurrentOrder($row['orderid']);
    $coupon = new Coupon($row['login'], $couponId);
    $orderObject->remove_coupon($coupon);
    echo $row['orderid'] . PHP_EOL;
    die;
}
