<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Services\Lms\LmsAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who has studied what in a course: for each student, how many materials they opened and finished
 * and when they were last in the course; for each material, who opened it and who has not yet.
 * For the course's lecturers and for oversight roles (LmsAccess::teaches).
 */
class ProgressController extends Controller
{
    public function __construct(private LmsAccess $access) {}

    private function data(CourseOffering $offering): array
    {
        $items = CourseItem::where('course_items.course_offering_id', $offering->id)
            ->leftJoin('course_sections as s', 's.id', '=', 'course_items.course_section_id')
            ->orderBy('s.position')->orderBy('course_items.position')
            ->get(['course_items.id', 'course_items.title', 'course_items.type', 'course_items.visible', 's.title as section']);
        $students = DB::table('enrolments')->join('users', 'users.id', '=', 'enrolments.user_id')
            ->where('course_offering_id', $offering->id)->where('enrolments.status', 'active')
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.identifier']);
        $ids = $items->pluck('id');
        $views = DB::table('item_views')->whereIn('course_item_id', $ids)->whereIn('user_id', $students->pluck('id'))->get()->groupBy('user_id');
        $done = DB::table('item_completions')->whereIn('course_item_id', $ids)->whereIn('user_id', $students->pluck('id'))->get()->groupBy('user_id');
        $handed = DB::table('assignment_submissions')->whereIn('course_item_id', $ids)->where('status', '!=', 'draft')
            ->select('user_id', 'course_item_id')->distinct()->get()->groupBy('user_id');

        $visible = $items->where('visible', true)->count();
        $rows = $students->map(function ($s) use ($views, $done, $handed, $visible) {
            $v = $views[$s->id] ?? collect();
            $d = $done[$s->id] ?? collect();
            // anything finished was opened, including work from before opens were counted
            $opened = $v->pluck('course_item_id')->merge($d->pluck('course_item_id'))->unique()->values();

            return [
                'id' => $s->id, 'name' => $s->name, 'identifier' => $s->identifier,
                'opened' => $opened->count(), 'completed' => $d->count(), 'handed_in' => ($handed[$s->id] ?? collect())->count(),
                'percent' => $visible ? min(100, (int) round($opened->count() / $visible * 100)) : 0,
                'last_seen' => collect([$v->max('last_viewed_at'), $d->max('completed_at')])->filter()->max(),
                'items' => $opened,
                'done' => $d->pluck('course_item_id')->values(),
            ];
        });
        $byItem = $items->map(fn ($i) => [
            'id' => $i->id, 'title' => $i->title, 'type' => $i->type, 'section' => $i->section, 'visible' => (bool) $i->visible,
            'opened' => $rows->filter(fn ($r) => $r['items']->contains($i->id))->count(),
            'completed' => $rows->filter(fn ($r) => $r['done']->contains($i->id))->count(),
            'views' => (int) DB::table('item_views')->where('course_item_id', $i->id)->sum('views'),
            'downloads' => (int) DB::table('item_views')->where('course_item_id', $i->id)->sum('downloads'),
        ]);

        return ['items' => $byItem, 'rows' => $rows, 'enrolled' => $students->count()];
    }

    public function show(Request $request, CourseOffering $offering): Response
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $offering->load('course');

        return Inertia::render('learn/progress', ['offering' => ['id' => $offering->id, 'code' => $offering->course->code, 'title' => $offering->course->title, 'label' => $offering->label()]]
            + $this->data($offering));
    }

    public function export(Request $request, CourseOffering $offering): StreamedResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $d = $this->data($offering);

        return response()->streamDownload(function () use ($d) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['Name', 'Matric / ID', 'Opened', 'Finished', 'Handed in', 'Last seen'], $d['items']->pluck('title')->all()));
            foreach ($d['rows'] as $r) {
                fputcsv($out, array_merge([$r['name'], $r['identifier'], $r['opened'], $r['completed'], $r['handed_in'], $r['last_seen']],
                    $d['items']->map(fn ($i) => $r['done']->contains($i['id']) ? 'finished' : ($r['items']->contains($i['id']) ? 'opened' : ''))->all()));
            }
            fclose($out);
        }, 'progress-'.$offering->id.'.csv', ['Content-Type' => 'text/csv']);
    }
}
