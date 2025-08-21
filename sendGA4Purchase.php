<?php
require "../admin/auth.php";

define('DEBUG_GA4', true);

$order = OrderFactory::get_by_orderid('1000000535');

DBP\Module\GoogleGlobalSiteTag\Service\MeasurementProtocol::setDefaultParams();
DBP\Module\GoogleGlobalSiteTag\Service\MeasurementProtocol::sendPurchaseEvent($order);
