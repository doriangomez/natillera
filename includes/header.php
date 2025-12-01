<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

checkAuth();
$appConfig = getConfiguracionGeneral($pdo);
$appName = trim($appConfig['nombre_sistema'] ?? '') ?: 'Aplicativo de Natillera creado por Dorian Gómez';
$logoFile = $appConfig['logo_archivo'] ?? null;
$logoPath = $logoFile ? 'assets/logo/' . basename($logoFile) : null;
$isAdmin = isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $appName; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background-color: #f6f7fb;
            color: #1f2937;
        }
        .app-shell {
            min-height: 100vh;
            display: flex;
        }
        .sidebar {
            width: 260px;
            background: #0f172a;
            color: #e2e8f0;
            position: sticky;
            top: 0;
            height: 100vh;
            padding: 1.5rem 1rem;
            box-shadow: 4px 0 16px rgba(0,0,0,0.2);
        }
        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            margin-bottom: 1.5rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.04);
        }
        .sidebar .brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            border-radius: 12px;
            background: #fff;
            padding: 6px;
        }
        .sidebar h1 {
            font-size: 1rem;
            margin: 0;
            color: #f8fafc;
        }
        .menu-title {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            color: #94a3b8;
            padding: 0 0.75rem;
            margin-bottom: 0.5rem;
        }
        .nav-link-sidebar {
            display: block;
            padding: 0.65rem 0.85rem;
            border-radius: 10px;
            color: #e2e8f0;
            text-decoration: none;
            margin-bottom: 0.15rem;
            transition: all 0.15s ease-in-out;
        }
        .nav-link-sidebar:hover, .nav-link-sidebar.active {
            background: #1d2a44;
            color: #fff;
        }
        .app-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .topbar {
            background: #ffffff;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .app-content {
            padding: 1.5rem;
        }
        .card {
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border-radius: 16px;
        }
        .btn-primary {
            background-color: #0f172a;
            border-color: #0f172a;
        }
        .btn-primary:hover {
            background-color: #1d2a44;
            border-color: #1d2a44;
        }
        .badge-soft {
            background: #e2e8f0;
            color: #0f172a;
            padding: 0.5rem 0.75rem;
            border-radius: 10px;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <?php if ($logoPath): ?>
                <img src="<?php echo $logoPath; ?>" alt="Logo">
            <?php else: ?>
                <div class="badge-soft">LOGO</div>
            <?php endif; ?>
            <div>
                <h1><?php echo $appName; ?></h1>
            </div>
        </div>
        <div class="menu-title">Menú</div>
        <nav>
            <a class="nav-link-sidebar<?php echo $currentPage === 'index.php' ? ' active' : ''; ?>" href="index.php">Inicio</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'socios.php' ? ' active' : ''; ?>" href="socios.php">Socios</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'actividades.php' ? ' active' : ''; ?>" href="actividades.php">Actividades</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'movimientos.php' ? ' active' : ''; ?>" href="movimientos.php">Movimientos</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'movimientos_socio.php' ? ' active' : ''; ?>" href="movimientos_socio.php">Movimientos por socio</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'conciliaciones.php' ? ' active' : ''; ?>" href="conciliaciones.php">Conciliación medios de pago</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'pollas.php' ? ' active' : ''; ?>" href="pollas.php">Pollas</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'prestamos.php' ? ' active' : ''; ?>" href="prestamos.php">Préstamos</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'gastos.php' ? ' active' : ''; ?>" href="gastos.php">Gastos</a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'reportes.php' ? ' active' : ''; ?>" href="reportes.php">Reportes</a>
            <a class="nav-link-sidebar" href="../actions/export_csv.php?tipo=menu">Exportar</a>
            <?php if ($isAdmin): ?>
                <a class="nav-link-sidebar<?php echo $currentPage === 'configuracion.php' ? ' active' : ''; ?>" href="configuracion.php">Configuración</a>
            <?php endif; ?>
            <a class="nav-link-sidebar" href="../actions/logout.php">Cerrar sesión</a>
        </nav>
    </aside>
    <div class="app-main">
        <header class="topbar d-flex justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Aplicativo de Natillera creado por Dorian Gómez</div>
                <div class="fw-semibold">Bienvenido, <?php echo clean($_SESSION['usuario'] ?? ''); ?></div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge-soft text-uppercase">Rol: <?php echo clean($_SESSION['rol'] ?? ''); ?></span>
            </div>
        </header>
        <main class="app-content">
