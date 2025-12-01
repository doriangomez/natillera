<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$search = isset($_GET['q']) ? clean($_GET['q']) : '';
$lista = getSocios($pdo, $search);
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editData = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM socios WHERE id_socio = :id");
    $stmt->execute([':id' => $editId]);
    $editData = $stmt->fetch();
}

$valorCuota = $editData['valor_presupuestado'] ?? 0;
$periodicidadPago = $editData['periodicidad_pago'] ?? 'mensual';
$valorCuotaMensual = $periodicidadPago === 'quincenal' ? $valorCuota * 2 : $valorCuota;
?>
<h2 class="mb-3 d-flex align-items-center gap-2"><i class="bi bi-people-fill text-primary"></i><span>Socios</span></h2>
<div class="row">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person-workspace"></i><span><?php echo $editData ? 'Editar socio' : 'Nuevo socio'; ?></span></div>
            <div class="card-body">
                <form method="POST" action="../actions/socios_save.php">
                    <input type="hidden" name="id_socio" value="<?php echo $editData['id_socio'] ?? ''; ?>">
                    <div class="mb-2">
                        <label class="form-label">Nombre completo <span class="text-danger">*</span></label>
                        <input type="text" name="nombre_completo" class="form-control" required value="<?php echo $editData['nombre_completo'] ?? ''; ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Teléfono <span class="text-danger">*</span></label>
                        <input type="text" name="telefono" class="form-control" required value="<?php echo $editData['telefono'] ?? ''; ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Número polla <span class="text-danger">*</span></label>
                        <input type="text" name="numero_polla" class="form-control" required maxlength="2" pattern="\d{2}" inputmode="numeric" autocomplete="off" placeholder="00" value="<?php echo isset($editData['numero_polla']) ? str_pad($editData['numero_polla'], 2, '0', STR_PAD_LEFT) : ''; ?>" aria-describedby="numeroPollaHelp">
                        <div class="form-text" id="numeroPollaHelp">Solo se permiten valores entre 00 y 99.</div>
                        <div class="alert alert-warning d-flex align-items-center justify-content-between mt-2 d-none" id="numeroPollaAlert"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Periodicidad pago <span class="text-danger">*</span></label>
                        <select name="periodicidad_pago" class="form-select" required>
                            <option value="quincenal" <?php echo ($periodicidadPago==='quincenal')?'selected':''; ?>>Quincenal</option>
                            <option value="mensual" <?php echo ($periodicidadPago==='mensual')?'selected':''; ?>>Mensual</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Valor cuota <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="valor_presupuestado" class="form-control" required value="<?php echo $valorCuota; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Valor cuota mensual</label>
                        <input type="text" class="form-control" id="valorCuotaMensual" value="<?php echo number_format($valorCuotaMensual,2,'.',''); ?>" readonly>
                    </div>
                    <button class="btn btn-success btn-icon" type="submit"><span><i class="bi bi-check-circle"></i> Guardar</span></button>
                    <?php if ($editData): ?>
                        <a class="btn btn-secondary btn-icon" href="socios.php"><span><i class="bi bi-x-circle"></i> Cancelar</span></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <form class="row mb-2" method="GET">
            <div class="col">
                <input type="text" name="q" class="form-control" placeholder="Buscar" value="<?php echo $search; ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary btn-icon"><span><i class="bi bi-search"></i> Buscar</span></button>
                <a class="btn btn-outline-secondary btn-icon" href="../actions/export_csv.php?tipo=socios"><span><i class="bi bi-file-earmark-arrow-down"></i> Exportar CSV</span></a>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Teléfono</th>
                        <th>Polla</th>
                        <th>Periodicidad</th>
                        <th>Valor cuota</th>
                        <th>Valor cuota mensual</th>
                        <th>Saldo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $s): ?>
                        <tr>
                            <td><?php echo $s['id_socio']; ?></td>
                            <td><?php echo clean($s['nombre_completo']); ?></td>
                            <td><?php echo clean($s['telefono']); ?></td>
                            <td><?php echo str_pad(clean($s['numero_polla']), 2, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo clean($s['periodicidad_pago']); ?></td>
                            <td>$<?php echo number_format($s['valor_presupuestado'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($s['periodicidad_pago']==='quincenal' ? $s['valor_presupuestado']*2 : $s['valor_presupuestado'],0,',','.'); ?></td>
                            <td>$<?php echo number_format($s['saldo_socio'],0,',','.'); ?></td>
                            <td>
                                <a class="btn btn-sm btn-primary btn-icon" href="?id=<?php echo $s['id_socio']; ?>"><span><i class="bi bi-pencil"></i> Editar</span></a>
                                <form method="POST" action="../actions/socios_save.php" class="d-inline" onsubmit="return confirm('¿Inactivar socio?');">
                                    <input type="hidden" name="id_socio" value="<?php echo $s['id_socio']; ?>">
                                    <input type="hidden" name="accion" value="inactivar">
                                    <button class="btn btn-sm btn-danger btn-icon"><span><i class="bi bi-slash-circle"></i> Inactivar</span></button>
                                </form>
                                <form method="POST" action="../actions/socios_save.php" class="d-inline" onsubmit="return confirm('¿Eliminar definitivamente el socio y todos sus registros asociados?');">
                                    <input type="hidden" name="id_socio" value="<?php echo $s['id_socio']; ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <button class="btn btn-sm btn-outline-danger btn-icon"><span><i class="bi bi-trash"></i> Eliminar</span></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    const periodicidadSelect = document.querySelector('select[name="periodicidad_pago"]');
    const valorCuotaInput = document.querySelector('input[name="valor_presupuestado"]');
    const valorCuotaMensualInput = document.getElementById('valorCuotaMensual');
    const numeroPollaInput = document.querySelector('input[name="numero_polla"]');
    const numeroPollaAlert = document.getElementById('numeroPollaAlert');
    const currentSocioId = <?php echo $editData['id_socio'] ?? 'null'; ?>;
    const sociosPorPolla = <?php echo json_encode(array_map(function($s) {
        return [
            'id' => $s['id_socio'],
            'nombre' => $s['nombre_completo'],
            'numero' => str_pad((string) $s['numero_polla'], 2, '0', STR_PAD_LEFT),
        ];
    }, $lista)); ?>;

    function actualizarValorMensual() {
        const valorCuota = parseFloat(valorCuotaInput.value) || 0;
        const periodicidad = periodicidadSelect.value;
        const valorMensual = periodicidad === 'quincenal' ? valorCuota * 2 : valorCuota;
        valorCuotaMensualInput.value = valorMensual.toFixed(2);
    }

    function limpiarNumeroPolla(valor) {
        return valor.replace(/\D/g, '').slice(0, 2);
    }

    function normalizarNumeroPolla(valor) {
        if (valor === '') return '';
        const numero = parseInt(valor, 10);
        if (Number.isNaN(numero)) return '';
        return numero.toString().padStart(2, '0');
    }

    function mostrarAlertaPolla(mensaje, socioId) {
        numeroPollaAlert.innerHTML = '';
        numeroPollaAlert.classList.add('d-flex');
        numeroPollaAlert.classList.remove('d-none');

        const texto = document.createElement('span');
        texto.textContent = mensaje;
        numeroPollaAlert.appendChild(texto);

        if (socioId) {
            const enlace = document.createElement('a');
            enlace.className = 'btn btn-sm btn-outline-dark';
            enlace.href = `?id=${socioId}`;
            enlace.textContent = 'Ver socio';
            numeroPollaAlert.appendChild(enlace);
        }
    }

    function ocultarAlertaPolla() {
        numeroPollaAlert.classList.add('d-none');
        numeroPollaAlert.classList.remove('d-flex');
        numeroPollaAlert.innerHTML = '';
    }

    function validarNumeroPolla() {
        const limpio = limpiarNumeroPolla(numeroPollaInput.value);
        numeroPollaInput.value = limpio;

        if (limpio === '') {
            ocultarAlertaPolla();
            return;
        }

        const normalizado = normalizarNumeroPolla(limpio);
        numeroPollaInput.value = normalizado;

        const socioExistente = sociosPorPolla.find(s => s.numero === normalizado && s.id !== currentSocioId);
        if (socioExistente) {
            mostrarAlertaPolla(`El número de polla ${normalizado} ya está asignado a ${socioExistente.nombre}.`, socioExistente.id);
        } else {
            ocultarAlertaPolla();
        }
    }

    periodicidadSelect.addEventListener('change', actualizarValorMensual);
    valorCuotaInput.addEventListener('input', actualizarValorMensual);
    numeroPollaInput.addEventListener('input', validarNumeroPolla);
    numeroPollaInput.addEventListener('blur', validarNumeroPolla);
    document.addEventListener('DOMContentLoaded', () => {
        actualizarValorMensual();
        validarNumeroPolla();
    });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
