<?php

namespace App\Conferencing;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance from BigBlueButton's join and leave events.
 *
 * The sessions are rebuilt from every stored event in time order each time an event arrives, so
 * duplicates (stored once by key), late or out-of-order delivery, reconnects and a meeting that ends
 * with people still inside all come out the same. Overlapping sessions (two tabs) are counted once.
 */
class Attendance
{
    public function rebuild(Meeting $m): void
    {
        $events = DB::table('meeting_events')->where('meeting_id', $m->id)->whereIn('type', ['user-joined', 'user-left', 'meeting-ended'])
            ->orderBy('occurred_at')->orderBy('id')->get();
        $open = [];
        $rows = [];
        $ended = null;
        foreach ($events as $e) {
            $at = CarbonImmutable::parse($e->occurred_at);
            $p = json_decode((string) $e->payload, true) ?: [];
            if ($e->type === 'meeting-ended') {
                $ended = $at;
                foreach ($open as $k => $r) {
                    $rows[] = $r + ['left_at' => $at, 'closed_by_meeting_end' => true];
                }
                $open = [];

                continue;
            }
            $key = (string) $e->internal_user_id;
            if ($e->type === 'user-joined' && ! isset($open[$key])) {
                $open[$key] = ['internal_user_id' => $key, 'user_id' => $e->user_id, 'name' => $p['name'] ?? null, 'role' => $p['role'] ?? null, 'joined_at' => $at];
            } elseif ($e->type === 'user-left' && isset($open[$key])) {
                $rows[] = $open[$key] + ['left_at' => $at, 'closed_by_meeting_end' => false];
                unset($open[$key]);
            }
        }
        $end = $ended ?? ($m->status === 'ended' ? CarbonImmutable::parse($m->ended_at ?? now()) : null);
        foreach ($open as $r) {
            $rows[] = $r + ['left_at' => $end, 'closed_by_meeting_end' => $end !== null];
        }
        DB::transaction(function () use ($m, $rows) {
            MeetingAttendance::where('meeting_id', $m->id)->delete();
            foreach ($rows as $r) {
                MeetingAttendance::create($r + ['meeting_id' => $m->id, 'tenant_id' => $m->tenant_id,
                    'seconds' => $r['left_at'] ? max(0, (int) $r['joined_at']->diffInSeconds($r['left_at'], true)) : 0]);
            }
        });
    }

    /**
     * Per person: first join, last leave, time present (overlaps merged), reconnects, status.
     *
     * @return list<array>
     */
    public function summary(Meeting $m): array
    {
        $scheduled = max(60, $m->duration_minutes * 60);
        $start = CarbonImmutable::parse($m->started_at ?? $m->starts_at);
        $people = [];
        foreach (MeetingAttendance::with('user:id,name,identifier')->where('meeting_id', $m->id)->orderBy('joined_at')->get()->groupBy(fn ($r) => $r->user_id ? 'u'.$r->user_id : 'x'.$r->internal_user_id) as $rows) {
            $intervals = [];
            foreach ($rows as $r) {
                $intervals[] = [$r->joined_at->getTimestamp(), ($r->left_at ?? now())->getTimestamp()];
            }
            usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
            $total = 0;
            [$cs, $ce] = $intervals[0];
            foreach (array_slice($intervals, 1) as [$s, $e]) {
                if ($s <= $ce) {
                    $ce = max($ce, $e);
                } else {
                    $total += $ce - $cs;
                    [$cs, $ce] = [$s, $e];
                }
            }
            $total += $ce - $cs;
            $first = $rows->first();
            $late = $first->joined_at->gt($start->addMinutes((int) config('conferencing.late_after_minutes')));
            $share = min(1, $total / $scheduled);
            $people[] = [
                'user_id' => $first->user_id, 'name' => $first->user?->name ?? $first->name, 'identifier' => $first->user?->identifier,
                'role' => $first->role, 'first_join' => $first->joined_at, 'last_leave' => $rows->max('left_at'),
                'seconds' => $total, 'sessions' => $rows->count(), 'reconnects' => max(0, $rows->count() - 1),
                'percent' => (int) round($share * 100),
                'status' => $share >= (float) config('conferencing.present_share') ? ($late ? 'late' : 'present') : 'partial',
            ];
        }
        usort($people, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $people;
    }
}
