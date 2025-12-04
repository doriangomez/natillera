<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAdmin();

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="dump_mysql_' . date('Ymd_His') . '.sql"');

echo "-- Copia de seguridad de base de datos" . PHP_EOL;
echo "-- Generado: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "SET FOREIGN_KEY_CHECKS=0;" . PHP_EOL . PHP_EOL;

$tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

foreach ($tables as $table) {
    $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
    $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
    $createSql = $createRow['Create Table'] ?? '';

    echo "DROP TABLE IF EXISTS `{$table}`;" . PHP_EOL;
    if ($createSql !== '') {
        echo $createSql . ';' . PHP_EOL . PHP_EOL;
    }

    $dataStmt = $pdo->query("SELECT * FROM `{$table}`");
    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
        $columns = array_map(fn($col) => "`{$col}`", array_keys($row));
        $values = array_map(function ($value) use ($pdo) {
            if ($value === null) {
                return 'NULL';
            }
            return $pdo->quote($value);
        }, array_values($row));

        $columnsSql = implode(', ', $columns);
        $valuesSql = implode(', ', $values);
        echo "INSERT INTO `{$table}` ({$columnsSql}) VALUES ({$valuesSql});" . PHP_EOL;
    }

    echo PHP_EOL;
}

echo "SET FOREIGN_KEY_CHECKS=1;" . PHP_EOL;
exit;
?>
