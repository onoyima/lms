<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\CourseItem;
use App\Models\CourseItemNote;
use App\Models\CourseOffering;
use App\Models\CoursePoll;
use App\Models\CoursePollVote;
use App\Models\CourseReview;
use App\Models\TopicRequest;
use App\Models\TopicRequestVote;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Lms\LmsAccess;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What students say and ask in a course: reviews with stars, polls, requests for topics and
 * material that were not covered, and each student's own notes and bookmarks on materials.
 */
class CourseFeedbackController extends Controller
{
    public function __construct(private LmsAccess $access, private AuditLogger $audit) {}

    /**
     * Everything the course page shows about reviews, polls and requests, for this person.
     */
    public static function forCourse(CourseOffering $offering, User $user, bool $teacher): array
    {
        $reviews = CourseReview::with('user:id,name')->where('course_offering_id', $offering->id)
            ->when(! $teacher, fn ($q) => $q->where(fn ($w) => $w->where('hidden', false)->orWhere('user_id', $user->id)))
            ->latest()->limit(200)->get();
        $shown = $reviews->where('hidden', false);
        $counts = array_fill(1, 5, 0);
        foreach ($shown as $r) {
            $counts[$r->rating]++;
        }

        $polls = CoursePoll::where('course_offering_id', $offering->id)->latest()->limit(50)->get();
        $votes = CoursePollVote::whereIn('course_poll_id', $polls->pluck('id'))->get()->groupBy('course_poll_id');

        $requests = TopicRequest::with('user:id,name')->where('course_offering_id', $offering->id)
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'planned' THEN 1 WHEN 'covered' THEN 2 ELSE 3 END")
            ->orderByDesc('votes')->latest()->limit(200)->get();
        $myVotes = TopicRequestVote::where('user_id', $user->id)->whereIn('topic_request_id', $requests->pluck('id'))->pluck('topic_request_id')->all();
        $items = CourseItem::whereIn('id', $requests->pluck('course_item_id')->filter())->pluck('title', 'id');

        return [
            'reviews' => [
                'average' => $shown->count() ? round($shown->avg('rating'), 1) : null,
                'count' => $shown->count(),
                'counts' => $counts,
                'mine' => ($m = $reviews->firstWhere('user_id', $user->id)) ? ['rating' => $m->rating, 'comment' => $m->comment, 'anonymous' => $m->anonymous] : null,
                'list' => $reviews->map(fn ($r) => [
                    'id' => $r->id, 'rating' => $r->rating, 'comment' => $r->comment, 'created_at' => $r->created_at,
                    'author' => $r->anonymous ? ($r->user_id === $user->id ? 'You (shown as anonymous)' : 'A student') : ($r->user_id === $user->id ? 'You' : $r->user?->name),
                    'reply' => $r->reply, 'replied_at' => $r->replied_at, 'hidden' => $r->hidden, 'mine' => $r->user_id === $user->id,
                ])->values(),
            ],
            'polls' => $polls->map(function ($p) use ($votes, $user, $teacher) {
                $pv = $votes->get($p->id, collect());
                $mine = $pv->firstWhere('user_id', $user->id);
                $closed = $p->closed_at !== null || ($p->closes_at && $p->closes_at->isPast());
                $see = $teacher || ($p->show_results === 'after_vote' && ($mine || $closed)) || ($p->show_results === 'after_close' && $closed);
                $tally = array_fill(0, count($p->options), 0);
                foreach ($pv as $v) {
                    foreach ((array) $v->choices as $c) {
                        isset($tally[$c]) && $tally[$c]++;
                    }
                }

                return [
                    'id' => $p->id, 'question' => $p->question, 'options' => $p->options, 'multiple' => $p->multiple, 'anonymous' => $p->anonymous,
                    'show_results' => $p->show_results, 'closes_at' => $p->closes_at, 'closed' => $closed, 'created_at' => $p->created_at,
                    'voters' => $pv->count(), 'mine' => $mine?->choices, 'results' => $see ? $tally : null,
                ];
            })->values(),
            'requests' => $requests->map(fn ($r) => [
                'id' => $r->id, 'kind' => $r->kind, 'title' => $r->title, 'details' => $r->details, 'status' => $r->status, 'reply' => $r->reply,
                'replied_at' => $r->replied_at, 'votes' => $r->votes, 'voted' => in_array($r->id, $myVotes, true), 'mine' => $r->user_id === $user->id,
                'author' => $teacher ? $r->user?->name : null, 'created_at' => $r->created_at,
                'item' => $r->course_item_id ? ['id' => $r->course_item_id, 'title' => $items[$r->course_item_id] ?? null] : null,
            ])->values(),
        ];
    }

