<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\AccommodationProfile;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\ProctorAssignment;
use App\Models\Seat;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\ExamScheduled;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\Eligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sittings, candidate lists, eligibility, seats, PINs, attendance and proctor assignment
 * (Target 44-48, 55-58, 63, 77-80, 83, 88-90, 96, 117, 181, 199-200).
 */
class SessionController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, Exam $exam): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'mode' => ['required', 'in:hall,remote'],
            'venue_id' => ['nullable', Rule::exists('venues', 'id')->where('tenant_id', tenant_id())],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'late_entry_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'grace_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
            'pin' => ['nullable', 'string', 'min:4', 'max:20'],
            'is_practice' => ['boolean'],
        ]);
        if ($w = \App\Support\Maintenance::blocksExamsAt(\Carbon\Carbon::parse($data['starts_at']), \Carbon\Carbon::parse($data['ends_at']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['starts_at' => 'This time overlaps planned maintenance ('.\Carbon\Carbon::parse($w['starts_at'])->format('D j M H:i').'–'.\Carbon\Carbon::parse($w['ends_at'])->format('H:i').').']);
        }
        $session = $exam->sessions()->create([
            'name' => $data['name'] ?? null, 'mode' => $data['mode'], 'venue_id' => $data['venue_id'] ?? null,
            'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
            'late_entry_minutes' => $data['late_entry_minutes'] ?? 15, 'grace_seconds' => $data['grace_seconds'] ?? 30,
            'access_pin_hash' => ! empty($data['pin']) ? Hash::make($data['pin']) : null,
            'is_practice' => (bool) ($data['is_practice'] ?? false),
        ]);
        $this->audit->record('session.created', $session, ['starts_at' => $session->starts_at->toIso8601String()]);

        return redirect()->route('sessions.show', $session)->with('success', 'Sitting scheduled. Add candidates next.');
    }

    /** Move a sitting (time, room, late entry). Candidates are told; a sitting that has started cannot move. */
    public function update(Request $request, ExamSession $session): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'venue_id' => ['nullable', Rule::exists('venues', 'id')->where('tenant_id', tenant_id())],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'late_entry_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'notify' => ['boolean'],
        ]);
        $started = \App\Models\Attempt::whereIn('exam_candidate_id', ExamCandidate::where('exam_session_id', $session->id)->select('id'))->exists();
        abort_if($started, 409, 'Candidates have already started this sitting, so it cannot be moved. Schedule another sitting instead.');
        if ($w = \App\Support\Maintenance::blocksExamsAt(\Carbon\Carbon::parse($data['starts_at']), \Carbon\Carbon::parse($data['ends_at']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['starts_at' => 'This time overlaps planned maintenance ('.\Carbon\Carbon::parse($w['starts_at'])->format('D j M H:i').'–'.\Carbon\Carbon::parse($w['ends_at'])->format('H:i').').']);
        }
        $session->loadMissing('venue', 'exam');
        $was = $session->starts_at->format('D j M, H:i').($session->venue ? ' at '.$session->venue->name : ' (online)');
        $before = ['starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'venue_id' => $session->venue_id];
        $session->update(['name' => $data['name'] ?? $session->name, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
            'venue_id' => $session->mode === 'hall' ? ($data['venue_id'] ?? $session->venue_id) : null, 'late_entry_minutes' => $data['late_entry_minutes'] ?? $session->late_entry_minutes]);
        $moved = $session->wasChanged(['starts_at', 'ends_at', 'venue_id']);
        if ($session->wasChanged('venue_id')) {
            ExamCandidate::where('exam_session_id', $session->id)->update(['seat_id' => null]); // seats belong to the old room
        }
        $told = 0;
        if ($moved && ($data['notify'] ?? true)) {
            $users = \App\Models\User::whereIn('id', ExamCandidate::where('exam_session_id', $session->id)->select('user_id'))->get();
            \Illuminate\Support\Facades\Notification::send($users, new \App\Notifications\ExamRescheduled($session->fresh(['venue', 'exam']), $was));
            $told = $users->count();
        }
        $this->audit->record('session.rescheduled', $session, ['before' => $before, 'after' => ['starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'venue_id' => $session->venue_id], 'notified' => $told]);

        return back()->with('success', $moved ? "Sitting moved. {$told} candidate(s) told." : 'Saved.');
    }

    public function show(ExamSession $session): Response
    {
        $session->load(['exam:id,title,type,course_id,semester_id,status', 'venue', 'proctors.user:id,name,email']);
        $candidates = ExamCandidate::with(['user:id,name,identifier,email,status', 'seat:id,label'])
            ->where('exam_session_id', $session->id)->orderBy('id')->paginate(100)
            ->through(fn (ExamCandidate $c) => [
                'id' => $c->id, 'name' => $c->user?->name, 'identifier' => $c->user?->identifier, 'status' => $c->user?->status,
                'eligibility' => $c->eligibility, 'reason' => $c->eligibility_reason, 'extra_time' => $c->extra_time_minutes,
                'attendance' => $c->attendance, 'seat' => $c->seat?->label, 'checked_in' => (bool) $c->checked_in_at,
                'has_pin' => (bool) $c->pin_hash, 'accommodations' => $c->accommodations,
            ]);

        return Inertia::render('exams/session', [
            'session' => [
                ...$session->only(['id', 'name', 'mode', 'status', 'late_entry_minutes', 'grace_seconds', 'is_practice']),
                'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(),
                'venue' => $session->venue?->only(['id', 'name', 'capacity']), 'has_pin' => (bool) $session->access_pin_hash,
                'started' => \App\Models\Attempt::whereIn('exam_candidate_id', ExamCandidate::where('exam_session_id', $session->id)->select('id'))->exists(),
                'exam' => $session->exam->only(['id', 'title', 'type', 'status', 'kind', 'parent_exam_id']),
                'proctors' => $session->proctors->map(fn ($p) => ['id' => $p->id, 'user' => $p->user->only(['id', 'name']), 'role' => $p->role, 'max_candidates' => $p->max_candidates]),
            ],
            'candidates' => $candidates,
            'clashes' => app(\App\Services\Exams\TimetableClashes::class)->forSession($session),
            'venues' => Venue::orderBy('name')->get(['id', 'name', 'capacity']),
            'staff' => User::where('user_type', 'staff')->orderBy('name')->limit(500)->get(['id', 'name']),
        ]);
    }

    /**
     * Add candidates by matric numbers / emails (one per line or comma separated), or
     * everyone enrolled in the exam's course this semester. Eligibility is checked on entry.
     */
    public function addCandidates(Request $request, ExamSession $session, \App\Services\Exams\CandidateRoster $roster): RedirectResponse
    {
        $data = $request->validate([
            'identifiers' => ['nullable', 'string', 'max:200000'],
            'from_enrolment' => ['boolean'],
            'from_parent' => ['boolean'],
        ]);

        return back()->with('success', $roster::message($roster->add($session, $data)));
    }

    /** Re-check eligibility (attendance, registration, status) for everyone not given an override. */
    public function recheck(ExamSession $session, Eligibility $eligibility): RedirectResponse
    {
        $candidates = ExamCandidate::where('exam_session_id', $session->id)->where('eligibility', '!=', 'override')->get();
        $verdicts = $eligibility->evaluate($session->exam, User::whereIn('id', $candidates->pluck('user_id'))->get());
        $changed = 0;
        foreach ($candidates as $c) {
            [$e, $r] = $verdicts[$c->user_id] ?? ['ineligible', 'Account not found'];
            if ($c->eligibility !== $e || $c->eligibility_reason !== $r) {
                $c->update(['eligibility' => $e, 'eligibility_reason' => $r]);
                $changed++;
            }
        }
        $this->audit->record('session.eligibility_rechecked', $session, ['changed' => $changed]);

        return back()->with('success', "Eligibility re-checked: $changed changed.");
    }

    public function updateCandidate(Request $request, ExamSession $session, ExamCandidate $candidate): RedirectResponse
    {
        abort_unless($candidate->exam_session_id === $session->id, 404);
        $data = $request->validate([
            'eligibility' => ['sometimes', 'in:eligible,ineligible,override'],
            'eligibility_reason' => ['nullable', 'string', 'max:255'],
            'extra_time_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'accommodations' => ['nullable', 'array'],
            'attendance' => ['sometimes', 'in:expected,present,absent,excused'],
            'seat_id' => ['nullable', Rule::exists('seats', 'id')],
        ]);
        $candidate->update($data);
        $this->audit->record('candidate.updated', $candidate, $data);

        return back();
    }

    /** Check-in at the hall door (Target 46, 91, 96) — invigilators, by matric number or list. */
    public function checkIn(Request $request, ExamSession $session, ExamCandidate $candidate): RedirectResponse
    {
        abort_unless($candidate->exam_session_id === $session->id, 404);
        $candidate->update(['checked_in_at' => now(), 'checked_in_by' => $request->user()->id, 'attendance' => 'present']);
        $this->audit->record('candidate.checked_in', $candidate);

        return back();
    }

    /** Give each candidate a one-time PIN and download the exam slips (Target 57, 90). */
    public function generatePins(ExamSession $session): StreamedResponse
    {
        $rows = [];
        DB::transaction(function () use ($session, &$rows) {
            ExamCandidate::with('user:id,name,identifier', 'seat:id,label')->where('exam_session_id', $session->id)->chunkById(500, function ($chunk) use (&$rows) {
                foreach ($chunk as $c) {
                    $pin = (string) random_int(100000, 999999);
                    $c->forceFill(['pin_hash' => Hash::make($pin, ['rounds' => 8])])->save();
                    $rows[] = [$c->user?->identifier, $c->user?->name, $c->seat?->label, $pin];
                }
            });
        });
        $this->audit->record('session.pins_generated', $session, ['count' => count($rows)]);

        return response()->streamDownload(function () use ($rows, $session) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Exam', $session->exam->title, 'Starts', $session->starts_at->toDateTimeString()]);
            fputcsv($out, ['Matric number', 'Name', 'Seat', 'PIN']);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, "exam-slips-{$session->id}.csv", ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    /** Assign seats in order (Target 47, 79, 89). */
    public function allocateSeats(ExamSession $session): RedirectResponse
    {
        abort_unless($session->venue_id, 422, 'Choose a venue first.');
        $seats = Seat::where('venue_id', $session->venue_id)->orderBy('label')->pluck('id');
        $candidates = ExamCandidate::where('exam_session_id', $session->id)->where('eligibility', '!=', 'ineligible')->orderBy('id')->get();
        abort_if($candidates->count() > $seats->count(), 422, "Not enough seats: {$candidates->count()} candidates, {$seats->count()} seats.");
        foreach ($candidates->values() as $i => $c) {
            $c->update(['seat_id' => $seats[$i]]);
        }

        return back()->with('success', 'Seats allocated.');
    }

    public function assignProctor(Request $request, ExamSession $session): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', tenant_id())],
            'role' => ['required', 'in:chief,invigilator,proctor,supervisor'],
            'max_candidates' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        ProctorAssignment::updateOrCreate(['exam_session_id' => $session->id, 'user_id' => $data['user_id']], ['role' => $data['role'], 'max_candidates' => $data['max_candidates'] ?? 30]);
        $this->balance($session);

        return back();
    }

    /** Spread candidates across proctors by capacity (Target 77, 79, 117). Called again on reassignment. */
    public function balance(ExamSession $session): void
    {
        $proctors = ProctorAssignment::where('exam_session_id', $session->id)->whereIn('role', ['proctor', 'invigilator'])->get();
        if ($proctors->isEmpty()) {
            return;
        }
        $candidateIds = ExamCandidate::where('exam_session_id', $session->id)->orderBy('id')->pluck('id');
        DB::table('proctor_candidate')->whereIn('proctor_assignment_id', ProctorAssignment::where('exam_session_id', $session->id)->pluck('id'))->delete();
        $rows = [];
        $p = 0;
        $load = array_fill(0, $proctors->count(), 0);
        foreach ($candidateIds as $cid) {
            for ($tries = 0; $tries < $proctors->count() && $load[$p] >= $proctors[$p]->max_candidates; $tries++) {
                $p = ($p + 1) % $proctors->count();
            }
            $rows[] = ['proctor_assignment_id' => $proctors[$p]->id, 'exam_candidate_id' => $cid];
            $load[$p]++;
            $p = ($p + 1) % $proctors->count();
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('proctor_candidate')->insert($chunk);
        }
    }

    public function removeProctor(ExamSession $session, ProctorAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->exam_session_id === $session->id, 404);
        $assignment->delete();
        $this->balance($session); // reassign their candidates (Target 79)

        return back();
    }
}
