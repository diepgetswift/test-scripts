<?php 

require "../auth.php";

function autoSelectBox($user, $routeId, $deliveryDate) {

    $orderId = db_query_builder()
                ->select('orderid')
                ->from('orders')
                ->where('login = :login AND route_id = :route_id AND date = :date')
                ->setParameter('login', $user)
                ->setParameter('route_id', $routeId)
                ->setParameter('date', $deliveryDate)
                ->fetchCell();

    if (!$orderId) {
        $hasBox = false;
    } else {
        $hasBox = db_query_builder()
                ->select('od.productid')
                ->from('order_details', 'od')
                ->innerJoin('od', 'products', 'p', 'od.productid = p.productid')
                ->where('od.orderid = :orderid AND p.master_sub_product = "Y"')
                ->setParameter('orderid', $orderId)
                ->fetchCell();
    }
    $balance = db_query_builder()
            ->select('SUM(payment_value)')
            ->from('payments')
            ->where('payment_login = :login AND payment_date <= :date')
            ->setParameter('login', $user)
            ->setParameter('date', strtotime('+1 day', $deliveryDate))
            ->fetchCell();

    if (!$hasBox && $balance > 1) {
        $products = db_query_builder()
                ->select('p.productid, p.product, IFNULL(pe.value, pr.price) as box_price')
                ->from('products', 'p')
                ->innerJoin('p', 'products_categories', 'pc', 'pc.productid = p.productid')
                ->innerJoin('p', 'pricing', 'pr', 'pr.productid = p.productid AND pr.quantity="1" AND pr.membership=""')
                ->leftJoin('p', 'product_extras', 'pe', 'pe.productid = p.productid AND pe.name = "min_subproducts_price" AND pe.value > 0')
                ->where('p.forsale in ("Y", "H")')
                ->andWhere('p.master_sub_product = "Y"')
                ->andWhere('pc.categoryid = :categoryid')
                ->having('box_price < ' . $balance)
                ->setParameter('categoryid', 60)
                ->orderBy('box_price', 'DESC')
                ->setMaxResults(10)
                ->fetchAll();
        if (!empty($products)) {
            $order = \OrderFactory::get($user, $routeId, 'C', $deliveryDate);
            
            foreach ($products as $product) {
                if ($order->addProduct($product, 1)) {
                    break;
                } else {
                    echo $order->get_last_error();
                }
            }
            
            $order->calculate_and_save();
            return true;
        }
    }
    return $hasBox;
}

$login = 'famtes157@hungryharvest.net';
$userinfo = new \UserInfo($login, 'C');
$routeIds = $userinfo->get_assigned_routes();

$routeId = $routeIds[0];

$deliveryDate = $userinfo->get_next_delivery_date($routeId);

autoSelectBox($login, $routeId, $deliveryDate);
