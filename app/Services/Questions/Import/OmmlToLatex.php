<?php

namespace App\Services\Questions\Import;

use DOMElement;
use DOMNode;

/**
 * Word's own equations (Office Math, the "Insert › Equation" kind) to LaTeX that MathJax shows
 * the way the lecturer typed them: fractions, powers, subscripts, roots, sums and integrals,
 * brackets, matrices, accents (vectors, bars, hats), functions, limits and chemistry arrows.
 * Unicode symbols (α, ×, ≤, →, ∞ …) pass through unchanged; MathJax reads them directly.
 */
class OmmlToLatex
{
    public const M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    private const NARY = ['∑' => '\sum', '∏' => '\prod', '∐' => '\coprod', '∫' => '\int', '∬' => '\iint', '∭' => '\iiint', '∮' => '\oint',
        '∯' => '\oiint', '⋃' => '\bigcup', '⋂' => '\bigcap', '⋁' => '\bigvee', '⋀' => '\bigwedge', '⨁' => '\bigoplus', '⨂' => '\bigotimes'];

    private const ACCENTS = ["\u{0302}" => '\hat', '^' => '\hat', "\u{0303}" => '\tilde', '~' => '\tilde', "\u{0304}" => '\bar', "\u{0305}" => '\overline',
        "\u{0307}" => '\dot', "\u{0308}" => '\ddot', "\u{20D7}" => '\vec', '→' => '\vec', "\u{0306}" => '\breve', "\u{030C}" => '\check', "\u{0301}" => '\acute', "\u{0300}" => '\grave'];

    private const FUNCTIONS = ['sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'sinh', 'cosh', 'tanh', 'coth', 'arcsin', 'arccos', 'arctan',
        'log', 'ln', 'lg', 'exp', 'lim', 'max', 'min', 'sup', 'inf', 'det', 'deg', 'gcd', 'dim', 'ker', 'arg', 'Pr'];

