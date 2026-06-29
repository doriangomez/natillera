<?php
/**
 * Minimal XLSXWriter-compatible writer for environments without Composer.
 * Implements the subset used by this project: writeSheet(), writeSheetHeader(),
 * writeSheetRow(), writeToStdOut() and writeToFile().
 */
class XLSXWriter
{
    private array $sheets = [];
    private array $styles = [];
    private array $styleKeys = [];

    public function writeSheet(array $data, string $sheetName = 'Sheet1', array $headerTypes = []): void
    {
        if ($headerTypes) {
            $this->writeSheetHeader($sheetName, $headerTypes);
        }
        foreach ($data as $row) {
            $this->writeSheetRow($sheetName, (array) $row);
        }
    }

    public function writeSheetHeader(string $sheetName, array $headerTypes, ?array $colOptions = null): void
    {
        $sheet = &$this->sheet($sheetName);
        $sheet['headers'] = $headerTypes;
        $sheet['types'] = array_values($headerTypes);
        $sheet['widths'] = $colOptions['widths'] ?? [];
        $sheet['rows'][] = [array_keys($headerTypes), array_fill(0, count($headerTypes), $this->styleId($colOptions ?: ['font-style' => 'bold']))];
    }

    public function writeSheetRow(string $sheetName, array $row, ?array $rowOptions = null): void
    {
        $sheet = &$this->sheet($sheetName);
        $styles = [];
        foreach ($row as $idx => $_value) {
            $cellStyle = is_array($rowOptions) && isset($rowOptions[$idx]) && is_array($rowOptions[$idx]) ? $rowOptions[$idx] : $rowOptions;
            $styles[] = $this->styleId($cellStyle ?: []);
        }
        $sheet['rows'][] = [array_values($row), $styles];
    }

    public function writeToStdOut(?string $filename = null): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'xlsx_writer_');
        $this->writeToFile($temp);
        readfile($temp);
        @unlink($temp);
    }

    public function writeToFile(string $filename): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive no está disponible en este servidor.');
        }
        $zip = new ZipArchive();
        if ($zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo XLSX.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->relsXml());
        $zip->addFromString('docProps/app.xml', $this->appXml());
        $zip->addFromString('docProps/core.xml', $this->coreXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $i = 1;
        foreach ($this->sheets as $sheet) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $this->worksheetXml($sheet));
            $i++;
        }
        $zip->close();
    }

    private function &sheet(string $name): array
    {
        $safeName = substr(preg_replace('/[\\/*?:\[\]]/', ' ', $name), 0, 31);
        if (!isset($this->sheets[$safeName])) {
            $this->sheets[$safeName] = ['name' => $safeName, 'rows' => [], 'types' => [], 'widths' => []];
        }
        return $this->sheets[$safeName];
    }

    private function styleId(array $style): int
    {
        if (!$style) {
            return 0;
        }
        ksort($style);
        $key = json_encode($style);
        if (!isset($this->styleKeys[$key])) {
            $this->styleKeys[$key] = count($this->styles);
            $this->styles[] = $style;
        }
        return $this->styleKeys[$key] + 1;
    }

    private function worksheetXml(array $sheet): string
    {
        $maxCols = 0;
        foreach ($sheet['rows'] as [$row]) {
            $maxCols = max($maxCols, count($row));
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($maxCols) {
            $xml .= '<cols>';
            for ($c = 0; $c < $maxCols; $c++) {
                $width = isset($sheet['widths'][$c]) ? (float) $sheet['widths'][$c] : 18;
                $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $r => [$row, $styles]) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $value) {
                $ref = $this->cellRef($r, $c);
                $style = (int) ($styles[$c] ?? 0);
                if ($value === null || $value === '') {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . $this->esc((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
    }

    private function stylesXml(): string
    {
        $fills = ['none' => 0, 'gray125' => 1];
        $fillXml = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ($this->styles as $style) {
            if (!empty($style['fill'])) {
                $color = strtoupper(ltrim((string) $style['fill'], '#'));
                if (!isset($fills[$color])) {
                    $fills[$color] = count($fills);
                    $fillXml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
                }
            }
        }
        $fonts = '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>';
        $cellXfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        foreach ($this->styles as $style) {
            $fontId = (($style['font-style'] ?? '') === 'bold') ? 1 : 0;
            $fillId = !empty($style['fill']) ? $fills[strtoupper(ltrim((string) $style['fill'], '#'))] : 0;
            $applyFill = $fillId ? ' applyFill="1"' : '';
            $applyFont = $fontId ? ' applyFont="1"' : '';
            $cellXfs .= '<xf numFmtId="0" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="0" xfId="0"' . $applyFill . $applyFont . '/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2">' . $fonts . '</fonts><fills count="' . count($fills) . '">' . $fillXml . '</fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="' . (count($this->styles) + 1) . '">' . $cellXfs . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        $i = 1;
        foreach ($this->sheets as $sheet) {
            $sheets .= '<sheet name="' . $this->esc($sheet['name']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $i++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        $i = 1;
        foreach ($this->sheets as $_sheet) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $i++;
        }
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        for ($i = 1; $i <= count($this->sheets); $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $overrides . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function relsXml(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
    private function appXml(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Natillera</Application></Properties>'; }
    private function coreXml(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Natillera</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>'; }

    private function cellRef(int $row, int $col): string
    {
        $letters = '';
        $col++;
        while ($col > 0) {
            $rem = ($col - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $col = intdiv($col - 1, 26);
        }
        return $letters . ($row + 1);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
