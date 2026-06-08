<?php
require "../admin/auth.php";

$orders = [235894, 235935, 235953, 235966, 235987, 236304, 236310, 236327, 235917, 235961, 236190, 236201, 236223, 236376, 236427, 236452, 236457, 235894, 235935, 235953, 235966, 235987, 236304, 236310, 236327, 235917, 235961, 236190, 236201, 236223, 236376, 236427, 236452, 236457];

foreach ($orders as $order) {
	echo $order . PHP_EOL;
	try {
		$orderObject = OrderFactory::get_by_orderid($order);
		$orderObject->calculate_and_save();
	} catch (\Exception $e) {
		echo $e->getMessage() . PHP_EOL;
	}

}
