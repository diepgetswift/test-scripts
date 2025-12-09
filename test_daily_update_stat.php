<?php
require "../auth.php";

echo "start " . date('H:i:s') . PHP_EOL;

$customers = func_query("SELECT tdc.customer_id, c.login from dbp_today_delivery_customers inner join dbp_customers c ON tdc.customer_id = c.customer_id");

$count = 1;
foreach ($customers as $c) {
    echo $count ++;
    $stat = func_query_first("select count(*) as count, sum(subtotal) as sum from dbp_orders where login = {$c['login']}");
    db_query("UPDATE dbp_customer_stats SET orders_count = {$stat['count']}, lifetime_value = {$stat['sum']} where customer_id = {$c['customer_id']}");
    db_query("DELETE FROM dbp_today_delivery_customers WHERE customer_id = {$c['customer_id']}");
    
    echo $c['login'] . ' ' . $stat['count'] . ' ' . $stat['sum'] . PHP_EOL; 
    die;
}
