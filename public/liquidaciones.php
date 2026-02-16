<?php
require_once __DIR__ . '/../includes/header.php';
?>
<h2 class="mb-3">Liquidaciones</h2>
<p class="text-muted mb-4">Gestiona las liquidaciones de socios por salida anticipada o finalización del ciclo.</p>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-calculator"></i> Liquidación anticipada</div>
            <div class="card-body">
                <p class="mb-3">Calcula de forma detallada la liquidación de un socio, incluyendo ingresos liquidables, préstamos pendientes y cuota de manejo.</p>
                <a class="btn btn-primary" href="liquidacion_anticipada.php">
                    <i class="bi bi-arrow-right-circle"></i> Ir al módulo
                </a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100 border-warning">
            <div class="card-header"><i class="bi bi-tools"></i> Liquidación definitiva</div>
            <div class="card-body">
                <p class="mb-3">Este módulo se encuentra en construcción y estará disponible próximamente.</p>
                <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> En construcción</span>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
