<?php

namespace App\Services\Questions\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Reads a Word file directly (no Pandoc needed) and keeps what science and maths papers depend on:
 *  - Word equations (Insert › Equation) become LaTeX, shown by MathJax exactly as built;
 *  - characters in the Symbol font (Insert › Symbol, or typed in that font) become real Greek
 *    letters and maths signs — they would otherwise show as plain a, b, ® …;
 *  - superscripts and subscripts (x², H₂SO₄, Fe³⁺, 10⁻⁶) stay raised and lowered;
 *  - bold, italic, underline, tables, line breaks and pictures (PNG/JPG/GIF);
 *  - Word's automatic numbering (1., 2. … and A., B. …) is written out so questions and options are found.
 * Old-style equation objects (Equation 3.0, MathType) are read from their embedded data (MTEF)
 * and become LaTeX too; one that cannot be read is flagged so the lecturer can convert it in Word.
 */
class WordScienceReader
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Adobe Symbol font, code point (0x20-0xFE) => Unicode. */
    public const SYMBOL = [
        0x22 => '∀', 0x24 => '∃', 0x27 => '∋', 0x2A => '∗', 0x2D => '−', 0x40 => '≅',
        0x41 => 'Α', 0x42 => 'Β', 0x43 => 'Χ', 0x44 => 'Δ', 0x45 => 'Ε', 0x46 => 'Φ', 0x47 => 'Γ', 0x48 => 'Η', 0x49 => 'Ι', 0x4A => 'ϑ',
        0x4B => 'Κ', 0x4C => 'Λ', 0x4D => 'Μ', 0x4E => 'Ν', 0x4F => 'Ο', 0x50 => 'Π', 0x51 => 'Θ', 0x52 => 'Ρ', 0x53 => 'Σ', 0x54 => 'Τ',
        0x55 => 'Υ', 0x56 => 'ς', 0x57 => 'Ω', 0x58 => 'Ξ', 0x59 => 'Ψ', 0x5A => 'Ζ', 0x5C => '∴', 0x5E => '⊥', 0x60 => '‾',
        0x61 => 'α', 0x62 => 'β', 0x63 => 'χ', 0x64 => 'δ', 0x65 => 'ε', 0x66 => 'φ', 0x67 => 'γ', 0x68 => 'η', 0x69 => 'ι', 0x6A => 'ϕ',
        0x6B => 'κ', 0x6C => 'λ', 0x6D => 'μ', 0x6E => 'ν', 0x6F => 'ο', 0x70 => 'π', 0x71 => 'θ', 0x72 => 'ρ', 0x73 => 'σ', 0x74 => 'τ',
        0x75 => 'υ', 0x76 => 'ϖ', 0x77 => 'ω', 0x78 => 'ξ', 0x79 => 'ψ', 0x7A => 'ζ', 0x7E => '∼',
        0xA1 => 'ϒ', 0xA2 => '′', 0xA3 => '≤', 0xA4 => '⁄', 0xA5 => '∞', 0xA6 => 'ƒ', 0xA7 => '♣', 0xA8 => '♦', 0xA9 => '♥', 0xAA => '♠',
        0xAB => '↔', 0xAC => '←', 0xAD => '↑', 0xAE => '→', 0xAF => '↓', 0xB0 => '°', 0xB1 => '±', 0xB2 => '″', 0xB3 => '≥', 0xB4 => '×',
        0xB5 => '∝', 0xB6 => '∂', 0xB7 => '•', 0xB8 => '÷', 0xB9 => '≠', 0xBA => '≡', 0xBB => '≈', 0xBC => '…', 0xBF => '↵',
        0xC0 => 'ℵ', 0xC1 => 'ℑ', 0xC2 => 'ℜ', 0xC3 => '℘', 0xC4 => '⊗', 0xC5 => '⊕', 0xC6 => '∅', 0xC7 => '∩', 0xC8 => '∪', 0xC9 => '⊃',
        0xCA => '⊇', 0xCB => '⊄', 0xCC => '⊂', 0xCD => '⊆', 0xCE => '∈', 0xCF => '∉', 0xD0 => '∠', 0xD1 => '∇', 0xD5 => '∏', 0xD6 => '√',
        0xD7 => '⋅', 0xD8 => '¬', 0xD9 => '∧', 0xDA => '∨', 0xDB => '⇔', 0xDC => '⇐', 0xDD => '⇑', 0xDE => '⇒', 0xDF => '⇓',
        0xE0 => '◊', 0xE1 => '⟨', 0xE5 => '∑', 0xF1 => '⟩', 0xF2 => '∫',
    ];

    private DOMXPath $x;

    private OmmlToLatex $math;

    /** @var array<string, string> relationship id => target */
    private array $rels = [];

    /** @var array<string, array{fmt: string, text: string, start: int}> "numId:ilvl" => format */
    private array $numbering = [];

    /** @var array<string, int> */
    private array $counters = [];

    private ZipArchive $zip;

    private string $mediaDir = '';

    /** @var list<string> */
    private array $media = [];

    /** @return array{html: string, media: list<string>} */
    public function read(string $path, string $mediaDir): array
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path) !== true || ($xml = $this->zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException('This does not look like a Word (.docx) file. In Word, use File › Save As › Word Document, then upload again.');
        }
        $this->mediaDir = $mediaDir;
        $this->media = [];
        $this->math = new OmmlToLatex;
        $this->counters = [];
        $this->rels = $this->readRels();
        $this->numbering = $this->readNumbering();

        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $this->x = new DOMXPath($doc);
        $this->x->registerNamespace('w', self::W);
        $body = $this->x->query('//w:body')->item(0);

        $html = $body ? $this->blocks($body) : '';
        $this->zip->close();

        return ['html' => $html, 'media' => $this->media];
    }

    private function blocks(DOMNode $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->namespaceURI === self::W && $node->localName === 'p') {
                $out .= $this->paragraph($node);
            } elseif ($node->namespaceURI === self::W && $node->localName === 'tbl') {
                $out .= $this->table($node);
            } elseif ($node->namespaceURI === OmmlToLatex::M && $node->localName === 'oMathPara') {
                $out .= '<p><span class="math display">\['.$this->tex($this->math->convert($node)).'\]</span></p>';
            } elseif (in_array($node->localName, ['sdt', 'sdtContent', 'customXml', 'smartTag'], true)) {
                $out .= $this->blocks($node);
            }
        }

        return $out;
    }

    private function paragraph(DOMElement $p): string
    {
        $inner = trim($this->inline($p));
        if ($inner === '' || $inner === '<br>') {
            return '';
        }
        $prefix = $this->listPrefix($p);

        return '<p>'.($prefix !== '' ? htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8').' ' : '').$inner."</p>\n";
    }

    /** Text, runs and equations inside a paragraph (or hyperlink, field, inserted text). */
    private function inline(DOMNode $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->namespaceURI === OmmlToLatex::M) {
                if ($node->localName === 'oMath') {
                    $out .= '<span class="math inline">\('.$this->tex($this->math->convert($node)).'\)</span>';
                } elseif ($node->localName === 'oMathPara') {
                    $out .= '<span class="math display">\['.$this->tex($this->math->convert($node)).'\]</span>';
                }

                continue;
            }
            $out .= match ($node->localName) {
                'r' => $this->run($node),
                'hyperlink', 'ins', 'smartTag', 'fldSimple', 'customXml', 'sdt', 'sdtContent', 'bdo', 'dir' => $this->inline($node),
                default => '',
            };
        }

        return $out;
    }

    private function run(DOMElement $r): string
    {
        $pr = $this->child($r, 'rPr');
        $font = $pr ? ($this->attr($this->child($pr, 'rFonts'), 'ascii') ?? $this->attr($this->child($pr, 'rFonts'), 'hAnsi') ?? '') : '';
        $symbolFont = in_array(strtolower($font), ['symbol', 'wingdings'], true);
        $text = '';
        foreach ($r->childNodes as $c) {
            if (! $c instanceof DOMElement) {
                continue;
            }
            $text .= match ($c->localName) {
                't' => htmlspecialchars($symbolFont ? $this->fromSymbolFont($c->textContent) : $c->textContent, ENT_QUOTES, 'UTF-8'),
                'tab' => ' ',
                'br', 'cr' => '<br>',
                'noBreakHyphen' => '‑',
                'sym' => htmlspecialchars($this->sym($c), ENT_QUOTES, 'UTF-8'),
                'drawing' => $this->drawing($c),
                'pict', 'object' => $this->legacyPicture($c),
                'AlternateContent' => $this->alternate($c),
                default => '',
            };
        }
        if ($text === '' || ! $pr) {
            return $text;
        }
        $vert = $this->attr($this->child($pr, 'vertAlign'), 'val');
        if ($vert === 'superscript') {
            $text = "<sup>$text</sup>";
        } elseif ($vert === 'subscript') {
            $text = "<sub>$text</sub>";
        }
        if ($this->on($pr, 'b')) {
            $text = "<strong>$text</strong>";
        }
        if ($this->on($pr, 'i')) {
            $text = "<em>$text</em>";
        }
        if (($u = $this->child($pr, 'u')) && $this->attr($u, 'val') !== 'none') {
            $text = "<u>$text</u>";
        }
        if ($this->on($pr, 'strike')) {
            $text = "<s>$text</s>";
        }

        return $text;
    }

    /** Insert › Symbol: <w:sym w:font="Symbol" w:char="F061"/>. */
    private function sym(DOMElement $s): string
    {
        $code = hexdec((string) $this->attr($s, 'char'));
        $font = strtolower((string) $this->attr($s, 'font'));
        if ($code >= 0xF000) {
            $code -= 0xF000;
        }
        if ($font === 'symbol' && isset(self::SYMBOL[$code])) {
            return self::SYMBOL[$code];
        }

        return $code > 0 ? mb_chr($code, 'UTF-8') : '';
    }

    /** Text typed in the Symbol font: letters are Greek, punctuation is maths. */
    private function fromSymbolFont(string $text): string
    {
        $out = '';
        foreach (mb_str_split($text) as $ch) {
            $code = mb_ord($ch, 'UTF-8');
            if ($code >= 0xF000 && $code <= 0xF0FF) {
                $code -= 0xF000;
            }
            $out .= self::SYMBOL[$code] ?? $ch;
        }

        return $out;
    }

    private function drawing(DOMElement $d): string
    {
        $blip = $d->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip')->item(0);
        $id = $blip instanceof DOMElement ? $blip->getAttributeNS(self::R, 'embed') : '';

        return $id ? $this->image($id) : '';
    }

    /** LaTeX inside HTML: <, > and & must be escaped (MathJax reads the text, so it sees them as typed). */
    private function tex(string $latex): string
    {
        return htmlspecialchars($latex, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * VML pictures and OLE objects. An old equation (Equation 3.0 / MathType) is an embedded object
     * whose "Equation Native" stream is read and turned into LaTeX; if that fails, the lecturer is told.
     */
    private function legacyPicture(DOMElement $el): string
    {
        $isEquation = false;
        $oleId = '';
        foreach ($el->getElementsByTagName('*') as $c) {
            if ($c->localName === 'OLEObject' && preg_match('/Equation|MathType/i', $c->getAttribute('ProgID'))) {
                $isEquation = true;
                $oleId = $c->getAttributeNS(self::R, 'id');
            }
        }
        $img = $el->getElementsByTagNameNS('urn:schemas-microsoft-com:vml', 'imagedata')->item(0);
        $id = $img instanceof DOMElement ? $img->getAttributeNS(self::R, 'id') : '';
        $target = $id ? ($this->rels[$id] ?? '') : '';
        if ($isEquation && $oleId && ($ole = $this->rels[$oleId] ?? null)) {
            $bin = $this->zip->getFromName('word/'.ltrim(str_replace('\\', '/', $ole), '/'));
            if ($bin !== false && strlen($bin) < 4 * 1024 * 1024) {
                try {
                    return '<span class="math inline">\\('.$this->tex((new MtefToLatex)->fromOle($bin)).'\\)</span>';
                } catch (\Throwable) {
                    // fall through to the message
                }
            }
        }
        if ($isEquation || preg_match('/\.(wmf|emf)$/i', $target)) {
            return '<em>[old-style equation that could not be read: in Word, click it and choose Convert (or retype it with Insert › Equation), then upload again]</em>';
        }

        return $id ? $this->image($id) : '';
    }

    /** mc:AlternateContent — prefer the plain choice Word also writes. */
    private function alternate(DOMElement $el): string
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === 'Fallback') {
                return $this->runChildren($c);
            }
        }
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === 'Choice') {
                return $this->runChildren($c);
            }
        }

        return '';
    }

    private function runChildren(DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $out .= match ($c->localName) { 'drawing' => $this->drawing($c), 'pict', 'object' => $this->legacyPicture($c), default => '' };
            }
        }

        return $out;
    }

    private function image(string $relId): string
    {
        $target = $this->rels[$relId] ?? null;
        if (! $target) {
            return '';
        }
        $inZip = 'word/'.ltrim(str_replace('\\', '/', $target), '/');
        $bytes = $this->zip->getFromName($inZip);
        if ($bytes === false) {
            return '';
        }
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        if (in_array($ext, ['wmf', 'emf'], true)) {
            return '<em>[picture in Windows Metafile format: insert it again as PNG or JPG]</em>';
        }
        File::ensureDirectoryExists($this->mediaDir);
        $file = $this->mediaDir.DIRECTORY_SEPARATOR.'img'.count($this->media).'.'.preg_replace('/[^a-z0-9]/', '', $ext);
        File::put($file, $bytes);
        $this->media[] = $file;

        return '<img src="'.htmlspecialchars($file, ENT_QUOTES, 'UTF-8').'" alt="">';
    }

    private function table(DOMElement $tbl): string
    {
        $rows = '';
        foreach ($tbl->childNodes as $tr) {
            if (! $tr instanceof DOMElement || $tr->localName !== 'tr') {
                continue;
            }
            $cells = '';
            foreach ($tr->childNodes as $tc) {
                if (! $tc instanceof DOMElement || $tc->localName !== 'tc') {
                    continue;
                }
                $span = ($pr = $this->child($tc, 'tcPr')) ? (int) $this->attr($this->child($pr, 'gridSpan'), 'val') : 0;
                $content = preg_replace('#</p>\s*<p>#', '<br>', trim($this->blocks($tc)));
                $content = preg_replace('#^<p>|</p>$#', '', trim($content));
                $cells .= '<td'.($span > 1 ? ' colspan="'.$span.'"' : '').'>'.$content.'</td>';
            }
            $rows .= "<tr>$cells</tr>";
        }

        return "<table><tbody>$rows</tbody></table>\n";
    }

    /** Word's automatic numbering, written out ("3.", "B.", "iv)") so the question parser sees it. */
    private function listPrefix(DOMElement $p): string
    {
        $pr = $this->child($p, 'pPr');
        $num = $pr ? $this->child($pr, 'numPr') : null;
        if (! $num) {
            return '';
        }
        $id = (string) $this->attr($this->child($num, 'numId'), 'val');
        $lvl = (int) $this->attr($this->child($num, 'ilvl'), 'val');
        $def = $this->numbering["$id:$lvl"] ?? null;
        if ($id === '' || $id === '0' || ! $def || $def['fmt'] === 'bullet' || $def['fmt'] === 'none') {
            return '';
        }
        $key = "$id:$lvl";
        $this->counters[$key] = ($this->counters[$key] ?? ($def['start'] - 1)) + 1;
        // a new item at a higher level restarts the levels below it
        foreach (array_keys($this->counters) as $k) {
            [$kid, $klvl] = explode(':', $k);
            if ($kid === $id && (int) $klvl > $lvl) {
                unset($this->counters[$k]);
            }
        }
        $n = $this->counters[$key];
        $value = match ($def['fmt']) {
            'upperLetter' => $this->letters($n),
            'lowerLetter' => strtolower($this->letters($n)),
            'upperRoman' => $this->roman($n),
            'lowerRoman' => strtolower($this->roman($n)),
            default => (string) $n,
        };

        return str_replace('%'.($lvl + 1), $value, $def['text'] ?: '%'.($lvl + 1).'.');
    }

    private function letters(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    private function roman(int $n): string
    {
        $map = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $s = '';
        foreach ($map as $r => $v) {
            while ($n >= $v) {
                $s .= $r;
                $n -= $v;
            }
        }

        return $s;
    }

    private function readRels(): array
    {
        $xml = $this->zip->getFromName('word/_rels/document.xml.rels');
        if ($xml === false) {
            return [];
        }
        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET);
        $rels = [];
        foreach ($doc->getElementsByTagName('Relationship') as $r) {
            if ($r->getAttribute('TargetMode') !== 'External') {
                $rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
            }
        }

        return $rels;
    }

    private function readNumbering(): array
    {
        $xml = $this->zip->getFromName('word/numbering.xml');
        if ($xml === false) {
            return [];
        }
        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET);
        $x = new DOMXPath($doc);
        $x->registerNamespace('w', self::W);
        $abstract = [];
        foreach ($x->query('//w:abstractNum') as $a) {
            $aid = $a->getAttributeNS(self::W, 'abstractNumId');
            foreach ($x->query('w:lvl', $a) as $l) {
                $abstract[$aid][(int) $l->getAttributeNS(self::W, 'ilvl')] = [
                    'fmt' => (string) $this->attr($x->query('w:numFmt', $l)->item(0), 'val'),
                    'text' => (string) $this->attr($x->query('w:lvlText', $l)->item(0), 'val'),
                    'start' => (int) ($this->attr($x->query('w:start', $l)->item(0), 'val') ?? 1),
                ];
            }
        }
        $out = [];
        foreach ($x->query('//w:num') as $n) {
            $nid = $n->getAttributeNS(self::W, 'numId');
            $aid = (string) $this->attr($x->query('w:abstractNumId', $n)->item(0), 'val');
            foreach ($abstract[$aid] ?? [] as $lvl => $def) {
                $out["$nid:$lvl"] = $def;
            }
        }

        return $out;
    }

    private function child(?DOMElement $el, string $name): ?DOMElement
    {
        if (! $el) {
            return null;
        }
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $name) {
                return $c;
            }
        }

        return null;
    }

    private function attr(?DOMNode $el, string $name): ?string
    {
        if (! $el instanceof DOMElement) {
            return null;
        }
        $v = $el->getAttributeNS(self::W, $name);

        return $v !== '' ? $v : ($el->hasAttribute($name) ? $el->getAttribute($name) : null);
    }

    /** <w:b/> or <w:b w:val="true"/> is on; w:val="0"/"false" is off. */
    private function on(DOMElement $pr, string $name): bool
    {
        $el = $this->child($pr, $name);

        return $el !== null && ! in_array($this->attr($el, 'val'), ['0', 'false', 'off'], true);
    }
}
