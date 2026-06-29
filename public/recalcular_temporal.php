<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';

recalcularSaldosDesdeMovimientos($pdo);

$stmt = $pdo->query('SELECT saldo_actual FROM natillera_estado WHERE id_estado = 1 LIMIT 1');
$saldo = (float) $stmt->fetchColumn();

echo 'Saldo natillera: ' . number_format($saldo, 0, ',', '.');
