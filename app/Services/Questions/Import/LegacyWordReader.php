<?php

namespace App\Services\Questions\Import;

use RuntimeException;

/**
 * Reads the text of an old Word file (.doc, Word 97–2003) without Word or LibreOffice: the
 * document's text is stored in pieces listed in its "piece table" (MS-DOC, the Clx in the table
 * stream). Paragraphs become lines; table cells are separated by tabs; field codes are dropped
 * and their results kept. Pictures and equations in a .doc are not read — saving the file as
 * .docx keeps them.
 */
class LegacyWordReader
{
    public function text(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('The file could not be opened.');
        }
        try {
            $cf = new CompoundFile($data);
        } catch (\Throwable) {
            throw new RuntimeException('This is not a Word 97–2003 (.doc) file. If it was renamed, save it again from Word as .docx.');
        }
        if (! $cf->has('WordDocument')) {
            throw new RuntimeException('This .doc file has no document text. Save it again from Word as .docx.');
        }
        $wd = $cf->stream('WordDocument');
        if (strlen($wd) < 0x200 || self::u16($wd, 0) !== 0xA5EC) {
            throw new RuntimeException('This .doc file could not be read. Save it again from Word as .docx.');
        }
        $flags = self::u16($wd, 0x0A);
        if ($flags & 0x0100) {
            throw new RuntimeException('This Word file is password-protected. Remove the password in Word and upload it again.');
        }
        $table = $cf->has(($flags & 0x0200) ? '1Table' : '0Table') ? $cf->stream(($flags & 0x0200) ? '1Table' : '0Table') : '';

        // FIB: base (32 bytes), csw + fibRgW, cslw + fibRgLw, cbRgFcLcb + fibRgFcLcbBlob
        $pos = 32;
        $csw = self::u16($wd, $pos);
        $pos += 2 + $csw * 2;
        $cslw = self::u16($wd, $pos);
        $rgLw = $pos + 2;
        $ccpText = self::u32($wd, $rgLw + 12);
        $pos += 2 + $cslw * 4;
        $blob = $pos + 2;
        $fcClx = self::u32($wd, $blob + 33 * 8);
        $lcbClx = self::u32($wd, $blob + 33 * 8 + 4);
        if (! $lcbClx || $fcClx + $lcbClx > strlen($table)) {
            throw new RuntimeException('This .doc file could not be read. Save it again from Word as .docx.');
        }

        // Clx: skip the Prc blocks (0x01), then the Pcdt (0x02)
        $clx = substr($table, $fcClx, $lcbClx);
        $i = 0;
        while ($i < strlen($clx) && ord($clx[$i]) === 0x01) {
            $i += 3 + self::i16($clx, $i + 1);
        }
        if ($i >= strlen($clx) || ord($clx[$i]) !== 0x02) {
            throw new RuntimeException('This .doc file could not be read. Save it again from Word as .docx.');
        }
        $lcb = self::u32($clx, $i + 1);
        $plc = substr($clx, $i + 5, $lcb);
        $n = intdiv($lcb - 4, 12);

        $text = '';
        for ($k = 0; $k < $n; $k++) {
            $cpStart = self::u32($plc, $k * 4);
            $cpEnd = self::u32($plc, ($k + 1) * 4);
            if ($cpStart >= $ccpText) {
                break;
            }
            $cpEnd = min($cpEnd, $ccpText);
            $len = $cpEnd - $cpStart;
            $fcRaw = self::u32($plc, ($n + 1) * 4 + $k * 8 + 2);
            if ($fcRaw & 0x40000000) {
                $fc = intdiv($fcRaw & 0x3FFFFFFF, 2);
                $text .= mb_convert_encoding(substr($wd, $fc, $len), 'UTF-8', 'Windows-1252');
            } else {
                $text .= mb_convert_encoding(substr($wd, $fcRaw, $len * 2), 'UTF-8', 'UTF-16LE');
            }
            if (strlen($text) > 4_000_000) {
                break;
            }
        }

