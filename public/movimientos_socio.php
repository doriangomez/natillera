<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$socios = getSocios($pdo);
$actividades = getActividades($pdo, false, true);
$medios = getMediosPago($pdo);

$fSocio = isset($_GET['socio']) ? (int) $_GET['socio'] : 0;
$fDesde = $_GET['desde'] ?? '';
$fHasta = $_GET['hasta'] ?? '';
$fActividad = isset($_GET['actividad']) ? (int) $_GET['actividad'] : 0;

$where = [];
$params = [];
if ($fSocio) { $where[] = 'm.id_socio = :s'; $params[':s'] = $fSocio; }
if ($fDesde) { $where[] = 'm.fecha >= :d'; $params[':d'] = $fDesde; }
if ($fHasta) { $where[] = 'm.fecha <= :h'; $params[':h'] = $fHasta; }
if ($fActividad) { $where[] = 'm.id_actividad = :a'; $params[':a'] = $fActividad; }

$sql = "SELECT m.*, s.nombre_completo, a.nombre_actividad, mp.nombre AS medio,
               a.afecta_saldo_socio, a.afecta_saldo_natillera, a.es_polla
        FROM movimientos m
        LEFT JOIN socios s ON m.id_socio = s.id_socio
        LEFT JOIN actividades_maestro a ON m.id_actividad = a.id_actividad
        LEFT JOIN medios_pago mp ON m.id_medio_pago = mp.id";
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY m.fecha DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movs = $stmt->fetchAll();

$totales = [
    'socio' => ['ingresos' => 0, 'egresos' => 0],
    'natillera' => ['ingresos' => 0, 'egresos' => 0],
];
foreach ($movs as &$m) {
    $reglaSocio = normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
    $afectaSocio = $m['es_polla'] ? 'neutral' : $reglaSocio;
    $valorSocio = 0;
    if ($afectaSocio === 'suma') {
        $valorSocio = $m['valor'];
    } elseif ($afectaSocio === 'resta') {
        $valorSocio = -$m['valor'];
    }
    $m['valor_socio'] = $valorSocio;

    $reglaNatillera = normalizarReglaAfectacion($m['afecta_saldo_natillera'] ?? 'neutral');
    $valorNatillera = 0;
    if ($reglaNatillera === 'suma') {
        $valorNatillera = $m['valor'];
    } elseif ($reglaNatillera === 'resta') {
        $valorNatillera = -$m['valor'];
    }
    $m['valor_natillera'] = $valorNatillera;

    if ($valorSocio > 0) { $totales['socio']['ingresos'] += $valorSocio; }
    if ($valorSocio < 0) { $totales['socio']['egresos'] += $valorSocio; }
    if ($valorNatillera > 0) { $totales['natillera']['ingresos'] += $valorNatillera; }
    if ($valorNatillera < 0) { $totales['natillera']['egresos'] += $valorNatillera; }
}
unset($m);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted small mb-1">Consulta detallada</p>
        <h1 class="h4 mb-0">Movimientos por socio</h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="../actions/export_csv.php?tipo=movimientos&socio=<?php echo $fSocio; ?>&desde=<?php echo $fDesde; ?>&hasta=<?php echo $fHasta; ?>">Exportar filtrado</a>
        <button id="btnExportarMovimientos" class="btn btn-outline-danger"
            data-socio="<?php echo $fSocio; ?>"
            data-desde="<?php echo $fDesde; ?>"
            data-hasta="<?php echo $fHasta; ?>"
            data-actividad="<?php echo $fActividad; ?>">
            Exportar reporte de movimientos
        </button>
    </div>
