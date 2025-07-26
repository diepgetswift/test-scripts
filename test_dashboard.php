<?php 

require "../admin/auth.php";

$stat = new StoreStat(time());

echo date('H:i:s') . ' ';
echo 'removeOutstandingCachedRecords' . PHP_EOL;

$stat->removeOutstandingCachedRecords();

echo date('H:i:s') . ' ';
echo 'update_overview' . PHP_EOL;

$stat->update_overview();

echo date('H:i:s') . ' ';
echo 'prepareRecurringData' . PHP_EOL;

$stat->updateRecurringData();

echo date('H:i:s') . ' ';
echo 'update_sales_stat' . PHP_EOL;
// Sales stats
$stat->update_sales_stat();

echo date('H:i:s') . ' ';
echo 'update_deposit_stat' . PHP_EOL;

$stat->update_deposit_stat();

echo date('H:i:s') . ' ';
echo 'update_delivery_charge_stat' . PHP_EOL;

$stat->update_delivery_charge_stat();

echo date('H:i:s') . ' ';
echo 'update_retention_stat' . PHP_EOL;
// Customer stats
$stat->update_graph_data('get_registration_graph_data', 'customer-registration-stat');
$stat->update_graph_data('get_cancellation_graph_data', 'customer-cancellation-stat');
$stat->update_graph_data('get_retention_graph_data_before', 'customer-retention-stat-before');
$stat->update_retention_stat();

echo date('H:i:s') . ' ';
echo 'updateProductSaleStat' . PHP_EOL;

$stat->updateProductSaleStat();

echo date('H:i:s') . ' ';
echo 'update_top_performer' . PHP_EOL;
// Top performer
$stat->update_top_performer();

echo date('H:i:s') . ' ';
echo 'update_billing_stat' . PHP_EOL;
// Billing stats
$stat->update_billing_stat();

echo date('H:i:s') . ' ';
echo 'update_promotion' . PHP_EOL;
// Promotion
$stat->update_promotion();

echo date('H:i:s') . ' ';
echo 'update_product_report' . PHP_EOL;

// Product report
$stat->update_watching_product_report();
$stat->update_product_report();
