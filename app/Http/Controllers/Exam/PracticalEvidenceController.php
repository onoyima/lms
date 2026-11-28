<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\PracticalEvidence;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Work handed in for a practical: candidates upload what they made (code, photos of a circuit, a lab
 * report) while their sitting is open and for two hours after; examiners add photos of the work.
 * Examiners see everything on the score sheet.
 */
class PracticalEvidenceController extends Controller
{
    private const INLINE = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'txt' => 'text/plain', 'md' => 'text/plain'];

    public function __construct(private AuditLogger $audit) {}

    /** A candidate hands in work for their practical. */
    public function candidateUpload(Request $request, ExamCandidate $candidate): RedirectResponse
    {
        abort_unless($candidate->user_id === $request->user()->id, 403);
        $candidate->load('session.exam');
        $exam = $candidate->session->exam;
        $p = (array) data_get($exam->settings, 'practical', []);
        abort_unless($exam->type === 'practical' && ! empty($p['evidence']), 404);
        $s = $candidate->session;
        abort_unless(now()->gte($s->starts_at->copy()->subMinutes(15)) && now()->lte($s->ends_at->copy()->addHours(2)), 422,
            'Work can be handed in from the start of your practical until two hours after it ends.');
        $types = array_map('strtolower', $p['evidence_types'] ?? []);
        $request->validate(['file' => ['required', 'file', 'max:51200'], 'note' => ['nullable', 'string', 'max:500']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        abort_if($types && ! in_array($ext, $types, true), 422, 'This kind of file is not accepted for this practical ('.implode(', ', $types).').');
        abort_if(PracticalEvidence::where('exam_id', $exam->id)->where('user_id', $candidate->user_id)->where('kind', 'candidate')->count() >= 20, 422, 'At most 20 files can be handed in.');

        $e = $this->store($exam, $candidate->user_id, $request->user()->id, 'candidate', $file, $request->input('note'));
        $this->audit->record('practical.work_handed_in', $exam, ['evidence' => $e->id, 'name' => $e->file_name]);

        return back()->with('success', $e->file_name.' handed in.');
    }

    /** An examiner adds a photo (or file) of a candidate's work to the score sheet. */
    public function examinerUpload(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExaminer($request, $exam);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:30720'], 'note' => ['nullable', 'string', 'max:500']]);
        abort_unless(ExamCandidate::whereIn('exam_session_id', $exam->sessions()->pluck('id'))->where('user_id', $data['user_id'])->exists(), 404);
        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        abort_unless(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'mp4', 'webm', 'mov'], true), 422, 'Add a photo, a short video or a PDF.');
        $e = $this->store($exam, (int) $data['user_id'], $request->user()->id, 'examiner', $request->file('file'), $data['note'] ?? null);
        $this->audit->record('practical.photo_added', $exam, ['evidence' => $e->id, 'candidate' => $data['user_id']]);

        return back()->with('success', 'Added to the score sheet.');
    }

    /** Open a file: the candidate it belongs to, the exam's examiners and the exams office. */
    public function show(Request $request, PracticalEvidence $evidence)
    {
        $user = $request->user();
        $exam = $evidence->exam;
        abort_unless($evidence->user_id === $user->id || $user->can('exams.manage') || $exam->examiners()->where('users.id', $user->id)->exists(), 403);
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);
        $ext = strtolower(pathinfo($evidence->file_name, PATHINFO_EXTENSION));

        if (isset(self::INLINE[$ext]) && ! $request->boolean('download')) {
            return response()->file(Storage::disk('local')->path($evidence->file_path), ['Content-Type' => self::INLINE[$ext], 'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline; filename="'.addcslashes($evidence->file_name, '"\\').'"', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'", 'Cache-Control' => 'private, max-age=300']);
        }

        return Storage::disk('local')->download($evidence->file_path, $evidence->file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /** A candidate takes back a file they handed in, while handing in is still open. */
    public function destroy(Request $request, PracticalEvidence $evidence): RedirectResponse
    {
        abort_unless($evidence->user_id === $request->user()->id && $evidence->kind === 'candidate', 403);
        $session = DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')
            ->where('c.user_id', $evidence->user_id)->where('s.exam_id', $evidence->exam_id)->orderByDesc('s.ends_at')->first(['s.ends_at']);
        abort_if(! $session || now()->gt(\Illuminate\Support\Carbon::parse($session->ends_at)->addHours(2)), 422, 'Handing in has closed.');
        Storage::disk('local')->delete($evidence->file_path);
        $evidence->delete();

        return back()->with('success', 'Removed.');
    }

    private function store(Exam $exam, int $userId, int $by, string $kind, UploadedFile $file, ?string $note): PracticalEvidence
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs("practicals/{$exam->tenant_id}/{$exam->id}/{$userId}", Str::uuid().'.'.$ext, 'local');

        return PracticalEvidence::create(['exam_id' => $exam->id, 'user_id' => $userId, 'uploaded_by' => $by, 'kind' => $kind, 'file_path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 190, ''), 'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize(), 'note' => $note]);
    }

    private function authorizeExaminer(Request $request, Exam $exam): void
    {
        $user = $request->user();
        abort_unless($user->can('exams.manage') || $exam->examiners()->where('users.id', $user->id)->exists(), 403, 'You are not an examiner for this exam.');
    }
}