    // ── reviews ─────────────────────────────────────────────────────────────

    public function review(Request $request, CourseOffering $offering): RedirectResponse
    {
        abort_unless($this->access->learns($request->user(), $offering), 403, 'Only students registered for this course can review it.');
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'anonymous' => ['boolean'],
        ]);
        CourseReview::updateOrCreate(['course_offering_id' => $offering->id, 'user_id' => $request->user()->id], $data + ['anonymous' => false]);

        return back()->with('success', 'Thank you. Your review is saved.');
    }

    public function deleteReview(Request $request, CourseReview $review): RedirectResponse
    {
        abort_unless($review->user_id === $request->user()->id, 403);
        $review->delete();

        return back()->with('success', 'Your review was removed.');
    }

    public function replyReview(Request $request, CourseReview $review): RedirectResponse
    {
        $offering = CourseOffering::findOrFail($review->course_offering_id);
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:3000'], 'hidden' => ['sometimes', 'boolean']]);
        if (array_key_exists('reply', $data)) {
            $review->fill(['reply' => $data['reply'], 'replied_by' => $request->user()->id, 'replied_at' => $data['reply'] ? now() : null]);
            $data['reply'] && User::find($review->user_id)?->notify(new LmsNotice('Your lecturer replied to your review', $offering->course?->code ?? 'Course', '/learn/courses/'.$offering->id.'?tab=reviews'));
        }
        if (array_key_exists('hidden', $data)) {
            $review->hidden = $data['hidden'];
            $this->audit->record('lms.review.'.($data['hidden'] ? 'hidden' : 'shown'), $review);
        }
        $review->save();

        return back()->with('success', 'Saved.');
    }

    // ── polls ───────────────────────────────────────────────────────────────

    public function storePoll(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:200'],
            'multiple' => ['boolean'],
            'anonymous' => ['boolean'],
            'show_results' => ['required', Rule::in(['after_vote', 'after_close', 'teacher'])],
            'closes_at' => ['nullable', 'date', 'after:now'],
            'notify' => ['boolean'],
        ]);
        $poll = CoursePoll::create([
            'course_offering_id' => $offering->id, 'question' => $data['question'], 'options' => array_values(array_map('trim', $data['options'])),
            'multiple' => $data['multiple'] ?? false, 'anonymous' => $data['anonymous'] ?? true, 'show_results' => $data['show_results'],
            'closes_at' => $data['closes_at'] ?? null, 'created_by' => $request->user()->id,
        ]);
        if ($data['notify'] ?? true) {
            $this->tellStudents($offering, 'New poll in '.($offering->course?->code ?? 'your course'), $poll->question, '/learn/courses/'.$offering->id.'?tab=polls');
        }

        return back()->with('success', 'Poll posted.');
    }

    public function vote(Request $request, CoursePoll $poll): RedirectResponse
    {
        $offering = CourseOffering::findOrFail($poll->course_offering_id);
        abort_unless($this->access->learns($request->user(), $offering), 403, 'Only students registered for this course can vote.');
        abort_if($poll->closed_at || ($poll->closes_at && $poll->closes_at->isPast()), 422, 'This poll has closed.');
        $data = $request->validate(['choices' => ['required', 'array', 'min:1', 'max:'.($poll->multiple ? count($poll->options) : 1)], 'choices.*' => ['integer', 'min:0', 'max:'.(count($poll->options) - 1)]]);
        CoursePollVote::updateOrCreate(['course_poll_id' => $poll->id, 'user_id' => $request->user()->id], ['choices' => array_values(array_unique(array_map('intval', $data['choices'])))]);

        return back()->with('success', 'Your vote is in.');
    }

    public function closePoll(Request $request, CoursePoll $poll): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), CourseOffering::findOrFail($poll->course_offering_id));
        $poll->update(['closed_at' => $poll->closed_at ? null : now()]);

        return back()->with('success', $poll->closed_at ? 'Poll closed.' : 'Poll opened again.');
    }

    public function deletePoll(Request $request, CoursePoll $poll): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), CourseOffering::findOrFail($poll->course_offering_id));
        $poll->delete();

        return back()->with('success', 'Poll removed.');
    }

    // ── requests for topics and material ────────────────────────────────────

    public function storeRequest(Request $request, CourseOffering $offering): RedirectResponse
    {
        abort_unless($this->access->learns($request->user(), $offering), 403, 'Only students registered for this course can send requests.');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['topic', 'material', 'explanation'])],
            'title' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:3000'],
        ]);
        abort_if(TopicRequest::where('course_offering_id', $offering->id)->where('user_id', $request->user()->id)->where('created_at', '>', now()->subDay())->count() >= 10, 429, 'You have sent many requests today. Try again tomorrow.');
        $r = TopicRequest::create($data + ['course_offering_id' => $offering->id, 'user_id' => $request->user()->id, 'votes' => 1]);
        TopicRequestVote::create(['topic_request_id' => $r->id, 'user_id' => $request->user()->id]);
        foreach ($this->staff($offering) as $u) {
            $u->notify(new LmsNotice('A student asked for: '.$r->title, ($offering->course?->code ?? 'Course').' · '.ucfirst($r->kind), '/learn/courses/'.$offering->id.'?tab=requests', category: 'requests'));
        }

        return back()->with('success', 'Your request was sent to the lecturer. Others can add their vote.');
    }

    public function voteRequest(Request $request, TopicRequest $topic): RedirectResponse
    {
        $offering = CourseOffering::findOrFail($topic->course_offering_id);
        abort_unless($this->access->learns($request->user(), $offering), 403);
        DB::transaction(function () use ($topic, $request) {
            $existing = TopicRequestVote::where('topic_request_id', $topic->id)->where('user_id', $request->user()->id)->first();
            $existing ? $existing->delete() : TopicRequestVote::create(['topic_request_id' => $topic->id, 'user_id' => $request->user()->id]);
            $topic->update(['votes' => TopicRequestVote::where('topic_request_id', $topic->id)->count()]);
        });

        return back();
    }

    public function respondRequest(Request $request, TopicRequest $topic): RedirectResponse
    {
        $offering = CourseOffering::findOrFail($topic->course_offering_id);
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'planned', 'covered', 'declined'])],
            'reply' => ['nullable', 'string', 'max:3000'],
            'course_item_id' => ['nullable', 'integer', Rule::exists('course_items', 'id')->where('course_offering_id', $offering->id)],
        ]);
        $topic->update($data + ['replied_by' => $request->user()->id, 'replied_at' => now()]);
        $words = ['open' => 'is open', 'planned' => 'is planned', 'covered' => 'is now covered', 'declined' => 'was answered'];
        $voters = User::whereIn('id', TopicRequestVote::where('topic_request_id', $topic->id)->pluck('user_id'))->get();
        foreach ($voters as $u) {
            $u->notify(new LmsNotice('Your request "'.$topic->title.'" '.$words[$data['status']], $data['reply'] ?: ($offering->course?->code ?? 'Course'), '/learn/courses/'.$offering->id.'?tab=requests', category: 'requests'));
        }

        return back()->with('success', 'Reply sent to the students who asked.');
    }

    public function deleteRequest(Request $request, TopicRequest $topic): RedirectResponse
    {
        $offering = CourseOffering::findOrFail($topic->course_offering_id);
        abort_unless($topic->user_id === $request->user()->id || $this->access->teaches($request->user(), $offering), 403);
        $topic->delete();

        return back()->with('success', 'Request removed.');
    }

    // ── a student's notes and bookmarks ─────────────────────────────────────

    public function note(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        $data = $request->validate(['body' => ['sometimes', 'nullable', 'string', 'max:20000'], 'bookmarked' => ['sometimes', 'boolean']]);
        CourseItemNote::updateOrCreate(['course_item_id' => $item->id, 'user_id' => $request->user()->id], $data);

        return back();
    }

    /** Take back "done" on a material (not on assessments, which are completed by submitting). */
    public function undo(Request $request, CourseItem $item): RedirectResponse
    {
        $this->access->authorizeOpen($request->user(), $item);
        abort_if(in_array($item->type, ['assignment', 'quiz'], true), 422);
        DB::table('item_completions')->where('course_item_id', $item->id)->where('user_id', $request->user()->id)->delete();

        return back();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function staff(CourseOffering $offering)
    {
        return User::whereIn('id', DB::table('course_offering_staff')->where('course_offering_id', $offering->id)->pluck('user_id'))->get();
    }

    private function tellStudents(CourseOffering $offering, string $title, string $body, string $url): void
    {
        User::whereIn('id', DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->pluck('user_id'))
            ->get()->each(fn ($u) => $u->notify(new LmsNotice($title, $body, $url)));
    }
}
