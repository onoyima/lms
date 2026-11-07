<?php

namespace App\Http\Controllers;

use App\Models\Semester;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\AcademicRecord;
use App\Services\Reports\Exporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Downloadable, verifiable PDFs: result statements, transcripts, registration and exam slips. */
class DocumentController extends Controller
{
    public function __construct(private AcademicRecord $records, private Exporter $exporter, private AuditLogger $audit) {}

    /** A student's own documents, or any student's for Registry (academics.manage). */
    private function holder(Request $request, ?User $student): User
    {
        if ($student && $student->id !== $request->user()->id) {
            abort_unless($request->user()->can('academics.manage'), 403);

            return $student;
        }

        return $request->user();
    }

    public function resultStatement(Request $request, ?User $student = null)
    {
        $holder = $this->holder($request, $student);
        $semester = $request->integer('semester') ?: null;
        $record = $this->records->record($holder, $semester);
        abort_if(! $record['semesters'], 404, 'No released results yet.');
        $label = $semester ? (Semester::find($semester)?->name ?? '') : 'All semesters';
        $code = $this->records->issue($holder, 'result_statement', "Result statement · $label", $record, $request->user());
        $this->audit->record('document.issued', $holder, ['type' => 'result_statement', 'code' => $code]);

        return $this->exporter->pdf('result-statement-'.$code, 'pdf.academic', $this->data($holder, $record, 'Statement of results', $code));
    }

    public function transcript(Request $request, ?User $student = null)
    {
        $holder = $this->holder($request, $student);
        $record = $this->records->record($holder);
        abort_if(! $record['semesters'], 404, 'No released results yet.');
        $code = $this->records->issue($holder, 'transcript', 'Academic transcript · CGPA '.$record['cgpa'], $record, $request->user());
        $this->audit->record('document.issued', $holder, ['type' => 'transcript', 'code' => $code]);

        return $this->exporter->pdf('transcript-'.$code, 'pdf.academic', $this->data($holder, $record, 'Academic transcript', $code) + ['transcript' => true]);
    }

    public function registrationSlip(Request $request)
    {
        $user = $request->user();
        $semester = Semester::find($request->integer('semester')) ?? Semester::where('is_current', true)->firstOrFail();
        $rows = DB::table('enrolments as e')->join('course_offerings as o', 'o.id', '=', 'e.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
            ->where('e.user_id', $user->id)->where('o.semester_id', $semester->id)->whereIn('e.status', ['active', 'pending'])
            ->orderBy('c.code')->get(['c.code', 'c.title', 'c.credit_units as units', 'e.category', 'e.status', 'e.stream']);
        $code = $this->records->issue($user, 'registration_slip', 'Registration slip · '.$semester->name, $rows->all(), $user);

        return $this->exporter->pdf('registration-slip-'.$code, 'pdf.slip', $this->data($user, [], 'Course registration slip', $code) + [
            'semester' => trim(DB::table('academic_sessions')->where('id', $semester->academic_session_id)->value('name').' '.$semester->name), 'rows' => $rows,
        ]);
    }

    /** Exam slip (Target 46, 58): where and when, seat, rules; one per candidate, verifiable. */
    public function examSlip(Request $request, \App\Models\ExamCandidate $candidate)
    {
        abort_unless($candidate->user_id === $request->user()->id, 404);

        return $this->slips(new \Illuminate\Database\Eloquent\Collection([$candidate]), $request->user(), 'exam-slip');
    }

    /** All slips of a sitting in one PDF, for the exams office to print. */
    public function sessionSlips(Request $request, \App\Models\ExamSession $session)
    {
        $candidates = \App\Models\ExamCandidate::where('exam_session_id', $session->id)->where('eligibility', '!=', 'ineligible')->orderBy('id')->limit(3000)->get();
        abort_if($candidates->isEmpty(), 422, 'No eligible candidates in this sitting yet.');
        $this->audit->record('documents.exam_slips', $session, ['count' => $candidates->count()]);

        return $this->slips($candidates, $request->user(), 'exam-slips-'.$session->id);
    }

    private function slips($candidates, User $by, string $name)
    {
        $candidates->load(['user', 'seat:id,label', 'session.exam.course:id,code,title', 'session.venue:id,name,campus']);
        $slips = $candidates->map(function ($c) use ($by) {
            $s = $c->session;
            $content = ['exam' => $s->exam->title, 'starts_at' => $s->starts_at->toIso8601String(), 'venue' => $s->venue?->name, 'seat' => $c->seat?->label, 'candidate' => $c->user->identifier];
            $code = $this->records->issue($c->user, 'exam_slip', 'Exam slip · '.$s->exam->title.' · '.$s->starts_at->format('j M Y H:i'), $content, $by);

            return ['candidate' => $c, 'session' => $s, 'exam' => $s->exam, 'code' => $code, 'verifyUrl' => url('/verify/'.$code),
                'minutes' => $s->exam->duration_minutes + $c->extra_time_minutes];
        });

        return $this->exporter->pdf($name, 'pdf.exam-slip', ['title' => 'Examination slip', 'slips' => $slips, 'footer' => 'Bring this slip and your ID card. Verify any slip at '.url('/verify')]);
    }

    /** Public check of a document code (rate-limited; reveals only what is printed on the document). */
    public function verify(string $code): Response
    {
        $doc = DB::table('issued_documents')->join('users', 'users.id', '=', 'issued_documents.user_id')->join('tenants', 'tenants.id', '=', 'issued_documents.tenant_id')
            ->where('code', strtoupper($code))->first(['issued_documents.*', 'users.name', 'users.identifier', 'tenants.name as institution']);

        return Inertia::render('verify', ['doc' => $doc ? [
            'type' => str_replace('_', ' ', $doc->type), 'summary' => $doc->summary, 'holder' => $doc->name, 'identifier' => $doc->identifier,
            'institution' => $doc->institution, 'issued_at' => $doc->created_at, 'revoked' => (bool) $doc->revoked_at, 'hash' => $doc->content_hash,
        ] : null, 'code' => strtoupper($code)]);
    }

    private function data(User $holder, array $record, string $title, string $code): array
    {
        $profile = DB::table('student_profiles')->leftJoin('programmes', 'programmes.id', '=', 'student_profiles.programme_id')->where('user_id', $holder->id)
            ->first(['programmes.name as programme', 'student_profiles.level', 'student_profiles.entry_year', 'student_profiles.academic_status']);

        return ['title' => $title, 'holder' => $holder, 'profile' => $profile, 'record' => $record, 'code' => $code,
            'verifyUrl' => url('/verify/'.$code), 'footer' => "Verify at ".url('/verify/'.$code)];
    }
}
