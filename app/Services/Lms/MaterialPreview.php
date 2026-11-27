<?php

namespace App\Services\Lms;

use App\Models\CourseItem;
use App\Services\Questions\Import\DocumentConverter;
use App\Services\Questions\Import\LegacyWordReader;
use App\Services\Questions\Import\SpreadsheetReader;
use App\Services\Questions\Import\WordScienceReader;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Course materials shown in the page, so students read them on any phone without Word,
 * PowerPoint or Excel installed: Word (.docx, .doc, .rtf) with its equations, pictures and
 * tables; PowerPoint (.pptx) slide by slide with its text and pictures; Excel (.xlsx) and CSV as
 * a table; text files as text. PDF, pictures, video and audio are shown by the browser itself.
 * The result is kept per version of the item, so each file is converted once.
 */
class MaterialPreview
{
    public const KINDS = ['docx' => 'word', 'doc' => 'word', 'rtf' => 'word', 'pptx' => 'slides', 'xlsx' => 'sheet', 'csv' => 'sheet', 'txt' => 'text', 'md' => 'text'];

    public static function kind(?string $fileName): ?string
    {
        return self::KINDS[strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION))] ?? null;
    }

    /** Folder holding a version's preview and its pictures. */
    public static function dir(CourseItem $item): string
    {
        return 'lms-previews/'.$item->id.'-v'.$item->version;
    }

    /** @return array{html: string, kind: string, slides?: int} */
    public function render(CourseItem $item): array
    {
        $kind = self::kind($item->file_name);
        abort_unless($kind && $item->file_path && Storage::disk('local')->exists($item->file_path), 404);
        $cache = self::dir($item).'/preview.json';
        if (Storage::disk('local')->exists($cache)) {
            return json_decode(Storage::disk('local')->get($cache), true);
        }

        $path = Storage::disk('local')->path($item->file_path);
        $mediaDir = Storage::disk('local')->path(self::dir($item).'/media');
        $ext = strtolower(pathinfo($item->file_name, PATHINFO_EXTENSION));
        // a .doc that is really a .docx (or RTF) is read as what it is
        $head = (string) file_get_contents($path, false, null, 0, 8);
        if ($ext === 'doc' && str_starts_with($head, 'PK')) {
            $ext = 'docx';
        } elseif (in_array($ext, ['doc', 'docx'], true) && str_starts_with($head, '{\rtf')) {
            $ext = 'rtf';
        }

        try {
            $out = match ($ext) {
                'docx' => ['html' => $this->word($path, $mediaDir, $item), 'kind' => 'word'],
                'doc' => ['html' => $this->paragraphs((new LegacyWordReader)->text($path)), 'kind' => 'word'],
                'rtf' => ['html' => $this->paragraphs(LegacyWordReader::rtfText(File::get($path))), 'kind' => 'word'],
                'pptx' => $this->slides($path, $mediaDir, $item),
                'xlsx' => ['html' => $this->table((new SpreadsheetReader)->csv($path)), 'kind' => 'sheet'],
                'csv' => ['html' => $this->table(DocumentConverter::toUtf8(File::get($path))), 'kind' => 'sheet'],
                default => ['html' => '<pre class="whitespace-pre-wrap">'.htmlspecialchars(mb_substr(DocumentConverter::toUtf8(File::get($path)), 0, 500000), ENT_QUOTES, 'UTF-8').'</pre>', 'kind' => 'text'],
            };
        } catch (\Throwable $e) {
            report($e);
            $out = ['html' => '', 'kind' => $kind, 'error' => 'This file could not be shown here. Download it to open it.'];
        }
        Storage::disk('local')->put($cache, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    private function word(string $path, string $mediaDir, CourseItem $item): string
    {
        $r = (new WordScienceReader)->read($path, $mediaDir);
        $html = DocumentConverter::markMathsInHtml($r['html']);

        return $this->pictures($html, $item);
    }

    /** Pictures written to the media folder are served through the item, to people who may open it. */
    private function pictures(string $html, CourseItem $item): string
    {
        return preg_replace_callback('/<img\b([^>]*?)src="([^"]+)"/i', function ($m) use ($item) {
            $name = basename(str_replace('\\', '/', html_entity_decode($m[2])));

            return '<img'.$m[1].'loading="lazy" src="/learn/items/'.$item->id.'/preview/media/'.rawurlencode($name).'"';
        }, $html);
    }

    private function paragraphs(string $text): string
    {
        $out = [];
        foreach (preg_split('/\n/', str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = '<p>'.DocumentConverter::markMaths(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')).'</p>';
            }
        }

        return implode("\n", $out);
    }

    private function table(string $csv): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, str_replace(["\r\n", "\r"], "\n", $csv));
        rewind($fh);
        $first = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false && count($rows) < 2000) {
            $rows[] = $row;
        }
        fclose($fh);
        if (! $rows) {
            return '<p>This sheet is empty.</p>';
        }
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<table><thead><tr>'.implode('', array_map(fn ($c) => '<th>'.$h($c).'</th>', $rows[0])).'</tr></thead><tbody>';
        foreach (array_slice($rows, 1) as $r) {
            $html .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.$h($c).'</td>', $r)).'</tr>';
        }

        return $html.'</tbody></table>';
    }

    /** PowerPoint: each slide's title, text (with its bullet levels) and pictures, in order. */
    private function slides(string $path, string $mediaDir, CourseItem $item): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Not a PowerPoint file.');
        }
        File::ensureDirectoryExists($mediaDir);
        $order = $this->slideOrder($zip);
        $out = '';
        $n = 0;
        foreach ($order as $slidePath) {
            $xml = $zip->getFromName($slidePath);
            if ($xml === false) {
                continue;
            }
            $n++;
            $doc = new DOMDocument;
            $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $x = new DOMXPath($doc);
            $x->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $x->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $x->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rels = $this->rels($zip, $slidePath);

            $title = '';
            $body = '';
            foreach ($x->query('//p:sp') as $sp) {
                $ph = $x->query('.//p:nvPr/p:ph', $sp)->item(0);
                $isTitle = $ph && in_array($ph->getAttribute('type'), ['title', 'ctrTitle'], true);
                $paras = [];
                foreach ($x->query('.//p:txBody/a:p', $sp) as $p) {
                    $t = '';
                    foreach ($x->query('.//a:r/a:t | .//a:fld/a:t', $p) as $tn) {
                        $t .= $tn->textContent;
                    }
                    $t = trim($t);
                    if ($t !== '') {
                        $lvl = (int) ($x->query('a:pPr', $p)->item(0)?->getAttribute('lvl') ?: 0);
                        $paras[] = [$lvl, $t];
                    }
                }
                if (! $paras) {
                    continue;
                }
                if ($isTitle && $title === '') {
                    $title = implode(' ', array_column($paras, 1));

                    continue;
                }
                $body .= '<ul>';
                foreach ($paras as [$lvl, $t]) {
                    $body .= '<li style="margin-left:'.($lvl * 1.25).'rem">'.DocumentConverter::markMaths(htmlspecialchars($t, ENT_QUOTES, 'UTF-8')).'</li>';
                }
                $body .= '</ul>';
            }
            $pics = '';
            foreach ($x->query('//p:pic//a:blip') as $blip) {
                $target = $rels[$blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')] ?? null;
                if (! $target || ! preg_match('/\.(png|jpe?g|gif|webp)$/i', $target, $em)) {
                    continue;
                }
                $data = $zip->getFromName($target);
                if ($data === false || strlen($data) > 15 * 1024 * 1024) {
                    continue;
                }
                $name = 's'.$n.'-'.substr(sha1($target), 0, 10).'.'.strtolower($em[1]);
                File::put($mediaDir.'/'.$name, $data);
                $pics .= '<img loading="lazy" alt="" src="/learn/items/'.$item->id.'/preview/media/'.$name.'">';
            }
            $out .= '<section class="slide" data-slide="'.$n.'"><p class="slide-no">Slide '.$n.'</p>'
                .($title !== '' ? '<h3>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h3>' : '').$body.($pics ? '<div class="slide-pics">'.$pics.'</div>' : '').'</section>';
        }
        $zip->close();

        return ['html' => $out ?: '<p>This presentation has no slides.</p>', 'kind' => 'slides', 'slides' => $n];
    }

    /** @return list<string> slide part names in presentation order */
    private function slideOrder(ZipArchive $zip): array
    {
        $pres = $zip->getFromName('ppt/presentation.xml');
        $rels = $this->rels($zip, 'ppt/presentation.xml');
        $out = [];
        if ($pres !== false) {
            $doc = new DOMDocument;
            $doc->loadXML($pres, LIBXML_NONET);
            foreach ($doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId') as $s) {
                $id = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                isset($rels[$id]) && $out[] = $rels[$id];
            }
        }
        if (! $out) {
            for ($i = 1; $zip->locateName("ppt/slides/slide$i.xml") !== false; $i++) {
                $out[] = "ppt/slides/slide$i.xml";
            }
        }

        return array_slice($out, 0, 500);
    }

    /** @return array<string, string> relationship id => part name */
    private function rels(ZipArchive $zip, string $part): array
    {
        $relsPath = dirname($part).'/_rels/'.basename($part).'.rels';
        $xml = $zip->getFromName($relsPath);
        if ($xml === false) {
            return [];
        }
        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET);
        $out = [];
        foreach ($doc->getElementsByTagName('Relationship') as $r) {
            if ($r->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = $r->getAttribute('Target');
            $out[$r->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : $this->resolve(dirname($part), $target);
        }

        return $out;
    }

    private function resolve(string $base, string $rel): string
    {
        $parts = explode('/', $base.'/'.$rel);
        $out = [];
        foreach ($parts as $p) {
            if ($p === '..') {
                array_pop($out);
            } elseif ($p !== '.' && $p !== '') {
                $out[] = $p;
            }
        }

        return implode('/', $out);
    }
}
