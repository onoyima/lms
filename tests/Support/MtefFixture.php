<?php

namespace Tests\Support;

/**
 * Builds old-style Word equations for tests: MTEF records (as Equation 3.0 and MathType write
 * them) inside an "Equation Native" stream inside an OLE compound file, as Word embeds them.
 */
class MtefFixture
{
    // ---- MTEF v3 (Equation 3.0) ----
    public static function v3(string $records): string
    {
        return "\x03\x01\x01\x03\x00"."\x0A".$records; // header, FULL size
    }

    public static function line3(string $objects): string
    {
        return "\x01".$objects."\x00";
    }

    public static function nullLine3(): string
    {
        return "\x11"; // LINE with xfNULL
    }

    public static function char3(int $face, int|string $code, string $embell = ''): string
    {
        $code = is_string($code) ? mb_ord($code) : $code;

        return chr(0x02 | ($embell !== '' ? 0x20 : 0)).chr($face + 128).pack('v', $code).($embell !== '' ? $embell."\x00" : '');
    }

    public static function chars3(int $face, string $s): string
    {
        return implode('', array_map(fn ($c) => self::char3($face, $c), mb_str_split($s)));
    }

    public static function tmpl3(int $sel, int $var, string $objects): string
    {
        return "\x03".chr($sel).chr($var)."\x00".$objects."\x00";
    }

    // ---- MTEF v5 (MathType 5 and later) ----
    public static function v5(string $records): string
    {
        $prefs = "\x12\x00"."\x02\x21\x2F\x45\x8F"."\x01\x25\xF0"."\x02\x01\x00\x00"; // EQN_PREFS: sizes 12pt, 58%; one space; two styles
        $defs = "\x13WinAllBasicCodePages\x00"."\x11\x01Times New Roman\x00".$prefs."\x08\x01\x00";

        return "\x05\x00\x01\x06\x00DSMT6\x00\x00".$defs."\x64\x02\x00\xAA\xBB"."\x0A".$records; // + a FUTURE record
    }

    public static function line5(string $objects): string
    {
        return "\x01\x00".$objects."\x00";
    }

    public static function nullLine5(): string
    {
        return "\x01\x01";
    }

    public static function char5(int $face, int|string $code, string $embell = ''): string
    {
        $code = is_string($code) ? mb_ord($code) : $code;

        return "\x02".chr($embell !== '' ? 0x01 : 0x00).chr($face + 128).pack('v', $code).($embell !== '' ? $embell."\x00" : '');
    }

    public static function chars5(int $face, string $s): string
    {
        return implode('', array_map(fn ($c) => self::char5($face, $c), mb_str_split($s)));
    }

    public static function embell5(int $type): string
    {
        return "\x06\x00".chr($type);
    }

    public static function tmpl5(int $sel, int $var, string $objects): string
    {
        return "\x03\x00".chr($sel).chr($var)."\x00".$objects."\x00";
    }

    public static function matrix5(int $rows, int $cols, string $cells): string
    {
        return "\x05\x00\x00\x01\x01".chr($rows).chr($cols)."\x00\x00".$cells."\x00";
    }

    public static function pile5(string $lines): string
    {
        return "\x04\x00\x01\x01".$lines."\x00";
    }

    // ---- wrappers ----
    public static function equationNative(string $mtef): string
    {
        return pack('vVvVVVVV', 28, 0x00020000, 0xC1C6, strlen($mtef), 0, 0, 0, 0).$mtef;
    }

    /** A minimal valid compound file with "\x01CompObj" and "Equation Native" streams (both in the mini stream). */
    public static function ole(string $equationNative): string
    {
        $streams = ["\x01CompObj" => "\x01\x00\xFE\xFF\x03\x0A\x00\x00Microsoft Equation 3.0\x00", 'Equation Native' => $equationNative];
        $mini = '';
        $miniFat = [];
        $starts = [];
        foreach ($streams as $name => $data) {
            $n = max(1, (int) ceil(strlen($data) / 64));
            $first = intdiv(strlen($mini), 64);
            $starts[$name] = [$first, strlen($data)];
            for ($i = 0; $i < $n; $i++) {
                $miniFat[] = $i === $n - 1 ? 0xFFFFFFFE : $first + $i + 1;
            }
            $mini .= str_pad($data, $n * 64, "\0");
        }
        $miniSectors = (int) ceil(strlen($mini) / 512);
        $fat = [0xFFFFFFFD, 0xFFFFFFFE, 0xFFFFFFFE];
        for ($i = 0; $i < $miniSectors; $i++) {
            $fat[] = $i === $miniSectors - 1 ? 0xFFFFFFFE : 3 + $i + 1;
        }
        $fat = array_pad($fat, 128, 0xFFFFFFFF);
        $entry = function (string $name, int $type, int $left, int $right, int $child, int $start, int $size) {
            $u = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8')."\0\0";

            return str_pad($u, 64, "\0").pack('v', strlen($u)).chr($type)."\x01".pack('VVV', $left, $right, $child).str_repeat("\0", 16).pack('V', 0)
                .str_repeat("\0", 16).pack('VVV', $start, $size, 0);
        };
        $none = 0xFFFFFFFF;
        $dir = $entry('Root Entry', 5, $none, $none, 1, 3, strlen($mini))
            .$entry("\x01CompObj", 2, $none, 2, $none, $starts["\x01CompObj"][0], $starts["\x01CompObj"][1])
            .$entry('Equation Native', 2, $none, $none, $none, $starts['Equation Native'][0], $starts['Equation Native'][1])
            .str_pad('', 128, "\0");
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 16).pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6).str_repeat("\0", 6)
            .pack('VVVVVVVVV', 0, 1, 1, 0, 4096, 2, 1, 0xFFFFFFFE, 0).pack('V', 0).str_repeat("\xFF", 108 * 4);

        return $header.pack('V*', ...$fat).$dir.str_pad(pack('V*', ...$miniFat), 512, "\xFF").str_pad($mini, $miniSectors * 512, "\0");
    }

    /** A .docx whose only paragraph holds the text and then the equation object. */
    public static function docx(string $before, string $oleBin, string $progId = 'Equation.3'): string
    {
        $doc = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"><w:body><w:p><w:r><w:t xml:space="preserve">'.$before.'</w:t></w:r>'
            .'<w:r><w:object><v:shape id="_x0000_i1025" type="#_x0000_t75"><v:imagedata r:id="rId8" o:title=""/></v:shape>'
            .'<o:OLEObject Type="Embed" ProgID="'.$progId.'" ShapeID="_x0000_i1025" DrawAspect="Content" ObjectID="_1700000000" r:id="rId9"/></w:object></w:r></w:p></w:body></w:document>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.wmf"/>'
            .'<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/oleObject" Target="embeddings/oleObject1.bin"/></Relationships>';
        $file = tempnam(sys_get_temp_dir(), 'mt');
        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
        $zip->addFromString('word/media/image1.wmf', "\xD7\xCD\xC6\x9A".str_repeat("\0", 40));
        $zip->addFromString('word/embeddings/oleObject1.bin', $oleBin);
        $zip->close();
        $out = file_get_contents($file);
        @unlink($file);

        return $out;
    }
}
