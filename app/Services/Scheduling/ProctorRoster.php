<?php

namespace App\Services\Scheduling;

use App\Http\Controllers\Exam\SessionController;
use App\Models\ExamSession;
use App\Models\ProctorAssignment;
use App\Models\User;
use App\Notifications\LmsNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automatic proctor rostering (Target 77-79): fills each sitting with enough proctors for its
 * candidates, using only staff who are free (no overlapping shift, not blocked out, inside any
 * availability they gave), spreading shifts evenly, with a chief invigilator where possible.
 */
class ProctorRoster
{
    /** @return array{0: int, 1: int} [shifts assigned, sittings still short] */
    public function fill(CarbonImmutable $from, CarbonImmutable $to, int $perProctor, bool $trainedOnly = false): array
    {
        $pool = User::permission(['exams.proctor', 'exams.invigilate', 'exams.lead_hall'])->where('status', 'active')->get(['id', 'name']);
        if ($trainedOnly) {
            $trained = DB::table('proctor_trainings')->where('tenant_id', tenant_id())->whereNotNull('completed_at')->pluck('user_id')->flip();
            $pool = $pool->filter(fn ($u) => isset($trained[$u->id]))->values();
        }
        $sessions = ExamSession::whereBetween('starts_at', [$from, $to])->where('is_practice', false)->where('status', 'scheduled')
            ->withCount('candidates')->with('proctors')->orderBy('starts_at')->get();

        $busy = [];
        DB::table('proctor_assignments as p')->join('exam_sessions as s', 's.id', '=', 'p.exam_session_id')->where('s.tenant_id', tenant_id())
            ->where('s.starts_at', '<', $to->addDay())->where('s.ends_at', '>', $from->subDay())->get(['p.user_id', 's.starts_at', 's.ends_at'])
            ->each(function ($r) use (&$busy) {
                $busy[$r->user_id][] = [strtotime($r->starts_at), strtotime($r->ends_at)];
            });
        $availability = DB::table('proctor_availability')->where('tenant_id', tenant_id())->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()->groupBy(fn ($a) => $a->user_id.'|'.$a->date);
        $load = $pool->mapWithKeys(fn ($u) => [$u->id => count($busy[$u->id] ?? [])])->all();

        $assigned = 0;
        $short = 0;
        $touched = [];
        foreach ($sessions as $s) {
            $need = max(1, (int) ceil($s->candidates_count / $perProctor)) + ($s->candidates_count > $perProctor ? 1 : 0); // + a chief for bigger halls
            $have = $s->proctors->count();
            $hasChief = $s->proctors->contains('role', 'chief');
            $start = $s->starts_at->getTimestamp() - 1800; // proctors arrive 30 minutes early
            $end = $s->ends_at->getTimestamp();
            $taken = $s->proctors->pluck('user_id')->flip();

            $free = $pool->filter(function ($u) use ($busy, $availability, $s, $start, $end, $taken) {
                if (isset($taken[$u->id])) {
                    return false;
                }
                foreach ($busy[$u->id] ?? [] as [$a, $b]) {
                    if ($a < $end && $b > $start) {
                        return false;
                    }
                }
                $rows = $availability[$u->id.'|'.$s->starts_at->toDateString()] ?? collect();
                $inside = fn ($r) => strtotime($s->starts_at->toDateString().' '.$r->from_time) <= $start && strtotime($s->starts_at->toDateString().' '.$r->to_time) >= $end;
                $overlap = fn ($r) => strtotime($s->starts_at->toDateString().' '.$r->from_time) < $end && strtotime($s->starts_at->toDateString().' '.$r->to_time) > $start;
                if ($rows->where('available', false)->contains($overlap)) {
                    return false;
                }
                $offered = $rows->where('available', true);

                return $offered->isEmpty() || $offered->contains($inside);
            })->sortBy(fn ($u) => $load[$u->id] ?? 0)->values();

            while ($have < $need && $free->isNotEmpty()) {
                $chiefPick = ! $hasChief ? $free->first(fn ($u) => $u->can('exams.lead_hall')) : null;
                $u = $chiefPick ?? $free->first();
                $free = $free->reject(fn ($x) => $x->id === $u->id)->values();
                $role = $chiefPick ? 'chief' : ($u->can('exams.invigilate') && $s->mode === 'hall' ? 'invigilator' : 'proctor');
                $hasChief = $hasChief || $chiefPick !== null;
                ProctorAssignment::create(['exam_session_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'max_candidates' => $perProctor]);
                $busy[$u->id][] = [$start, $end];
                $load[$u->id] = ($load[$u->id] ?? 0) + 1;
                $have++;
                $assigned++;
                $touched[$s->id] = $s;
                $u->notify(new LmsNotice('New proctoring shift', "You are rostered as $role for {$s->exam->title} on ".$s->starts_at->format('D j M, H:i').'.', '/proctor/shifts', category: 'exams'));
            }
            if ($have < $need) {
                $short++;
            }
        }
        foreach ($touched as $s) {
            app(SessionController::class)->balance($s);
        }

        return [$assigned, $short];
    }
}
