<?php
session_start();
if (isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}
$message = isset($_GET['error']) ? 'Credenciales inválidas' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aplicativo de Natillera creado por Dorian Gómez - Ingreso</title>
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
        <div class="col-md-4">
            <div class="card login-card text-center text-md-start">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted small">Aplicativo de Natillera creado por Dorian Gómez</div>
                        <h1 class="h4 mb-0">Ingreso seguro</h1>
                    </div>
                    <?php if ($message): ?>
                        <div class="alert alert-danger"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <form method="POST" action="../actions/login.php">
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
            </div>
        </div>
    </div>
</div>
</body>
</html>
