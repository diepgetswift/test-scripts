<?php
require "../auth.php";

use DBP\Module\CreditSubscription\Service\AutoSpendCreditService;

$users = ['testbbekele40', 'testbbekele41', 'testbbekele42'];

foreach ($users as $user) {
    $userinfo = \Userinfo_factory::get($user);
    $routeIds = $userinfo->get_assigned_routes();

    $routeId = $routeIds[0];

    $deliveryDate = $userinfo->get_next_delivery_date($routeId);

    echo "Order:        $orderId\n";
    echo "User:         $user\n";
    echo "Route ID:     $routeId\n";
    echo "Delivery date: " . date('Y-m-d', $deliveryDate) . "\n\n";

    $result = AutoSpendCreditService::selectBox($user, $routeId, $deliveryDate);

    echo "selectBox result: " . ($result ? 'box selected (true)' : 'no box selected (false)') . "\n";
}