        return self::clean($text);
    }

    /** The text of an RTF file (Word can save a .doc as RTF): paragraphs, tabs and special characters kept. */
    public static function rtfText(string $rtf): string
    {
        if (! str_starts_with(ltrim($rtf), '{\\rtf')) {
            throw new RuntimeException('This file could not be read. Save it again from Word as .docx.');
        }
        // drop destinations that hold no document text: font/colour/style tables, pictures, \* groups
        $out = '';
        $len = strlen($rtf);
        $skipDepth = null;
        $depth = 0;
        $uc = 1;
        $skipChars = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $rtf[$i];
            if ($c === '{') {
                $depth++;

                continue;
            }
            if ($c === '}') {
                if ($skipDepth !== null && $depth <= $skipDepth) {
                    $skipDepth = null;
                }
                $depth--;

                continue;
            }
            if ($c === '\\') {
                $next = $rtf[$i + 1] ?? '';
                if ($next === '\\' || $next === '{' || $next === '}') {
                    $skipDepth === null && $out .= $next;
                    $i++;

                    continue;
                }
                if ($next === "'") {
                    $hex = substr($rtf, $i + 2, 2);
                    $i += 3;
                    if ($skipDepth === null) {
                        $skipChars > 0 ? $skipChars-- : $out .= mb_convert_encoding(chr(hexdec($hex)), 'UTF-8', 'Windows-1252');
                    }

                    continue;
                }
                if ($next === '*') {
                    $skipDepth ??= $depth;
                    $i++;

                    continue;
                }
                if (! preg_match('/\G\\\\([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $rtf, $m, 0, $i)) {
                    $i++; // control symbol such as \~ or \-

                    continue;
                }
                $i += strlen($m[0]) - 1;
                $word = $m[1];
                $arg = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
                if (in_array($word, ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'header', 'footer', 'headerl', 'headerr', 'footerl', 'footerr', 'listtable', 'listoverridetable', 'rsidtbl', 'themedata', 'datastore', 'latentstyles', 'xmlnstbl', 'generator'], true)) {
                    $skipDepth ??= $depth;
                }
                if ($skipDepth !== null) {
                    continue;
                }
                match ($word) {
                    'par', 'line', 'row', 'sect', 'page' => $out .= "\n",
                    'tab', 'cell' => $out .= "\t",
                    'emdash' => $out .= '—', 'endash' => $out .= '–', 'bullet' => $out .= '•',
                    'lquote' => $out .= '‘', 'rquote' => $out .= '’', 'ldblquote' => $out .= '“', 'rdblquote' => $out .= '”',
                    'uc' => $uc = (int) $arg,
                    'u' => [$out .= mb_chr($arg < 0 ? $arg + 65536 : (int) $arg, 'UTF-8'), $skipChars = $uc],
                    default => null,
                };

                continue;
            }
            if ($c === "\r" || $c === "\n" || $skipDepth !== null) {
                continue;
            }
            if ($skipChars > 0) {
                $skipChars--;

                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    /** Word's control characters → plain text lines. */
    public static function clean(string $text): string
    {
        // fields: \x13 code \x14 result \x15 → result (or nothing when there is no result)
        $text = preg_replace('/\x13[^\x13\x14\x15]*\x14([^\x13\x15]*)\x15/u', '$1', $text);
        $text = preg_replace('/\x13[^\x13\x14\x15]*\x15/u', '', $text);
        $text = preg_replace('/\x13[^\x13\x14\x15]*\x14([^\x13\x15]*)\x15/u', '$1', $text); // nested fields
        $text = strtr($text, [
            "\x07\x07" => "\n", // end of a table row (cell mark + row mark)
            "\x07" => "\t",
            "\r" => "\n",
            "\x0B" => "\n",
            "\x0C" => "\n",
            "\x1E" => '-',
            "\x1F" => '',
            "\x01" => '',
            "\x08" => '',
            "\x13" => '', "\x14" => '', "\x15" => '',
        ]);

        return preg_replace('/[\x00-\x08\x0E-\x1F]/u', '', $text);
    }

    private static function u16(string $s, int $o): int
    {
        return strlen($s) >= $o + 2 ? unpack('v', $s, $o)[1] : 0;
    }

    private static function i16(string $s, int $o): int
    {
        $v = self::u16($s, $o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32(string $s, int $o): int
    {
        return strlen($s) >= $o + 4 ? unpack('V', $s, $o)[1] : 0;
    }
}
