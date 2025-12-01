<?php
session_start();
function checkAuth() {
    if (!isset($_SESSION['usuario'])) {
        header('Location: /public/login.php');
        exit;
    }
}
?>
