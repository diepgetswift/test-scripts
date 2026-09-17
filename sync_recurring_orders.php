<?php

/**
 * sync_recurring_orders.php
 *
 * Syncs recurring orders, limited to a fixed date range, for the customer
 * list defined in check_customer_info.php.
 */
set_time_limit(0);
require "../auth.php";

$start_date = '2026-09-27 00:00:00';
$end_date = '2026-10-30 23:59:59';
$start_ts = strtotime($start_date);
$end_ts = strtotime($end_date);

// Reuse the same customer list defined in check_customer_info.php so the two
// scripts can't drift apart.
$source = file_get_contents(__DIR__ . '/check_customer_info.php');
if (!preg_match('/\$customers\s*=\s*"(.*?)";/s', $source, $matches)) {
    die("Could not find customer list in check_customer_info.php\n");
}
$logins = array_values(array_unique(array_filter(array_map('trim', explode("\n", $matches[1])))));

echo "Syncing recurring orders for " . count($logins) . " customers, $start_date to $end_date\n";

$synced = 0;
$skipped = 0;

foreach ($logins as $login) {
    $customer_rows = func_query([
        "SELECT login, user_active FROM $sql_tbl[customers] WHERE login = ?",
        [$login]
    ]);

    if (!$customer_rows) {
        echo "SKIP $login - not found\n";
        $skipped++;
        continue;
    }

    if ($customer_rows[0]['user_active'] != 'Y') {
        echo "SKIP $login - inactive\n";
        $skipped++;
        continue;
    }

    $routes = func_table2column(
        func_query([
            "SELECT route_id FROM $sql_tbl[users_routes] WHERE login = ? AND route_id > 0",
            [$login]
        ]),
        'route_id'
    );

    if (!$routes) {
        echo "SKIP $login - no routes assigned\n";
        $skipped++;
        continue;
    }

    foreach ($routes as $route_id) {
        echo "Syncing $login, route $route_id\n";
        $summary = new Summary($login, 'R', $route_id);
        $summary->sync(false, 0, false, null, [$start_ts, $end_ts], [$route_id]);
        $summary->__destruct();
        unset($summary);
    }

    $synced++;
}

echo "Done. Synced: $synced, Skipped: $skipped\n";
