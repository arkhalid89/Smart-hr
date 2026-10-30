<?php
/**
 * Export helpers - Excel (.xlsx, pure PHP via ZipArchive) and CSV.
 * No external library required; works on standard shared hosting.
 *
 *   export_excel('employees', ['Code','Name'], [[...],[...]]);   // sends file & exits
 *   export_csv(...)                                             // same, CSV
 *
 * If the zip extension is unavailable the .xlsx call silently falls back to CSV.
 */
declare(strict_types=1);

function export_filename(string $base, string $ext): string
{
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $base);
    return $base . '_' . date('Ymd_His') . '.' . $ext;
}

function export_csv(string $name, array $headers, array $rows, ?string $title = null): never
{
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_filename($name, 'csv') . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads Unicode correctly
    if ($title) { fputcsv($out, [$title]); fputcsv($out, []); }
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($v) => $v === null ? '' : (string)$v, array_values($r)));
    }
    fclose($out);
    exit;
}

function export_excel(string $name, array $headers, array $rows, ?string $title = null, string $sheet = 'Sheet1'): never
{
    if (!class_exists('ZipArchive')) {
        export_csv($name, $headers, $rows, $title);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        export_csv($name, $headers, $rows, $title);
    }

    $xmlEsc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $colLetter = function (int $i): string {
        $s = '';
        $i++;
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
        return $s;
    };
    $cell = function (int $c, int $r, mixed $v, int $style = 0) use ($xmlEsc, $colLetter): string {
        $ref = $colLetter($c) . $r;
        if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $style . '"/>';
        // numeric (but not codes like 0012 or CNIC / phone)
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v) && strlen($v) < 16)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
        }
        return '<c r="' . $ref . '" s="' . ($style ?: 0) . '" t="inlineStr"><is><t xml:space="preserve">' . $xmlEsc($v) . '</t></is></c>';
    };

    $rowsXml = '';
    $r = 1;
    $colCount = max(1, count($headers));
    if ($title) {
        $rowsXml .= '<row r="1">' . $cell(0, 1, $title, 2) . '</row>';
        $r = 3;
    }
    $h = '';
    foreach (array_values($headers) as $i => $hd) $h .= $cell($i, $r, $hd, 1);
    $rowsXml .= '<row r="' . $r . '">' . $h . '</row>';
    $r++;
    foreach ($rows as $row) {
        $line = '';
        foreach (array_values($row) as $i => $v) $line .= $cell($i, $r, $v);
        $rowsXml .= '<row r="' . $r . '">' . $line . '</row>';
        $r++;
    }
    // column widths: estimate from header length
    $cols = '<cols>';
    foreach (array_values($headers) as $i => $hd) {
        $w = min(60, max(12, mb_strlen((string)$hd) + 4));
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $cols .= '</cols>';
    $headerRow = $title ? 3 : 1;
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . $cols . '<sheetData>' . $rowsXml . '</sheetData>'
        . '<autoFilter ref="A' . $headerRow . ':' . $colLetter($colCount - 1) . max($headerRow, $r - 1) . '"/>'
        . '</worksheet>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $xmlEsc(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . export_filename($name, 'xlsx') . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Dispatch on ?format=csv|xlsx (default xlsx). */
function export_table(string $name, array $headers, array $rows, ?string $title = null): never
{
    $format = strtolower((string)get('format', 'xlsx'));
    if ($format === 'csv') export_csv($name, $headers, $rows, $title);
    export_excel($name, $headers, $rows, $title);
}
