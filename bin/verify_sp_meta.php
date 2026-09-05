<?php

declare(strict_types=1);

require __DIR__ . '/../public/bootstrap.php';

$rows = $pdo->query(
    "SELECT name, last_modified, modified_by, person
     FROM sharepoint_items
     WHERE IFNULL(modified_by, '') != ''
     ORDER BY id ASC
     LIMIT 5"
)->fetchAll();

foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$withPerson = (int) $pdo->query(
    "SELECT COUNT(*) FROM sharepoint_items WHERE IFNULL(person, '') != ''"
)->fetchColumn();
$withMod = (int) $pdo->query(
    "SELECT COUNT(*) FROM sharepoint_items WHERE IFNULL(modified_by, '') != ''"
)->fetchColumn();
$total = (int) $pdo->query('SELECT COUNT(*) FROM sharepoint_items')->fetchColumn();

echo "total=$total with_modified_by=$withMod with_person=$withPerson\n";
