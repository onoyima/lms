<?php

namespace App\Services\Questions\Import;

use RuntimeException;

/**
 * Old Word equations — Microsoft Equation 3.0 and MathType — to LaTeX.
 *
 * These equations sit in a .docx as an embedded OLE object whose "Equation Native" stream holds
 * MathType's MTEF format (version 3 for Equation 3.0, version 4/5 for MathType 4 and later).
 * The equation tree (lines, characters, templates such as fractions, roots, scripts, big
 * operators, fences, matrices and piles, and accents on characters) is rebuilt and written as
 * LaTeX for MathJax. Anything unreadable throws, and the caller falls back to a clear message.
 */
class MtefToLatex
{
    // record types
    private const END = 0, LINE = 1, CHAR = 2, TMPL = 3, PILE = 4, MATRIX = 5, EMBELL = 6, RULER = 7, FONT = 8, SIZE = 9;

    private const FUNCTIONS = ['sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'sinh', 'cosh', 'tanh', 'coth', 'arcsin', 'arccos', 'arctan',
        'log', 'ln', 'lg', 'exp', 'lim', 'max', 'min', 'sup', 'inf', 'det', 'deg', 'gcd', 'dim', 'ker', 'arg', 'Pr'];

    private const BIG = [0x222B => '\int', 0x222C => '\iint', 0x222D => '\iiint', 0x222E => '\oint', 0x222F => '\oiint', 0x2211 => '\sum', 0x220F => '\prod',
        0x2210 => '\coprod', 0x22C3 => '\bigcup', 0x22C2 => '\bigcap', 0x222A => '\bigcup', 0x2229 => '\bigcap'];

    private const EMBELL_LATEX = [2 => '\dot', 3 => '\ddot', 4 => '\dddot', 8 => '\tilde', 9 => '\hat', 11 => '\vec', 12 => '\overleftarrow', 13 => '\overleftrightarrow',
        14 => '\overrightharpoon', 15 => '\overleftharpoon', 17 => '\bar', 19 => '\overparen', 24 => '\ddddot', 29 => '\underline', 30 => '\utilde'];

    private string $d = '';

    private int $p = 0;

    private int $v = 3;

    /** From an embedded object (oleObjectN.bin bytes). */
    public function fromOle(string $bin): string
    {
        $cf = new CompoundFile($bin);
        if (! $cf->has('Equation Native')) {
            throw new RuntimeException('Not an equation object.');
        }

        return $this->fromEquationNative($cf->stream('Equation Native'));
    }

    /** From the "Equation Native" stream: a 28-byte header, then MTEF. */
    public function fromEquationNative(string $s): string
    {
        if (strlen($s) < 34) {
            throw new RuntimeException('Equation too short.');
        }
        $hdr = unpack('v', substr($s, 0, 2))[1];

        return $this->fromMtef(substr($s, $hdr > 0 && $hdr < 200 ? $hdr : 28));
    }

    public function fromMtef(string $mtef): string
    {
        $this->d = $mtef;
        $this->p = 0;
        $this->v = $this->u8();
        if (! in_array($this->v, [2, 3, 4, 5], true)) {
            throw new RuntimeException('Unknown equation format version '.$this->v.'.');
        }
        $this->p += 4; // platform, product, version, subversion
        if ($this->v >= 4) {
            while ($this->p < strlen($this->d) && $this->d[$this->p] !== "\0") {
                $this->p++; // application key, e.g. "DSMT4"
            }
            $this->p++;
            $this->p++; // equation options
        }
        $nodes = $this->objects(0);
        $tex = trim(preg_replace('/\s+/', ' ', $this->emitList($nodes)));
        if ($tex === '') {
            throw new RuntimeException('Empty equation.');
        }

        return $tex;
    }

    // ── reading ─────────────────────────────────────────────────────────────

