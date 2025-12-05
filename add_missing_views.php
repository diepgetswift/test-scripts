<?php
require "../auth.php";

\dbp::get_instance()->db()->connection()->exec("CREATE VIEW dbp_pricing_per_pound AS SELECT * FROM dbp_pricing");

\dbp::get_instance()->db()->connection()->exec("CREATE VIEW dbp_products_order AS SELECT IFNULL(poc.delivery_date, 0) AS delivery_date, MD5(CONCAT(IFNULL(poc.productid, pod.productid), '__', IFNULL(poc.option_id, pod.option_id))) AS hash_key, 0 AS route_id, IFNULL(poc.order_by, pod.order_by) AS order_by, IFNULL(poc.store_id, pod.store_id) AS store_id FROM dbp_products_order_default pod LEFT JOIN dbp_products_order_current poc ON poc.productid = pod.productid AND poc.option_id = pod.option_id AND poc.store_id = pod.store_id");
