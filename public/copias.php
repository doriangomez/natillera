<?php
require_once __DIR__ . '/../includes/header.php';
checkAdmin();

$ejecutarConciliacion = isset($_GET['auditar']);
$diagnosticoDatos = null;

if ($ejecutarConciliacion) {
    $diagnosticoDatos = generarConciliacionInterna($pdo);
}
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
                    <div class="col-lg-4 col-md-6">
                        <div class="p-4 border rounded-3 h-100 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <h5 class="mb-1">Reconciliación de Efectivo</h5>
                                    <p class="text-muted mb-0">Compara el efectivo esperado contra bancos, Nequi, personas y efectivo físico.</p>
                                </div>
                                <div class="badge-soft">Control</div>
                            </div>
                            <a class="btn btn-outline-primary w-100" href="reconciliacion_efectivo.php">
                                <i class="bi bi-cash-stack"></i>
                                Abrir módulo
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
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
                    <div class="col-lg-4 col-md-6">
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
                    <div class="col-lg-4 col-md-6">
                        <div class="p-4 border rounded-3 h-100 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <h5 class="mb-1">Registros del sistema</h5>
                                    <p class="text-muted mb-0">Descarga el archivo completo de logs para auditar eventos y errores.</p>
                                </div>
                                <div class="badge-soft">Logs</div>
                            </div>
                            <a class="btn btn-outline-dark w-100" href="../actions/copias_logs.php">
                                <i class="bi bi-clipboard-data"></i>
                                Descargar logs
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-clipboard2-pulse"></i>
                    <span>Conciliación interna y salud de datos</span>
                </div>
                <a class="btn btn-outline-primary btn-sm" href="copias.php?auditar=1">
                    <i class="bi bi-arrow-repeat"></i>
                    Ejecutar validación
                </a>
            </div>
            <div class="card-body">
                <p class="text-muted">Valida la coherencia entre saldos, movimientos y préstamos antes de generar respaldos.</p>

                <?php if ($ejecutarConciliacion && $diagnosticoDatos): ?>
                    <?php if ($diagnosticoDatos['ok']): ?>
                        <div class="alert alert-success">No se encontraron inconsistencias en la conciliación interna.</div>
                    <?php else: ?>
                        <div class="alert alert-warning">Se encontraron hallazgos que requieren revisión. Consulta los detalles.</div>
                    <?php endif; ?>

                    <div class="table-responsive mb-3">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Revisión</th>
                                    <th>Registrado</th>
                                    <th>Esperado</th>
                                    <th>Diferencia</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diagnosticoDatos['checks'] as $check): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo clean($check['titulo']); ?></div>
                                            <div class="text-muted small"><?php echo clean($check['detalle']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars((string) $check['registrado']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $check['esperado']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $check['diferencia']); ?></td>
                                        <td>
                                            <?php if (!empty($check['ok'])): ?>
                                                <span class="badge bg-success">OK</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Revisar</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($diagnosticoDatos['desviaciones_socios'])): ?>
                        <div class="alert alert-info">
                            <div class="fw-semibold mb-1">Saldos de socios con diferencias</div>
                            <p class="mb-2">La tabla muestra los socios cuyo saldo almacenado no coincide con el reconstruido a partir de los movimientos.</p>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Registrado</th>
                                            <th>Calculado</th>
                                            <th>Diferencia</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($diagnosticoDatos['desviaciones_socios'] as $desviacion): ?>
                                            <tr>
                                                <td><?php echo (int) $desviacion['id']; ?></td>
                                                <td><?php echo htmlspecialchars($desviacion['nombre']); ?></td>
                                                <td><?php echo number_format((float) $desviacion['registrado'], 2); ?></td>
                                                <td><?php echo number_format((float) $desviacion['esperado'], 2); ?></td>
                                                <td class="fw-semibold text-danger"><?php echo number_format((float) $desviacion['diferencia'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($diagnosticoDatos['posibles_pagos_duplicados'])): ?>
                        <div class="alert alert-warning">
                            <div class="fw-semibold mb-1">Posibles pagos duplicados por error de carga</div>
                            <p class="mb-2">Se encontraron movimientos con el mismo socio, fecha, actividad y valor, registrados con menos de 30 minutos de diferencia. Esta validación solo informa; no borra ni modifica datos.</p>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>IDs movimiento</th>
                                            <th>Socio</th>
                                            <th>Fecha pago</th>
                                            <th>Actividad</th>
                                            <th class="text-end">Valor</th>
                                            <th>Horas de registro</th>
                                            <th class="text-end">Ventana (min.)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($diagnosticoDatos['posibles_pagos_duplicados'] as $duplicado): ?>
                                            <tr>
                                                <td>#<?php echo htmlspecialchars(implode(', #', $duplicado['ids_movimiento'])); ?></td>
                                                <td><?php echo htmlspecialchars($duplicado['socio']); ?></td>
                                                <td><?php echo htmlspecialchars($duplicado['fecha']); ?></td>
                                                <td><?php echo htmlspecialchars($duplicado['actividad']); ?></td>
                                                <td class="text-end">$<?php echo number_format((float) $duplicado['valor'], 2); ?></td>
                                                <td><?php echo htmlspecialchars(implode(' / ', $duplicado['fechas_registro'])); ?></td>
                                                <td class="text-end"><?php echo (int) $duplicado['minutos_ventana']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($diagnosticoDatos['desviaciones_prestamos'])): ?>
                        <div class="alert alert-warning">
                            <div class="fw-semibold mb-1">Préstamos con saldos diferentes a los movimientos</div>
                            <p class="mb-2">Revisa los saldos registrados frente a los calculados por capital desembolsado, pagos y causación de intereses.</p>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Deudor</th>
                                            <th class="text-end">Capital registrado</th>
                                            <th class="text-end">Capital esperado</th>
                                            <th class="text-end">Dif. capital</th>
                                            <th class="text-end">Interés registrado</th>
                                            <th class="text-end">Interés esperado</th>
                                            <th class="text-end">Dif. interés</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($diagnosticoDatos['desviaciones_prestamos'] as $desviacion): ?>
                                            <tr>
                                                <td><?php echo (int) $desviacion['id']; ?></td>
                                                <td><?php echo htmlspecialchars($desviacion['deudor']); ?></td>
                                                <td class="text-end">$<?php echo number_format((float) $desviacion['capital_registrado'], 2); ?></td>
                                                <td class="text-end">$<?php echo number_format((float) $desviacion['capital_esperado'], 2); ?></td>
                                                <td class="text-end fw-semibold <?php echo abs($desviacion['diferencia_capital']) >= 0.01 ? 'text-danger' : 'text-success'; ?>">
                                                    $<?php echo number_format((float) $desviacion['diferencia_capital'], 2); ?>
                                                </td>
                                                <td class="text-end">$<?php echo number_format((float) $desviacion['interes_registrado'], 2); ?></td>
                                                <td class="text-end">$<?php echo number_format((float) $desviacion['interes_esperado'], 2); ?></td>
                                                <td class="text-end fw-semibold <?php echo abs($desviacion['diferencia_interes']) >= 0.01 ? 'text-danger' : 'text-success'; ?>">
                                                    $<?php echo number_format((float) $desviacion['diferencia_interes'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-secondary mb-0">Ejecuta la validación para obtener un resumen de salud de datos.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