    private function u8(): int
    {
        if ($this->p >= strlen($this->d)) {
            throw new RuntimeException('Unexpected end of equation.');
        }

        return ord($this->d[$this->p++]);
    }

    private function u16(): int
    {
        return $this->u8() | ($this->u8() << 8);
    }

    private function cstring(): void
    {
        while ($this->u8() !== 0) {
        }
    }

    private function nudge(): void
    {
        $dx = $this->u8();
        $dy = $this->u8();
        if ($dx === 128 && $dy === 128) {
            $this->p += 4;
        }
    }

    /** Reads records until END; returns the equation nodes among them. */
    private function objects(int $depth): array
    {
        if ($depth > 60) {
            throw new RuntimeException('Equation nested too deeply.');
        }
        $out = [];
        while ($this->p < strlen($this->d)) {
            $tag = $this->u8();
            [$type, $opt] = $this->v >= 4 ? [$tag, null] : [$tag & 0x0F, $tag >> 4];
            if ($type === self::END) {
                break;
            }
            $node = $this->record($type, $opt, $depth);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function record(int $type, ?int $opt, int $depth): ?array
    {
        $v5 = $this->v >= 4;
        if ($v5 && in_array($type, [self::LINE, self::CHAR, self::TMPL, self::PILE, self::MATRIX, self::EMBELL], true)) {
            $opt = $this->u8();
        }
        $nudge = $v5 ? 0x08 : 0x08;

        switch ($type) {
            case self::LINE:
                if ($opt & $nudge) {
                    $this->nudge();
                }
                if ($opt & 0x04) {
                    $this->u16(); // line spacing
                }
                if ($opt & 0x02) {
                    $this->ruler();
                }

                return ['line', ($opt & 0x01) ? [] : $this->objects($depth + 1)];

            case self::CHAR:
                if ($opt & $nudge) {
                    $this->nudge();
                }
                $face = $this->u8() - 128;
                $code = 0;
                if ($v5) {
                    if (! ($opt & 0x20)) {
                        $code = $this->u16();
                    }
                    if ($opt & 0x04) {
                        $c8 = $this->u8();
                        $code = $code ?: $c8;
                    }
                    if ($opt & 0x10) {
                        $c16 = $this->u16();
                        $code = $code ?: $c16;
                    }
                } else {
                    $code = $this->u16();
                }
                $emb = ($opt & ($v5 ? 0x01 : 0x02)) ? array_values(array_filter($this->objects($depth + 1), fn ($n) => $n[0] === 'embell')) : [];

                return ['char', $face, $code, array_map(fn ($e) => $e[1], $emb)];

            case self::TMPL:
                if ($opt & $nudge) {
                    $this->nudge();
                }
                $sel = $this->u8();
                $var = $this->u8();
                if ($v5 && ($var & 0x80)) {
                    $var = ($var & 0x7F) | ($this->u8() << 8);
                }
                $this->u8(); // template-specific options

                return ['tmpl', $sel, $var, $this->objects($depth + 1)];

            case self::PILE:
                if ($opt & $nudge) {
                    $this->nudge();
                }
                $halign = $this->u8();
                $this->u8(); // valign
                if ($opt & 0x02) {
                    $this->ruler();
                }

                return ['pile', $halign, $this->objects($depth + 1)];

            case self::MATRIX:
                if ($opt & $nudge) {
                    $this->nudge();
                }
                $this->u8(); // valign
                $this->u8(); // h_just
                $this->u8(); // v_just
                $rows = $this->u8();
                $cols = $this->u8();
                $this->p += intdiv(($rows + 1) * 2 + 7, 8) + intdiv(($cols + 1) * 2 + 7, 8);

                return ['matrix', $rows, $cols, $this->objects($depth + 1)];

            case self::EMBELL:
                if ($opt & $nudge) {
                    $this->nudge();
                }

                return ['embell', $this->u8()];

            case self::RULER:
                $this->p--;
                $this->ruler();

                return null;

            case self::FONT: // v3 FONT, v5 FONT_STYLE_DEF
                if ($v5) {
                    $this->u8();
                    $this->u8();
                } else {
                    $this->u8();
                    $this->u8();
                    $this->cstring();
                }

                return null;

            case self::SIZE:
                $l = $this->u8();
                if ($l === 101) {
                    $this->u16();
                } elseif ($l === 100) {
                    $this->u8();
                    $this->u16();
                } else {
                    $this->u8();
                }

                return null;

            case 10: case 11: case 12: case 13: case 14: // FULL, SUB, SUB2, SYM, SUBSYM
                return null;
        }

        if ($v5) {
            switch ($type) {
                case 15: // COLOR
                    $this->u8();

                    return null;
                case 16: // COLOR_DEF
                    $o = $this->u8();
                    $this->p += ($o & 0x01) ? 8 : 6;
                    if ($o & 0x04) {
                        $this->cstring();
                    }

                    return null;
                case 17: // FONT_DEF
                    $this->u8();
                    $this->cstring();

                    return null;
                case 18: // EQN_PREFS
                    $this->u8();
                    $this->dimensions();
                    $this->dimensions();
                    $n = $this->u8();
                    for ($i = 0; $i < $n; $i++) {
                        if ($this->u8() !== 0) {
                            $this->u8();
                        }
                    }

                    return null;
                case 19: // ENCODING_DEF
                    $this->cstring();

                    return null;
            }
            if ($type >= 100) {
                $this->p += $this->u16();

                return null;
            }
        }

        throw new RuntimeException('Unknown equation record '.$type.'.');
    }

    private function ruler(): void
    {
        $this->u8(); // RULER tag
        $n = $this->u8();
        $this->p += $n * 3;
    }

    /** EQN_PREFS nibble-coded dimension array. */
    private function dimensions(): void
    {
        $count = $this->u8();
        $done = 0;
        $first = true; // the first nibble of each value is its unit
        while ($done < $count) {
            $b = $this->u8();
            foreach ([$b >> 4, $b & 0x0F] as $nib) {
                if ($done >= $count) {
                    break;
                }
                if ($first) {
                    $first = false;

                    continue;
                }
                if ($nib === 0x0F) {
                    $done++;
                    $first = true;
                }
            }
        }
    }

    // ── writing ─────────────────────────────────────────────────────────────

    private function emitList(array $nodes): string
    {
        $out = '';
        $run = '';
        $runFace = null;
        $flush = function () use (&$out, &$run, &$runFace) {
            if ($run === '') {
                return;
            }
            if ($runFace === 2) {
                $out .= in_array($run, self::FUNCTIONS, true) ? '\\'.$run.' ' : '\mathrm{'.$this->escape($run).'}';
            } elseif ($runFace === 1) {
                $out .= trim($run) === '' ? '\ ' : '\text{'.$this->escape($run).'}';
            }
            $run = '';
            $runFace = null;
        };

        foreach ($nodes as $n) {
            if ($n[0] === 'char' && in_array($n[1], [1, 2], true) && ! $n[3] && ($ch = $this->unicode($n[1], $n[2])) !== '' && ! $this->isSpace($n[1], $n[2])) {
                if ($runFace !== $n[1]) {
                    $flush();
                }
                $runFace = $n[1];
                $run .= $ch;

                continue;
            }
            $flush();
            $out .= match ($n[0]) {
                'char' => $this->char($n),
                'line' => $this->emitList($n[1]),
                'tmpl' => $this->template($n, $out),
                'pile' => $this->pile($n),
                'matrix' => $this->matrix($n),
                default => '',
            };
        }
        $flush();

        return $out;
    }

    private function group(string $s): string
    {
        return '{'.trim($s).'}';
    }

    private function isSpace(int $face, int $code): bool
    {
        return $face === 24 || ($code >= 0xEF00 && $code <= 0xEF0F);
    }

    private function unicode(int $face, int $code): string
    {
        if ($code < 0x100 && in_array($face, [4, 5, 6], true) && isset(WordScienceReader::SYMBOL[$code])) {
            return WordScienceReader::SYMBOL[$code];
        }
        if ($code === 0 || ($code >= 0xE000 && $code <= 0xF8FF) || $code > 0x10FFFF) {
            return '';
        }

        $ch = mb_chr($code, 'UTF-8');

        return $ch === false ? '' : $ch;
    }

    private function escape(string $s): string
    {
        return strtr($s, ['\\' => '\backslash ', '{' => '\{', '}' => '\}', '#' => '\#', '$' => '\$', '%' => '\%', '&' => '\&', '_' => '\_', '^' => '\hat{}', '~' => '\sim ',
            "\u{2212}" => '-', "\u{00A0}" => '\ ', "\u{2009}" => '\,', "\u{200B}" => '']);
    }

    private function char(array $n): string
    {
        [, $face, $code, $emb] = $n;
        if ($this->isSpace($face, $code)) {
            return in_array($code, [0xEF00, 0xEF08], true) ? '' : ($code >= 0xEF04 ? '\; ' : '\, ');
        }
        $c = $this->escape($this->unicode($face, $code));
        if ($c === '') {
            return '';
        }
        if ($face === 7) {
            $c = '\mathbf{'.$c.'}';
        }
        foreach ($emb as $e) {
            $c = match (true) {
                $e === 5 => $c."'",
                $e === 6 => $c."''",
                $e === 18 => $c."'''",
                $e === 7 => $c.'`',
                $e === 10 => '\not '.$c,
                isset(self::EMBELL_LATEX[$e]) => self::EMBELL_LATEX[$e].'{'.$c.'}',
                default => $c,
            };
        }

        return $c.(preg_match('/\\\\[a-zA-Z]+$/', $c) ? ' ' : '');
    }

    private function template(array $n, string $before): string
    {
        [, $sel, $var, $kids] = $n;
        $slots = [];
        $chars = [];
        foreach ($kids as $k) {
            if ($k[0] === 'char') {
                $chars[] = $k;
            } else {
                $slots[] = trim($this->emitList([$k]));
            }
        }
        $s = fn (int $i) => $slots[$i] ?? '';
        $fence = function (string $l, string $r) use ($var, $s) {
            return '\left'.(($var & 0x01) || $var === 0 ? $l : '.').' '.$s(0).' \right'.(($var & 0x02) || $var === 0 ? $r : '.');
        };
        $scripts = fn (string $lo, string $hi) => ($lo !== '' ? '_'.$this->group($lo) : '').($hi !== '' ? '^'.$this->group($hi) : '');

        switch ($sel) {
            case 0: return $fence('\langle', '\rangle');
            case 1: return $fence('(', ')');
            case 2: return $fence('\{', '\}');
            case 3: return $fence('[', ']');
            case 4: return $fence('|', '|');
            case 5: return $fence('\|', '\|');
            case 6: return $fence('\lfloor', '\rfloor');
            case 7: return $fence('\lceil', '\rceil');
            case 8: return '[\![ '.$s(0).' ]\!]';
            case 9:
                $l = $chars[0] ?? null;
                $r = $chars[1] ?? null;
                $lt = $l ? $this->escape($this->unicode($l[1], $l[2])) : '(';
                $rt = $r ? $this->escape($this->unicode($r[1], $r[2])) : ')';

                return '\left'.($lt === '' ? '.' : $lt).' '.$s(0).' \right'.($rt === '' ? '.' : $rt);
            case 10: return $s(1) !== '' ? '\sqrt['.$s(1).']'.$this->group($s(0)) : '\sqrt'.$this->group($s(0));
            case 11: return ($var & 0x02) ? $this->group($s(0)).'/'.$this->group($s(1)) : '\frac'.$this->group($s(0)).$this->group($s(1));
            case 12: return '\underline'.$this->group($s(0));
            case 13: return '\overline'.$this->group($s(0));
            case 14:
                $arrow = ($var & 0x03) ? '\rightleftharpoons' : (($var & 0x10) && ! ($var & 0x20) ? '\leftarrow' : '\rightarrow');
                $top = $s(0);
                $bottom = $s(1);

                return $bottom !== '' ? '\underset'.$this->group($bottom).'{\overset'.$this->group($top).'{'.$arrow.'}}' : '\overset'.$this->group($top).'{'.$arrow.'}';
            case 15: case 16: case 17: case 18: case 19: case 20: case 21: case 22:
                $op = null;
                foreach (array_reverse($chars) as $c) {
                    if (isset(self::BIG[$c[2]])) {
                        $op = self::BIG[$c[2]];
                        break;
                    }
                }
                $op ??= match ($sel) {
                    15, 21 => match ($var & 0x03) { 2 => '\iint', 3 => '\iiint', default => ($var & 0x04) ? '\oint' : '\int' },
                    16, 22 => '\sum', 17 => '\prod', 18 => '\coprod', 19 => '\bigcup', default => '\bigcap',
                };

                return $op.$scripts($s(1), $s(2)).' '.$s(0);
            case 23: return $s(0).$scripts($s(1), $s(2)).' ';
            case 24: case 25: return ($var & 0x01) ? '\overbrace'.$this->group($s(0)).($s(1) !== '' ? '^'.$this->group($s(1)) : '') : '\underbrace'.$this->group($s(0)).($s(1) !== '' ? '_'.$this->group($s(1)) : '');
            case 26: return ($s(1) !== '' ? $s(1).' ' : '').'\overline{\left.\right) '.$s(0).'}';
            case 27: case 28: case 29:
                $sc = $scripts($s(0), $s(1));
                $base = rtrim($before);

                return ($base === '' || preg_match('/[_^]\{[^{}]*\}$/', $base) && ($var & 0x01) ? '{}' : '').$sc;
            case 30: return '\left\langle '.$s(0).' \middle| '.$s(1).' \right\rangle';
            case 31: return (($var & 0x01) && ! ($var & 0x02) ? '\overleftarrow' : (($var & 0x03) === 0x03 ? '\overleftrightarrow' : '\overrightarrow')).$this->group($s(0));
            case 32: return '\widetilde'.$this->group($s(0));
            case 33: return '\widehat'.$this->group($s(0));
            case 34: return '\overparen'.$this->group($s(0));
            case 35: return $s(0).' \Big|';
            case 36: return '\cancel'.$this->group($s(0));
            case 37: return '\boxed'.$this->group($s(0));
        }

        return implode(' ', array_filter($slots, fn ($x) => $x !== ''));
    }

    private function pile(array $n): string
    {
        $lines = array_map(fn ($l) => trim($this->emitList([$l])), array_values(array_filter($n[2], fn ($k) => $k[0] !== 'char')));
        if (count($lines) <= 1) {
            return $lines[0] ?? '';
        }
        $align = match ($n[1]) { 1 => 'l', 3 => 'r', default => 'c' };

        return '\begin{array}{'.$align.'} '.implode(' \\\\ ', $lines).' \end{array}';
    }

    private function matrix(array $n): string
    {
        [, $rows, $cols, $kids] = $n;
        $cells = array_map(fn ($l) => trim($this->emitList([$l])), array_values(array_filter($kids, fn ($k) => $k[0] !== 'char')));
        $out = [];
        for ($r = 0; $r < max(1, $rows); $r++) {
            $out[] = implode(' & ', array_slice($cells, $r * max(1, $cols), max(1, $cols)));
        }

        return '\begin{matrix} '.implode(' \\\\ ', $out).' \end{matrix}';
    }
}
