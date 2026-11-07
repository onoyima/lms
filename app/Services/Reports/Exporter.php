<?php

namespace App\Services\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV, Excel (.xlsx) and PDF output for reports and documents (LMS 196; Target 63-65).
 * Cell values starting with = + - @ are neutralised so a spreadsheet never runs them.
 */
class Exporter
{
    public static function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v) ? "'".$v : $v;
    }

    public function csv(string $name, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel opens UTF-8 correctly
            fputcsv($out, $columns);
            foreach ($rows as $r) {
                fputcsv($out, array_map([self::class, 'safe'], array_values((array) $r)));
            }
            fclose($out);
        }, "$name.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Minimal, standards-compliant XLSX (Office Open XML) with a bold, frozen header row. */
    public function xlsx(string $name, string $sheet, array $columns, iterable $rows): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26).$s;
            }

            return $s;
        };
        $cell = function ($v, string $ref, bool $head = false) use ($x) {
            if (! $head && (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v) && strlen($v) < 15 && ! preg_match('/^0\d/', $v)))) {
                return "<c r=\"$ref\"><v>".($v + 0).'</v></c>';
            }
            $v = self::safe($v);

            return "<c r=\"$ref\" t=\"inlineStr\"".($head ? ' s="1"' : '').'><is><t xml:space="preserve">'.$x(preg_replace('/[^\P{C}\t\n]/u', '', (string) $v)).'</t></is></c>';
        };
        $data = '<row r="1">';
        foreach (array_values($columns) as $i => $c) {
            $data .= $cell($c, $col($i).'1', true);
        }
        $data .= '</row>';
        $r = 1;
        foreach ($rows as $row) {
            $r++;
            $data .= "<row r=\"$r\">";
            foreach (array_values((array) $row) as $i => $v) {
                if ($v !== null && $v !== '') {
                    $data .= $cell($v, $col($i).$r);
                }
            }
            $data .= '</row>';
        }
        $widths = '';
        foreach (array_values($columns) as $i => $c) {
            $widths .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.max(10, min(50, mb_strlen($c) + 4)).'" customWidth="1"/>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$x(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheet), 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$widths.'</cols><sheetData>'.$data.'</sheetData><autoFilter ref="A1:'.$col(max(0, count($columns) - 1)).max(1, $r).'"/></worksheet>');
        $zip->close();

        return response()->download($tmp, "$name.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** Render a Blade view to PDF (A4). Remote resources are disabled: only local files and data URIs. */
    public function pdf(string $name, string $view, array $data, string $orientation = 'portrait'): \Illuminate\Http\Response
    {
        return response($this->pdfBytes($view, $data, $orientation), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'.pdf"', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdfBytes(string $view, array $data, string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([public_path(), storage_path('app/public')]);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir(sys_get_temp_dir());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data + ['tenant' => tenant()])->render(), 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf->output();
    }
}
