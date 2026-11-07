<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Data for the institution's reporting and analytics systems (LMS 224). Aggregates by course and
 * semester by default, so a warehouse can be fed without copying personal records.
 */
class AnalyticsFeed
{
    public const DATASETS = ['enrolments', 'results', 'attendance', 'exams', 'engagement'];

    public function dataset(string $name, ?int $semesterId = null): array
    {
        $tid = tenant_id();
        $sem = $semesterId ?? DB::table('semesters')->where('tenant_id', $tid)->where('is_current', true)->value('id');
        $offerings = fn () => DB::table('course_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')->where('o.tenant_id', $tid)->when($sem, fn ($q) => $q->where('o.semester_id', $sem));

        return match ($name) {
            'enrolments' => $offerings()->leftJoin('enrolments as e', fn ($j) => $j->on('e.course_offering_id', '=', 'o.id')->where('e.status', 'active'))
                ->groupBy('o.id', 'c.code', 'c.title', 'c.credit_units')->get(['o.id as offering_id', 'c.code', 'c.title', 'c.credit_units as units', DB::raw('COUNT(e.id) as students')])->all(),
            'results' => $offerings()->join('course_grades as g', 'g.course_offering_id', '=', 'o.id')->groupBy('o.id', 'c.code', 'g.grade')
                ->get(['o.id as offering_id', 'c.code', 'g.grade', DB::raw('COUNT(*) as students'), DB::raw('ROUND(AVG(g.total), 2) as mean_total')])->all(),
            'attendance' => $offerings()->join('class_meetings as m', 'm.course_offering_id', '=', 'o.id')->leftJoin('attendance_records as a', 'a.class_meeting_id', '=', 'm.id')
                ->groupBy('o.id', 'c.code')->get(['o.id as offering_id', 'c.code', DB::raw('COUNT(DISTINCT m.id) as meetings'),
                    DB::raw("SUM(a.status IN ('present','late')) as present"), DB::raw('COUNT(a.id) as records')])->all(),
            'exams' => DB::table('exams as e')->where('e.tenant_id', $tid)->leftJoin('attempts as a', 'a.exam_id', '=', 'e.id')
                ->groupBy('e.id', 'e.tenant_id', 'e.title', 'e.type', 'e.status')->orderByDesc('e.id')->limit(500)
                ->get(['e.id as exam_id', 'e.title', 'e.type', 'e.status', DB::raw('COUNT(a.id) as attempts'), DB::raw("SUM(a.status = 'submitted') as submitted"),
                    DB::raw('ROUND(AVG(CASE WHEN a.score_max > 0 THEN a.score_raw / a.score_max * 100 END), 1) as mean_pct'),
                    DB::raw('(SELECT COUNT(*) FROM incidents i WHERE i.tenant_id = e.tenant_id AND i.exam_session_id IN (SELECT s.id FROM exam_sessions s WHERE s.exam_id = e.id)) as incidents')])->all(),
            'engagement' => $offerings()->join('course_items as i', 'i.course_offering_id', '=', 'o.id')->whereNull('i.deleted_at')
                ->leftJoin('item_completions as ic', 'ic.course_item_id', '=', 'i.id')->groupBy('o.id', 'c.code')
                ->get(['o.id as offering_id', 'c.code', DB::raw('COUNT(DISTINCT i.id) as items'), DB::raw('COUNT(ic.id) as completions')])->all(),
            default => abort(422, 'Unknown dataset.'),
        };
    }

    public function all(?int $semesterId = null): array
    {
        return ['institution' => tenant()?->slug, 'generated_at' => now()->toIso8601String(), 'semester_id' => $semesterId,
            'datasets' => collect(self::DATASETS)->mapWithKeys(fn ($d) => [$d => $this->dataset($d, $semesterId)])->all()];
    }

    /** Daily push to the configured endpoint, signed like every other platform webhook. */
    public function push(Connectors $connectors): string
    {
        $c = $connectors->get('analytics');
        if (! $c['enabled'] || ! $c['url'] || ! $c['secret']) {
            return 'not set up';
        }
        if (! Connectors::safeUrl($c['url'])) {
            return 'address not allowed';
        }
        $body = json_encode($this->all(), JSON_UNESCAPED_SLASHES);
        $res = Http::timeout(60)->withHeaders(['X-Platform-Signature' => Connectors::sign($body, $c['secret'])])->withBody($body, 'application/json')->post($c['url']);

        return $res->successful() ? 'sent' : 'failed ('.$res->status().')';
    }
}
