<?php
require "../auth.php";

function func_cs_calculate_real_end_date(array $updateData)
{
    $originalEndDate = func_set_time_on_date($updateData['end_date'], 23, 59, 59);

    if (($updateData['is_recurring'] ?? '') !== 'Y') {
        return $originalEndDate;
    }

    $dayStart = func_set_time_on_date($updateData['start_date']);

    if ($updateData['frequency'] === 'weekly') {
        $newEndDate = strtotime('+ 7 days', $dayStart) - 1;
    } else if ($updateData['frequency'] === 'bi-weekly') {
        $newEndDate = strtotime('+ 14 days', $dayStart) - 1;
    } else if ($updateData['frequency'] === 'monthly') {
        $newEndDate = strtotime('+ 1 month', $dayStart) - 1;
    } else {
        return $originalEndDate;
    }

    return $newEndDate > $originalEndDate ? $originalEndDate : $newEndDate;
}

// Dry-run by default. Pass --apply to actually persist the fixes.
$apply = in_array('--apply', $argv);

$rows = db_query_builder()
    ->select('p.payment_id, p.payment_login, p.payment_date, p.payment_extra, uc.start_date, uc.end_date, uc.frequency, uc.is_recurring')
    ->from('payments', 'p')
    ->innerJoin('p', 'user_credit', 'uc', 'p.payment_login = uc.login')
    ->where('p.payment_type = :type')
    ->andWhere('p.payment_notes = :notes')
    ->setParameter('type', 'T')
    ->setParameter('notes', 'Credit Subscription')
    ->fetchAll();

echo "Found " . count($rows) . " Credit Subscription payment(s) to check." . PHP_EOL . PHP_EOL;

$checked = 0;
$skipped = 0;
$fixed = 0;

foreach ($rows as $row) {
    $extra = json_decode($row['payment_extra'], true);

    if (!is_array($extra) || !array_key_exists('end_date', $extra)) {
        $skipped++;
        continue;
    }

    $checked++;

    $recordedEndDate = (int) $extra['end_date'];

    $correctEndDate = func_cs_calculate_real_end_date([
        'start_date'   => $row['payment_date'],
        'end_date'     => $row['end_date'],
        'frequency'    => $row['frequency'],
        'is_recurring' => $row['is_recurring'],
    ]);

    if ($recordedEndDate === $correctEndDate) {
        continue;
    }

    echo sprintf(
        "payment_id=%d login=%s payment_date=%s recorded_end=%s correct_end=%s" . PHP_EOL,
        $row['payment_id'],
        $row['payment_login'],
        date('Y-m-d', $row['payment_date']),
        $recordedEndDate ? date('Y-m-d H:i:s', $recordedEndDate) : 'none',
        date('Y-m-d H:i:s', $correctEndDate)
    );

    if ($apply) {
        $extra['end_date'] = $correctEndDate;
        db_query_builder()
            ->array2update('payments', ['payment_extra' => json_encode($extra)])
            ->where('payment_id = :payment_id')
            ->setParameter('payment_id', $row['payment_id'])
            ->execute();
    }

    $fixed++;
}

echo PHP_EOL;
echo "Checked: {$checked}, skipped (no end_date in payment_extra): {$skipped}, mismatched: {$fixed}." . PHP_EOL;

if (!$apply && $fixed > 0) {
    echo "Dry run only - re-run with --apply to persist these fixes." . PHP_EOL;
} elseif ($apply) {
    echo "Applied fixes to {$fixed} payment(s)." . PHP_EOL;
}
