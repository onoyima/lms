<?php

namespace App\Services\Lms;

use App\Models\CourseOffering;
use Illuminate\Support\Facades\DB;

/**
 * Participation score 0-100 per student (LMS 155, 164-166): class attendance 30%, materials
 * opened/completed 30%, work handed in on time 20%, forum contributions 20% (three posts = full).
 * A guide for lecturers and advisers — it is not part of the grade unless a lecturer adds it.
 */
class Participation
{
    /** @return array<int, array{score: int, attendance: ?int, completion: int, on_time: ?int, posts: int}> */
    public function scores(CourseOffering $o): array
    {
        $students = DB::table('enrolments')->where('course_offering_id', $o->id)->where('status', 'active')->pluck('user_id');
        if ($students->isEmpty()) {
            return [];
        }
        $meetings = DB::table('class_meetings')->where('course_offering_id', $o->id)->where('held_at', '<=', now())->pluck('id');
        $present = $meetings->isEmpty() ? collect() : DB::table('attendance_records')->whereIn('class_meeting_id', $meetings)->whereIn('status', ['present', 'late', 'excused'])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) n')->pluck('n', 'user_id');
        $items = DB::table('course_items')->where('course_offering_id', $o->id)->where('visible', true)->whereNull('deleted_at')->pluck('id');
        $done = $items->isEmpty() ? collect() : DB::table('item_completions')->whereIn('course_item_id', $items)->groupBy('user_id')->selectRaw('user_id, COUNT(*) n')->pluck('n', 'user_id');
        $due = DB::table('course_items')->where('course_offering_id', $o->id)->where('type', 'assignment')->where('visible', true)->whereNull('deleted_at')->where('due_at', '<', now())->pluck('id');
        $onTime = $due->isEmpty() ? collect() : DB::table('assignment_submissions')->whereIn('course_item_id', $due)->whereIn('status', ['submitted', 'marked', 'returned'])->where('is_late', false)
            ->groupBy('user_id')->selectRaw('user_id, COUNT(DISTINCT course_item_id) n')->pluck('n', 'user_id');
        $posts = DB::table('forum_posts as p')->join('forum_threads as t', 't.id', '=', 'p.forum_thread_id')->join('course_items as i', 'i.id', '=', 't.course_item_id')
            ->where('i.course_offering_id', $o->id)->groupBy('p.user_id')->selectRaw('p.user_id, COUNT(*) n')->pluck('n', 'user_id');

        $out = [];
        foreach ($students as $id) {
            $att = $meetings->isEmpty() ? null : (int) round(($present[$id] ?? 0) / $meetings->count() * 100);
            $comp = $items->isEmpty() ? 0 : (int) round(($done[$id] ?? 0) / $items->count() * 100);
            $ot = $due->isEmpty() ? null : (int) round(($onTime[$id] ?? 0) / $due->count() * 100);
            $p = (int) ($posts[$id] ?? 0);
            // parts with nothing to measure yet are left out and the rest re-weighted
            $parts = array_filter(['a' => [$att, 30], 'c' => [$items->isEmpty() ? null : $comp, 30], 'o' => [$ot, 20], 'p' => [min(100, (int) round($p / 3 * 100)), 20]], fn ($x) => $x[0] !== null);
            $weight = array_sum(array_column($parts, 1));
            $score = $weight ? (int) round(array_sum(array_map(fn ($x) => $x[0] * $x[1], $parts)) / $weight) : 0;
            $out[$id] = ['score' => $score, 'attendance' => $att, 'completion' => $comp, 'on_time' => $ot, 'posts' => $p];
        }

        return $out;
    }
}
