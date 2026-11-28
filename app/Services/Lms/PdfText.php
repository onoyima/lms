<?php

namespace App\Services\Lms;

/**
 * A small PDF text reader in plain PHP, used when pdftotext is not installed (as on most Windows
 * servers). It reads the page content streams, decodes Flate-compressed streams, and maps glyphs
 * through each font's ToUnicode table, which is what Word, Google Docs and LibreOffice write.
 * Scanned PDFs have no text to read; those are reported as unreadable.
 */
class PdfText
{
    public function read(string $path): string
    {
        $pdf = (string) file_get_contents($path, false, null, 0, 60_000_000);
        if (! str_starts_with(ltrim($pdf), '%PDF')) {
            return '';
        }
        // every object with a stream, decoded
        $objects = [];
        if (preg_match_all('/(\d+)\s+0\s+obj(.*?)endobj/s', $pdf, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                $objects[(int) $o[1]] = $o[2];
            }
        }
        $streams = [];
        foreach ($objects as $id => $body) {
            if (preg_match('/^(.*?)stream\r?\n(.*)endstream/s', $body, $s)) {
                $data = $s[2];
                if (str_contains($s[1], '/FlateDecode')) {
                    $data = @gzuncompress($data) ?: (@gzinflate(substr($data, 2)) ?: '');
                }
                $streams[$id] = ['dict' => $s[1], 'data' => $data];
            }
        }
        // objects packed inside compressed object streams (Word, newer PDF writers)
        foreach ($streams as $s) {
            if (preg_match('/\/Type\s*\/ObjStm/', $s['dict']) && preg_match('/\/First\s+(\d+)/', $s['dict'], $f)) {
                $head = preg_split('/\s+/', trim(substr($s['data'], 0, (int) $f[1])));
                $body = substr($s['data'], (int) $f[1]);
                for ($i = 0; $i + 1 < count($head); $i += 2) {
                    $from = (int) $head[$i + 1];
                    $to = isset($head[$i + 3]) ? (int) $head[$i + 3] : strlen($body);
                    $objects[(int) $head[$i]] ??= substr($body, $from, $to - $from);
                }
            }
        }
        // ToUnicode table of each font object, then font resource names (/F1) to those tables
        $byObject = [];
        foreach ($objects as $id => $body) {
            if (preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $body, $t) && isset($streams[(int) $t[1]])) {
                $byObject[$id] = $this->cmap($streams[(int) $t[1]]['data']);
            }
        }
        // a font is named (/F1 12 0 R) in a page's /Font dictionary, written inline or as its own object
        $byName = [];
        foreach ($objects as $body) {
            preg_match_all('/\/([^\s\/<>\[\]()]+)\s+(\d+)\s+0\s+R/', $body, $refs, PREG_SET_ORDER);
            foreach ($refs as $r) {
                if (isset($byObject[(int) $r[2]])) {
                    $byName[$r[1]] ??= $byObject[(int) $r[2]];
                }
            }
        }
        $merged = $byObject ? array_replace(...array_values($byObject)) : [];

        $out = '';
        foreach ($streams as $s) {
            if (! str_contains($s['data'], 'BT') || preg_match('/\/(Type\s*\/XObject\s*\/Subtype\s*\/Image|Length1|CMapName)/', $s['dict'].$s['data'])) {
                continue;
            }
            if (preg_match_all('/BT(.*?)ET/s', $s['data'], $blocks)) {
                foreach ($blocks[1] as $block) {
                    $out .= $this->block($block, $byName, $merged)."\n";
                }
            }
            if (strlen($out) > 5_000_000) {
                break;
            }
        }

