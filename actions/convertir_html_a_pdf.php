<?php
use Dompdf\Dompdf;
use Dompdf\Options;

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

$autoloadEncontrado = false;
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $autoloadEncontrado = true;
        break;
    }
}

if (!$autoloadEncontrado) {
    exit('No se encontró vendor/autoload.php. Ejecute "composer install" en la raíz del proyecto.');
}

$rutaHtmlParaConversion = $rutaHtmlParaConversion ?? (__DIR__ . '/html_pdfs');
$rutaPdfGenerados = $rutaPdfGenerados ?? (__DIR__ . '/pdf_generados');

if (!is_dir($rutaPdfGenerados)) {
    mkdir($rutaPdfGenerados, 0777, true);
}

$opciones = new Options();
$opciones->set('isRemoteEnabled', true);

foreach (glob($rutaHtmlParaConversion . '/*.html') as $archivoHtml) {
    $contenido = file_get_contents($archivoHtml);
    if ($contenido === false) {
        continue;
    }

    $dompdf = new Dompdf($opciones);
    $dompdf->loadHtml($contenido);
    $dompdf->setPaper('A4');
    $dompdf->render();

    $nombreBase = pathinfo($archivoHtml, PATHINFO_FILENAME);
    file_put_contents($rutaPdfGenerados . '/' . $nombreBase . '.pdf', $dompdf->output());
}
