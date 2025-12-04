<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAdmin();

$tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="copia_datos_' . date('Ymd_His') . '.xls"');
echo "<html><head><meta charset=\"UTF-8\"><style>table{border-collapse:collapse;margin-bottom:24px;}th,td{border:1px solid #999;padding:6px;}caption{font-weight:bold;margin:8px 0;}</style></head><body>";

echo '<h1>Exportación completa de datos</h1>';
echo '<p>Generado el ' . date('Y-m-d H:i:s') . '</p>';

foreach ($tables as $table) {
    echo '<table>'; 
    echo '<caption>Tabla: ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '</caption>';

    $columnsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    echo '<thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead>';

    $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
    $hasRows = false;
    echo '<tbody>';
    while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
        $hasRows = true;
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $row[$column];
            if ($value === null) {
                $value = '';
            }
            echo '<td>' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }

    if (!$hasRows) {
        echo '<tr><td colspan="' . count($columns) . '">Sin registros</td></tr>';
    }

    echo '</tbody></table>';
}

echo '</body></html>';
exit;
?>