    public function convert(DOMElement $math): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->children($math)));
    }

    private function children(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $out .= $this->element($child);
            }
        }

        return $out;
    }

    /** The m:<name> child of an element. */
    private function part(DOMElement $el, string $name): ?DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->namespaceURI === self::M && $c->localName === $name) {
                return $c;
            }
        }

        return null;
    }

    private function arg(DOMElement $el, string $name): string
    {
        $p = $this->part($el, $name);

        return $p ? $this->children($p) : '';
    }

    /** A property value such as <m:fPr><m:type m:val="lin"/></m:fPr>. */
    private function prop(DOMElement $el, string $pr, string $name): ?string
    {
        $p = $this->part($el, $pr);
        $v = $p ? $this->part($p, $name) : null;

        return $v ? ($v->getAttributeNS(self::M, 'val') ?: $v->getAttribute('m:val') ?: null) : null;
    }

    private function group(string $s): string
    {
        $s = trim($s);

        return mb_strlen($s) === 1 ? $s : '{'.$s.'}';
    }

    private function element(DOMElement $el): string
    {
        if ($el->namespaceURI !== self::M) {
            // Word puts ordinary run properties and the odd w:r inside maths; keep their text
            return $el->localName === 't' ? $this->text($el->textContent, false) : $this->children($el);
        }

        switch ($el->localName) {
            case 'oMath':
            case 'oMathPara':
            case 'e':
            case 'num':
            case 'den':
            case 'sub':
            case 'sup':
            case 'deg':
            case 'lim':
            case 'fName':
            case 'box':
            case 'phant':
                return $this->children($el);

            case 'r':
                $plain = $this->prop($el, 'rPr', 'sty') === 'p' || (bool) $this->part($this->part($el, 'rPr') ?? $el, 'nor');
                $text = '';
                foreach ($el->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->localName === 't') {
                        $text .= $c->textContent;
                    }
                }

                return $this->text($text, $plain);

            case 'f':
                $num = $this->arg($el, 'num');
                $den = $this->arg($el, 'den');

                return match ($this->prop($el, 'fPr', 'type')) {
                    'lin' => $this->group($num).'/'.$this->group($den),
                    'skw' => '{}^{'.$num.'}/_{'.$den.'}',
                    'noBar' => '\genfrac{}{}{0pt}{}{'.$num.'}{'.$den.'}',
                    default => '\frac{'.$num.'}{'.$den.'}',
                };

            case 'sSup':
                return $this->group($this->arg($el, 'e')).'^{'.$this->arg($el, 'sup').'}';
            case 'sSub':
                return $this->group($this->arg($el, 'e')).'_{'.$this->arg($el, 'sub').'}';
            case 'sSubSup':
                return $this->group($this->arg($el, 'e')).'_{'.$this->arg($el, 'sub').'}^{'.$this->arg($el, 'sup').'}';
            case 'sPre':
                return '{}_{'.$this->arg($el, 'sub').'}^{'.$this->arg($el, 'sup').'}'.$this->group($this->arg($el, 'e'));

            case 'rad':
                $deg = trim($this->arg($el, 'deg'));
                $hide = $this->prop($el, 'radPr', 'degHide');

                return $deg !== '' && ! in_array($hide, ['1', 'on', 'true'], true) ? '\sqrt['.$deg.']{'.$this->arg($el, 'e').'}' : '\sqrt{'.$this->arg($el, 'e').'}';

            case 'nary':
                $chr = $this->prop($el, 'naryPr', 'chr') ?? '∫';
                $op = self::NARY[$chr] ?? $chr;
                $sub = trim($this->arg($el, 'sub'));
                $sup = trim($this->arg($el, 'sup'));
                $lim = ($sub !== '' && ! in_array($this->prop($el, 'naryPr', 'subHide'), ['1', 'on'], true) ? '_{'.$sub.'}' : '')
                    .($sup !== '' && ! in_array($this->prop($el, 'naryPr', 'supHide'), ['1', 'on'], true) ? '^{'.$sup.'}' : '');
                $limits = $this->prop($el, 'naryPr', 'limLoc') === 'undOvr' && ! str_contains($op, 'int') ? '\limits' : '';

                return $op.$limits.$lim.' '.$this->arg($el, 'e');

            case 'd':
                $beg = $this->prop($el, 'dPr', 'begChr') ?? '(';
                $end = $this->prop($el, 'dPr', 'endChr') ?? ')';
                $sep = $this->prop($el, 'dPr', 'sepChr') ?? '|';
                $parts = [];
                foreach ($el->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->localName === 'e') {
                        $parts[] = $this->children($c);
                    }
                }

                return '\left'.$this->fence($beg).' '.implode(' '.$this->fence($sep, true).' ', $parts).' \right'.$this->fence($end);

            case 'func':
                $name = trim($this->arg($el, 'fName'));
                $bare = preg_replace('/^\\\\mathrm\{(.*)\}$/', '$1', $name);
                $fn = in_array($bare, self::FUNCTIONS, true) ? '\\'.$bare : (preg_match('/^[A-Za-z]+$/', $bare) ? '\operatorname{'.$bare.'}' : $name);

                return $fn.' '.$this->arg($el, 'e');

            case 'limLow':
                return $this->limit($this->arg($el, 'e')).'_{'.$this->arg($el, 'lim').'}';
            case 'limUpp':
                return $this->limit($this->arg($el, 'e')).'^{'.$this->arg($el, 'lim').'}';

            case 'acc':
                $chr = $this->prop($el, 'accPr', 'chr') ?? "\u{0302}";

                return (self::ACCENTS[$chr] ?? '\hat').'{'.$this->arg($el, 'e').'}';

            case 'bar':
                return ($this->prop($el, 'barPr', 'pos') === 'top' ? '\overline{' : '\underline{').$this->arg($el, 'e').'}';

            case 'groupChr':
                $chr = $this->prop($el, 'groupChrPr', 'chr') ?? '⏟';
                $top = $this->prop($el, 'groupChrPr', 'pos') === 'top';
                $e = $this->arg($el, 'e');

                return match ($chr) {
                    '→' => '\xrightarrow{'.$e.'}', '←' => '\xleftarrow{'.$e.'}', '⇌', '⇄' => '\xrightleftharpoons{'.$e.'}',
                    '⏞' => '\overbrace{'.$e.'}', default => $top ? '\overbrace{'.$e.'}' : '\underbrace{'.$e.'}',
                };

            case 'borderBox':
                return '\boxed{'.$this->arg($el, 'e').'}';

            case 'm':
                $rows = [];
                foreach ($el->childNodes as $row) {
                    if ($row instanceof DOMElement && $row->localName === 'mr') {
                        $cells = [];
                        foreach ($row->childNodes as $cell) {
                            if ($cell instanceof DOMElement && $cell->localName === 'e') {
                                $cells[] = $this->children($cell);
                            }
                        }
                        $rows[] = implode(' & ', $cells);
                    }
                }

                return '\begin{matrix} '.implode(' \\\\ ', $rows).' \end{matrix}';

            case 'eqArr':
                $rows = [];
                foreach ($el->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->localName === 'e') {
                        $rows[] = $this->children($c);
                    }
                }

                return '\begin{aligned} '.implode(' \\\\ ', $rows).' \end{aligned}';

            // properties and control characters carry no content of their own
            case 'rPr':
            case 'fPr':
            case 'ctrlPr':
            case 'oMathParaPr':
            case 'sSupPr':
            case 'sSubPr':
            case 'sSubSupPr':
            case 'radPr':
            case 'naryPr':
            case 'dPr':
            case 'accPr':
            case 'barPr':
            case 'funcPr':
            case 'limLowPr':
            case 'limUppPr':
            case 'mPr':
            case 'eqArrPr':
            case 'groupChrPr':
            case 'boxPr':
            case 'borderBoxPr':
            case 'sPrePr':
            case 'phantPr':
                return '';

            default:
                return $this->children($el);
        }
    }

    private function limit(string $base): string
    {
        $b = trim($base);
        if (in_array($b, ['lim', '\mathrm{lim}', '\lim'], true)) {
            return '\lim';
        }

        return '\mathop{'.$b.'}\limits';
    }

    private function fence(string $chr, bool $middle = false): string
    {
        if ($chr === '') {
            return $middle ? '' : '.';
        }
        $map = ['{' => '\{', '}' => '\}', '|' => '|', '‖' => '\|', '⟨' => '\langle', '⟩' => '\rangle', '〈' => '\langle', '〉' => '\rangle',
            '⌊' => '\lfloor', '⌋' => '\rfloor', '⌈' => '\lceil', '⌉' => '\rceil', '[' => '[', ']' => ']', '(' => '(', ')' => ')'];
        $f = $map[$chr] ?? $chr;

        return $middle ? '\middle'.$f : $f;
    }

    /** Escape TeX's special characters; multi-letter function names become operators. */
    private function text(string $text, bool $plain): string
    {
        if ($text === '') {
            return '';
        }
        $escaped = strtr($text, [
            '\\' => '\backslash ', '{' => '\{', '}' => '\}', '#' => '\#', '$' => '\$', '%' => '\%', '&' => '\&', '_' => '\_', '^' => '\hat{}', '~' => '\sim ',
            "\u{2212}" => '-', "\u{00A0}" => '\ ', "\u{2009}" => '\,', "\u{200B}" => '',
        ]);
        if ($plain && preg_match('/[A-Za-z]{2,}/', $text)) {
            return in_array(trim($text), self::FUNCTIONS, true) ? '\\'.trim($text).' ' : '\mathrm{'.$escaped.'}';
        }
        if (in_array(trim($text), self::FUNCTIONS, true)) {
            return '\\'.trim($text).' ';
        }

        return $escaped;
    }
}
