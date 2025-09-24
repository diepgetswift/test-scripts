<?php 
require "../auth.php";

$sourceRouteId = 18;
$destinationRoute = 15;

$usernames = [
    "Lisamarieclark816@gmail.com"
];

foreach ($usernames as $username) {
    $check1 = func_query_first_cell("SELECT * FROM dbp_users_routes where login = '{$username}' AND route_id = '{$sourceRouteId}'");
    $check2 = func_query_first_cell("SELECT * FROM dbp_users_routes where login = '{$username}' AND route_id = '{$destinationRoute}'");
    $check3 = func_query_first_cell("SELECT * FROM dbp_orders where login = '{$username}' AND route_id = '{$destinationRoute}' AND date > unix_timestamp()");
    
    if (!$check1 || !$check2 || $check3) {
        echo "Validateion fail" . $username . PHP_EOL;
        continue;
    }
    
    $userinfo = new UserInfo($username);
    $userinfo->move_user_orders($sourceRouteId, $destinationRoute, true, false);

    // Copy recurring order
    //$userinfo->copy_recurring_order($sourceRouteId, $destinationRoute, true);
    db_query("update dbp_recuring_orders set route_id = {$destinationRoute} where login = '{$username}' AND route_id = {$sourceRouteId}");
    
    $userinfo->unassign_from_route($sourceRouteId);
}
