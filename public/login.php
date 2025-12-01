<?php
session_start();
if (isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}
$config = [];
try {
    require_once __DIR__ . '/../includes/functions.php';
    $config = getConfiguracionGeneral($pdo);
} catch (Exception $e) {
    $config = ['nombre_sistema' => 'Aplicativo de Natillera creado por Dorian Gómez'];
}
$appName = $config['nombre_sistema'] ?? 'Aplicativo de Natillera creado por Dorian Gómez';
$logoFile = $config['logo_archivo'] ?? null;
$logoPath = $logoFile ? 'assets/logo/' . basename($logoFile) : null;
$message = isset($_GET['error']) ? 'Credenciales inválidas' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $appName; ?> - Ingreso</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1d2a44 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border-radius: 18px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.08);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card login-card text-center">
                <div class="card-body p-4">
                    <?php if ($logoPath): ?>
                        <div class="mb-3 d-flex justify-content-center">
                            <img src="<?php echo $logoPath; ?>" alt="Logo" class="rounded" style="max-height:110px; background:#fff; padding:10px;">
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted small"><?php echo $appName; ?></div>
                        <h1 class="h4 mb-0">Ingreso Administrador</h1>
                    </div>
                    <?php if ($message): ?>
                        <div class="alert alert-danger"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <form method="POST" action="../actions/login.php" class="text-start">
                        <div class="mb-3">
                            <label class="form-label">Usuario</label>
                            <input type="text" name="usuario" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contraseña</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-dark w-100">Ingresar</button>
                    </form>
                    <div class="mt-3 text-muted small text-start">
                        Usuario inicial: admin / admin123 (ejecuta actions/create_admin.php después de cargar la base de datos).
                    </div>
                </div>
                <div class="card-footer bg-light text-muted small">
                    Aplicativo de Natillera creado por Dorian Gómez
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
