<?php
/**
 * db_export.php — mysqldump-compatible export using pure PHP (mysqli).
 * Use this when mysqldump binary isn't available on the server but you
 * have DB connection credentials.
 *
 * Usage:
 *   php db_export.php --host=127.0.0.1 --user=root --pass=secret --db=mydb --out=dump.sql
 *
 * Optional flags:
 *   --port=3306
 *   --no-data              (schema only, no INSERT statements)
 *   --exclude-data=tbl1,tbl2   (dump structure for these tables but skip rows)
 *   --batch=200            (rows per INSERT statement, default 200)
 *
 * Restore with:
 *   mysql -u user -p mydb < dump.sql
 */

error_reporting(E_ALL);
ini_set('memory_limit', '256M'); // rows are streamed, so this stays low even for big tables

function arg($name, $default = null) {
    foreach ($GLOBALS['argv'] as $a) {
        if (strpos($a, "--$name=") === 0) {
            return substr($a, strlen("--$name="));
        }
    }
    foreach ($GLOBALS['argv'] as $a) {
        if ($a === "--$name") return true; // boolean flag
    }
    return $default;
}

$host   = arg('host', '127.0.0.1');
$port   = (int) arg('port', 3306);
$user   = arg('user');
$pass   = arg('pass');
$db     = arg('db');
$out    = arg('out', 'dump.sql');
$noData = (bool) arg('no-data', false);
$batchSize = (int) arg('batch', 200);
$excludeData = array_filter(array_map('trim', explode(',', arg('exclude-data', ''))));

if (!$user || !$db) {
    fwrite(STDERR, "Usage: php db_export.php --user=USER --pass=PASS --db=DBNAME [--host=] [--port=] [--out=] [--no-data] [--exclude-data=a,b]\n");
    exit(1);
}