</div>
<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2" method="GET">
            <div class="col-md-3">
                <label class="form-label">Socio</label>
                <select name="socio" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach($socios as $s): ?>
                        <option value="<?php echo $s['id_socio']; ?>" <?php echo $fSocio==$s['id_socio']?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Actividad</label>
                <select name="actividad" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach($actividades as $a): ?>
                        <option value="<?php echo $a['id_actividad']; ?>" <?php echo $fActividad==$a['id_actividad']?'selected':''; ?>><?php echo clean($a['nombre_actividad']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?php echo $fDesde; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?php echo $fHasta; ?>">
            </div>
            <div class="col-md-2 align-self-end">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Socio</th>
                <th>Actividad</th>
                <th>Medio</th>
                <th>Valor</th>
                <th>Ingreso</th>
                <th>Egreso</th>
                <th>Afectación saldo socio</th>
                <th>Afectación natillera</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($movs as $m): ?>
                <?php
                    $afectaSocio = $m['es_polla'] ? 'neutral' : normalizarReglaAfectacion($m['afecta_saldo_socio'] ?? 'neutral');
                    $etiquetaAfectacion = 'No afecta';
                    $claseAfectacion = 'bg-secondary-subtle text-secondary';
                    if ($afectaSocio === 'suma') { $etiquetaAfectacion = 'Suma'; $claseAfectacion = 'bg-success-subtle text-success'; }
                    if ($afectaSocio === 'resta') { $etiquetaAfectacion = 'Resta'; $claseAfectacion = 'bg-danger-subtle text-danger'; }

                    $reglaNatillera = normalizarReglaAfectacion($m['afecta_saldo_natillera'] ?? 'neutral');
                    $etiquetaNatillera = 'No afecta';
                    $claseNatillera = 'bg-secondary-subtle text-secondary';
                    if ($reglaNatillera === 'suma') { $etiquetaNatillera = 'Suma'; $claseNatillera = 'bg-success-subtle text-success'; }
                    if ($reglaNatillera === 'resta') { $etiquetaNatillera = 'Resta'; $claseNatillera = 'bg-danger-subtle text-danger'; }
                ?>
                <tr>
                    <td><?php echo $m['fecha']; ?></td>
                    <td><?php echo clean($m['nombre_completo'] ?? 'General'); ?></td>
                    <td><?php echo clean($m['nombre_actividad']); ?></td>
                    <td><?php echo clean($m['medio'] ?: $m['medio_consignacion']); ?></td>
                    <td>$<?php echo number_format($m['valor'],0,',','.'); ?></td>
                    <td><?php echo $m['es_ingreso'] ? 'Sí' : ''; ?></td>
                    <td><?php echo $m['es_egreso'] ? 'Sí' : ''; ?></td>
                    <td>
                        <div><span class="badge <?php echo $claseAfectacion; ?>"><?php echo $etiquetaAfectacion; ?></span></div>
                        <div class="small text-muted">$<?php echo number_format($m['valor_socio'],0,',','.'); ?></div>
                    </td>
                    <td>
                        <div><span class="badge <?php echo $claseNatillera; ?>"><?php echo $etiquetaNatillera; ?></span></div>
                        <div class="small text-muted">$<?php echo number_format($m['valor_natillera'],0,',','.'); ?></div>
                    </td>
                    <td class="small text-muted"><?php echo clean($m['observaciones']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="alert alert-info">
    <div><strong>Socio:</strong> Ingresos $<?php echo number_format($totales['socio']['ingresos'],0,',','.'); ?> | Egresos $<?php echo number_format(abs($totales['socio']['egresos']),0,',','.'); ?> | Saldo neto $<?php echo number_format($totales['socio']['ingresos']+$totales['socio']['egresos'],0,',','.'); ?></div>
    <div class="mt-1"><strong>Natillera:</strong> Ingresos $<?php echo number_format($totales['natillera']['ingresos'],0,',','.'); ?> | Egresos $<?php echo number_format(abs($totales['natillera']['egresos']),0,',','.'); ?> | Saldo neto $<?php echo number_format($totales['natillera']['ingresos']+$totales['natillera']['egresos'],0,',','.'); ?></div>
</div>
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exportar movimientos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="exportForm" class="needs-validation" novalidate>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label d-block">Modo de exportación</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="modoExportacion" id="modoIndividual" value="individual" checked>
                            <label class="form-check-label" for="modoIndividual">Individual</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="modoExportacion" id="modoColectivo" value="colectivo">
                            <label class="form-check-label" for="modoColectivo">Colectivo</label>
                        </div>
                    </div>
                    <div class="mb-3" id="campoSocio">
                        <label class="form-label" for="socioExport">Socio</label>
                        <select class="form-select" id="socioExport" required>
                            <option value="">Selecciona un socio</option>
                            <?php foreach($socios as $s): ?>
                                <option value="<?php echo $s['id_socio']; ?>" <?php echo $fSocio==$s['id_socio']?'selected':''; ?>><?php echo clean($s['nombre_completo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Debes elegir un socio para exportar de forma individual.</div>
                    </div>
                    <div class="mb-3" id="campoRuta" style="display:none;">
                        <label class="form-label" for="rutaExport">Carpeta destino (opcional)</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="rutaExport" name="rutaExport" placeholder="Ej: exportes_movimientos" aria-describedby="btnSeleccionarRuta">
                            <button class="btn btn-outline-secondary" type="button" id="btnSeleccionarRuta">Elegir carpeta…</button>
                        </div>
                        <input type="file" id="selectorRuta" webkitdirectory directory multiple style="display:none;">
                        <div class="form-text">El archivo ZIP se descargará en tu carpeta de descargas; este nombre define la carpeta interna.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="formatoExport">Formato de reporte</label>
                        <input type="text" readonly class="form-control" id="formatoExport" value="HTML (abre en la aplicación)">
                        <div class="form-text">La exportación individual se abrirá en esta aplicación como página HTML.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="mensajeNoticias">Noticias o mensaje para el reporte</label>
                        <textarea class="form-control" id="mensajeNoticias" rows="3" placeholder="Comparte avisos importantes que quedarán visibles en el reporte"></textarea>
                        <div class="form-text">Si lo dejas vacío solo se mostrarán las notas generales configuradas.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-danger">Exportar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const btnExportar = document.getElementById('btnExportarMovimientos');
    if (!btnExportar) return;

    let exportModal;
    const modalEl = document.getElementById('exportModal');
    const folderInput = document.getElementById('selectorRuta');
    const socioSelect = document.getElementById('socioExport');
    const rutaInput = document.getElementById('rutaExport');

    const actualizarVisibilidadCampos = (modo) => {
        const campoSocio = document.getElementById('campoSocio');
        const campoRuta = document.getElementById('campoRuta');
        if (modo === 'individual') {
            campoSocio.style.display = '';
            campoRuta.style.display = 'none';
            socioSelect.setAttribute('required', 'required');
        } else {
            campoSocio.style.display = 'none';
            campoRuta.style.display = '';
            socioSelect.removeAttribute('required');
        }
    };

    const obtenerNombreCarpetaSeleccionada = (fileInput) => {
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) return '';
        const primeraRuta = fileInput.files[0].webkitRelativePath || fileInput.files[0].name || '';
        return primeraRuta.split('/')[0] || '';
    };

    const obtenerModal = () => {
        if (!window.bootstrap || !modalEl) return null;
        if (!exportModal) {
            exportModal = new bootstrap.Modal(modalEl);
        }
        return exportModal;
    };

    btnExportar.addEventListener('click', () => {
        const modalInstance = obtenerModal();
        if (!modalInstance) return;

        socioSelect.value = btnExportar.dataset.socio || '';
        rutaInput.value = '';
        document.getElementById('modoIndividual').checked = true;
        actualizarVisibilidadCampos('individual');
        modalInstance.show();
    });

    document.querySelectorAll('input[name="modoExportacion"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            actualizarVisibilidadCampos(e.target.value);
        });
    });

    const btnSeleccionarRuta = document.getElementById('btnSeleccionarRuta');
    if (btnSeleccionarRuta && folderInput) {
        btnSeleccionarRuta.addEventListener('click', () => {
            if (folderInput.showPicker) {
                folderInput.showPicker().catch(() => {});
            } else {
                folderInput.click();
            }
        });

        folderInput.addEventListener('change', () => {
            const nombreCarpeta = obtenerNombreCarpetaSeleccionada(folderInput);
            if (nombreCarpeta) {
                rutaInput.value = nombreCarpeta;
            }
        });
    }

    const exportForm = document.getElementById('exportForm');
    if (exportForm) {
        exportForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const modalInstance = obtenerModal();
            if (!modalInstance) return;

            const modo = document.querySelector('input[name="modoExportacion"]:checked').value;
            const socioSeleccionado = socioSelect.value;
            const rutaDestino = rutaInput.value.trim();
            const mensajeNoticias = (document.getElementById('mensajeNoticias')?.value || '').trim();
            const formato = 'html';

            socioSelect.classList.remove('is-invalid');
            if (modo === 'individual' && !socioSeleccionado) {
                event.stopPropagation();
                socioSelect.classList.add('is-invalid');
                return;
            }

            const params = new URLSearchParams({
                socio: modo === 'individual' ? socioSeleccionado : (btnExportar.dataset.socio || ''),
                desde: btnExportar.dataset.desde || '',
                hasta: btnExportar.dataset.hasta || '',
                actividad: btnExportar.dataset.actividad || ''
            });
            params.set('modo', modo);
            if (modo === 'colectivo' && rutaDestino) {
                params.set('ruta', rutaDestino);
            }
            if (mensajeNoticias) {
                params.set('mensaje', mensajeNoticias);
            }
            params.set('formato', formato);

            modalInstance.hide();
            const url = '../actions/export_movimiento_socio_pdf.php?' + params.toString();
            window.open(url, '_blank');
        });
    }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
