<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The search box at the top of every page: people, exams, courses and questions the signed-in
 * person may open, a few of each, for jumping straight to them.
 */
class QuickSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $user = $request->user();
        if (mb_strlen($q) < 2 || ! $user || $user->isPlatformAdmin()) {
            return response()->json(['groups' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $groups = [];
        $on = fn (string $m) => app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), $m);

        if ($user->can('users.manage')) {
            $groups[] = ['label' => 'People', 'items' => User::query()->whereIn('user_type', ['student', 'staff'])
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('identifier', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'identifier', 'user_type'])
                ->map(fn ($u) => ['title' => $u->name, 'meta' => trim(($u->identifier ?? '').' '.($u->user_type === 'student' ? 'student' : 'staff')), 'href' => '/admin/people?q='.urlencode($u->identifier ?: $u->name)])];
        }
        if ($on('question_bank') && $user->can('exams.manage')) {
            $groups[] = ['label' => 'Exams', 'items' => Exam::query()->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('code', 'like', $like))
                ->latest('id')->limit(5)->get(['id', 'title', 'type', 'status'])
                ->map(fn ($e) => ['title' => $e->title, 'meta' => $e->type.' · '.str_replace('_', ' ', $e->status), 'href' => '/exams/'.$e->id])];
        } elseif ($user->user_type === 'student') {
            $groups[] = ['label' => 'Exams', 'items' => collect()];
        }
        $groups[] = ! $on('lms') ? ['label' => 'Courses', 'items' => collect()] : ['label' => 'Courses', 'items' => Course::query()->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('title', 'like', $like))
            ->orderBy('code')->limit(5)->get(['id', 'code', 'title'])
            ->map(fn ($c) => ['title' => trim($c->code.' — '.$c->title, ' —'), 'meta' => 'course', 'href' => '/learn/courses?q='.urlencode($c->code)])];
        if ($on('question_bank') && $user->can('questions.view')) {
            $groups[] = ['label' => 'Questions', 'items' => Question::query()->where('stem_html', 'like', $like)->latest('id')->limit(4)->get(['id', 'stem_html', 'type'])
                ->map(fn ($x) => ['title' => mb_strimwidth(trim(html_entity_decode(strip_tags($x->stem_html))), 0, 90, '…'), 'meta' => str_replace('_', ' ', $x->type), 'href' => '/question-bank?q='.urlencode(mb_substr($q, 0, 60))])];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => count($g['items']) > 0))]);
    }
}
