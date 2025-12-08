<?php
require_once __DIR__ . '/../includes/auth.php';

resetSession();
header('Location: ../public/login.php');
exit;
?>
