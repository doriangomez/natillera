<?php
// Configuración de conexión a la base de datos MySQL usando PDO
// Ajusta los valores según tu entorno de XAMPP.
// host: normalmente "localhost" o "127.0.0.1"
// usuario: por defecto "root" en XAMPP
// contraseña: usualmente "" (vacío) en XAMPP
// nombre BD: natillera_db (se crea con el script database.sql)

require_once __DIR__ . '/../includes/logger.php';

$host = 'localhost';
$db   = 'natillera_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new LoggedPDO($dsn, $user, $pass, $options, 'registrarLog');
} catch (PDOException $e) {
    registrarLog('connection_error', $e->getMessage(), ['dsn' => $dsn]);
    die('Error de conexión: ' . $e->getMessage());
}
?>
