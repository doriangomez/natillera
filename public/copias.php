<?php
require_once __DIR__ . '/../includes/header.php';
checkAdmin();
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-hdd-stack"></i>
                <span>Copias y respaldos</span>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">Descarga la información completa de la base de datos ya sea en un archivo compatible con Excel o en un script SQL listo para restaurar.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-4 border rounded-3 h-100 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <h5 class="mb-1">Exportar a Excel</h5>
                                    <p class="text-muted mb-0">Genera un archivo .xls con todas las tablas y registros actuales.</p>
                                </div>
                                <div class="badge-soft">Excel</div>
                            </div>
                            <a class="btn btn-primary w-100" href="../actions/copias_export_excel.php">
                                <i class="bi bi-file-earmark-spreadsheet"></i>
                                Descargar exportación
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-4 border rounded-3 h-100 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <h5 class="mb-1">Dump de MySQL</h5>
                                    <p class="text-muted mb-0">Crea un archivo SQL con el esquema y los datos para restaurar la base de datos.</p>
                                </div>
                                <div class="badge-soft">SQL</div>
                            </div>
                            <a class="btn btn-outline-secondary w-100" href="../actions/copias_dump.php">
                                <i class="bi bi-database-down"></i>
                                Descargar dump
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
