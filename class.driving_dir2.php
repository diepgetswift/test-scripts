<?php
if (! defined('DBP_START'))
{
    header("Location: ../");
    die("Access denied");
}

// TODO $print_bag_labels
// TODO FORCE_LOAD_SHEET_ORDER

class DrivingDir extends DBPObject {
    private $error = FALSE;
    private $ready = FALSE;
    private $way_points = array();
    private $all_products = array();
    private $route_users = array();
    private $driver_details = array();
    private $routeDrivers = [];
    private $driverLogin = '';
    private $route_details = array();
    private $route_date = 0;
    private $file_name = '';
    private $file_name_out = '';
    private $use_week_start = 0;
    private $use_week_end = 0;
    private $only_customers = array();
    private $skip_subproducts = FALSE;
    private $order_deposits_included = FALSE;
    private $route_spots = array();

    /**
     * Indicate whether this object is used to
     * print bag labels
     *
     * @access private
     */
    private $print_bag_labels = '';

    /**
     * Set to TRUE to group products by their IDs
     * to join same products all together without
     * breaking to separate line items
     *
     * @access private
     */
    private $group_products_by_productid = FALSE;

    public function __construct($route_id, $active_only, $driverLogin, $print_bag_labels, $use_week_start, $use_week_end, $only_customers = '', array $orderids = [])
    {
        global $config, $active_modules, $smarty, $sql_tbl;
        
        \DBPLogger::info('start', time());

        $orderids = $orderids ? array_map('intval', $orderids) : [];
        $orderids = array_unique($orderids);

        // Initialize some stuff here
        if ($only_customers) {
            if (is_array($only_customers)) {
                $this->only_customers = $only_customers;
	    } else {
                $this->only_customers = [$only_customers];
            }
        } else {
            $this->only_customers = [];
	}

        if ($orderids) {
            $ordersUsers = db_query_builder()
                ->select('login')
                ->from('orders')
                ->andWhereIn('orderid', $orderids)
                ->groupBy('login')
                ->orderBy('login', 'DESC')
                ->fetchColumn();

            $this->only_customers = array_merge($this->only_customers, $ordersUsers);
	}

        $this->only_customers = array_unique($this->only_customers);

        $this->file_name = '';
        $this->file_name_out = '';

        $this->print_bag_labels = $print_bag_labels;

        $this->use_week_start = $use_week_start;
        $this->use_week_end = $use_week_end;

        $good_order_status = array(
            'C',
            'P'
        );

        $this->skip_subproducts = FALSE;

        if (isset($active_modules['Sub_Products']) and
            (! $print_bag_labels) and ($config['Module_Settings']['show_sheet_subproducts'] != 'Y')
        ) {
            $this->skip_subproducts = TRUE;
        }

        if (func_is_site('scarpa'))
        {
            $good_order_status = array(
                'P'
            );
        }

        $good_status_string = implode("','", $good_order_status);

        if (! defined('FORCE_LOAD_SHEET_ORDER'))
        {
            define ('FORCE_LOAD_SHEET_ORDER', 'Y');
        }

        // get pickup locations for later
        $pickup_locations = array();
        if (isset($active_modules['Pickup_Locations']))
        {
            $pickup_locations = db_query_builder()
                ->select('*')
                ->from('pickup_locations')
                ->fetchHash('location_id');
        }

        // Check to see what must be delivered
        $this->route_details = \Route_factory::get($route_id)->get();

        // get products order for later
        $products_order = db_query_builder()
            ->select('hash_key, order_by')
            ->from('products_order')
            ->where('store_id IN (0, :id)')
            ->setParameter('id', intval($this->route_details['store_id']))
            ->fetchHashPairs('hash_key');

        // get product translations and advanced options for later
        $translated_products = \Products::get_translate_lookup();
        $advanced_options = Products::get_options_lookup();

        // Retrieve delivery notes
        $this->route_details['delivery_notes'] = \Route::get_route_delivery_notes($route_id);

        // Calculate route date
        $route_obj = Route_factory::get($route_id);
        $this->route_date = $route_obj->get_delivery_date($use_week_start);

        $index = 0;

        $all_products = array();

        if (! $this->route_details)
        {
            $this->ready = FALSE;
            $this->error = TRUE;

            return FALSE;
        }

        // Get the driver information
        $this->driverLogin = $driverLogin;
        $this->routeDrivers = \Route::getRouteDrivers($route_id);

        if ($this->routeDrivers) {
            $foundDriver = false;
            foreach ($this->routeDrivers as $driver) {
                if ($driver['login'] == $this->driverLogin) {
                    $this->driver_details = $driver;
                    $foundDriver = true;
                    break;
                }
            }
            if (!$foundDriver) {
                $this->driver_details = $this->routeDrivers[0] ?? [];
            }
        }

        $way_points = array();

	$route_spots = unserialize($this->route_details['spots']) ?: array();
        $route_spots = array_filter($route_spots, function($spot) {
            return  array_filter($spot);
        });

        //if not spot data for route than set for start spot as storage location
        if (empty($route_spots['start'])) {

            $route_spots['start'] = func_query_first("SELECT
                                    s_address as address,
                                    s_city as city,
                                    s_state as state,
                                    s_zipcode as zipcode,
                                    store_name as name,
                                    phone as phone,
                                    latitude,
                                    longitude
                                FROM $sql_tbl[stores]
                                WHERE store_id='" . $this->route_details['store_id'] . "'"
            );

            // GeoCode provider's location
            if (! Geocoder::is_geocoded($route_spots['start']))
            {
                $_data = Geocoder::geocode($route_spots['start']['address'],
                    $route_spots['start']['city'],
                    $route_spots['start']['state'],
                    $route_spots['start']['zipcode']);

                if ($_data)
                {
                    db_query("
					UPDATE
					$sql_tbl[stores]
					SET
					latitude='" . db_escape($_data['latitude']) . "',
						longitude='" . db_escape($_data['longitude']) . "'
						WHERE
						store_id='" . intval($this->route_details['store_id']) . "'
						");

                    $this->route_spots['start'] = array_merge($route_spots['start'], $_data);
                };
            } else {
                $this->route_spots['start'] = $route_spots['start'];
            }

        } else {
            $this->route_spots = $route_spots;
        }

        /*
         * Add first waypoint - the store
         */
        $way_points[] = array(
            //"destination" => $this->route_spots['start'],
            "products" => ""
        );

        /*
         * Get a list of the customers having orders either on the route
         * or being assigned to the route
         */
        $unions = array();

        if($this->only_customers) {
            $order_customers =  " AND login IN ('".implode("','", $this->only_customers)."') ";
            $route_customers =  " AND c.login IN ('".implode("','", $this->only_customers)."') ";
        } else {
            $order_customers = $route_customers = '';
        }

        /*
         * Order table
         */
        $unions[] = "
			(
				SELECT
			 		login, date AS order_date, 999999 AS order_by, 999999 AS default_order_by, 0 AS location_order_by, firstname, lastname, 0 AS use_location_order
				FROM
					$sql_tbl[orders]
				WHERE
					route_id='$route_id' AND date BETWEEN '$use_week_start' AND '$use_week_end' AND status != 'I' $order_customers
        )";

        /*
         * Users routes
         */
        $unions[] = "
			(
				SELECT
					ur.login, 0 AS order_date, 10000+IFNULL(ur.order_by, 0) AS order_by, ur.order_by AS default_order_by, 0 AS location_order_by, c.firstname, c.lastname, 0 AS use_location_order
				FROM
					$sql_tbl[users_routes] ur,
					$sql_tbl[customers] c
				WHERE
					ur.route_id='$route_id' AND
					ur.login=c.login
                    AND c.usertype='C' $route_customers
        )";

        /*
         * Pickup locations
         * The locations order_by has a priority over the one from users_routes
         */
        if (isset($active_modules['Pickup_Locations']))
        {
            $unions[] = "
				(
				 	SELECT
						login, 0 AS order_date, order_by AS order_by, 0 AS default_order_by, order_by AS location_order_by, firstname, lastname, 1 AS use_location_order
					FROM
						$sql_tbl[customers] c, $sql_tbl[pickup_locations_routes] plr
					WHERE
						c.location_id=plr.location_id AND plr.route_id='$route_id' $route_customers
				)";
        }

        /*
         * Get the full users list
         */
        $users_list = func_query(implode(" UNION ALL", $unions));

        \DBPLogger::info('end user list', time());
        /*
         * Set the order_date for every customer
         * Also set the locations order
         */
        $_dates = array();
        $_locations_order = array();
        if ($users_list) {
            foreach ($users_list as $k => $v) {
                if ($v['order_date']) {
                    $_dates[$v['login']] = $v['order_date'];
                }

                if ($v['use_location_order']) {
                    $_locations_order[$v['login']] = $v['order_by'];
                }
            }

            if ($_dates) {
                foreach ($users_list as $k => $v) {
                    if (!empty($_dates[$v['login']])) {
                        $users_list[$k]['order_date'] = $_dates[$v['login']];
                    }
                }
            }

            if ($_locations_order) {
                foreach ($users_list as $k => $v) {
                    if (isset($_locations_order[$v['login']])) {
                        $users_list[$k]['use_location_order'] = 1;
                        $users_list[$k]['location_order_by'] = $_locations_order[$v['login']];
                    }
                }
            }
        }

        /*
         * Get the users list (sorted)
         */
        if ($users_list) {
            usort($users_list, array($this, 'sort_customers'));
        }

        /*
         * Now the $users_list contains a list of the customer usernames (may be duplicated, some of them could have the non-zero order_date).
         * Get a list of all the retrieved usernames.
         */
        $all_usernames = array();
        if ($users_list)
        {
            foreach ($users_list as $k => $v)
            {
                $all_usernames[] = $v['login'];
            }
        }
        $all_usernames = array_unique($all_usernames);

        /*
         * Now get a list of the customers who are not on hold and get their additional details
         */
        $only_users = func_query("SELECT login, user_active, recurring_notes FROM $sql_tbl[customers] WHERE login IN ('" .
            implode("', '", array_map('db_escape',$all_usernames)) . "') AND (store_id='" . $this->route_details['store_id'] .
            "') AND (on_hold!='Y' OR (on_hold_until='Y' AND on_hold_until_date<='" .
            func_set_time_on_date($this->route_date) . "'))");

        /*
         * Now get the final users list
         */
        $this->route_users = array();
        if ($users_list)
        {
            foreach ($users_list as $k => $v)
            {
                if ($only_users)
                {
                    foreach ($only_users as $sk => $route_user)
                    {
                        if ($v['login'] == $route_user['login'])
                        {
                            /*
                             * Add this user to the list
                             */
                            $this->route_users[] = array(
                                'login'       => $v['login'],
                                'order_date'  => $v['order_date'],
                                'order_by'    => $v['order_by'],
                                'user_active' => $route_user['user_active']);

                            /*
                             * Unset from the $only_users to skip adding this customer again
                             */
                            unset($only_users[$sk]);
                            break;
                        }
                    }
                }
            }
        }

        $date_condition = '';
        $date_conditions = array();

        if (!isset($active_modules['Manual_Orders']))
        {
            $date_conditions[] = " (o.date>='$use_week_start' AND o.date<='$use_week_end') ";
        }

        if ($date_conditions)
        {
            $date_condition = " AND ((" . implode(") OR (", $date_conditions) . ")) ";
        }

        if (func_is_site('diamondddairy,smc'))
        {
            $date_condition .= " AND o.date BETWEEN '$this->route_date' AND '$this->route_date' ";
        }

        $this->emitSignal('DRIVINGDIR_BeforeGetWaypointData', array(&$this));
        
        \DBPLogger::info('start waypoint loop', time());

        if ($this->route_users)
        {
            $logins = array();
            foreach ($this->route_users as $route_user) {
                $logins[] = $route_user['login'];
            }
            //$userinfoList = \Userinfo_factory::getByList(array_unique($logins), 'C', true, $route_id);

            foreach ($this->route_users as $route_user)
            {
                $price_condition = "";

                switch ($print_bag_labels) {
                    case 'P':
                        $just_bagged_condition = " AND p.produce_item = 'Y' ";
                        break;

                    case 'Y':
                        $just_bagged_condition = " AND p.bagged_item = 'Y' ";
                        break;

                    case 'PSR':
                        $just_bagged_condition = " AND p.refrigerated = 'Y' ";
                        break;

                    case 'PSG':
                        $just_bagged_condition = " AND p.grocery = 'Y' ";
                        break;

                    case 'PSF':
                        $just_bagged_condition = " AND p.frozen = 'Y' ";
                        break;

                    default:
                        $just_bagged_condition = '';
                }

                /* we will remove later products with zero amount if need*/
                $products_amount_condition = " AND od.amount>='0' ";

                $product_group = '';
                $amount_select = '';

                if ($this->is_group_products_by_productid())
                {
                    $product_group = ' GROUP BY od.productid';
                    $amount_select = ', SUM(od.amount) AS amount';
                }

                /*
                 * Fetch waypoint products
                 */
                $products = func_query("
						SELECT od.*, od.itemid AS cartid,
							o.*,
							p.product AS p_product, p.weight AS p_weight, p.weight_unit as p_weight_unit, p.float_qty AS p_float_qty,
							p.product_size AS p_product_size, IFNULL(s.supplier_name,'') AS p_supplier_name,
                                p.bagged_item AS p_bagged_item,
                                p.grocery AS p_grocery,
                                p.refrigerated AS p_refrigerated,
                                p.preorder AS p_preorder,
                                p.preorder_hide AS p_preorder_hide,
                                p.break_into_cases AS p_break_into_cases,
                                p.frozen AS p_frozen,
                                p.produce_item AS p_produce_item,
                                p.product_color AS p_product_color
							{$amount_select}
						FROM $sql_tbl[order_details] od
							    JOIN $sql_tbl[orders] o ON od.orderid = o.orderid
							    LEFT JOIN $sql_tbl[products] p ON od.productid = p.productid
							    LEFT JOIN $sql_tbl[suppliers] s ON p.supplier_id = s.id
						WHERE o.route_id='$route_id' AND
							o.login='" . addslashes($route_user["login"]) . "' AND
							o.status IN ('$good_status_string') AND
							o.store_id='" . $this->route_details['store_id'] . "'
							$products_amount_condition
							$date_condition
							$price_condition
							$just_bagged_condition
							{$product_group}");

                if (
                    ($print_bag_labels) and ($print_bag_labels != 'C') and ($print_bag_labels != 'U') or
                    isset($active_modules['Manual_Orders'])
                ) {
                    $skipped_products = array();
                } else {
                    $skipped_products = func_query("SELECT io.*, 'Y' AS skipped_product, IFNULL(p.product,'DELETED FROM DATABASE') AS product FROM $sql_tbl[inactive_orders] io LEFT JOIN $sql_tbl[products] p ON io.productid=p.productid WHERE io.date>='$use_week_start' AND io.date<='$use_week_end' AND io.login='" .
                        addslashes($route_user["login"]) . "'");

                    // Do not include the dummy product when using offline application
                    if ((! $products) and
                        (! $skipped_products) and (($route_user["user_active"] == "Y") or (func_is_site('diamondd')))
                    )
                    {
                        if (! defined("OFFLINE_APP"))
                        {
                            $skipped_products = array(
                                array(
                                    "product"         => "",
                                    "skipped_product" => "Y"
                                )
                            );
                        }
                    }
                }

                if ((! defined("OFFLINE_APP")) and
                    ((($config["Appearance"]["show_no_invoice_users"] != "Y") or ($active_only == 'Y')) and
                        (! $products))
                )
                {
                    $skipped_products = array();
                }

                if ($just_bagged_condition && empty($products)) {
                    $shouldInclude = db_query_builder()
                        ->select('orderid')
                        ->from('orders')
                        ->where('route_id = :route_id')
                        ->andWhere('login = :login')
                        ->andWhere('store_id = :store_id')
                        ->andWhere('date >= :start_date AND date <= :end_date')
                        ->setParameter('route_id', $route_id)
                        ->setParameter('login', $route_user["login"])
                        ->setParameter('store_id', $this->route_details['store_id'])
                        ->setParameter('start_date', $use_week_start)
                        ->setParameter('end_date', $use_week_end)
                        ->setMaxResults(1)
                        ->fetchCell();
                }

                $special_cartids = array();

                if ($products or $shouldInclude or $skipped_products or defined("OFFLINE_APP"))
                {
                    $wp_products = array();
                    $customer_notes = "";

                    if ($products)
                    {
                        if ($orderids) {
                            foreach ($products as $prodkey => $prod) {
                                if (!in_array($prod['orderid'], $orderids)) {
                                    unset($products[$prodkey]);
                                }
                            }
                        }

                        foreach ($products as $prodkey => $prod)
                        {

                            if (!empty($prod['customer_notes'])) {
                                $customer_notes = $prod['customer_notes'];
                            }

                            $products[$prodkey]['options'] = $options = $prod['options'] = unserialize($prod['options']);

                            /* for Jardin we should leave products with 0 amount */
                            if (($prod['amount'] == 0) && !func_is_site('jardin')){
                                /* For other websites, if no information about original ordered amount - we will just remove this entry.
                                *  If this information exist - we can show it on bag labels
                                */
                                if (\Config::get("report_outofstock_products") == "Y") {
                                    if (!(isset($options['out_of_stock']) && $options['out_of_stock'] == 'Y')){
                                        unset($products[$prodkey]);
                                        continue;
                                    }
                                } elseif ($config['PDF']['pdf_bag_label_format'] != 'template_02'){
                                    unset($products[$prodkey]);
                                    continue;
                                }
                            }

                            $_product_details = array(
                                'product'          => $prod['p_product'],
                                'product_color'    => $prod['p_product_color'],
                                'weight'           => $prod['p_weight'],
                                'float_qty'        => $prod['p_float_qty'],
                                'product_size'     => $prod['p_product_size'],
                                'supplier_name'    => $prod['p_supplier_name'],
                                'bagged_item'      => $prod['p_bagged_item'],
                                'grocery'          => $prod['p_grocery'],
                                'produce_item'     => $prod['p_produce_item'],
                                'refrigerated'     => $prod['p_refrigerated'],
                                'preorder'         => $prod['p_preorder'],
                                'preorder_hide'    => $prod['p_preorder_hide'],
                                'break_into_cases' => $prod['p_break_into_cases'],
                                'frozen'           => $prod['p_frozen'],

                            );

                            // get product name from order details if product was deleted
                            if (empty($_product_details['product']))
                            {
                                $_product_details['product'] = $prod['product'];
                            }

                            $_product_name = $_product_details['product'];

                            // Mark the master product as 'special' if a sub-product's quantity differs from default
                            if (isset($active_modules['Sub_Products']) && !empty($options['master_cartid'])) {
                                $master_productid = 0;

                                foreach ($products as $sssk => $sssv)
                                {
                                    if (isset($sssv['master_cartid']) && $sssv['master_cartid'] == $options['master_cartid'])
                                    {
                                        $master_productid = $sssv['productid'];
                                    }
                                }

                                if ($master_productid)
                                {
                                    $sub_product_type = Subproducts::get_type($master_productid);
                                }

                                // Depending on the sub-product type,
                                // Find the start of the week
                                if (isset($sub_product_type) && $sub_product_type == 'W')
                                {
                                    $week_condition = " AND " . eq_day('sub_product_week', $this->use_week_start);
                                }
                                else
                                {
                                    $week_condition = " AND sub_product_week='0' ";
                                }

                                $_default_qty = null;
                                if($master_productid)
                                {
                                    $_default_qty = func_query_first_cell("SELECT default_qty FROM $sql_tbl[sub_products] WHERE productid='$master_productid' AND sub_productid='$prod[productid]' $week_condition");
                                }

                                if ($prod['amount'] != $_default_qty)
                                {
                                    // Mark that master product as 'special'
                                    $special_cartids[$options['master_cartid']] = 'Y';
                                }

                            }

                            // Skip sub-products
                            if (($this->skip_subproducts) and ($options['master_cartid']))
                            {
                                continue;
                            }

                            // Retrieve product notes
                            $products[$prodkey]['product_notes'] = $prod['product_notes'] = $options['product_notes'] ?? '';

                            $products[$prodkey]["product"] = $prod["product"] = $_product_name;
                            $products[$prodkey]['product_color'] = $prod['product_color'] = $_product_details['product_color'];
                            $products[$prodkey]['supplier_name'] = $prod['supplier_name'] = $_product_details['supplier_name'];
                            $products[$prodkey]['float_qty'] = $prod['float_qty'] = $_product_details['float_qty'];
                            $products[$prodkey]['weight'] = $prod['weight'] = $_product_details['weight'];
                            $products[$prodkey]['product_size'] = $prod['product_size'] = $_product_details['product_size'];
                            $products[$prodkey]['bagged_item'] = $prod['bagged_item'] = $_product_details['bagged_item'];
                            $products[$prodkey]['grocery'] = $prod['grocery'] = $_product_details['grocery'];
                            $products[$prodkey]['produce_item'] = $prod['produce_item'] = $_product_details['produce_item'];
                            $products[$prodkey]['refrigerated'] = $prod['refrigerated'] = $_product_details['refrigerated'];
                            $products[$prodkey]['preorder'] = $prod['preorder'] = $_product_details['preorder'];
                            $products[$prodkey]['preorder_hide'] = $prod['preorder_hide'] = $_product_details['preorder_hide'];
                            $products[$prodkey]['break_into_cases'] = $prod['break_into_cases'] = $_product_details['break_into_cases'];
                            $products[$prodkey]['frozen'] = $prod['frozen'] = $_product_details['frozen'];


                            $hash = md5($prod["productid"] . "__" . intval($options['advanced_option_id'] ?? 0));

                            if (!empty($all_products[$hash]))
                            {
                                $all_products[$hash]["amount"] += $prod["amount"];
                            }
                            else
                            {
                                $all_products[$hash] = $prod;
                            }
                        }

                        foreach ($products as $prodkey => $prod)
                        {
                            // Skip sub-products
                            if (($this->skip_subproducts) and (!empty($prod['options']['master_cartid'])))
                            {
                                continue;
                            }

                            // Treat the sub-products as usual products on Express
                            if (func_is_site('express'))
                            {
                                $products[$prodkey]['options']['master_cartid'] = $prod['options']['master_cartid'] = '';
                                $products[$prodkey]['options']['is_master_product'] = $prod['options']['is_master_product'] = '';
                            }

                            $hash = md5($prod["productid"] . "__" . intval($prod['options']['advanced_option_id'] ?? 0) . "_" .
                                intval($prod['options']['master_cartid'] ?? 0) . "_" . ($prod['options']['is_master_product'] ?? '') .
                                "_" . (isset($prod['options']['is_master_product']) && ($prod['options']['is_master_product'] == 'Y') ? $prod['cartid'] : ''));

                            if (!empty($wp_products[$hash]))
                            {
                                $wp_products[$hash]["amount"] += $prod["amount"];
                            }
                            else
                            {
                                $wp_products[$hash] = $prod;
                            }
                        }
                    }

                    if ($skipped_products)
                    {
                        $wp_products = array_merge($wp_products, $skipped_products);
                    }

                    $driver_notes = func_get_driver_notes($route_user['login']);

                    /*
                     * Get the full customer details
                     */
                    $userinfoList = \Userinfo_factory::getByList(array($route_user["login"]), 'C', true, $route_id);
                    $destination = isset($userinfoList[$route_user["login"]])
                        ? $userinfoList[$route_user["login"]]->get()
                        : array();

                    /*
                     * Make sure the address was geocoded
                     */
                    if ($destination)
                    {
                        // Check if it has latitude/longitude values
                        if (!\Geocoder::is_geocoded($destination) && \Geocoder::can_geocoded($destination))
                        {
                            $_data = Geocoder::geocode($destination['s_address'],
                                $destination['s_city'],
                                $destination['s_state'],
                                $destination['s_zipcode'],
                                $destination['s_country'],
                                $destination
                            );

                            if ($_data)
                            {
                                db_query("
									UPDATE
									$sql_tbl[customers]
									SET
									latitude='" . db_escape($_data['latitude']) . "',
										longitude='" . db_escape($_data['longitude']) . "'
										WHERE
										login='" . db_escape($route_user['login']) . "' AND
										usertype='C'
										");

                                $destination = array_merge($destination, $_data);
                            }
                        }
                    }
                    else
                    {
                        $destination = func_query_first("SELECT * FROM $sql_tbl[orders] WHERE login='" .
                            addslashes($route_user["login"]) . "' AND status='P' AND store_id='" .
                            $this->route_details['store_id'] . "' ORDER BY orderid DESC LIMIT 1");
                    }

                    if ($destination) {
                        $destination['additional_fields'] = \User::get_instance()->get_additional_fields('C', $route_user["login"]);
                    }

                    /*
                     * Pickup location mod
                     */
                    if (isset($active_modules['Pickup_Locations']) and ($destination)) {
                        $_location = $pickup_locations[$destination['location_id']];

                        if ($_location)
                        {
                            /*
                             * Save original customer's phone
                             */
                            $destination['org_phone'] = $destination['phone'];

                            $destination = array_merge($destination, $_location);
                        }
                    }

                    // Sort products
                    if ($wp_products)
                    {
                        $skippedProductsHashKeyValue = md5("__0");

                        foreach ($wp_products as $product_hash_key => $product_data)
                        {
                            if (empty($product_data["productid"])) {
                                $product_hash_key_value = $skippedProductsHashKeyValue;
                            } else {
                                $product_hash_key_value = md5($product_data["productid"] . "__" . intval($product_data['options']['advanced_option_id'] ?? 0));
                            }

                            $wp_products[$product_hash_key]["hash"] = $product_hash_key_value;
                            $wp_products[$product_hash_key]["order_by"] = $products_order[$product_hash_key_value] ?? null;
                        }

                        sort_products($wp_products, $print_bag_labels ? 'bag_label' : 'sheet');
                    }

                    // Get outstanding deposits
                    // $outstanding_deposits = func_get_user_outstanding_deposits ($sv["login"]);
                    $outstanding_deposit_by_date = 0;

                    /*
                     */
                    if (func_is_site('petwants'))
                    {
                        $outstanding_deposit_by_date = func_get_week_end();
                    }
                    elseif (func_is_site('deliverlean'))
                    {
                        $outstanding_deposit_by_date = dbp::now();
                    }

                    $outstanding_deposits = func_query("SELECT d.* FROM $sql_tbl[deposits] d, $sql_tbl[user_deposits] ud WHERE d.deposit_show_outstanding='Y' AND d.deposit_id=ud.deposit_id AND ud.login='$route_user[login]' ORDER BY d.deposit_name");
                    if ($outstanding_deposits)
                    {
                        foreach ($outstanding_deposits as $ssssk => $ssssv)
                        {
                            $outstanding_deposits[$ssssk]["outstanding_amount"] = func_get_outstanding_deposit_items($route_user["login"], $ssssv["deposit_id"], $outstanding_deposit_by_date);
                        }
                    }

                    // Get customer entered notes
                    if ($config['General']['use_customer_directions'] == 'Y')
                    {
                        $destination['customer_directions'] = func_get_customer_directions($route_user['login'], $this->route_details['route_id'],
                            $use_week_start + 3 * 24 * 60 * 60);
                    }

                    // Retrieve phones and other additional information
                    if (func_is_site('canyon,macro'))
                    {
                        $destination['phones'] = func_query("SELECT rfv.value, rf.field FROM $sql_tbl[register_field_values] rfv, $sql_tbl[register_fields] rf WHERE rfv.login='" .
                            addslashes($route_user['login']) . "' AND rfv.fieldid=rf.fieldid AND rf.field LIKE '%phone%'");
                        $destination['names'] = func_query("SELECT rfv.value, rf.field FROM $sql_tbl[register_field_values] rfv, $sql_tbl[register_fields] rf WHERE rfv.login='" .
                            addslashes($route_user['login']) . "' AND rfv.fieldid=rf.fieldid AND rf.field LIKE '%name%'");
                    }

                    // Check vacation status
                    // vacation_end
                    if ($this->route_date)
                    {
                        $check_vacation_date = $this->route_date;
                    }
                    else
                    {
                        $check_vacation_date = time();
                    }

                    $routeLogin = db_escape($route_user['login']);
                    $destination['vacation'] = func_query_first("SELECT 'Y' AS on_vacation, vacation_start, vacation_end FROM {$sql_tbl['users_vacations']} WHERE login='$routeLogin' AND vacation_start<='$check_vacation_date' AND vacation_end>='$check_vacation_date' AND true_vacation!='N'");

                    // Now check if it is a customer's first delivery
                    if (func_is_site('scarpa'))
                    {
                        $destination['is_first_delivery'] = func_query_first_cell("SELECT orderid FROM {$sql_tbl['orders']} WHERE login='$routeLogin' AND status='C'") ? '' : 'Y';
                    }
                    else
                    {
                        $destination['is_first_delivery'] = func_query_first_cell("SELECT orderid FROM {$sql_tbl['orders']} WHERE login='$routeLogin' AND date<='$use_week_start'") ? '' : 'Y';
                    }

                    // When printing the bag labels and when the Sub_Products module is turned on,
                    // Set the proper order of the products
                    if (isset($active_modules['Sub_Products']) &&
                        (! func_is_site('lospoblanos,milehigh,jardin,express,harold,ivy,farmbox,seatosky,pacificcoast'))
                    ) {
                        if ($wp_products)
                        {
                            $master_products = array();
                            foreach ($wp_products as $prodkey => $prod)
                            {
                                if (!empty($prod['options']) and (!empty($prod['options']['master_cartid'])))
                                {
                                    if (empty($master_products[$prod['options']['master_cartid']]))
                                    {
                                        $master_products[$prod['options']['master_cartid']] = array();
                                    }

                                    $master_products[$prod['options']['master_cartid']][] = $prod['cartid'];
                                }
                            }

                            // Now mark the master products
                            foreach ($wp_products as $prodkey => $prod)
                            {
                                if (!isset($prod['itemid']) || !empty($master_products[$prod['itemid']]))
                                {
                                    $wp_products[$prodkey]['options']['master_product'] = 'Y';
                                }
                            }

                            /*
                             * Split products to products/add-ons
                             * @todo This functionality is obsolete and should be removed
                             */
                            if (($print_bag_labels) &&
                                ($print_bag_labels != 'C') &&
                                $config['Appearance']['not_resort_products_in_bag_label'] != 'Y'
                            )
                            {
                                $new_wp_products = array();

                                $start_addons = FALSE;

                                foreach ($wp_products as $prodkey => $prod)
                                {
                                    if (
                                        ($prod['amount'] <= 0 && ($config['PDF']['pdf_bag_label_format'] != 'template_02'))
                                        or ($prod['skipped_product'] == 'Y')
                                    )
                                    {
                                        continue;
                                    }

                                    if ((! ($prod['options']['master_cartid'])) and
                                        (! $prod['options']['master_product']) and (! $start_addons)
                                    )
                                    {
                                        $new_wp_products[] = array(
                                            'is_header'   => 'Y',
                                            'header_type' => 'addons'
                                        );

                                        $start_addons = TRUE;
                                    }

                                    // Dont include products with zero amounts or skipped products when printing bag labels
                                    $new_wp_products[] = $prod;
                                }

                                // We also should rotate the products matrix here
                                $wp_products = array();
                                if ($new_wp_products)
                                {
                                    $product_index = 0;
                                    $rows = intval(ceil(sizeof($new_wp_products) / 2));
                                    foreach ($new_wp_products as $prodkey => $prod)
                                    {
                                        if ($product_index < ($rows))
                                        {
                                            $use_index = $product_index * 2;
                                        }
                                        else
                                        {
                                            $use_index = ($product_index - $rows) * 2 + 1;
                                        }
                                        $wp_products[$use_index] = $prod;

                                        $product_index ++;
                                    }

                                    // Fill missing cells with spaces
                                    // Find max key
                                    $max_key = 0;
                                    foreach ($wp_products as $prodkey => $prod)
                                    {
                                        if ($prodkey > $max_key)
                                        {
                                            $max_key = $prodkey;
                                        }
                                    }
                                    for ($product_index = 0; $product_index < $max_key; $product_index ++)
                                    {
                                        if (! $wp_products[$product_index])
                                        {
                                            $wp_products[$product_index] = array(
                                                'is_header'   => 'Y',
                                                'header_type' => '&nbsp;'
                                            );
                                        }
                                    }

                                    // Move to site hooks

                                    /*
                                     * Sort by key
                                     * Out of the box wants it in load sheet order, this sort messes up that sorting
                                     */
                                    if (! func_is_site('veggiebin,outofbox,organic2u,localorganicmoms'))
                                    {
                                        ksort($wp_products);
                                    }
                                }

                                unset ($new_wp_products);
                            }
                        }
                    }

                    if (isset($active_modules['Products_Dislikes']))
                    {
                        $destination['customer_dislikes'] = dbp::call_helper('Product_dislikes', 'get_customer_dislikes', $destination['login'], TRUE, 'D');
                    }

                    if (isset($active_modules['Products_Likes']))
                    {
                        $destination['customer_likes'] = dbp::call_helper('Product_dislikes', 'get_customer_dislikes', $destination['login'], TRUE, 'L');
                    }

                    \Products::translate($wp_products, $translated_products);

                    Products::addDisplayOptions($wp_products, $advanced_options);

                    // For each product,
                    // check if it is included into the customer's recurring order
                    if ($wp_products)
                    {
                        // Read recurring order
                        $_recurring_order = new Order($route_user['login'], $route_id, 'R', 0, 0, TRUE); /* Quick load */
                        $_recurring_order_products = $_recurring_order->getProducts();

                        if ($_recurring_order_products)
                        {
                            foreach ($wp_products as $prodkey => $prod)
                            {
                                foreach ($_recurring_order_products as $sssk => $sssv)
                                {
                                    if ($prod['productid'] == $sssv['productid'])
                                    {
                                        $wp_products[$prodkey]['is_recurring'] = 'Y';
                                        break;
                                    }
                                }
                            }
                        }

                        // Drop temporary recurring order
                        $_recurring_order->__destruct();
                        unset($_recurring_order);
                    }

                    $route_notes = func_get_customer_directions($route_user['login'], $route_id, $route_user['order_date']);

                    // Mark products as special
                    if (isset($active_modules['Sub_Products']) and ($wp_products)) {
                        foreach ($wp_products as $prodkey => $prod) {
                            if (isset($prod['cartid']) && !empty($special_cartids[$prod['cartid']])) {
                                $wp_products[$prodkey]['special'] = 'Y';
                            }
                        }
                    }

                    // Get pickup location notes
                    $pickup_location_notes = '';

                    if (isset($active_modules['Pickup_Locations']))
                    {
                        $pickup_location_notes = $pickup_locations[$destination['location_id']]['location_info'];
                    }

                    // Update order_by
                    $destination['order_by'] = $route_user['order_by'];

                    // Create userinfo object in destination
                    $destination['user_info'] = $userinfoList[$route_user['login']];

                    // Calculate order invoice amount
                    $order = new Order($destination['login'], $route_id, 'C', $route_user['order_date'], 0, TRUE);
                    /*
                     * Dont forget to update display totals before retrieving them
                     */
                    $order->calculate();

                    /*
                     * Get the totals
                     */
                    $order_total = $order->getTotals();

                    $invoice_total = $order_total['total'];

                    if ($order_total['payments'])
                    {
                        foreach ($order_total['payments'] as $key => $val)
                        {
                            $invoice_total -= $val['payment_value'];
                        }
                    }

                    $way_points[] = array(
                        "destination"           => $destination,
                        "products"              => $wp_products,
                        'pickup_location_notes' => $pickup_location_notes,
                        "driver_notes"          => $driver_notes,
                        "recurring_notes"       => $route_user['recurring_notes'],
                        "customer_notes"        => $customer_notes,
                        "current_balance"       => $destination['user_info']->get('this_week_balance'),
                        "outstanding_deposits"  => $outstanding_deposits,
                        'route_notes'           => $route_notes,
                        'order_date'            => $route_user['order_date'],
                        'invoice_total'         => $invoice_total,
                        'order_total'           => $order_total['total'],
                        'order_id'              => $order->getOrderId());

                    $this->emitSignal('DRIVINGDIR_FinalizingWaypoint', array($this, &$way_points[count($way_points) - 1]));
                }
            }
        }
        
        \DBPLogger::info('end waypoint loop', time());

        // Force including the route's pickup locations
        if (
            ($config['Module_Settings']['sheets_always_show_locations'] == 'Y') and
            isset($active_modules['Pickup_Locations']) and (! defined('OFFLINE_APP')) and (! $print_bag_labels)
        ) {
            // Get a list of the pickup locations
            $_add_locations = func_query("SELECT pl.*, IFNULL(plr.order_by,0) AS order_by FROM $sql_tbl[pickup_locations] pl LEFT JOIN $sql_tbl[pickup_locations_routes] plr ON pl.location_id=plr.location_id AND plr.route_id='$route_id' WHERE pl.location_id IN ('" .
                implode("','", explode(",", $this->route_details['pickup_locations'])) . "')");

            // Add these locations to the $way_points array
            if ($_add_locations)
            {
                $dummy_products = array(
                    array(
                        'product'         => '',
                        'skipped_product' => 'Y'
                    )
                );

                foreach ($_add_locations as $_add_location)
                {

                    $way_points[] = array(
                        'destination'           => $_add_location,
                        'products'              => $dummy_products,
                        'pickup_location_notes' => $_add_location['location_info'],
                        'driver_notes'          => '',
                        'current_balance'       => '',
                    );
                }
            }

            // Force sort of waypoints
            $_starting_waypoint = $way_points[0];
            $_all_waypoints = array_slice($way_points, 1);

            usort($_all_waypoints, function ($a, $b) {
                $aOrder = intval($a['destination']['order_by'] ?? 0);
                $bOrder = intval($b['destination']['order_by'] ?? 0);
                return ($aOrder > $bOrder) ? 1 : (($aOrder < $bOrder) ? -1 : 0);
            });

            $way_points = array();

            // Add first waypoint
            $way_points[] = $_starting_waypoint;

            if ($_all_waypoints)
            {
                foreach ($_all_waypoints as $sk => $route_user)
                {
                    $way_points[] = $route_user;
                }
            }
        }

        // Check if it needs to swap coolers
        if (($way_points) and isset($active_modules['Cooler_Tracking']) and (! defined('OFFLINE_APP')))
        {
            foreach ($way_points as $sk => $route_user)
            {
                // Skip store address
                if ($sk == 0)
                {
                    continue;
                }

                // Get user route information
                $user_route_info = func_get_user_route_extra($route_user["destination"]["login"], $route_id);

                $swap_cooler_date = 0;

                if ($user_route_info["swap_cooler_date"])
                {
                    $swap_cooler_date = $user_route_info["swap_cooler_date"];
                }
                else
                {
                    // Check to see if it's time to swap cooler
                    $staying_days = ($use_week_end -
                            func_query_first_cell("SELECT first_login FROM $sql_tbl[customers] WHERE login='" .
                                $route_user["destination"]["login"] . "' AND usertype='C'")) / (24 * 60 * 60);

                    if ($staying_days >= $config["Module_Settings"]["swap_cooler_days"])
                    {
                        $user_orders_count = func_query_first_cell("SELECT COUNT(*) FROM $sql_tbl[orders] WHERE login='" .
                            $route_user["destination"]["login"] . "' AND route_id='" . $route_id .
                            "' AND status IN ('C','P','Q') AND date<='$use_week_end'");

                        if ($user_orders_count >= $config["Module_Settings"]["swap_cooler_orders_count"])
                        {
                            $swap_cooler_date = intval($use_week_start + ($use_week_end - $use_week_start) / 2);
                        }
                    }
                }

                $user_route_info["swap_cooler_date"] = $swap_cooler_date;

                // Save user's route info back
                func_save_user_route_extra($route_user["destination"]["login"], $route_id, $user_route_info);

                // Check if swap cooler date is on the current week
                if (($swap_cooler_date > 0) and
                    ($swap_cooler_date >= $use_week_start) and ($swap_cooler_date <= $use_week_end)
                )
                {
                    $way_points[$sk]["destination"]["swap_cooler"] = "Y";
                }
            }
        }
        
        \DBPLogger::info('func_save_user_route_extra', time());

        // Update display_options of $all_products
        Products::addDisplayOptions($all_products);

        // Retrieve information about recurring order products for each waypoint
        if ((func_is_site('jardin')) and ($way_points) and (! defined('OFFLINE_APP')))
        {
            foreach ($way_points as $k => $v)
            {
                if ($v['destination']['login'])
                {
                    $recurring_order = new Order($v['destination']['login'], $route_id, 'R', 0, 0, TRUE); /* Quick load */

                    $way_points[$k]['delivery_options_list'] = $recurring_order->getProductsDeliveryOptions();

                    unset ($recurring_order);

                    // Check if a Basket has no delivery on the previous week
                    if ($v['products'])
                    {
                        foreach ($v['products'] as $sk => $route_user)
                        {
                            if ((strpos(strtolower($route_user['product']), 'basket') !== FALSE) or
                                (strpos(strtolower($route_user['product']), 'panier') !== FALSE)
                            )
                            {
                                // Basket found
                                // Check if it was in the past week's order
                                if (! func_query_first_cell("SELECT o.orderid FROM $sql_tbl[orders] o, $sql_tbl[order_details] od WHERE o.login='" .
                                    $v['destination']['login'] . "' AND o.date>='" .
                                    func_set_time_on_date(strtotime("-7 days", $v['order_date'])) . "' AND o.date<='" .
                                    func_set_time_on_date(strtotime("-7 days", $v['order_date']), 23, 59, 59) .
                                    "' AND o.route_id='$route_id' AND o.orderid=od.orderid AND od.productid='$route_user[productid]'")
                                )
                                {
                                    $way_points[$k]['products'][$sk]['no_prev_week_order'] = 'Y';
                                }
                            }
                        }
                    }
                }
            }
        }

        // Get a warehouse name for each product
        if ($all_products)
        {
            foreach ($all_products as $k => $v)
            {
                $all_products[$k]['warehouse'] = func_query_first_cell("SELECT w.warehouse_name FROM $sql_tbl[warehouses] w, $sql_tbl[products_warehouses] pw WHERE w.warehouse_id=pw.warehouse_id AND pw.productid='$v[productid]'");
            }
        }

        $this->ready = TRUE;

        // get product group
        foreach ($way_points as $key => $wp) {
            if ( ! empty($wp['products'])) {
                $wp['product_groups'] = array();
                foreach ($wp['products'] as $index => $product) {
                    if ($product['bagged_item'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Bagged Item';

                    } elseif ($product['produce_item'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Produce Item';

                    } elseif ($product['refrigerated'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Refrigerated';

                    } elseif ($product['preorder'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Pre-Order Product';

                    } elseif ($product['grocery'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Grocery';

                    } elseif ($product['frozen'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Frozen';

                    } elseif ($product['break_into_cases'] == 'Y') {
                        $way_points[$key]['products'][$index]['product_group'] = 'Break into cases';

                    } elseif (!empty($product['product'])){
                        $way_points[$key]['products'][$index]['product_group'] = 'No Group';
                    }
                }

                usort($way_points[$key]['products'], function($a, $b) {
                    if ($a['product_group'] === $b['product_group']) {
                        return $a['order_by'] <=> $b['order_by'];
                        
                    }
                    return $a['product_group'] <=> $b['product_group'];
                });
    
                if (count($way_points[$key]['products']) > 1) {
                    $productGroupArray = [];
                    foreach ($way_points[$key]['products'] as $product) {
                        $currentGroup = $product['product_group'];
                        if (!isset($productGroupArray[$currentGroup])) {
                            $productGroupArray[$currentGroup] = [];
                        }
            
                        $productGroupArray[$currentGroup][] = $product;
                    }
        
                    foreach ($productGroupArray as $group => $array) {
                        /* move box & subs to top, separated products to bottom */
                        $boxArray = $boxArrayIds = [];
                        foreach ($productGroupArray[$group] as $index => $product) {
                            if (!empty($product['options']['is_master_product']) && $product['options']['is_master_product'] === 'Y') {
                                $boxArrayIds[] = $product['productid'];
                                $boxArray[] = $product;
                                foreach ($productGroupArray[$group] as $i => $p) {
                                    if (!empty($p['options']['master_cartid']) && $p['options']['master_cartid'] === $product['cartid']) {
                                        $boxArrayIds[] = $p['productid'];
                                        $boxArray[] = $p;
                                    }
                                }
                            }
                        }
            
                        foreach ($productGroupArray[$group] as $index => $product) {
                            if (
                                !in_array($product['productid'], $boxArrayIds)
                                || (empty($product['options']['is_master_product']) && empty($product['options']['master_cartid']))
                            ) {
                                $boxArray[] = $product;
                            }
                        }
            
                        $productGroupArray[$group] = $boxArray;
                        /* end of move*/
                    }
        
                    $newWayPoints = [];
                    foreach ($productGroupArray as $group => $array) {
                        foreach ($array as $p) {
                            $newWayPoints[] = $p;
                        }
                    }
                    $way_points[$key]['products'] = $newWayPoints;
                }
            }
        }

        $this->way_points = $way_points;
        $this->all_products = $all_products;

        unset ($way_points);
        unset ($all_products);

        // Now sort $all_products in the order set by admin
        if ($this->all_products)
        {
            foreach ($this->all_products as $k => $v)
            {
                $this->all_products[$k]["hash"] = $k;
                $this->all_products[$k]["order_by"] = $products_order[$k];

                /*
                The code below doesnt work. Replaced to "$products_order[$k];" VP
                    func_query_first_cell("
					SELECT
					order_by
					FROM
					$sql_tbl[products_order]
					WHERE
					hash_key='" . db_escape($k) . "' AND
					store_id IN ('0','" . intval($this->route_details['store_id']) . "')
					");
*/

            }

            sort_products($this->all_products, 'sheet');

            // Now translate all products
            Products::translate($this->all_products);

            // Break the products to cases
            if (! defined("OFFLINE_APP"))
            {
                $_new_all_products = array();

                foreach ($this->all_products as $k => $v)
                {
                    $case_info = func_query_first("SELECT break_into_cases, items_per_case FROM $sql_tbl[products] WHERE productid='$v[productid]'");

                    if (isset($case_info["break_into_cases"]) && ($case_info["break_into_cases"] == "Y") && ($case_info['items_per_case'] > 0))
                    {
                        $_cases_amount = intval($v["amount"] / $case_info["items_per_case"]);
                        $_items_amount = intval($v["amount"] % $case_info["items_per_case"]);

                        if ($_cases_amount > 0)
                        {
                            $_new_all_products[] = array_merge($v, array("amount" => $_cases_amount, "is_case" => "Y", "items_per_case" => $case_info["items_per_case"]));
                        }

                        if ($_items_amount > 0)
                        {
                            $_new_all_products[] = array_merge($v, array("amount" => $_items_amount));
                        }
                    }
                    else
                    {
                        $_new_all_products[] = $v;
                    }
                }

                $this->all_products = $_new_all_products;
                unset ($_new_all_products);
            }
        }
        
        \DBPLogger::info('end', time());

        /*
         * Populate stop_number values for way points
         */
        $current_stop_number = 0;
        foreach($this->way_points as $k => $v)
        {
            $this->way_points[$k]['stop_number'] = $current_stop_number++;
        }

        $this->emitSignal('DRIVINGDIR_WaypointDataRead', array($this));

        $smarty->assign('driver_details', $this->driver_details);
        $smarty->assign('route_spots', $this->route_spots);
        $smarty->assign('driverLogin', $this->driverLogin);
        $smarty->assign('routeDrivers', $this->routeDrivers);
        $smarty->assign('route_details', $this->route_details);
        $smarty->assign('route', $this->route_details);
        $smarty->assign('all_products', $this->all_products);
        $smarty->assign('way_points', $this->way_points);
        $smarty->assign('route_date', $this->route_date);
        $smarty->assign('use_week_start', $use_week_start);
        $smarty->assign('use_week_end', $use_week_end);
    }

    /**
     * Return route details array
     *
     * @param  string $field
     * @return mixed[]
     */
    public function get_route_details($field = NULL)
    {
        if ($field === NULL)
        {
            return $this->route_details;
        }
        else
        {
            return $this->route_details[$field];
        }
    }

    public function get_driver_details($field = NULL)
    {
        if ($field === NULL)
        {
            return $this->driver_details;
        }
        else
        {
            return $this->driver_details[$field];
        }
    }

    /**
     * Get a delivery date
     *
     * @return int
     */
    public function get_route_date()
    {
        return $this->route_date;
    }

    /**
     * Get the string to be used in the sorting callback
     *
     * Used in the DrivingDir::sort_customers callback
     *
     * @param mixed[] $row
     * @return string
     */
    private function get_sort_customers_key($row)
    {
        $key = '';

        /*
         * Always check the route locations order (previously was on Jardin & SMV only)
         */
        if (TRUE)
        {
            if ($row['use_location_order'])
            {
                $key .= str_pad($row['location_order_by'], 12, '0', STR_PAD_LEFT);
            }
            else
            {
                $key .= str_pad($row['default_order_by'], 12, '0', STR_PAD_LEFT);
            }
        }
        else
        {
            $key .= str_pad($row['order_by'], 12, '0', STR_PAD_LEFT);
        }

        if (func_is_site('lospoblanos,smveggies'))
        {
            $key .= strtolower($row['lastname'] . $row['firstname']);
        }
        else
        {
            $key .= strtolower($row['firstname'] . $row['lastname']);
        }

        return $key;
    }

    /**
     * Callback to sort customers after getting the users list
     *
     * Sort it in the pickup location order first,
     * and then in route order
     */
    public function sort_customers($a, $b)
    {
        /*
         * See if location_order_by is '1' - it's the location order_by
         */
        $x = $this->get_sort_customers_key($a);
        $y = $this->get_sort_customers_key($b);

        return $x > $y ? 1 : ($x < $y ? - 1 : 0);
    }

    /**
     * Force grouping products by their IDs
     * to join products with the similar names
     *
     * @param bool $new_state
     */
    public function set_group_products_by_productid($new_state = TRUE)
    {
        $this->group_products_by_productid = $new_state;
    }

    /**
     * Return the state of the products grouping by ID
     *
     * @return bool
     */
    public function is_group_products_by_productid()
    {
        return $this->group_products_by_productid;
    }

    /**
     * Gets a current state of $print_bag_labels
     *
     * @return string
     */
    public function get_print_bag_labels_state()
    {
        return $this->print_bag_labels;
    }

    public function includeOrderDeposits()
    {
        global $sql_tbl;

        if ($this->order_deposits_included)
        {
            return TRUE;
        }

        // Add deposits applied to each order, put it BEFORE the products list
        if ($this->way_points)
        {
            foreach ($this->way_points as $k => $v)
            {
                if (($k) and ($v['products']))
                {
                    $orderids = array();
                    $deposits = array();

                    // First find the order#'s
                    foreach ($v['products'] as $sk => $sv)
                    {
                        if (($sv['orderid']) and (! in_array($sv['orderid'], $orderids)))
                        {
                            $orderids[] = $sv['orderid'];
                        }
                    }

                    // See which deposits has been applied
                    $deposits_query =
                        "SELECT p.*, d.*, d.deposit_name AS product, p.payment_items AS amount, d.deposit_value AS price, 'Y' AS is_deposit FROM $sql_tbl[payments] p, $sql_tbl[deposits] d WHERE p.payment_type='B' AND p.payment_orderid IN ('" .
                        implode("','", $orderids) . "') AND p.payment_deposit_id>'0' AND p.payment_deposit_id=d.deposit_id ORDER BY d.deposit_name";

                    $deposits = func_query($deposits_query);

                    if ($deposits)
                    {
                        $this->way_points[$k]['products'] = array_merge($deposits, $v['products']);
                    }
                }
            }
        }

        $this->order_deposits_included = TRUE;
    }

    public function getAllProducts()
    {
        return $this->all_products;
    }

    /**
     * Setter for $this->all_products
     *
     * @param mixed[] $products
     */
    public function set_all_products($products)
    {
        $this->all_products = $products;
    }

    public function onlyOrder($orderid)
    {
        if ($this->way_points)
        {
            $new_way_points = array();

            foreach ($this->way_points as $k => $v)
            {
                $product_orderid = 0;

                if (is_array($v['products']))
                {
                    foreach ($v['products'] as $sk => $sv)
                    {
                        if ($sv['orderid'])
                        {
                            $product_orderid = $sv['orderid'];
                        }
                    }
                }

                if (($k == 0) or ($product_orderid == $orderid))
                {
                    $new_way_points[] = $v;
                }
            }

            $this->way_points = $new_way_points;
            unset ($new_way_points);
        }
    }

    public function getWaypointsCount()
    {
        return sizeof($this->way_points);
    }

    /**
     * Set waypoints
     *
     * @param mixed[] $waypoints
     */
    public function set_waypoints($waypoints)
    {
        $this->way_points = $waypoints;
    }

    /**
     * Get waypoints
     *
     * @param  int $start Index of the first item
     * @return mixed[]
     */
    public function get_waypoints($start = 0)
    {
        return $this->GetWaypoints($start);
    }

    public function GetWaypoints($start = 0)
    {
        global $config;

        $waypoints = array();

        // Check if we're dealing with the offline app,
        // in certain cases we shouldn't download customers with no orders
        if (defined('OFFLINE_APP') and ($config['Offline_Application']['oa_dont_include_no_order'] == 'Y'))
        {
            if ($this->way_points)
            {
                foreach ($this->way_points as $k => $v)
                {
                    // Always include the first waypoint
                    if (($k == 0) or ($v['products']))
                    {
                        $waypoints[] = $v;
                    }
                }


            }
        }
        else
        {
            $waypoints = array_slice($this->way_points, $start);
        }

        $this->emitSignal('DRIVINGDIR_BeforeReturnWayPoints', array($this, &$waypoints));

        return $waypoints;
    }

    /**
     * Get route spots
     *
     * @return mixed[]
     */
    public function getRouteSpots()
    {
        return $this->route_spots;
    }

    public function PrintBagLabels($return_file = FALSE, $custom_labels = FALSE, $just_check = FALSE)
    {
        global $dbp_dir, $sql_tbl, $smarty, $config, $active_modules;

        // Filter to leave the selected customers only
        if (($this->only_customers) && (is_array($this->only_customers)))
        {
            $new_waypoints = array();

            if ($this->way_points)
            {
                foreach ($this->way_points as $k => $v)
                {
                    if (($k == 0) OR (in_array($v['destination']['login'], $this->only_customers)))
                    {
                        $new_waypoints[] = $v;
                    }
                }
            }

            $this->way_points = $new_waypoints;
        }

        /*
         * For the Aussie's
         */
        if (func_is_site('b2b'))
        {
            $custom_labels = FALSE;
        }
        // If chosen to print custom bag labels only,
        // filter out everything else
        if (($custom_labels) and ($this->way_points))
        {
            $new_waypoints = array();

            /*
            // First get a list of the master sub-products with default qtys
            $master_products = array();
            $all_master_productids = func_table2column(func_query("SELECT productid FROM $sql_tbl[products] WHERE master_sub_product='Y'"), 'productid');

            if ($all_master_productids)
            {
                foreach ($all_master_productids as $k => $v)
                {
                    // Prepare $week_condition
                    $sub_product_type = Subproducts::get_type($v);

                    switch ($sub_product_type)
                    {
                        case 'W':
                            $week_condition = " AND " . eq_day('sub_product_week', $this->use_week_start);
                            break;
                        default:
                            $week_condition = " AND sub_product_week='0' ";
                            break;
                    }

                    $master_products[$v] = array();

                    // sub_productid, default_qty
                    $_subproducts = func_query("SELECT sub_productid, sub_product_day, default_qty FROM $sql_tbl[sub_products] WHERE productid='$v' $week_condition");

                    if ($_subproducts)
                    {
                        foreach ($_subproducts as $sk => $sv)
                        {
                            if ($sub_product_type == 'W')
                            {
                                if (! is_array($master_products[$v][$sv['sub_product_day']]))
                                {
                                    $master_products[$v][$sv['sub_product_day']] = array();
                                }

                                // Add it 'per day'
                                $master_products[$v][$sv['sub_product_day']][$sv['sub_productid']] = $sv['default_qty'];
                            }
                            else
                            {
                                $master_products[$v][$sv['sub_productid']] = $sv['default_qty'];
                            }
                        }
                    }
                }

                foreach ($this->way_points as $k => $v)
                {
                    // Skip first waypoint
                    $add_waypoint = TRUE;

                    if ($k > 0)
                    {
                        $add_waypoint = FALSE;

                        // Check for each master product
                        if ($v['products'])
                        {
                            foreach ($v['products'] as $product_key => $product)
                            {
                                if ($product['skipped_product'] == 'Y')
                                {
                                    continue;
                                }

                                if (in_array($product['productid'], $all_master_productids))
                                {
                                    // Master product found, now look for the subproducts
                                    $master_cartid = $product['itemid'];

                                    $sub_product_type = Subproducts::get_type($product['productid']);

                                    $subproducts = array();

                                    foreach ($v['products'] as $ssv)
                                    {
                                        if ($ssv['options']['master_cartid'] == $master_cartid)
                                        {
                                            if (! $subproducts[$ssv['productid']])
                                            {
                                                $subproducts[$ssv['productid']] = 0;
                                            }

                                            $subproducts[$ssv['productid']] += $ssv['amount'];
                                        }
                                    }

                                    if ($sub_product_type == 'W')
                                    {
                                        $_weekday = date('w', $v['order_date']);

                                        // Check if there are any 'per day' sub products set
                                        if ($master_products[$product['productid']][$_weekday])
                                        {
                                            $compare_array = $master_products[$product['productid']][$_weekday];
                                        }
                                        else
                                        {
                                            // Not found, take the default 'per week' values
                                            $compare_array = $master_products[$product['productid']][''];
                                        }
                                    }
                                    else
                                    {
                                        $compare_array = $master_products[$product['productid']];
                                    }

                                    // Convert all values to float values, also unset empty elements
                                    if ($subproducts and is_array($subproducts))
                                    {
                                        $_new_subproducts = array();
                                        foreach ($subproducts as $___k => $___v)
                                        {
                                            if ($___v > 0)
                                            {
                                                $_new_subproducts[$___k] = price_format($___v);
                                            }
                                        }
                                        $subproducts = $_new_subproducts;
                                    }
                                    if ($compare_array and is_array($compare_array))
                                    {
                                        $_new_compare_array = array();
                                        foreach ($compare_array as $___k => $___v)
                                        {
                                            if ($___v > 0)
                                            {
                                                $_new_compare_array[$___k] = price_format($___v);
                                            }
                                        }
                                        $compare_array = $_new_compare_array;
                                    }

                                    // Now we filled the $subproducts array,
                                    if ($subproducts != $compare_array)
                                    {
                                        $add_waypoint = TRUE;
                                    }
                                }
                            }
                        }
                    }

                }
            }
            */

            foreach ($this->way_points as $k => $v) {
                $add_waypoint = true;
                if ($k > 0) {
                    $add_waypoint = false;
                    foreach ($v['products'] as $product) {
                        if ($product['options']['is_master_product'] == 'Y' && !empty($product['options']['is_customized'])) {
                            $add_waypoint = true;
                            break;
                        }
                    }
                }

                if ($add_waypoint) {
                    $new_waypoints[] = $v;
                }
            }

            $this->way_points = $new_waypoints;
        }

        // Sort the products first
        if ($this->way_points)
        {
            foreach ($this->way_points as $k => $v)
            {
                if ($v['products'])
                {
                    // Replace product names with the bag labels names taken from the extra fields
                    foreach ($v['products'] as $sk => $sv)
                    {
                        if (func_is_site('earthbaby'))
                        {
                            $new_name = func_query_first_cell("SELECT value FROM $sql_tbl[extra_field_values] WHERE productid='$sv[productid]' AND fieldid='1'"); // 1 - hardcoded

                            if ($new_name)
                            {
                                // Override the product name
                                $this->way_points[$k]['products'][$sk]['product'] = $new_name;
                            }
                        }

                        /*
                         * Get a warehouse details
                         */
                        $warehouse_details = func_query_first("SELECT w.warehouse_id, w.warehouse_name FROM $sql_tbl[warehouses] w, $sql_tbl[products_warehouses] pw WHERE w.warehouse_id=pw.warehouse_id AND pw.productid='$sv[productid]'");

                        if ($warehouse_details)
                        {
                            $this->way_points[$k]['products'][$sk]['warehouse_id'] = $warehouse_details['warehouse_id'];
                            $this->way_points[$k]['products'][$sk]['warehouse_name'] = $warehouse_details['warehouse_name'];
                        }
                    }

                    // Sort products within a waypoint stop
                    if (func_is_site('earthbaby'))
                    {
                        usort($this->way_points[$k]['products'], 'func_sort_earthbaby_products');
                    }

                    // For testing purposes (populate test products)
                    // REMOVE AT SOME POINT IN THE FUTURE
                    //for($i = 0; $i < 240; $i++)
                    //	$this->way_points[$k]['products'][] = array("amount" => 1, "product" => "test");
                }
            }

            // Re-assign waypoints
            $smarty->assign('way_points', $this->way_points);
        }

        $smarty->assign('driving_dir', $this);

        /*
         * Emit a signal to gather additional details about waypoints
         * This sorts the products alphabetically.
         * Out of the Box wants their bag labels in load sheet order
         */
        if (! func_is_site('outofbox,localorganicmoms'))
        {
            $this->emitSignal('DRIVINGDIR_GetWaypointData', array(&$this));
        }

        if ($just_check)
        {
            if ((is_array($this->way_points)) and (sizeof($this->way_points) > 1))
            {
                return TRUE;
            }
            else
            {
                return FALSE;
            }
        }

        /*
         * To produce bag labels in a hook
         */
        $this->emitSignal('DRIVINGDIR_PrintBagLabels', array($this));

        $rtf_label_id = $pdf_label_id = "";

        // For a particular site, assign the rtf label type
        if (func_is_site('growersdisabled'))
        {
            $rtf_label_id = "8663";
        }
        elseif (func_is_site('ivy'))
        {
            $rtf_label_id = "8731";
        }
        elseif (func_is_site('dogood'))
        {
            $rtf_label_id = "5161";
        }
        elseif (func_is_site('lospoblanos')) // (attempted) skarsgardfarms moved to PDF
        {
            $rtf_label_id = "5354";
        }

        // For a particular site, assign the pdf label type
        if ($config['PDF']['pdf_bag_label_format']) {
            $pdf_label_id = $config['PDF']['pdf_bag_label_format'];
        } elseif (func_is_site('bodyfuel'))
        {
            $pdf_label_id = "avery_8168";
        }
        elseif (func_is_site('puree'))
        {
            $pdf_label_id = "avery_8164";
        }
        elseif (func_is_site('petwants'))
        {
            $pdf_label_id = 'avery_5163';
        }
        elseif (func_is_site('pacificcoast,4rivers'))
        {
            $pdf_label_id = "avery_5164";
        }
        elseif (func_is_site('itsorganic'))
        {
            $pdf_label_id = "avery_5160";
        }
        elseif (func_is_site('blackhog'))
        {
            $pdf_label_id = "brother_2.4x4";
        }
        elseif (func_is_site('blessedbums'))
        {
            $pdf_label_id = "avery_8860";
        }
        elseif (func_is_site('veggiebin')) // fpp
        {
            $pdf_label_id = "zebra_lp_2844";
        } // replacement model: Zebra GC420
	      elseif (func_is_site('localfresh'))
	      {
		        $pdf_label_id = "avery_22827";
	      }
	      elseif(func_is_site('cuisine,smc'))
	      {
		      $pdf_label_id = "avery_8163";
	      }
        elseif(func_is_site('lospoblanos,mogro')) // skarsgardfarms
        {
	        $pdf_label_id = "avery_5351";
        }

        if ($rtf_label_id != "")
        {
            /*
             * Convert to RTF
             */

            $rtf = new RTFBagLabels($rtf_label_id,
                "Bag_Labels_" . str_replace(' ', '_', $this->route_details['route_name']));

            $rtf->fill($this->route_details['route_name'], $this->way_points);

            if ($return_file)
            {
                $this->file_name = $rtf->getFileName();
            }
            else
            {
                $rtf->out();
                exit;
            }
        }
        else
        {
            /*
             * Convert to PDF
             */
    
            if ($config['PDF']['pdf_bag_label_format'] === 'template_11') {
                // Group products by segregation
                $this->removeBoxProducts();
                $this->groupProductsBySegregation();
                $this->breakWaypointsBySegregation();
            } else if ($config['PDF']['pdf_bag_label_format'] === 'template_12') {
                $this->groupProductsBySegregation(true);
                $this->breakWaypointsBySegregation();
            }

            $smarty->assign('way_points', $this->way_points);
            $bag_labels = new Bag_label($pdf_label_id, $this);

            $pdf = Converter_factory::get('pdf', $bag_labels,
                'Bag_Labels_' . $this->route_details['route_name'], array('bag_labels' => 1));

            if ($return_file)
            {
                $this->file_name = $pdf->convert(FALSE);
            }
            else
            {
                $pdf->convert(TRUE);
                exit;
            }
        }

        return $this->file_name;
    }
    
    public function get_route_message()
    {
        global $sql_tbl;

        return func_query_first_cell("SELECT route_message FROM $sql_tbl[route_messages] WHERE route_id = '" .
            intval($this->route_details['route_id']) . "'");
    }

    public function get_week_end()
    {
        return $this->use_week_end;
    }

    public function getBagLabelFileExtension()
    {
        if (func_is_site('growersdisabled,lospoblanos'))
        {
            return 'rtf';
        }
        else
        {
            return 'pdf';
        }
    }

    public function getRouteDetails()
    {
        return $this->route_details;
    }

    /**
     * Sort waypoint products by field
     *
     * @param string $field
     */
    public function sort_waypoints_products_by($field)
    {
        $sorter = new Sorter($field);

        if ($this->way_points)
        {
            foreach ($this->way_points as &$way_point)
            {
                if ($way_point['products'])
                {
                    $sorter->sort($way_point['products']);
                }

                unset($way_point);
            }
        }
    }

    /**
     * Get a list of all warehouses in the system
     *
     * @return mixed[]
     */
    public function get_all_warehouses()
    {
        global $sql_tbl;

        return func_query("SELECT * FROM $sql_tbl[warehouses] ORDER BY order_by, warehouse_name");
    }

    public function get_map_bounds()
    {
        $lat1 = $long1 = $lat2 = $long2 = NULL;

        if ($this->way_points)
        {
            foreach ($this->way_points as $wp)
            {
                if (is_null($lat1) OR ($wp['destination']['latitude'] < $lat1))
                {
                    $lat1 = $wp['destination']['latitude'];
                }
                if (is_null($long1) OR ($wp['destination']['longitude'] < $long1))
                {
                    $long1 = $wp['destination']['longitude'];
                }
                if (is_null($lat2) OR ($wp['destination']['latitude'] > $lat2))
                {
                    $lat2 = $wp['destination']['latitude'];
                }
                if (is_null($long2) OR ($wp['destination']['longitude'] > $long2))
                {
                    $long2 = $wp['destination']['longitude'];
                }
            }
        }

        return array(
            $lat1,
            $long1,
            $lat2,
            $long2);
    }

    /**
     * Print all bag labels to PDF
     *
     * @param  bool $display set to TRUE to display the resulting file on screen
     * @param  bool $html    set to TRUE to return plain HTML
     * @return string            file name
     */
    public function print_delivery_sheet($display = FALSE, $html = FALSE)
    {
        global $smarty, $https_location, $dbp_dir;

        $route_data = $this->getRouteDetails();

        $smarty->assign('driving_dir', $this);

        $file_name = TempFile::get_temp_name('.html');

        $fd = fopen($file_name, 'wb');
        fputs($fd, '<html>');
        fputs($fd, '<head>');
        fputs($fd, func_display('meta.tpl', $smarty, false));
        fputs($fd, "<link rel='stylesheet' href='$https_location/min/?g=css_admin_pdf'>");

        /*
         * Including print_fix.js disabled by D on 11/21/2012
         */
        fputs($fd, '</head>');
        fputs($fd, "<body style='height: auto; padding: 0px; margin: 0px;'>");

        $smarty->assign('wp', $this->getWaypoints());

        $sheet_template = 'main/delivery_sheet.tpl';

        $this->emitSignal('DRIVINGDIR_BeforeRouteSheetTemplateDisplay', array($this, &$sheet_template));

        fputs($fd, func_display($sheet_template, $smarty, FALSE));
        fputs($fd, '</body></html>');
        fclose($fd);

        /*
         * Convert to PDF
         */
        if ($html)
        {
            if ($display)
            {
                readfile($file_name);
                unlink($file_name);
            }
            else
            {
                return $file_name;
            }
        }
        else
        {
            $converter = Converter_factory::get('pdf', $file_name, strtolower($route_data['route_name']));

            return $converter->convert($display);
        }
    }

    /**
     * Return a waypoint stop number for a customer
     *
     * Starting with zero, which is used to indicate the warehouse position
     *
     * @param  string $username
     * @return int
     */
    public function get_stop_number($username)
    {
        if ($this->way_points)
        {
            foreach ($this->way_points as $k => $v)
            {
                if (isset($v['destination']['login']) &&
                    ($v['destination']['login'] == $username)
                )
                {
                    return $k;
                }
            }
        }

        /*
         * Not found, return 0
         */

        return 0;
    }

    private function removeBoxProducts() {
        global $config;

        if ($this->way_points) {
            $way_points = [];
            foreach ($this->way_points as $k => $v) {
                if ($v['products']) {
                    $new_products = [];
                    foreach ($v['products'] as $product) {
                        if ($product['amount'] <= 0 || $product['skipped_product'] == 'Y') {
                            continue;
                        }

                        if ($config['PDF']['pdf_bag_label_format'] === 'template_11' && $product['options']['is_master_product']) {
                            continue;
                        }

                        $new_products[] = $product;
                    }
                    $this->way_points[$k]['products'] = $new_products;
                    $way_points[] = $this->way_points[$k];
                }
            }
        }
        $this->way_points = $way_points;
    }

    /**
     * Return a waypoints with grouped products by Segregation
     *
     * @return array
     */

    private function groupProductsBySegregation($includeBox = false)
    {

        if ($this->way_points) {

            $segregation = $includeBox ? ['box' => 'Boxes'] : [];

            $segregation  += [
                'produce_item'          => 'Produce Item',
                'frozen'                => 'Frozen',
                'refrigerated'          => 'Refrigerated',
                'grocery'               => 'Grocery',
                'bagged_item'           => 'Bagged Item',
                'preorder'              => 'Pre-Order Product',
                'preorder_hide'         => 'Mark hidden but avail. for sale on the go-live date',
                'break_into_cases'      => 'Break into cases',
                'without_segregation'   => 'No Segregation',
            ];
            $count_segregation = count($segregation);

            foreach ($this->way_points as $k => $v) {

                if ($v['products']) {

                    $temp = [];
                    array_walk($v['products'], function ($product, $key) use ($segregation, &$temp ,$count_segregation, $v) {
                        if ($v['amount'] <= 0 || $v['skipped_product'] == 'Y' || $v['options']['is_master_product']) {
                            //return;
                        }

                        if (!empty($product['options']['is_master_product']) || !empty($product['options']['master_cartid'])) {
                            $product['box'] = 'Y';
                        }

                        $i = 0;
                        foreach ($segregation as $s_key => $label) {
                            $i++;
                            if ($product[$s_key] == 'Y') {
                                $temp[$s_key][] = $product;
                                break;
                            } else {

                                if ($i == $count_segregation) {
                                    $temp['without_segregation'][] = $product;
                                } else {
                                    continue;
                                }

                            }
                        }

                    });

                    $this->way_points[$k]['products_group_by_segregation'] = $this->reorderSegregations($temp, $segregation);
                    $this->way_points[$k]['products_segregation'] = $segregation;
                }
            }

            return  $this->way_points;
        }

    }

    private function reorderSegregations($temp, $segregations) {
        $result = [];

        foreach ($segregations as $k => $v) {
            if (isset($temp[$k])) {
                $result[$k] = $temp[$k];
            }
        }
        return $result;
    }

    private function breakWaypointsBySegregation() {
        global $config;

        $bagLabelFormats = Bag_label::getFormats();
        $productPerLabel = $bagLabelFormats[$config['PDF']['pdf_bag_label_format']]['products_per_label'];

        $waypoints = [];
        if ($this->way_points) {
            foreach ($this->way_points as $k => $v) {
                if (count($v['products']) <= $productPerLabel) {
                    $waypoints[] = $v;
                    continue;
                }

                $productCount = 0;
                $products_group_by_segregation = [];
                foreach ($v['products_group_by_segregation'] as $segregation => $products) {

                    while ($productCount + count($products) > $productPerLabel) {
                        $products_group_by_segregation[$segregation] = array_splice(
                            $products,
                            0,
                            min(
                                count($products),
                                $productPerLabel - $productCount
                            )
                        );

                        $temp = $v;
                        $temp['products_group_by_segregation'] = $products_group_by_segregation;
                        $waypoints[] = $temp;

                        $productCount = 0;
                        $products_group_by_segregation = [];
                    }

                    $productCount += count($products);
                    $products_group_by_segregation[$segregation] = $products;
                }
                $v['products_group_by_segregation'] = $products_group_by_segregation;
                $waypoints[] = $v;
            }
        }
        $this->way_points = $waypoints;
    }
}
