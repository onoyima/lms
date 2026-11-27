<?php

namespace App\Services\Questions\Import;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The first sheet of an Excel workbook (.xlsx) as CSV text, so a question sheet kept in Excel is
 * read by the same rules as a CSV file (columns Question, Option A … D, Answer, Mark, …). Values
 * only: formulas give their last calculated value; formatting and pictures are ignored.
 */
class SpreadsheetReader
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function csv(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This Excel file could not be opened. Save it again as .xlsx or as CSV.');
        }
        try {
            $strings = $this->sharedStrings($zip);
            $sheet = $this->firstSheet($zip);
            $xml = $sheet ? $zip->getFromName($sheet) : false;
            if ($xml === false) {
                throw new RuntimeException('This Excel file has no worksheet.');
            }
            $doc = new DOMDocument;
            $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
            $x = new DOMXPath($doc);
            $x->registerNamespace('m', self::MAIN);

            $rows = [];
            $width = 0;
            foreach ($x->query('//m:sheetData/m:row') as $r) {
                $cells = [];
                foreach ($x->query('m:c', $r) as $c) {
                    $col = $this->column((string) $c->getAttribute('r')) ?? count($cells);
                    $type = $c->getAttribute('t');
                    $v = $x->query('m:v', $c)->item(0)?->textContent;
                    $value = match ($type) {
                        's' => $strings[(int) $v] ?? '',
                        'inlineStr' => implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($x->query('.//m:t', $c)))),
                        'b' => $v === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $v,
                    };
                    $cells[$col] = $value;
                }
                if (! $cells || trim(implode('', $cells)) === '') {
                    continue;
                }
                $width = max($width, max(array_keys($cells)) + 1);
                $rows[] = $cells;
                if (count($rows) > 20000) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }

        $fh = fopen('php://temp', 'w+');
        foreach ($rows as $cells) {
            $line = [];
            for ($i = 0; $i < $width; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            fputcsv($fh, $line, ',', '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return (string) $csv;
    }

    /** "C12" → 2 (zero-based column). */
    private function column(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]{1,3})\d+$/', $ref, $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $x = new DOMXPath($doc);
        $x->registerNamespace('m', self::MAIN);
        $out = [];
        foreach ($x->query('/m:sst/m:si') as $si) {
            // rich text has several runs; phonetic hints (rPh) are not part of the text
            $out[] = implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($x->query('m:t | m:r/m:t', $si))));
        }

        return $out;
    }

    private function firstSheet(ZipArchive $zip): ?string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $d = new DOMDocument;
            $d->loadXML($wb, LIBXML_NONET);
            $sheet = $d->getElementsByTagName('sheet')->item(0);
            $rid = $sheet?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $r = new DOMDocument;
            $r->loadXML($rels, LIBXML_NONET);
            foreach ($r->getElementsByTagName('Relationship') as $rel) {
                if ($rel->getAttribute('Id') === $rid) {
                    $target = ltrim($rel->getAttribute('Target'), '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    }
}
