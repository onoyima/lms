<?php

namespace App\Services\Questions\Import;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Turns an uploaded file into HTML with maths as LaTeX:
 *   inline  <span class="math inline">\( … \)</span>
 *   display <span class="math display">\[ … \]</span>
 *
 * .docx  -> pandoc (Word's built-in equations become LaTeX; images are extracted)
 * .md    -> pandoc if available, else the plain-text rules
 * .txt   -> plain-text rules ($…$, $$…$$, \(…\), \[…\] and \ce{…} are kept as maths)
 * .html  -> as is (sanitised later)
 */
class DocumentConverter
{
    public function __construct(private ?string $pandoc = null)
    {
        $this->pandoc ??= config('platform.pandoc_path', 'pandoc');
    }

    public function pandocAvailable(): bool
    {
        try {
            $p = new Process([$this->pandoc, '--version']);
            $p->setTimeout(10)->run();

            return $p->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  string  $mediaDir  absolute directory where images from the document are written
     * @return array{html: string, media: list<string>}
     */
    public function toHtml(string $path, string $format, string $mediaDir): array
    {
        return match ($format) {
            // "Science & maths" reader: no Pandoc, keeps Word equations, Symbol-font characters and sub/superscripts
            'sci-docx' => (fn ($r) => ['html' => self::markMathsInHtml($r['html']), 'media' => $r['media']])((new WordScienceReader)->read($path, $mediaDir)),
            'sci-txt', 'sci-md' => ['html' => $this->textToHtml(self::toUtf8(File::get($path))), 'media' => []],
            'sci-html', 'sci-htm' => ['html' => self::markMathsInHtml(self::toUtf8(File::get($path))), 'media' => []],
            'docx' => $this->viaPandoc($path, 'docx', $mediaDir),
            'md' => $this->pandocAvailable() ? $this->viaPandoc($path, 'markdown', $mediaDir) : ['html' => $this->textToHtml(File::get($path)), 'media' => []],
            'txt' => ['html' => $this->textToHtml(File::get($path)), 'media' => []],
            'html', 'htm' => ['html' => File::get($path), 'media' => []],
            default => throw new RuntimeException("Unsupported file type .$format"),
        };
    }

    private function viaPandoc(string $path, string $from, string $mediaDir): array
    {
        if (! $this->pandocAvailable()) {
            throw new RuntimeException('Pandoc is not installed on the server, so Word files cannot be read yet. Install it from pandoc.org and set PANDOC_PATH in .env.');
        }

        File::ensureDirectoryExists($mediaDir);
        $process = new Process([
            $this->pandoc, $path,
            '--from='.$from, '--to=html5',
            '--mathjax', '--wrap=none',
            '--extract-media='.$mediaDir,
        ]);
        $process->setTimeout(120)->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Could not read the document: '.trim($process->getErrorOutput()));
        }

        $media = is_dir($mediaDir) ? array_map(fn ($f) => $f->getPathname(), File::allFiles($mediaDir)) : [];

        // Setters often type LaTeX as ordinary text in Word ("$\\ce{H2O}$", "\\frac{1}{2}");
        // turn those into maths too, alongside Word's own equations.
        return ['html' => self::markMathsInHtml($process->getOutput()), 'media' => $media];
    }

    /** Apply markMaths() to text between tags, skipping text already inside pandoc maths spans. */
    public static function markMathsInHtml(string $html): string
    {
        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inMath = 0;
        $depth = 0;
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('/^<span\b[^>]*class="[^"]*\bmath\b/i', $part)) {
                    $inMath++;
                    $depth = 0;
                } elseif ($inMath && preg_match('/^<span\b/i', $part)) {
                    $depth++;
                } elseif ($inMath && preg_match('/^<\/span>/i', $part)) {
                    $depth === 0 ? $inMath-- : $depth--;
                }

                continue;
            }
            if (! $inMath) {
                $parts[$i] = self::markMaths($part);
            }
        }

        return implode('', $parts);
    }

    /**
     * Text saved by Notepad or Word as "Unicode" (UTF-16) or in the old Windows encoding turns
     * symbols such as ±, °, µ, ² into junk unless converted; this reads all of them as UTF-8.
     */
    public static function toUtf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /** Plain text: one paragraph per line; dollar maths normalised to \( \) and \[ \]. */
    public function textToHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $out[] = '<p>'.self::markMaths(htmlspecialchars(trim($line), ENT_QUOTES, 'UTF-8')).'</p>';
        }

        return implode("\n", $out);
    }

    /** Wrap $$…$$, $…$, \[…\], \(…\) in pandoc-style maths spans (input already HTML-escaped). */
    public static function markMaths(string $escaped): string
    {
        $placeholders = [];
        $keep = function (string $tex, bool $display) use (&$placeholders) {
            $key = "\u{E000}".count($placeholders)."\u{E001}";
            $placeholders[$key] = $display
                ? '<span class="math display">\['.$tex.'\]</span>'
                : '<span class="math inline">\('.$tex.'\)</span>';

            return $key;
        };
        $escaped = preg_replace_callback('/\$\$(.+?)\$\$/su', fn ($m) => $keep($m[1], true), $escaped);
        $escaped = preg_replace_callback('/\\\\\[(.+?)\\\\\]/su', fn ($m) => $keep($m[1], true), $escaped);
        $escaped = preg_replace_callback('/\\\\\((.+?)\\\\\)/su', fn ($m) => $keep($m[1], false), $escaped);
        $escaped = preg_replace_callback('/(?<![\\\\$])\$(?!\s)([^$\n]+?)(?<![\s\\\\])\$/u', fn ($m) => $keep($m[1], false), $escaped);
        // bare \ce{...} outside dollars (chemists often type it that way)
        $escaped = preg_replace_callback('/\\\\ce\{((?:[^{}]|\{[^{}]*\})*)\}/u', fn ($m) => $keep('\ce{'.$m[1].'}', false), $escaped);
        $escaped = str_replace('\$', '$', $escaped);

        return strtr($escaped, $placeholders);
    }
}
