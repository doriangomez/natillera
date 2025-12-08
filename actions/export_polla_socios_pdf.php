<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

function cargarAutoloadDompdf(): void
{
    static $autoloadCargado = false;
    if ($autoloadCargado) {
        return;
    }

    $paths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $autoloadCargado = true;
            break;
        }
    }

    if (!$autoloadCargado) {
        exit('No se encontró vendor/autoload.php. Ejecute "composer install".');
    }
}

function crearDompdf(): Dompdf
{
    cargarAutoloadDompdf();

    $opciones = new Options();
    $opciones->set('isRemoteEnabled', true);

    return new Dompdf($opciones);
}

function formatearNumeroPolla(?string $numero): string
{
    if ($numero === null || $numero === '') {
        return '';
    }

    return str_pad($numero, 2, '0', STR_PAD_LEFT);
}

$config = getConfiguracionGeneral($pdo);

$sociosStmt = $pdo->query(
    "SELECT nombre_completo, telefono, numero_polla FROM socios " .
    "WHERE activo = 1 AND numero_polla IS NOT NULL AND numero_polla <> '' " .
    "ORDER BY LPAD(numero_polla, 2, '0'), nombre_completo"
);
$socios = $sociosStmt->fetchAll();

$fechaGeneracion = date('Y-m-d H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relación de pollas</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #111; }
        h1 { margin-bottom: 4px; }
        .subtitulo { color: #555; margin-top: 0; }
        .meta { font-size: 12px; color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; border: 1px solid #ddd; font-size: 13px; }
        th { background: #f2f2f2; text-align: left; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 8px; background: #eef2ff; color: #4338ca; font-weight: bold; }
        .muted { color: #777; font-size: 12px; }
    </style>
</head>
<body>
    <h1><?php echo htmlspecialchars($config['nombre_sistema'] ?? 'Gestión de pollas', ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="subtitulo">Relación de socios con número de polla asignado</p>
    <p class="meta">Generado el <?php echo htmlspecialchars($fechaGeneracion, ENT_QUOTES, 'UTF-8'); ?></p>

    <table>
        <thead>
            <tr>
                <th style="width: 10%;">Polla</th>
                <th style="width: 55%;">Socio</th>
                <th style="width: 35%;">Teléfono</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($socios as $socio): ?>
                <tr>
                    <td><span class="badge">#<?php echo htmlspecialchars(formatearNumeroPolla($socio['numero_polla']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td><?php echo htmlspecialchars($socio['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($socio['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($socios)): ?>
                <tr><td colspan="3" class="muted">No hay socios con número de polla registrado.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

$dompdf = crearDompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4');
$dompdf->render();

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="relacion_polla_socios.pdf"');
echo $dompdf->output();
