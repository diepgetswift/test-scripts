<?php
/**
 * Import customers from gnd_import.csv into dbp_customers.
 * - Geocodes s_address to populate latitude/longitude
 * - Reformats phone to US format: (XXX) XXX-XXXX
 * - Sets location_id = 0 (unassigned from pickup)
 * - Assigns all imported customers to route_id = 1
 */
set_time_limit(0);

require "../admin/auth.php";

$CSV_FILE = __DIR__ . '/gnd_import.csv';

function format_us_phone($phone)
{
    $digits = preg_replace('/\D/', '', $phone);

    // Strip leading country code 1 if 11 digits
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }

    if (strlen($digits) !== 10) {
        return $phone; // return as-is if not a valid 10-digit US number
    }

    return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
}

// Prevent the auto-route-assign hook from running (we handle routing manually below)
define('DISABLE_AUTO_ROUTE_ASSIGN', true);

$import = new \DBP\Logic\Importer\Customers($CSV_FILE);

$import->setOption(\DBP\Logic\Importer\Customers::OPTION_OVERWRITE_EXISTING, true);
$import->setOption(\DBP\Logic\Importer\Customers::OPTION_AUTO_CREATE_ROUTES, false);
$import->setOption(\DBP\Logic\Importer\Customers::OPTION_BILLING_SAME_AS_SHIPPING, false);
$import->setOption(\DBP\Logic\Importer\Customers::OPTION_SEND_AUTORESPONDERS, false);

// CSV columns already match DB columns; no translation needed except login == email
$import->setHeadersTranslations([
    'login'      => 'login',
    'b_address'  => 'b_address',
    'b_city'     => 'b_city',
    'b_state'    => 'b_state',
    'b_country'  => 'b_country',
    'b_zipcode'  => 'b_zipcode',
    's_address'  => 's_address',
    's_city'     => 's_city',
    's_state'    => 's_state',
    's_country'  => 's_country',
    's_zipcode'  => 's_zipcode',
    'phone'      => 'phone',
]);

// Reformat phone and set location_id = 0 before saving
$import->bindEvent('record.update', function (array $state, $object, $record) {
    $record = $record ?: $state['record'];
    if (!empty($record['phone'])) {
        $record['phone'] = format_us_phone($record['phone']);
    }
    $record['location_id'] = 0;
    return $record;
});

$ROUTE_ID = 6;

// Geocode s_address and assign route after each successful save
$import->bindEvent('record.save', function (array $state, $object, $record) use (&$geocoded_count, &$geocode_failed, &$route_assigned, $ROUTE_ID) {
    global $sql_tbl;

    if (!$state['result']) {
        return;
    }

    $row   = $state['record'];
    $login = $row['login'];

    // Move customer from their existing route to the target route
    // Query users_routes directly to avoid get_assigned_routes() hitting getAutoAssignRoutes()
    $existing_routes = func_query(
        "SELECT route_id FROM $sql_tbl[users_routes] WHERE login = '" . db_escape($login) . "'"
    );

    $userinfo = new \UserInfo($login, 'C');
    foreach ($existing_routes as $r) {
        $source_route_id = (int)$r['route_id'];
        if ($source_route_id == $ROUTE_ID) {
            break;
        }
        if ($userinfo->move_to_route($source_route_id, $ROUTE_ID) !== false) {
            echo "  [route] $login: $source_route_id => $ROUTE_ID\n";
            $route_assigned++;
            break;
        }
    }

    // Geocode
    if (empty($row['s_address']) || empty($row['s_city']) || empty($row['s_state']) || empty($row['s_zipcode'])) {
        echo "  [SKIP geocode] $login — missing address fields\n";
        return;
    }

    $geo = Geocoder::geocode(
        $row['s_address'],
        $row['s_city'],
        $row['s_state'],
        $row['s_zipcode'],
        $row['s_country'] ?? 'US'
    );

    if ($geo) {
        db_query(
            "UPDATE $sql_tbl[customers]
             SET latitude = " . (float)$geo['latitude'] . ",
                 longitude = " . (float)$geo['longitude'] . "
             WHERE login = '" . db_escape($login) . "'"
        );
        echo "  [geocoded] $login => {$geo['latitude']}, {$geo['longitude']}\n";
        $geocoded_count++;
    } else {
        echo "  [geocode FAILED] $login\n";
        $geocode_failed++;
    }

    usleep(500000); // respect Google Maps rate limit (0.5s between requests)
});

$geocoded_count = 0;
$geocode_failed = 0;
$route_assigned = 0;

echo "Starting GND customer import from: $CSV_FILE\n";
echo "Route ID    : $ROUTE_ID\n";

$import->start();

echo "\nDone.\n";
echo "Route assigned: $route_assigned\n";
echo "Geocoded OK   : $geocoded_count\n";
echo "Geocode fail  : $geocode_failed\n";

exit(0);
