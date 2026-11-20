<?php

namespace App\Services\Documents;

use App\Models\CertificateTemplate;
use App\Models\Exam;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Awards certificates and badges. An exam with a certificate template awards it, when results are
 * released, to every candidate whose final score reaches the template's pass percentage. Each award
 * is an issued document with a code anyone can check at /verify/{code}; it can be revoked.
 */
class Certificates
{
    public function __construct(private AcademicRecord $records) {}

    /** @return int awards made */
    public function awardForExam(Exam $exam, ?User $by = null): int
    {
        $template = $exam->certificate_template_id ? CertificateTemplate::find($exam->certificate_template_id) : null;
        if (! $template) {
            return 0;
        }
        $out = (float) ($exam->total_mark ?: 100);
        $n = 0;
        foreach (Result::with('user')->where('exam_id', $exam->id)->where('status', 'published')->whereNotNull('final_score')->get() as $r) {
            $pct = $out > 0 ? round((float) $r->final_score / $out * 100, 2) : 0;
            if ($pct + 1e-9 < $template->min_percent || ! $r->user) {
                continue;
            }
            $n += $this->award($template, $r->user, 'exam', $exam->id, $exam->title, $pct, $r->grade, $by) ? 1 : 0;
        }

        return $n;
    }

    /** One award (once per person, template and subject). @return bool newly awarded */
    public function award(CertificateTemplate $t, User $user, string $subjectType, int $subjectId, string $title, ?float $percent, ?string $grade, ?User $by = null): bool
    {
        if (DB::table('certificate_awards')->where(['certificate_template_id' => $t->id, 'user_id' => $user->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId])->exists()) {
            return false;
        }
        $code = $this->records->issue($user, $t->kind === 'badge' ? 'badge' : 'certificate', ($t->kind === 'badge' ? 'Badge' : 'Certificate').' · '.$t->name.' · '.$title,
            ['template' => $t->id, 'subject' => [$subjectType, $subjectId], 'percent' => $percent, 'grade' => $grade], $by);
        DB::table('certificate_awards')->insert([
            'tenant_id' => $user->tenant_id ?? tenant_id(), 'certificate_template_id' => $t->id, 'user_id' => $user->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'subject_title' => mb_substr($title, 0, 255), 'percent' => $percent, 'grade' => $grade, 'code' => $code, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    /** The certificate's text with the person's details filled in. */
    public static function fill(string $text, array $award, User $user): string
    {
        return strtr($text, [
            '{name}' => $user->name, '{exam}' => $award['subject_title'], '{course}' => $award['subject_title'],
            '{score}' => $award['percent'] !== null ? rtrim(rtrim(number_format((float) $award['percent'], 1), '0'), '.').'%' : '',
            '{grade}' => (string) ($award['grade'] ?? ''), '{date}' => \Illuminate\Support\Carbon::parse($award['created_at'])->format('j F Y'),
            '{institution}' => tenant()?->name ?? '',
        ]);
    }
}
