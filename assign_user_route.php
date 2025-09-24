<?php
require "../auth.php";

$sourceRouteId = 1;
$sourceRouteName = Route::get_route_name($sourceRouteId);

$users = func_query("SELECT login from dbp_users_routes where route_id = '{$sourceRouteId}' LIMIT 1");

foreach ($users as $user) {
    $username = $user['login'];
    $userinfo = new UserInfo($username);
    $userinfo->autoRouteAssign();

    $routeId = func_query_first_cell("SELECT route_id from dbp_users_routes where login = '{$username}'");

    $newRouteName = Route::get_route_name($routeId);

    echo $username . ',' . $sourceRouteName . ',' . $newRouteName;
}
