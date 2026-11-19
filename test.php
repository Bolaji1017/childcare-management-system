<?php
require 'includes/db.php';

$rows = $pdo->query('SELECT * FROM homeroom')->fetchAll();
echo '<pre>';
print_r($rows);
echo '</pre>';
