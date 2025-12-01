<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$config = getConfiguracionGeneral($pdo);
$archivo = $config['reglamento_archivo'] ?? null;
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <p class="text-muted small mb-1">Consulta disponible para todos los usuarios autenticados</p>
        <h1 class="h4 mb-0">Reglamento general</h1>
    </div>
    <a class="btn btn-outline-secondary" href="index.php">
        <i class="bi bi-arrow-left"></i> Volver al panel
    </a>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h6 mb-0">Documento vigente</h2>
                <p class="text-muted small mb-0">Descarga el PDF con las reglas de la natillera</p>
            </div>
            <span class="badge bg-dark">Acceso</span>
        </div>

        <?php if (!empty($archivo)): ?>
            <div class="p-3 border rounded bg-light mb-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="text-muted small d-block">Archivo actual</span>
                        <strong><?php echo clean($archivo); ?></strong>
                    </div>
                    <a class="btn btn-primary" href="../actions/reglamento_download.php">
                        <i class="bi bi-download"></i> Descargar reglamento
                    </a>
                </div>
            </div>
            <p class="text-muted small mb-0">Si necesitas una copia impresa o un formato diferente, contacta al administrador.</p>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                Aún no se ha cargado el reglamento general en el sistema. Pide al administrador que suba el PDF desde Configuración.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($isAdmin)): ?>
    <div class="card mt-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h6 mb-0">Administrar reglamento</h2>
                    <p class="text-muted small mb-0">Sube un nuevo PDF o reemplaza el documento vigente</p>
                </div>
                <span class="badge bg-dark">Admin</span>
            </div>
            <form method="POST" action="../actions/reglamento_upload.php" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Archivo PDF</label>
                    <input type="file" name="reglamento_pdf" class="form-control" accept="application/pdf" required>
                    <?php if (!empty($archivo)): ?>
                        <p class="text-muted small mt-2 mb-0">El archivo nuevo reemplazará al reglamento actual.</p>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Subir reglamento</button>
                    <a class="btn btn-outline-secondary" href="index.php">Volver al panel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
