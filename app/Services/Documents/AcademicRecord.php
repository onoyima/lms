<?php

namespace App\Services\Documents;

use App\Models\User;
use App\Support\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Official academic documents (LMS 23, 153, 163): result statements and transcripts built from
 * released course results, with semester GPA and cumulative GPA, and a verification code.
 */
class AcademicRecord
{
    /** Released results grouped by semester, with GPA per semester and CGPA. */
    public function record(User $student, ?int $semesterId = null): array
    {
        $rows = DB::table('course_grades as g')->join('course_offerings as o', 'o.id', '=', 'g.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
            ->join('semesters as s', 's.id', '=', 'o.semester_id')->join('academic_sessions as a', 'a.id', '=', 's.academic_session_id')
            ->where('g.user_id', $student->id)->where('g.status', 'released')
            ->when($semesterId, fn ($q) => $q->where('o.semester_id', $semesterId))
            ->orderBy('a.name')->orderBy('s.id')->orderBy('c.code')
            ->get(['a.name as session', 's.id as semester_id', 's.name as semester', 'c.code', 'c.title', 'c.credit_units as units', 'g.total', 'g.grade']);

        $semesters = [];
        $allUnits = 0;
        $allPoints = 0.0;
        foreach ($rows->groupBy('semester_id') as $group) {
            $units = $group->sum('units');
            $points = $group->sum(fn ($r) => $r->units * Policy::gradePoint($r->grade));
            $allUnits += $units;
            $allPoints += $points;
            $semesters[] = [
                'label' => $group[0]->session.' · '.$group[0]->semester,
                'courses' => $group->map(fn ($r) => ['code' => $r->code, 'title' => $r->title, 'units' => $r->units, 'total' => $r->total, 'grade' => $r->grade, 'points' => Policy::gradePoint($r->grade) * $r->units])->values()->all(),
                'units' => $units, 'points' => $points, 'gpa' => $units ? round($points / $units, 2) : null,
                'cgpa' => $allUnits ? round($allPoints / $allUnits, 2) : null,
            ];
        }

        return ['semesters' => $semesters, 'units' => $allUnits, 'cgpa' => $allUnits ? round($allPoints / $allUnits, 2) : null];
    }

    public function issue(User $holder, string $type, string $summary, array $content, ?User $by = null): string
    {
        $code = strtoupper(Str::random(16));
        DB::table('issued_documents')->insert([
            'tenant_id' => tenant_id(), 'user_id' => $holder->id, 'type' => $type, 'code' => $code,
            'content_hash' => hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE)), 'summary' => mb_substr($summary, 0, 255),
            'issued_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