        return $out;
    }

    private function block(string $block, array $fonts, array $map): string
    {
        $text = '';
        preg_match_all('/(\/[^\s\/<>\[\]()]+\s+-?[\d.]+\s+Tf|\[(?:[^\]\\\\]|\\\\.)*\]\s*TJ|\((?:[^)\\\\]|\\\\.)*\)\s*(?:Tj|\'|")|<[0-9A-Fa-f\s]*>\s*Tj|T\*|-?[\d.]+\s+-?[\d.]+\s+T[dD])/s', $block, $ops);
        foreach ($ops[0] as $op) {
            if ($op[0] === '/' && str_ends_with($op, 'Tf')) {
                $map = $fonts[strtok(substr($op, 1), " \t\r\n")] ?? $map;

                continue;
            }
            if ($op === 'T*' || preg_match('/T[dD]$/', $op)) {
                $text .= ' ';

                continue;
            }
            if ($op[0] === '[') {
                preg_match_all('/\((?:[^)\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>|-?\d+(?:\.\d+)?/', substr($op, 1, strrpos($op, ']') - 1), $parts);
                foreach ($parts[0] as $p) {
                    if (is_numeric($p)) {
                        if ((float) $p < -180) {
                            $text .= ' ';
                        }
                    } else {
                        $text .= $this->string($p, $map);
                    }
                }
            } else {
                $text .= $this->string(preg_replace('/\s*(Tj|\'|")$/', '', $op), $map);
            }
        }

        return $text;
    }

    private function string(string $s, array $map): string
    {
        if ($s !== '' && $s[0] === '<') {
            $hex = preg_replace('/\s/', '', trim($s, '<>'));
            $out = '';
            $width = $map && strlen(array_key_first($map)) === 4 ? 4 : 2;
            if (! $map) {
                $width = 2;
            }
            foreach (str_split($hex, $width) as $code) {
                $out .= $map[strtoupper(str_pad($code, $width, '0'))] ?? ($width === 2 ? chr(hexdec($code)) : '');
            }

            return $out;
        }
        $raw = substr($s, 1, -1);
        $raw = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', fn ($m) => match ($m[1]) {
            'n' => "\n", 'r' => '', 't' => ' ', 'b', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\', default => chr(octdec($m[1])),
        }, $raw);
        if ($map && strlen(array_key_first($map)) === 2) {
            $out = '';
            foreach (str_split($raw) as $ch) {
                $out .= $map[strtoupper(bin2hex($ch))] ?? $ch;
            }

            return $out;
        }
        if ($map && strlen(array_key_first($map)) === 4 && strlen($raw) % 2 === 0) {
            $out = '';
            foreach (str_split(bin2hex($raw), 4) as $code) {
                $out .= $map[strtoupper($code)] ?? '';
            }

            return $out;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    /** @return array<string, string> glyph code (hex) => text */
    private function cmap(string $data): array
    {
        $map = [];
        $u = fn (string $hex) => mb_convert_encoding((string) hex2bin(strlen($hex) % 4 ? str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT) : $hex), 'UTF-8', 'UTF-16BE');
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $data, $sections)) {
            foreach ($sections[1] as $sec) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $sec, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $map[strtoupper($p[1])] = $u($p[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $data, $sections)) {
            foreach ($sections[1] as $sec) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]+)>|\[([^\]]*)\])/', $sec, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = hexdec($r[1]);
                    $hi = min(hexdec($r[2]), $lo + 5000);
                    $w = strlen($r[1]);
                    if (! empty($r[5]) || (isset($r[5]) && $r[3][0] === '[')) {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $r[5], $list);
                        foreach ($list[1] as $i => $dst) {
                            $map[strtoupper(str_pad(dechex($lo + $i), $w, '0', STR_PAD_LEFT))] = $u($dst);
                        }
                    } else {
                        $start = hexdec($r[4]);
                        for ($c = $lo; $c <= $hi; $c++) {
                            $map[strtoupper(str_pad(dechex($c), $w, '0', STR_PAD_LEFT))] = mb_chr($start + $c - $lo, 'UTF-8');
                        }
                    }
                }
            }
        }

        return $map;
    }
}
