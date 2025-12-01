<?php
require_once __DIR__ . '/auth.php';
checkAuth();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Natillera</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding-top: 70px; }
        .navbar-brand { font-weight: bold; }
        footer { margin-top: 60px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="/public/index.php">Natillera</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="/public/socios.php">Socios</a></li>
        <li class="nav-item"><a class="nav-link" href="/public/actividades.php">Actividades</a></li>
        <li class="nav-item"><a class="nav-link" href="/public/movimientos.php">Movimientos</a></li>
        <li class="nav-item"><a class="nav-link" href="/public/pollas.php">Pollas</a></li>
        <li class="nav-item"><a class="nav-link" href="/public/prestamos.php">Préstamos</a></li>
        <li class="nav-item"><a class="nav-link" href="/public/reportes.php">Reportes</a></li>
        <li class="nav-item"><a class="nav-link" href="/actions/export_csv.php?tipo=menu">Exportar a Excel</a></li>
        <li class="nav-item"><a class="nav-link" href="/actions/logout.php">Cerrar sesión</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container">
