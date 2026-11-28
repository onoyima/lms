<?php

namespace App\Services\Lms;

use App\Models\AssignmentSubmission;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Plain text from the typed answer and the attached file (see DocumentText). The text of a file
     * is kept in the cache by its hash, so a hand-in is read once and not again for every comparison.
     * Peers' photos are not sent to the AI again: only their cached text is used.
     */
    public function text(AssignmentSubmission $s, bool $peer = false): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $s->text)), ENT_QUOTES);
        if ($s->file_path && Storage::disk('local')->exists($s->file_path)) {
            $key = 'subtext:'.($s->file_hash ?: md5($s->file_path));
            $file = Cache::get($key);
            if ($file === null && ! ($peer && preg_match('/\.(jpe?g|png|webp|gif|bmp|tiff?|heic)$/i', (string) $s->file_name))) {
                $file = app(DocumentText::class)->read(Storage::disk('local')->path($s->file_path), (string) $s->file_name);
                Cache::put($key, $file, now()->addDays(60));
            }
            $text .= "\n".(string) $file;
        }

        return trim($text);
    }

    /**
     * Every check for one submission: reads its text, scores it for AI writing, and compares it
     * with other students' work. Runs after the student's upload has been answered.
     */
    public function run(AssignmentSubmission $s): void
    {
        $text = $this->text($s);
        $words = DocumentText::words($text);
        $status = $words === 0 ? ($s->file_path ? 'unreadable' : 'short') : ($words < WritingCheck::MIN_WORDS ? 'short' : 'read');
        $s->forceFill(['text_status' => $status, 'word_count' => $words])->save();
        app(WritingCheck::class)->check($s, $text);
        $this->check($s, $text);
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

    public function check(AssignmentSubmission $s, ?string $text = null): ?float
    {
        $mine = $this->fingerprints($text ?? $this->text($s));
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
                    $pct = self::overlap($mine, $this->fingerprints($this->text($o, true)));
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
}
