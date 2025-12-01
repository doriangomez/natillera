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
    <title>Login Natillera</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Ingreso Administrador</div>
                <div class="card-body">
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
                        <button type="submit" class="btn btn-primary w-100">Ingresar</button>
                    </form>
                    <div class="mt-3 text-muted small">
                        Usuario inicial: admin / admin123 (ejecuta actions/create_admin.php después de cargar la base de datos).
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
