<?php 

$sourceRouteId = 18;
$destinationRoute = 15;

$usernames = [
    "clairesorrels@gmail.com"
];

foreach ($usernames as $username) {
    $check1 = func_query_first_cell("SELECT * FROM dbp_users_routes where login = '{$username}' AND route_ud = '{$sourceRouteId}'");
    $check2 = func_query_first_cell("SELECT * FROM dbp_users_routes where login = '{$username}' AND route_ud = '{$destinationRoute}'");
    $check3 = func_query_first_cell("SELECT * FROM dbp_orders where login = '{$username}' AND route_ud = '{$destinationRoute}' AND date > unix_timestamp()");
    
    if (!$check1 || !$check2 || $check3) {
        echo "Validateion fail" . $username . PHP_EOL;
        continue;
    }
    
    $userinfo = new UserInfo($username);
    $userinfo->move_user_orders($sourceRouteId, $destinationRoute, true, false);

    // Copy recurring order
    $userinfo->copy_recurring_order($sourceRouteId, $destinationRoute, true);
    
    $userinfo->unassign_from_route($sourceRouteId);
}
