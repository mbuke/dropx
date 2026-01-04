// api/test-db.php
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include "db.php";

$result = $conn->query("SHOW TABLES");
$tables = [];

while ($row = $result->fetch_array()) {
    $tables[] = $row[0];
}

echo json_encode($tables);
