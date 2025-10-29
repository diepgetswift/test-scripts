<?php
set_time_limit(0);
require "../admin/auth.php";

$users = func_query("select * from dbp_users_routes where last_processed > unix_timestamp() - 21 * 86400 AND last_processed < unix_timestamp() - 14 * 86400 AND login = 'veronica.scoggins@rocketmail.com'");
$start_date = strtotime("2025-11-24");

foreach ($users as $u) {
    $user = $u['login'];
    $route_id = $u['route_id'];
    echo "Syncing for $user, $route_id\n";
    $summary = new Summary($user, 'R', $route_id);
    $summary->syncRecurringWithCurrent(false, $start_date);
    $summary->__destruct();
    
    db_query_builder()
        ->array2update(
            'users_routes',
            array(
                'last_processed' => time()
            )
        )
        ->where('login=:user AND route_id = :route_id')
        ->setParameter('user', $user)
        ->setParameter('route_id', $route_id)
        ->execute();
    unset($summary);
}
