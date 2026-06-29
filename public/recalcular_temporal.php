<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

recalcularSaldosDesdeMovimientos($pdo);
echo "Recálculo completado. Saldo natillera: ";

$stmt = $pdo->query("SELECT saldo_actual FROM natillera_estado WHERE id_estado = 1");
echo number_format($stmt->fetchColumn(), 0, ',', '.');
