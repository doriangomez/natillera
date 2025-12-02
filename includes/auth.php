<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
function checkAuth() {
    if (!isset($_SESSION['usuario'])) {
        header('Location: ../public/login.php');
        exit;
    }
}

function checkAdmin() {
    checkAuth();
    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
        header('Location: ../public/index.php?error=permiso');
        exit;
    }
}
?>
