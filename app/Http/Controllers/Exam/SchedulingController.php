<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\ProctorAssignment;
use App\Models\User;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\TimetableClashes;
use App\Services\Scheduling\ProctorRoster;
use App\Services\Scheduling\TimetableGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exam calendar, automatic timetabling and bulk scheduling (Target 48, 77-79, 200; LMS 77).
 */
class SchedulingController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function calendar(Request $request, TimetableClashes $clashes): Response
    {
        $month = CarbonImmutable::parse($request->query('month', now()->format('Y-m')).'-01')->startOfMonth();
        $from = $month->startOfWeek();
        $to = $month->endOfMonth()->endOfWeek();

        $sessions = ExamSession::query()->join('exams as e', 'e.id', '=', 'exam_sessions.exam_id')->leftJoin('venues as v', 'v.id', '=', 'exam_sessions.venue_id')
            ->whereBetween('exam_sessions.starts_at', [$from, $to])
            ->when($request->integer('venue_id'), fn ($q, $v) => $q->where('exam_sessions.venue_id', $v))
            ->orderBy('exam_sessions.starts_at')
            ->get(['exam_sessions.id', 'exam_sessions.exam_id', 'e.title', 'e.kind', 'exam_sessions.name', 'exam_sessions.mode', 'exam_sessions.status', 'exam_sessions.is_practice',
                'exam_sessions.starts_at', 'exam_sessions.ends_at', 'v.name as venue',
                DB::raw('(SELECT COUNT(*) FROM exam_candidates c WHERE c.exam_session_id = exam_sessions.id) as candidates'),
                DB::raw('(SELECT COUNT(*) FROM proctor_assignments p WHERE p.exam_session_id = exam_sessions.id) as proctors'),
                DB::raw('(SELECT COUNT(*) FROM attempts a WHERE a.exam_session_id = exam_sessions.id) as attempts')]);

        $found = $clashes->between($from->toDateTimeString(), $to->toDateTimeString());
        $clashIds = collect($found['candidates'])->flatMap(fn ($c) => [$c->session_a, $c->session_b])
            ->merge(collect($found['venues'])->flatMap(fn ($c) => [$c->session_a, $c->session_b]))->unique()->values();

        $run = $request->integer('run') ? DB::table('timetable_runs')->where('tenant_id', tenant_id())->where('id', $request->integer('run'))->first() : null;

        return Inertia::render('exams/calendar', [
            'month' => $month->format('Y-m'),
            'sessions' => $sessions->map(fn ($s) => [
                'id' => $s->id, 'exam_id' => $s->exam_id, 'title' => $s->title, 'kind' => $s->kind, 'name' => $s->name, 'mode' => $s->mode, 'status' => $s->status,
                'practice' => (bool) $s->is_practice, 'venue' => $s->venue, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'candidates' => (int) $s->candidates, 'proctors' => (int) $s->proctors, 'attempts' => (int) $s->attempts, 'clash' => $clashIds->contains($s->id),
            ]),
            'clashes' => ['candidates' => collect($found['candidates'])->sum('candidates'), 'venues' => count($found['venues'])],
            'venues' => Venue::orderBy('name')->get(['id', 'name', 'capacity']),
            'exams' => Exam::whereIn('status', ['approved', 'scheduled', 'draft', 'in_review'])->whereNotIn('category', ['quiz', 'practice'])
                ->with('course:id,code')->orderBy('title')->get(['id', 'title', 'status', 'kind', 'duration_minutes', 'course_id', 'semester_id'])
                ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'status' => $e->status, 'course' => $e->course?->code, 'duration' => $e->duration_minutes,
                    'scheduled' => DB::table('exam_sessions')->where('exam_id', $e->id)->where('is_practice', false)->exists(), 'ready' => (bool) ($e->course_id && $e->semester_id)]),
            'run' => $run ? ['id' => $run->id, 'status' => $run->status, 'proposal' => json_decode($run->proposal, true), 'unplaced' => json_decode($run->unplaced ?? '[]', true), 'params' => json_decode($run->params, true)] : null,
            'filters' => $request->only('venue_id'),
        ]);
    }

    public function generate(Request $request, TimetableGenerator $generator): RedirectResponse
    {
        $data = $request->validate([
            'exam_ids' => ['required', 'array', 'min:1', 'max:500'], 'exam_ids.*' => ['integer', Rule::exists('exams', 'id')->where('tenant_id', tenant_id())],
            'from' => ['required', 'date', 'after_or_equal:today'], 'to' => ['required', 'date', 'after_or_equal:from', 'before:'.now()->addYear()->toDateString()],
            'slots' => ['required', 'array', 'min:1', 'max:6'], 'slots.*' => ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'skip_weekends' => ['boolean'], 'mode' => ['required', 'in:hall,remote'],
            'venue_ids' => ['required_if:mode,hall', 'array'], 'venue_ids.*' => ['integer', Rule::exists('venues', 'id')->where('tenant_id', tenant_id())],
            'max_per_day' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);
        $result = $generator->generate($data);
        $id = DB::table('timetable_runs')->insertGetId([
            'tenant_id' => tenant_id(), 'created_by' => $request->user()->id, 'status' => 'draft', 'params' => json_encode($data),
            'proposal' => json_encode($result['proposal']), 'unplaced' => json_encode($result['unplaced']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('timetable.generated', null, ['run' => $id, 'placed' => count($result['proposal']), 'unplaced' => count($result['unplaced'])]);

        return redirect()->route('exams.calendar', ['month' => substr($data['from'], 0, 7), 'run' => $id])
            ->with('success', count($result['proposal']).' exams placed'.(count($result['unplaced']) ? ', '.count($result['unplaced']).' could not be placed' : '').'. Review, then confirm.');
    }

    public function commit(int $run, TimetableGenerator $generator): RedirectResponse
    {
        try {
            $n = $generator->commit($run);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->audit->record('timetable.committed', null, ['run' => $run, 'sittings' => $n]);

        return redirect()->route('exams.calendar')->with('success', "$n sittings created and candidates added. Check eligibility notes on each sitting.");
    }

    public function discard(int $run): RedirectResponse
    {
        DB::table('timetable_runs')->where('tenant_id', tenant_id())->where('id', $run)->where('status', 'draft')->update(['status' => 'discarded', 'updated_at' => now()]);

        return redirect()->route('exams.calendar')->with('success', 'Proposal discarded.');
    }

    /** Bulk changes to many sittings at once: move, change venue, or cancel unused sittings. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', 'in:shift,venue,cancel,late_entry'],
            'minutes' => ['required_if:action,shift', 'nullable', 'integer', 'between:-20160,20160'],
            'venue_id' => ['required_if:action,venue', 'nullable', 'integer', Rule::exists('venues', 'id')->where('tenant_id', tenant_id())],
            'late_entry_minutes' => ['required_if:action,late_entry', 'nullable', 'integer', 'between:0,240'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $sessions = ExamSession::whereIn('id', $data['ids'])->withCount('attempts')->get();
        $movable = $sessions->filter(fn ($s) => $s->attempts_count === 0 && $s->status === 'scheduled');
        $affected = User::whereIn('id', DB::table('exam_candidates')->whereIn('exam_session_id', $movable->pluck('id'))->pluck('user_id'))->get();
        $skipped = 0;
        DB::transaction(function () use ($sessions, $data, &$skipped) {
            foreach ($sessions as $s) {
                // sittings that have started are never moved or cancelled from here
                if ($s->attempts_count > 0 || $s->status !== 'scheduled') {
                    $skipped++;

                    continue;
                }
                match ($data['action']) {
                    'shift' => $s->update(['starts_at' => $s->starts_at->addMinutes($data['minutes']), 'ends_at' => $s->ends_at->addMinutes($data['minutes'])]),
                    'venue' => $s->update(['venue_id' => $data['venue_id'], 'mode' => 'hall']),
                    'late_entry' => $s->update(['late_entry_minutes' => $data['late_entry_minutes']]),
                    'cancel' => $s->delete(),
                };
            }
        });
        if ($data['action'] !== 'late_entry' && $affected->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send($affected, new \App\Notifications\LmsNotice(
                $data['action'] === 'cancel' ? 'Exam sitting cancelled' : 'Exam timetable changed',
                $data['action'] === 'cancel' ? 'One of your exam sittings was cancelled. The exams office will give you the new date.' : 'The time or venue of one of your exams has changed. Check your exam slip.',
                '/my/exams'));
        }
        $this->audit->record('sessions.bulk_'.$data['action'], null, ['ids' => $sessions->pluck('id')->all(), 'skipped' => $skipped, 'reason' => $data['reason']] + array_intersect_key($data, array_flip(['minutes', 'venue_id', 'late_entry_minutes'])));

        return back()->with('success', ($sessions->count() - $skipped).' sittings updated.'.($skipped ? " $skipped already started and were left alone." : ''));
    }

    /** Proctor shifts: availability and assignments over a period, plus automatic rostering. */
    public function shifts(Request $request): Response
    {
        $from = CarbonImmutable::parse($request->query('from', now()->toDateString()))->startOfDay();
        $to = $from->addDays(13)->endOfDay();
        $manage = $request->user()->can('exams.manage');
        $proctors = User::permission(['exams.proctor', 'exams.invigilate', 'exams.lead_hall'])->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        $assignments = DB::table('proctor_assignments as p')->join('exam_sessions as s', 's.id', '=', 'p.exam_session_id')->join('exams as e', 'e.id', '=', 's.exam_id')
            ->leftJoin('venues as v', 'v.id', '=', 's.venue_id')->where('s.tenant_id', tenant_id())->whereBetween('s.starts_at', [$from, $to])
            ->when(! $manage, fn ($q) => $q->where('p.user_id', $request->user()->id))
            ->orderBy('s.starts_at')->get(['p.id', 'p.user_id', 'p.role', 's.id as session_id', 'e.title', 's.starts_at', 's.ends_at', 'v.name as venue']);

        return Inertia::render('proctor/shifts', [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'manage' => $manage,
            'proctors' => $manage ? $proctors : [],
            'assignments' => $assignments->map(fn ($a) => ['id' => $a->id, 'user_id' => $a->user_id, 'role' => $a->role, 'session_id' => $a->session_id, 'title' => $a->title,
                'starts_at' => CarbonImmutable::parse($a->starts_at)->toIso8601String(), 'ends_at' => CarbonImmutable::parse($a->ends_at)->toIso8601String(), 'venue' => $a->venue]),
            'availability' => DB::table('proctor_availability')->where('tenant_id', tenant_id())->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->when(! $manage, fn ($q) => $q->where('user_id', $request->user()->id))->orderBy('date')->get(['id', 'user_id', 'date', 'from_time', 'to_time', 'available', 'note']),
            'unstaffed' => $manage ? ExamSession::whereBetween('starts_at', [$from, $to])->where('is_practice', false)->whereDoesntHave('proctors')->with('exam:id,title')
                ->orderBy('starts_at')->get(['id', 'exam_id', 'starts_at', 'name'])->map(fn ($s) => ['id' => $s->id, 'title' => $s->exam->title, 'name' => $s->name, 'starts_at' => $s->starts_at->toIso8601String()]) : [],
            'trained' => $manage ? DB::table('proctor_trainings')->where('tenant_id', tenant_id())->whereNotNull('completed_at')->pluck('user_id')->unique()->values() : [],
        ]);
    }

    public function saveAvailability(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'], 'date' => ['required', 'date', 'after_or_equal:today'],
            'from_time' => ['required', 'date_format:H:i'], 'to_time' => ['required', 'date_format:H:i', 'after:from_time'],
            'available' => ['boolean'], 'note' => ['nullable', 'string', 'max:190'],
        ]);
        // staff record their own availability; the exams office may record it for anyone
        $userId = $request->user()->can('exams.manage') && ! empty($data['user_id']) ? User::findOrFail($data['user_id'])->id : $request->user()->id;
        DB::table('proctor_availability')->insert(['tenant_id' => tenant_id(), 'user_id' => $userId, 'date' => $data['date'], 'from_time' => $data['from_time'], 'to_time' => $data['to_time'],
            'available' => $data['available'] ?? true, 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Availability saved.');
    }

    public function deleteAvailability(Request $request, int $id): RedirectResponse
    {
        DB::table('proctor_availability')->where('tenant_id', tenant_id())->where('id', $id)
            ->when(! $request->user()->can('exams.manage'), fn ($q) => $q->where('user_id', $request->user()->id))->delete();

        return back();
    }

    public function roster(Request $request, ProctorRoster $roster): RedirectResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'per' => ['required', 'integer', 'min:5', 'max:200'], 'trained_only' => ['boolean']]);
        [$assigned, $short] = $roster->fill(CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay(), $data['per'], (bool) ($data['trained_only'] ?? false));
        $this->audit->record('proctors.rostered', null, ['assigned' => $assigned, 'short' => $short] + $data);

        return back()->with($short ? 'error' : 'success', "$assigned proctor shifts assigned.".($short ? " $short sittings still need more proctors than are available." : ''));
    }
}
