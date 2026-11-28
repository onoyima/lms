<?php

namespace App\Services\Lms;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiSettings;

/**
 * Reads the words out of a student's hand-in so it can be checked: Word, PDF, PowerPoint,
 * OpenDocument, RTF, plain text, HTML, and photos or scans of written work. Runs on this server.
 * PDFs use pdftotext when it is installed and a built-in reader otherwise (so it works on Windows
 * too). Photos use Tesseract when installed, or the institution's AI when that is set up.
 */
class DocumentText
{
    private const MAX = 5_000_000;

    public function read(string $path, string $name): string
    {
        if (! is_file($path) || filesize($path) > 200_000_000) {
            return '';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            $text = match ($ext) {
                'docx', 'docm', 'dotx' => $this->zipXml($path, ['word/document.xml'], ['</w:p>', '<w:tab/>', '<w:br/>']),
                'pptx' => $this->zipXml($path, $this->zipNames($path, '#^ppt/slides/slide\d+\.xml$#'), ['</a:p>']),
                'odt', 'odp', 'ods' => $this->zipXml($path, ['content.xml'], ['</text:p>', '</text:h>']),
                'xlsx' => $this->zipXml($path, ['xl/sharedStrings.xml'], ['</si>']),
                'rtf' => $this->rtf((string) file_get_contents($path, false, null, 0, self::MAX)),
                'txt', 'md', 'csv', 'tex' => (string) file_get_contents($path, false, null, 0, self::MAX),
                'html', 'htm' => html_entity_decode(strip_tags((string) file_get_contents($path, false, null, 0, self::MAX)), ENT_QUOTES),
                'pdf' => $this->pdf($path),
                'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic' => $this->image($path, $ext),
                default => '',
            };
        } catch (\Throwable) {
            $text = '';
        }

        return trim(preg_replace('/[ \t]+/u', ' ', (string) $text));
    }

    public static function words(string $text): int
    {
        return count(preg_split('/[^\p{L}\p{N}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    private function zipNames(string $path, string $pattern): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }
        $names = [];
        for ($i = 0; $i < min($zip->numFiles, 2000); $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match($pattern, $n)) {
                $names[] = $n;
            }
        }
        $zip->close();
        natsort($names);

        return array_values($names);
    }

    private function zipXml(string $path, array $entries, array $breaks): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $out = '';
        foreach ($entries as $entry) {
            $stat = $zip->statName($entry);
            if (! $stat || $stat['size'] > 30_000_000) { // refuse zip bombs
                continue;
            }
            $xml = (string) $zip->getFromName($entry);
            $xml = str_replace($breaks, "\n", $xml);
            $out .= html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1)."\n";
        }
        $zip->close();

        return $out;
    }

    private function rtf(string $rtf): string
    {
        $rtf = preg_replace('/\{\\\\\*[^{}]*\}/', '', $rtf);
        $rtf = preg_replace("/\\\\'([0-9a-f]{2})/i", '', $rtf);
        $rtf = preg_replace('/\\\\par[d]?\b/', "\n", $rtf);
        $rtf = preg_replace('/\\\\[a-z]+-?\d* ?/i', '', $rtf);

        return str_replace(['{', '}', '\\'], '', $rtf);
    }

    private function pdf(string $path): string
    {
        $bin = PHP_OS_FAMILY === 'Windows' ? '' : trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin !== '') {
            $t = (string) @shell_exec(escapeshellarg($bin).' -q -l 200 '.escapeshellarg($path).' - 2>/dev/null');
            if (trim($t) !== '') {
                return $t;
            }
        }

        return (new PdfText)->read($path);
    }

    private function image(string $path, string $ext): string
    {
        $bin = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where tesseract 2>NUL' : 'command -v tesseract 2>/dev/null'));
        $bin = strtok($bin, "\r\n") ?: '';
        if ($bin !== '') {
            $t = (string) @shell_exec(escapeshellarg($bin).' '.escapeshellarg($path).' - 2>'.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
            if (DocumentText::words($t) >= 20) {
                return $t;
            }
        }
        if (AiSettings::enabled('check_writing') && filesize($path) <= 8_000_000 && in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext];
            $reply = AiClient::forTenant()->ask('Copy out the words written or printed in this image, exactly as written, as plain text. Reply with the text only. If there are no words, reply with nothing.',
                [['image' => (string) file_get_contents($path), 'mime' => $mime]], 4000);

            return $reply['text'];
        }

        return '';
    }
}
