<?php

namespace App\Services\Lms;

use App\Models\AssignmentSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Similarity check for assignment submissions (LMS 66): compares each submission's text with
 * every other submission to the same assignment and to earlier offerings of the same course,
 * using hashed 5-word sequences (winnowed fingerprints). Runs on this server only — no work is
 * sent to an outside service. A high score is a prompt for a person to read both, not a verdict.
 */
class Similarity
{
    private const K = 5;      // words per sequence
    private const WINDOW = 4; // winnowing window

    /** Plain text from the typed answer and the attached file (docx, txt, md, pdf when pdftotext exists). */
    public function text(AssignmentSubmission $s): string
    {
        $text = strip_tags((string) $s->text);
        if ($s->file_path && Storage::disk('local')->exists($s->file_path)) {
            $path = Storage::disk('local')->path($s->file_path);
            $ext = strtolower(pathinfo((string) $s->file_name, PATHINFO_EXTENSION));
            $text .= ' '.match ($ext) {
                'docx' => $this->docx($path),
                'txt', 'md' => (string) file_get_contents($path, false, null, 0, 5_000_000),
                'pdf' => $this->pdf($path),
                default => '',
            };
        }

        return $text;
    }

    /** @return array<int, true> fingerprint hashes */
    public function fingerprints(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < self::K) {
            return [];
        }
        $hashes = [];
        for ($i = 0; $i <= count($words) - self::K; $i++) {
            $hashes[] = crc32(implode(' ', array_slice($words, $i, self::K)));
        }
        $prints = [];
        for ($i = 0; $i <= max(0, count($hashes) - self::WINDOW); $i++) {
            $prints[min(array_slice($hashes, $i, self::WINDOW))] = true;
        }

        return $prints;
    }

    /** Share of A's fingerprints also found in B, as a percentage. */
    public static function overlap(array $a, array $b): float
    {
        return $a ? round(count(array_intersect_key($a, $b)) / count($a) * 100, 2) : 0.0;
    }

    public function check(AssignmentSubmission $s): ?float
    {
        $mine = $this->fingerprints($this->text($s));
        if (count($mine) < 10) { // too short to judge
            $s->forceFill(['similarity' => null, 'similarity_matches' => null, 'similarity_checked_at' => now()])->save();

            return null;
        }
        $item = DB::table('course_items')->where('id', $s->course_item_id)->first(['course_offering_id']);
        $courseId = DB::table('course_offerings')->where('id', $item->course_offering_id)->value('course_id');
        $peerItems = DB::table('course_items as i')->join('course_offerings as o', 'o.id', '=', 'i.course_offering_id')
            ->where('o.course_id', $courseId)->where('i.type', 'assignment')->pluck('i.id');

        $matches = [];
        AssignmentSubmission::whereIn('course_item_id', $peerItems)->where('id', '!=', $s->id)->where('user_id', '!=', $s->user_id)
            ->whereIn('status', ['submitted', 'marked', 'returned'])
            ->when($s->course_group_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('course_group_id')->orWhere('course_group_id', '!=', $s->course_group_id)))
            ->orderBy('id')->chunk(200, function ($others) use ($mine, &$matches) {
                foreach ($others as $o) {
                    $pct = self::overlap($mine, $this->fingerprints($this->text($o)));
                    if ($pct >= 15) {
                        $matches[] = ['submission_id' => $o->id, 'user_id' => $o->user_id, 'item_id' => $o->course_item_id, 'percent' => $pct];
                    }
                }
            });
        usort($matches, fn ($a, $b) => $b['percent'] <=> $a['percent']);
        $top = $matches[0]['percent'] ?? 0.0;
        $s->forceFill(['similarity' => $top, 'similarity_matches' => array_slice($matches, 0, 10), 'similarity_checked_at' => now()])->save();

        return $top;
    }

    private function docx(string $path): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        if (strlen($xml) > 20_000_000) {
            return '';
        }

        return html_entity_decode(strip_tags(str_replace(['</w:p>', '<w:tab/>'], ["\n", ' '], $xml)), ENT_QUOTES | ENT_XML1);
    }

    private function pdf(string $path): string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            return '';
        }

        return (string) @shell_exec(escapeshellarg($bin).' -q -l 200 '.escapeshellarg($path).' - 2>/dev/null');
    }
}
