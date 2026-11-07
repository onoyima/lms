<?php

namespace App\Services\Scheduling;

use App\Models\AccommodationProfile;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\User;
use App\Models\Venue;
use App\Services\Exams\Eligibility;
use App\Services\Exams\TimetableClashes;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Automatic exam timetable (Target 48, 200; LMS 77): places exams in date/time slots so no
 * student has two exams at once or more than the daily limit, and each sitting fits the
 * venues' seats. Exams with the most shared students are placed first (a graph-colouring
 * heuristic), and sittings that already exist in the period are respected.
 */
class TimetableGenerator
{
    public function __construct(private Eligibility $eligibility) {}

    /**
     * @param  array{exam_ids: int[], from: string, to: string, slots: string[], skip_weekends?: bool, venue_ids?: int[], max_per_day?: int, mode?: string}  $p
     * @return array{proposal: array, unplaced: array}
     */
    public function generate(array $p): array
    {
        $exams = Exam::whereIn('id', $p['exam_ids'])->get()->keyBy('id');
        $maxPerDay = (int) ($p['max_per_day'] ?? Policy::get('max_exams_per_day', 2));
        $mode = $p['mode'] ?? (empty($p['venue_ids']) ? 'remote' : 'hall');
        $venues = $mode === 'hall' ? Venue::whereIn('id', $p['venue_ids'] ?? [])->orderByDesc('capacity')->get(['id', 'name', 'capacity']) : collect();

        // students per exam: registered students, or the candidate list already entered
        $students = [];
        foreach ($exams as $exam) {
            $offering = $this->eligibility->offering($exam);
            $students[$exam->id] = $offering
                ? DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->pluck('user_id')->all()
                : DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->where('s.exam_id', $exam->id)->pluck('c.user_id')->unique()->all();
        }

        // conflict graph from shared students
        $byStudent = [];
        foreach ($students as $examId => $ids) {
            foreach ($ids as $u) {
                $byStudent[$u][] = $examId;
            }
        }
        $degree = array_fill_keys(array_keys($students), 0);
        foreach ($byStudent as $list) {
            foreach ($list as $e) {
                $degree[$e] += count($list) - 1;
            }
        }
        $order = collect(array_keys($students))->sortByDesc(fn ($e) => [$degree[$e], count($students[$e])])->values();

        $slots = $this->slots($p['from'], $p['to'], $p['slots'], (bool) ($p['skip_weekends'] ?? true));
        abort_if($slots->isEmpty(), 422, 'No exam slots in that period.');

        // what is already booked in the period: per student intervals and per venue intervals
        $busyStudent = [];
        $busyVenue = [];
        $existing = ExamSession::where('is_practice', false)->where('starts_at', '<', CarbonImmutable::parse($p['to'])->endOfDay())
            ->where('ends_at', '>', CarbonImmutable::parse($p['from'])->startOfDay())->whereNotIn('exam_id', $exams->keys())->get(['id', 'venue_id', 'starts_at', 'ends_at']);
        $existingCandidates = DB::table('exam_candidates')->whereIn('exam_session_id', $existing->pluck('id'))->get(['exam_session_id', 'user_id'])->groupBy('exam_session_id');
        foreach ($existing as $s) {
            $iv = [$s->starts_at->getTimestamp(), $s->ends_at->getTimestamp()];
            foreach ($existingCandidates[$s->id] ?? [] as $c) {
                $busyStudent[$c->user_id][] = $iv;
            }
            if ($s->venue_id) {
                $busyVenue[$s->venue_id][] = $iv;
            }
        }

        $proposal = [];
        $unplaced = [];
        foreach ($order as $examId) {
            $exam = $exams[$examId];
            $ids = $students[$examId];
            $need = count($ids);
            if ($need === 0) {
                $unplaced[] = ['exam_id' => $examId, 'title' => $exam->title, 'reason' => 'No registered students'];

                continue;
            }
            $minutes = (int) $exam->duration_minutes + 15; // changeover time
            $placed = false;
            foreach ($slots as $slot) {
                $start = $slot->getTimestamp();
                $end = $slot->addMinutes($minutes)->getTimestamp();
                $day = $slot->toDateString();
                if (\App\Support\Maintenance::blocksExamsAt($slot, $slot->addMinutes($minutes)) || ! $this->studentsFree($ids, $start, $end, $day, $busyStudent, $maxPerDay)) {
                    continue;
                }
                $allocation = [];
                if ($mode === 'hall') {
                    $left = $need;
                    foreach ($venues as $v) {
                        if ($left <= 0) {
                            break;
                        }
                        if ($v->capacity <= 0 || $this->overlaps($busyVenue[$v->id] ?? [], $start, $end)) {
                            continue;
                        }
                        $seats = min($v->capacity, $left);
                        $allocation[] = ['venue_id' => $v->id, 'venue' => $v->name, 'seats' => $seats];
                        $left -= $seats;
                    }
                    if ($left > 0) {
                        continue;
                    }
                }
                foreach ($ids as $u) {
                    $busyStudent[$u][] = [$start, $end];
                }
                foreach ($allocation as $a) {
                    $busyVenue[$a['venue_id']][] = [$start, $end];
                }
                $proposal[] = [
                    'exam_id' => $examId, 'title' => $exam->title, 'starts_at' => $slot->toIso8601String(),
                    'ends_at' => $slot->addMinutes((int) $exam->duration_minutes + 15)->toIso8601String(),
                    'mode' => $mode, 'students' => $need, 'venues' => $allocation, 'conflicts' => $degree[$examId],
                ];
                $placed = true;
                break;
            }
            if (! $placed) {
                $unplaced[] = ['exam_id' => $examId, 'title' => $exam->title, 'reason' => $mode === 'hall'
                    ? 'No slot where every student is free and enough seats are available' : 'No slot where every student is free'];
            }
        }

        usort($proposal, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return ['proposal' => $proposal, 'unplaced' => $unplaced];
    }

    /** Create the sittings, split students across venues, check eligibility, and re-check for clashes. */
    public function commit(int $runId): int
    {
        return DB::transaction(function () use ($runId) {
            $run = DB::table('timetable_runs')->where('id', $runId)->where('tenant_id', tenant_id())->lockForUpdate()->first();
            abort_unless($run && $run->status === 'draft', 409, 'This timetable has already been used or discarded.');
            $proposal = json_decode($run->proposal, true);
            $created = collect();
            foreach ($proposal as $row) {
                $exam = Exam::findOrFail($row['exam_id']);
                $offering = $this->eligibility->offering($exam);
                $ids = $offering ? DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->orderBy('user_id')->pluck('user_id')->all() : [];
                $users = User::whereIn('id', $ids)->get()->keyBy('id');
                $verdicts = $this->eligibility->evaluate($exam, $users->values());
                $profiles = AccommodationProfile::whereIn('user_id', $ids)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))->get()->keyBy('user_id');
                $groups = $row['mode'] === 'hall' ? $row['venues'] : [['venue_id' => null, 'venue' => null, 'seats' => count($ids)]];
                $offset = 0;
                foreach ($groups as $i => $g) {
                    $session = ExamSession::create([
                        'exam_id' => $exam->id, 'venue_id' => $g['venue_id'], 'mode' => $row['mode'],
                        'name' => count($groups) > 1 ? 'Batch '.chr(65 + $i).($g['venue'] ? ' · '.$g['venue'] : '') : $g['venue'],
                        'starts_at' => CarbonImmutable::parse($row['starts_at']), 'ends_at' => CarbonImmutable::parse($row['ends_at']),
                        'late_entry_minutes' => 15, 'status' => 'scheduled',
                    ]);
                    $now = now();
                    $rows = [];
                    foreach (array_slice($ids, $offset, $g['seats']) as $uid) {
                        [$eligibility, $reason] = $verdicts[$uid] ?? ['ineligible', 'Not found'];
                        $profile = $profiles[$uid] ?? null;
                        $rows[] = ['tenant_id' => tenant_id(), 'exam_session_id' => $session->id, 'user_id' => $uid, 'eligibility' => $eligibility, 'eligibility_reason' => $reason,
                            'extra_time_minutes' => $profile ? (int) ceil($exam->duration_minutes * $profile->extra_time_percent / 100) : 0,
                            'accommodations' => $profile?->settings ? json_encode($profile->settings) : null, 'created_at' => $now, 'updated_at' => $now];
                    }
                    foreach (array_chunk($rows, 500) as $chunk) {
                        ExamCandidate::insert($chunk);
                    }
                    $offset += $g['seats'];
                    $created->push($session);
                }
                if ($exam->status === 'approved') {
                    $exam->update(['status' => 'scheduled']);
                }
            }
            // final safety net: something may have been booked since the proposal was made
            $clashes = app(TimetableClashes::class);
            foreach ($created as $s) {
                if ($clashes->forSession($s)->isNotEmpty()) {
                    throw new \DomainException("Students in {$s->exam->title} now clash with another sitting. Generate the timetable again.");
                }
            }
            DB::table('timetable_runs')->where('id', $runId)->update(['status' => 'committed', 'committed_at' => now(), 'updated_at' => now()]);

            return $created->count();
        });
    }

    /** @return Collection<int, CarbonImmutable> */
    private function slots(string $from, string $to, array $times, bool $skipWeekends): Collection
    {
        $out = collect();
        $tz = config('app.timezone');
        for ($d = CarbonImmutable::parse($from, $tz)->startOfDay(); $d->lte(CarbonImmutable::parse($to, $tz)->startOfDay()); $d = $d->addDay()) {
            if ($skipWeekends && $d->isWeekend()) {
                continue;
            }
            foreach ($times as $t) {
                [$h, $m] = array_map('intval', explode(':', $t) + [1 => 0]);
                $slot = $d->setTime($h, $m);
                if ($slot->isFuture()) {
                    $out->push($slot);
                }
            }
        }

        return $out->sort()->values();
    }

    private function studentsFree(array $ids, int $start, int $end, string $day, array $busy, int $maxPerDay): bool
    {
        $dayStart = CarbonImmutable::parse($day)->startOfDay()->getTimestamp();
        $dayEnd = $dayStart + 86400;
        foreach ($ids as $u) {
            $today = 0;
            foreach ($busy[$u] ?? [] as [$s, $e]) {
                if ($s < $end && $e > $start) {
                    return false;
                }
                if ($s >= $dayStart && $s < $dayEnd && ++$today >= $maxPerDay) {
                    return false;
                }
            }
        }

        return true;
    }

    private function overlaps(array $intervals, int $start, int $end): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($s < $end && $e > $start) {
                return true;
            }
        }

        return false;
    }
}
