<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
checkAuth();

/* =======================
   FILTROS

$desde     = $_GET['desde']  ?? '';
$hasta     = $_GET['hasta']  ?? '';
$socio     = $_GET['socio']  ?? '';
$actividad = $_GET['actividad'] ?? '';

$where  = [];
$params = [];

if ($desde) {
    $where[] = "m.fecha >= :desde";
    $params[':desde'] = $desde;
}

if ($hasta) {
    $where[] = "m.fecha <= :hasta";
    $params[':hasta'] = $hasta;
}

if ($socio && $socio !== 'Todos') {
    $where[] = "m.socio_id = :socio";
    $params[':socio'] = $socio;
}

if ($actividad && $actividad !== 'Todas') {
    $where[] = "a.id_actividad = :actividad";
    $params[':actividad'] = $actividad;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* =======================
   CONSULTA CONSOLIDADO

$sql = "
SELECT 
    m.*,
    a.nombre_actividad,
    a.afecta_saldo_socio,
    a.afecta_saldo_natillera,
    mp.nombre_medio,
    s.nombre AS socio_nombre
FROM movimientos m
JOIN actividades_maestro a ON a.id_actividad = m.id_actividad
LEFT JOIN medios_pago mp ON mp.id = m.medio_pago_id
LEFT JOIN socios s ON s.id = m.socio_id
$whereSQL
ORDER BY m.fecha ASC, m.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =======================
   CÁLCULO DE SALDOS

$saldoGeneral = 0;
$saldosSocios = [];

foreach ($rows as &$row) {

    $valor = floatval($row['valor']);
    $socioId = $row['socio_id'];

    /* ----- Inicializa saldo por socio ----- */
    if ($socioId && !isset($saldosSocios[$socioId])) {
        $saldosSocios[$socioId] = 0;
    }

    /* ----- SALDO SOCIO ----- */
    if ($socioId) {
        if ($row['afecta_saldo_socio'] === 'suma') {
            $saldosSocios[$socioId] += $valor;
        } elseif ($row['afecta_saldo_socio'] === 'resta') {
            $saldosSocios[$socioId] -= $valor;
        }
        // neutral => no altera
        $row['saldo_socio'] = $saldosSocios[$socioId];
    } else {
        $row['saldo_socio'] = '-';
    }

    /* ----- SALDO GENERAL ----- */
    if ($row['afecta_saldo_natillera'] === 'suma') {
        $saldoGeneral += $valor;
    } elseif ($row['afecta_saldo_natillera'] === 'resta') {
        $saldoGeneral -= $valor;
    }
    // neutral => no altera

    $row['saldo_general'] = $saldoGeneral;
}

/* =======================
   LISTADOS AUXILIARES

// SOCIOS
$socios = $pdo->query("SELECT id, nombre FROM socios WHERE activo=1 ORDER BY nombre")->fetchAll();

// ACTIVIDADES
$actividades = $pdo->query("SELECT id_actividad, nombre_actividad FROM actividades_maestro WHERE activo=1 ORDER BY nombre_actividad")->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consolidado de Movimientos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container-fluid p-4">

<h4 class="mb-3">📊 Consolidado de movimientos</h4>

<!-- FILTROS -->
<form class="row g-3 mb-4">

    <div class="col-md-2">
        <label>Desde</label>
        <input type="date" name="desde" class="form-control" value="<?=$desde?>">
    </div>

    <div class="col-md-2">
        <label>Hasta</label>
        <input type="date" name="hasta" class="form-control" value="<?=$hasta?>">
    </div>

    <div class="col-md-3">
        <label>Socio</label>
        <select name="socio" class="form-select">
            <option value="Todos">Todos</option>
            <?php foreach ($socios as $s): ?>
                <option value="<?=$s['id']?>" <?=($socio==$s['id'])?'selected':''?>>
                    <?=$s['nombre']?>
                </option>
            <?php endforeach ?>
        </select>
    </div>

    <div class="col-md-3">
        <label>Actividad</label>
        <select name="actividad" class="form-select">
            <option value="Todas">Todas</option>
            <?php foreach ($actividades as $a): ?>
                <option value="<?=$a['id_actividad']?>" <?=($actividad==$a['id_actividad'])?'selected':''?>>
                    <?=$a['nombre_actividad']?>
                </option>
            <?php endforeach ?>
        </select>
    </div>

    <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-dark me-2">Filtrar</button>
        <a href="index.php" class="btn btn-outline-secondary">Limpiar</a>
    </div>

</form>

<!-- BOTONES EXPORTAR -->
<div class="text-end mb-2">
    <a href="../actions/export_csv.php" class="btn btn-success">Exportar a Excel</a>
    <a href="../actions/export_pdf.php" class="btn btn-danger">Exportar a PDF</a>
</div>

<!-- TABLA -->
<div class="card">
<div class="card-body p-0">

<table class="table table-striped table-bordered mb-0">

<thead class="table-dark">
<tr>
    <th>Fecha</th>
    <th>Socio / Tercero</th>
    <th>Actividad</th>
    <th>Medio de pago</th>
    <th>Valor</th>
    <th>Saldo socio</th>
    <th>Saldo general</th>
</tr>
</thead>

<tbody>

<?php foreach ($rows as $r): ?>
<tr>
    <td><?=$r['fecha']?></td>
    <td><?=$r['socio_nombre'] ?? 'General'?></td>
    <td><?=$r['nombre_actividad']?></td>
    <td><?=$r['nombre_medio'] ?? ''?></td>
    <td>$<?=number_format($r['valor'],0,',','.')?></td>

    <td>
        <?=$r['saldo_socio']==='-' 
            ? '-' 
            : '$'.number_format($r['saldo_socio'],0,',','.')?>
    </td>

    <td>
        $<?=number_format($r['saldo_general'],0,',','.')?>
    </td>
</tr>
<?php endforeach ?>

</tbody>

</table>

</div>
</div>

</div>

</body>
</html>
