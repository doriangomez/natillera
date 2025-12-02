<?php
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';

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
