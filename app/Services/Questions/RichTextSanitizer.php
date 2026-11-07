<?php

namespace App\Services\Questions;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans question HTML before it is stored or shown to candidates.
 * Keeps formatting, tables, images and maths (LaTeX inside \( \) / \[ \] spans);
 * removes scripts, event handlers, styles and remote/javascript links.
 */
class RichTextSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowAttribute('class', ['span', 'div', 'p'])
            ->allowAttribute('colspan', ['td', 'th'])
            ->allowAttribute('rowspan', ['td', 'th'])
            ->allowRelativeMedias()
            ->allowMediaSchemes(['https', 'data'])
            ->allowLinkSchemes(['https'])
            ->forceHttpsUrls()
            ->withMaxInputLength(2_000_000);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $clean = $this->sanitizer->sanitize($html);

        // Only keep the maths classes pandoc uses; drop any other class names.
        return preg_replace_callback('/\sclass="([^"]*)"/', function ($m) {
            $keep = array_intersect(preg_split('/\s+/', $m[1]), ['math', 'inline', 'display']);

            return $keep ? ' class="'.implode(' ', $keep).'"' : '';
        }, $clean) ?? '';
    }
}
