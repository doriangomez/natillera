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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background-color: #f6f7fb;
            color: #1f2937;
        }
        body.sidebar-open {
            overflow: hidden;
        }
        .app-shell {
            min-height: 100vh;
            display: flex;
            position: relative;
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
            overflow-y: auto;
            transition: transform 0.25s ease-in-out;
        }
        .sidebar-backdrop {
            display: none;
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
            width: 72px;
            height: 72px;
            object-fit: contain;
            border-radius: 14px;
            background: #fff;
            padding: 8px;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .nav-link-sidebar:hover, .nav-link-sidebar.active {
            background: #1d2a44;
            color: #fff;
        }
        .nav-submenu {
            margin-left: 1.75rem;
            margin-top: -0.1rem;
            margin-bottom: 0.35rem;
            border-left: 1px solid rgba(148, 163, 184, 0.35);
            padding-left: 0.65rem;
        }
        .nav-link-submenu {
            display: block;
            color: #cbd5e1;
            text-decoration: none;
            padding: 0.45rem 0.6rem;
            border-radius: 8px;
            font-size: 0.92rem;
            margin-bottom: 0.1rem;
        }
        .nav-link-submenu:hover, .nav-link-submenu.active {
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
        .topbar .welcome-text {
            min-width: 0;
        }
        .app-content {
            padding: 1.5rem;
        }
        .card {
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border-radius: 16px;
        }
        .card-header {
            border-bottom: none;
            background: #f8fafc;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-body {
            overflow-x: auto;
        }
        .card-body table {
            min-width: 720px;
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
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .category-ingresos { color: #0f5132; background: #e6f4ea; }
        .category-egresos { color: #842029; background: #f8d7da; }
        .category-prestamos { color: #0d3b66; background: #dce9ff; }
        .category-pollas { color: #553c9a; background: #f1e8ff; }
        .category-gastos { color: #8a4600; background: #ffedd5; }
        .btn-icon span { display: inline-flex; align-items: center; gap: 0.4rem; }
        @media (max-width: 991.98px) {
            .app-shell {
                flex-direction: column;
            }
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                height: 100vh;
                transform: translateX(-100%);
                z-index: 1050;
            }
            .sidebar .brand img {
                width: 56px;
                height: 56px;
            }
            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.55);
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.2s ease-in-out, visibility 0.2s ease-in-out;
                z-index: 1040;
            }
            body.sidebar-open .sidebar {
                transform: translateX(0);
                box-shadow: 12px 0 24px rgba(0, 0, 0, 0.35);
            }
            body.sidebar-open .sidebar-backdrop {
                opacity: 1;
                visibility: visible;
            }
            .topbar {
                position: sticky;
                top: 0;
                z-index: 1030;
                padding: 0.9rem 1rem;
            }
            .app-content {
                padding: 1rem;
            }
            .card-body table {
                min-width: 560px;
            }
        }
        @media (max-width: 575.98px) {
            .topbar {
                padding: 0.85rem 0.85rem;
            }
            .app-content {
                padding: 0.85rem;
            }
            .badge-soft {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
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
        <div class="d-lg-none text-center mb-3">
            <button type="button" class="btn btn-outline-light w-100" id="sidebarClose">
                <i class="bi bi-x-lg"></i> Cerrar menú
            </button>
        </div>
        <div class="menu-title">Menú</div>
        <nav>
            <a class="nav-link-sidebar<?php echo $currentPage === 'index.php' ? ' active' : ''; ?>" href="index.php"><i class="bi bi-house-door"></i><span>Inicio</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'socios.php' ? ' active' : ''; ?>" href="socios.php"><i class="bi bi-people"></i><span>Socios</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'actividades.php' ? ' active' : ''; ?>" href="actividades.php"><i class="bi bi-kanban"></i><span>Actividades</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'movimientos.php' ? ' active' : ''; ?>" href="movimientos.php"><i class="bi bi-arrows-left-right"></i><span>Movimientos</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'movimientos_socio.php' ? ' active' : ''; ?>" href="movimientos_socio.php"><i class="bi bi-person-lines-fill"></i><span>Movimientos por socio</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'cuotas_matriz.php' ? ' active' : ''; ?>" href="cuotas_matriz.php"><i class="bi bi-grid-3x3-gap"></i><span>Matriz de cuotas</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'conciliaciones.php' ? ' active' : ''; ?>" href="conciliaciones.php"><i class="bi bi-credit-card-2-front"></i><span>Conciliación medios de pago</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'pollas.php' ? ' active' : ''; ?>" href="pollas.php"><i class="bi bi-trophy"></i><span>Pollas</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'rifas.php' ? ' active' : ''; ?>" href="rifas.php"><i class="bi bi-stars"></i><span>Rifas</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'prestamos.php' ? ' active' : ''; ?>" href="prestamos.php"><i class="bi bi-cash-coin"></i><span>Préstamos</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'prestamos_matriz.php' ? ' active' : ''; ?>" href="prestamos_matriz.php"><i class="bi bi-table"></i><span>Matriz de préstamos</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'retiros_caja.php' ? ' active' : ''; ?>" href="retiros_caja.php"><i class="bi bi-safe2"></i><span>Retiros a caja</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'gastos.php' ? ' active' : ''; ?>" href="gastos.php"><i class="bi bi-receipt"></i><span>Gastos</span></a>
            <a class="nav-link-sidebar<?php echo in_array($currentPage, ['liquidaciones.php', 'liquidacion_anticipada.php', 'liquidacion_definitiva.php'], true) ? ' active' : ''; ?>" href="liquidaciones.php"><i class="bi bi-calculator-fill"></i><span>Liquidaciones</span></a>
            <div class="nav-submenu">
                <a class="nav-link-submenu<?php echo $currentPage === 'liquidacion_anticipada.php' ? ' active' : ''; ?>" href="liquidacion_anticipada.php">Liquidación anticipada</a>
                <a class="nav-link-submenu<?php echo $currentPage === 'liquidacion_definitiva.php' ? ' active' : ''; ?>" href="liquidacion_definitiva.php">Liquidación definitiva</a>
            </div>
            <a class="nav-link-sidebar<?php echo $currentPage === 'reportes.php' ? ' active' : ''; ?>" href="reportes.php"><i class="bi bi-file-earmark-bar-graph"></i><span>Reportes</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'estadisticas.php' ? ' active' : ''; ?>" href="estadisticas.php"><i class="bi bi-pie-chart"></i><span>Estadísticas</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'gestion_talento.php' ? ' active' : ''; ?>" href="gestion_talento.php"><i class="bi bi-people-fill"></i><span>Gestión visual de talento</span></a>
            <a class="nav-link-sidebar<?php echo $currentPage === 'reglamento.php' ? ' active' : ''; ?>" href="reglamento.php"><i class="bi bi-journal-text"></i><span>Reglamento</span></a>
            <a class="nav-link-sidebar" href="../actions/export_csv.php?tipo=menu"><i class="bi bi-filetype-csv"></i><span>Exportar</span></a>
            <?php if ($isAdmin): ?>
                <a class="nav-link-sidebar<?php echo $currentPage === 'copias.php' ? ' active' : ''; ?>" href="copias.php"><i class="bi bi-hdd-stack"></i><span>Copias</span></a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a class="nav-link-sidebar<?php echo $currentPage === 'configuracion.php' ? ' active' : ''; ?>" href="configuracion.php"><i class="bi bi-gear"></i><span>Configuración</span></a>
            <?php endif; ?>
            <a class="nav-link-sidebar" href="../actions/logout.php"><i class="bi bi-box-arrow-right"></i><span>Cerrar sesión</span></a>
        </nav>
    </aside>
    <div class="sidebar-backdrop d-lg-none"></div>
    <div class="app-main">
        <header class="topbar d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3 flex-wrap flex-grow-1">
                <button class="btn btn-outline-primary d-lg-none" type="button" id="sidebarToggle" aria-label="Abrir menú principal">
                    <i class="bi bi-list"></i>
                </button>
                <div class="welcome-text">
                    <div class="text-muted small">Aplicativo de Natillera creado por Dorian Gómez</div>
                    <div class="fw-semibold text-truncate">Bienvenido, <?php echo clean($_SESSION['usuario'] ?? ''); ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                <span class="badge-soft text-uppercase"><i class="bi bi-person-badge"></i> Rol: <?php echo clean($_SESSION['rol'] ?? ''); ?></span>
            </div>
        </header>
        <main class="app-content">
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger d-flex align-items-center justify-content-between gap-3">
                    <div><?php echo clean($_SESSION['error']); ?></div>
                    <?php if (!empty($_SESSION['error_action']) && is_array($_SESSION['error_action'])): ?>
                        <a class="btn btn-sm btn-outline-light" href="<?php echo clean($_SESSION['error_action']['url'] ?? '#'); ?>">
                            <?php echo clean($_SESSION['error_action']['label'] ?? 'Ver detalle'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php unset($_SESSION['error'], $_SESSION['error_action']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?php echo clean($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><?php echo clean($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>
