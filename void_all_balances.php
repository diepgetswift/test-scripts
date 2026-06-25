<?php
require "../auth.php";

set_time_limit(0);

$dry_run = !empty($argv[1]) && $argv[1] === '--dry-run';

$today      = time();
$date_label = date('m/d/Y', $today);
$payment_notes = "Balance void {$date_label}";

$first_day_of_year = mktime(12, 0, 0, 1, 1, intval(date('Y', $today)));

$customers = func_query("
    SELECT login, first_login
    FROM $sql_tbl[customers]
    WHERE usertype = 'C'
    ORDER BY login
");

if (!$customers) {
    echo "No active customers found.\n";
    exit;
}

$voided  = 0;
$skipped = 0;

foreach ($customers as $row) {
    $login = $row['login'];
    $payment_date = !empty($row['first_login']) ? intval($row['first_login']) : $first_day_of_year;

    $balance = floatval(func_query_first_cell("
        SELECT SUM(payment_value)
        FROM $sql_tbl[payments]
        WHERE payment_login = '" . db_escape($login) . "'
        AND payment_date <= '{$today}'
    "));

    if ($balance == 0) {
        $skipped++;
        continue;
    }

    // Insert an adjustment that brings the balance to zero
    $void_value = -1 * $balance;

    echo ($dry_run ? "[DRY RUN] " : "") . "Voiding balance for {$login}: {$balance} -> inserting {$void_value}\n";

    if (!$dry_run) {
        db_query("
            INSERT INTO $sql_tbl[payments]
                (payment_date, payment_value, payment_notes, payment_login, payment_type)
            VALUES
                ('{$payment_date}', '" . db_escape($void_value) . "', '" . db_escape($payment_notes) . "', '" . db_escape($login) . "', 'A')
        ");
    }

    $voided++;
}

echo "\nDone. Voided: {$voided}, Skipped (zero balance): {$skipped}\n";