$mysqli = mysqli_init();
if (!$mysqli->real_connect($host, $user, $pass, $db, $port)) {
    fwrite(STDERR, "Connection failed: " . mysqli_connect_error() . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$fh = fopen($out, 'w');
if (!$fh) {
    fwrite(STDERR, "Cannot open output file: $out\n");
    exit(1);
}

function w($fh, $s) { fwrite($fh, $s); }

// ---- Header ----
w($fh, "-- PHP-generated dump (mysqldump-compatible) of `$db`\n");
w($fh, "-- Host: $host   Generated: " . date('Y-m-d H:i:s') . "\n\n");
w($fh, "SET NAMES utf8mb4;\n");
w($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
w($fh, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

// ---- Get tables and views ----
$result = $mysqli->query("SHOW FULL TABLES FROM `$db`");
$tables = [];
$views  = [];
while ($row = $result->fetch_array(MYSQLI_NUM)) {
    if (strtoupper($row[1]) === 'VIEW') {
        $views[] = $row[0];
    } else {
        $tables[] = $row[0];
    }
}
$result->free();

// ---- Dump tables ----
foreach ($tables as $table) {
    fwrite(STDERR, "Dumping table: $table\n");

    w($fh, "-- ----------------------------\n");
    w($fh, "-- Table structure for `$table`\n");
    w($fh, "-- ----------------------------\n");
    w($fh, "DROP TABLE IF EXISTS `$table`;\n");

    $createRes = $mysqli->query("SHOW CREATE TABLE `$table`");
    $createRow = $createRes->fetch_array(MYSQLI_NUM);
    w($fh, $createRow[1] . ";\n\n");
    $createRes->free();

    if ($noData || in_array($table, $excludeData, true)) {
        continue;
    }

    // Get column names in order
    $colsRes = $mysqli->query("SHOW COLUMNS FROM `$table`");
    $columns = [];
    while ($c = $colsRes->fetch_assoc()) {
        $columns[] = $c['Field'];
    }
    $colsRes->free();
    $colList = '`' . implode('`,`', $columns) . '`';

    // Count rows just for a progress comment (optional, cheap enough with COUNT)
    $countRes = $mysqli->query("SELECT COUNT(*) AS c FROM `$table`");
    $rowCount = (int) $countRes->fetch_assoc()['c'];
    $countRes->free();

    if ($rowCount === 0) {
        w($fh, "-- (no rows)\n\n");
        continue;
    }

    w($fh, "-- ----------------------------\n");
    w($fh, "-- Records of `$table` ($rowCount rows)\n");
    w($fh, "-- ----------------------------\n");

    // Unbuffered query so large tables don't blow up memory
    $dataRes = $mysqli->query("SELECT * FROM `$table`", MYSQLI_USE_RESULT);

    $buffer = [];
    $flush = function () use (&$buffer, $fh, $table, $colList) {
        if (!$buffer) return;
        w($fh, "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $buffer) . ";\n");
        $buffer = [];
    };

    while ($row = $dataRes->fetch_row()) {
        $vals = [];
        foreach ($row as $v) {
            if ($v === null) {
                $vals[] = 'NULL';
            } else {
                $vals[] = "'" . $mysqli->real_escape_string($v) . "'";
            }
        }
        $buffer[] = '(' . implode(',', $vals) . ')';

        if (count($buffer) >= $batchSize) {
            $flush();
        }
    }
    $flush();
    $dataRes->free();

    w($fh, "\n");
}

// ---- Dump views (structure only, after tables so referenced tables exist) ----
foreach ($views as $view) {
    fwrite(STDERR, "Dumping view: $view\n");
    w($fh, "-- ----------------------------\n");
    w($fh, "-- View structure for `$view`\n");
    w($fh, "-- ----------------------------\n");
    w($fh, "DROP VIEW IF EXISTS `$view`;\n");

    $createRes = $mysqli->query("SHOW CREATE VIEW `$view`");
    $createRow = $createRes->fetch_array(MYSQLI_NUM);
    w($fh, $createRow[1] . ";\n\n");
    $createRes->free();
}

// ---- Dump triggers ----
$trigRes = $mysqli->query("SHOW TRIGGERS FROM `$db`");
$triggers = [];
while ($t = $trigRes->fetch_assoc()) {
    $triggers[] = $t['Trigger'];
}
$trigRes->free();

if ($triggers) {
    w($fh, "-- ----------------------------\n");
    w($fh, "-- Triggers\n");
    w($fh, "-- ----------------------------\n");
    foreach ($triggers as $trig) {
        fwrite(STDERR, "Dumping trigger: $trig\n");
        $createRes = $mysqli->query("SHOW CREATE TRIGGER `$trig`");
        $row = $createRes->fetch_assoc();
        $createRes->free();

        w($fh, "DROP TRIGGER IF EXISTS `$trig`;\n");
        w($fh, "DELIMITER ;;\n");
        w($fh, $row['SQL Original Statement'] . ";;\n");
        w($fh, "DELIMITER ;\n\n");
    }
}

// ---- Dump stored procedures ----
$procRes = $mysqli->query("SHOW PROCEDURE STATUS WHERE Db='" . $mysqli->real_escape_string($db) . "'");
$procs = [];
while ($p = $procRes->fetch_assoc()) {
    $procs[] = $p['Name'];
}
$procRes->free();

if ($procs) {
    w($fh, "-- ----------------------------\n");
    w($fh, "-- Stored procedures\n");
    w($fh, "-- ----------------------------\n");
    foreach ($procs as $proc) {
        fwrite(STDERR, "Dumping procedure: $proc\n");
        $createRes = $mysqli->query("SHOW CREATE PROCEDURE `$proc`");
        $row = $createRes->fetch_assoc();
        $createRes->free();

        w($fh, "DROP PROCEDURE IF EXISTS `$proc`;\n");
        w($fh, "DELIMITER ;;\n");
        w($fh, $row['Create Procedure'] . ";;\n");
        w($fh, "DELIMITER ;\n\n");
    }
}

// ---- Dump functions ----
$funcRes = $mysqli->query("SHOW FUNCTION STATUS WHERE Db='" . $mysqli->real_escape_string($db) . "'");
$funcs = [];
while ($f = $funcRes->fetch_assoc()) {
    $funcs[] = $f['Name'];
}
$funcRes->free();

if ($funcs) {
    w($fh, "-- ----------------------------\n");
    w($fh, "-- Functions\n");
    w($fh, "-- ----------------------------\n");
    foreach ($funcs as $func) {
        fwrite(STDERR, "Dumping function: $func\n");
        $createRes = $mysqli->query("SHOW CREATE FUNCTION `$func`");
        $row = $createRes->fetch_assoc();
        $createRes->free();

        w($fh, "DROP FUNCTION IF EXISTS `$func`;\n");
        w($fh, "DELIMITER ;;\n");
        w($fh, $row['Create Function'] . ";;\n");
        w($fh, "DELIMITER ;\n\n");
    }
}

// ---- Dump events ----
$evRes = $mysqli->query("SHOW EVENTS FROM `$db`");
$events = [];
while ($e = $evRes->fetch_assoc()) {
    $events[] = $e['Name'];
}
$evRes->free();

if ($events) {
    w($fh, "-- ----------------------------\n");
    w($fh, "-- Events\n");
    w($fh, "-- ----------------------------\n");
    foreach ($events as $ev) {
        fwrite(STDERR, "Dumping event: $ev\n");
        $createRes = $mysqli->query("SHOW CREATE EVENT `$ev`");
        $row = $createRes->fetch_assoc();
        $createRes->free();

        w($fh, "DROP EVENT IF EXISTS `$ev`;\n");
        w($fh, "DELIMITER ;;\n");
        w($fh, $row['Create Event'] . ";;\n");
        w($fh, "DELIMITER ;\n\n");
    }
}

w($fh, "SET FOREIGN_KEY_CHECKS=1;\n");

fclose($fh);
$mysqli->close();

fwrite(STDERR, "Done. Wrote $out\n");
