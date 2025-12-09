<?php
require "../auth.php";

echo "start " . date('H:i:s') . PHP_EOL;

$date = strtotime('2025-01-01');

$dayStart = strtotime('-3 months');
$dayEnd = strtotime('+1 month');

\dbp::get_instance()->db()->connection()->exec("TRUNCATE TABLE {$sql_tbl['today_delivery_customers']}");
db_query("INSERT INTO {$sql_tbl['today_delivery_customers']} (SELECT DISTINCT c.customer_id FROM {$sql_tbl['customers']} c INNER JOIN {$sql_tbl['orders']} o ON c.login = o.login WHERE o.date > {$dayStart} and o.date < {$dayEnd})");

echo "Finish prepare " . date('H:i:s') . PHP_EOL;

$joinTodayDelivery = "INNER JOIN {$sql_tbl['today_delivery_customers']} tdc on c.customer_id = tdc.customer_id";
$paymentSelect = "SELECT c.customer_id, c.login, ABS(IFNULL(SUM(p.payment_value),0)) FROM $sql_tbl[customers] c $joinTodayDelivery LEFT JOIN $sql_tbl[payments] p ON c.login = p.payment_login AND p.payment_date >= UNIX_TIMESTAMP(MAKEDATE(year(now()),1)) AND p.payment_date <= UNIX_TIMESTAMP() AND p.payment_value < 0 group by c.login, p.payment_login";
$ordersSelect = "SELECT c.customer_id, c.login, IFNULL(SUM(o.subtotal), 0), COUNT(o.orderid) FROM $sql_tbl[customers] c $joinTodayDelivery LEFT JOIN $sql_tbl[orders] o ON c.login = o.login group by c.login, o.login";
$nextDeliveryDate = "SELECT c.customer_id, o.login, IFNULL(MIN(`date`), 0) as `customers_order_next_delivery` FROM $sql_tbl[orders] o INNER JOIN $sql_tbl[customers] c ON c.login = o.login $joinTodayDelivery WHERE `date` > UNIX_TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL '23:59:59' HOUR_SECOND)) GROUP BY c.login, o.login";
$firstOrderDate = "SELECT c.customer_id, o.login, IFNULL(MIN(`date`), 0) AS `first_order_date` FROM $sql_tbl[orders] o JOIN $sql_tbl[customers] c $joinTodayDelivery ON c.login = o.login group by c.login, o.login";

db_query("DELETE FROM $sql_tbl[customer_stats] cs WHERE NOT EXISTS (SELECT login FROM $sql_tbl[customers] c WHERE c.customer_id = cs.customer_id AND c.login = cs.customer_login)");
echo "Finish query 1 " . date('H:i:s') . PHP_EOL;
db_query("INSERT INTO $sql_tbl[customer_stats] (customer_id, customer_login, sales_ytd) $paymentSelect ON DUPLICATE KEY UPDATE sales_ytd = VALUES(sales_ytd)");
echo "Finish query 2 " . date('H:i:s') . PHP_EOL;
db_query("INSERT INTO $sql_tbl[customer_stats] (customer_id, customer_login, lifetime_value, orders_count) $ordersSelect ON DUPLICATE KEY UPDATE lifetime_value = VALUES(lifetime_value), orders_count = VALUES(orders_count)");
echo "Finish query 3 " . date('H:i:s') . PHP_EOL;
db_query("INSERT INTO $sql_tbl[customer_stats] (customer_id, customer_login, customers_order_next_delivery) $nextDeliveryDate ON DUPLICATE KEY UPDATE customers_order_next_delivery = VALUES(customers_order_next_delivery)");
echo "Finish query 4 " . date('H:i:s') . PHP_EOL;
db_query("INSERT INTO $sql_tbl[customer_stats] (customer_id, customer_login, first_order_date) $firstOrderDate ON DUPLICATE KEY UPDATE first_order_date = VALUES(first_order_date)");
echo "Finish query 5 " . date('H:i:s') . PHP_EOL;
