<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Support\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Private messages (LMS 92-95): students can always write to the people who teach them;
 * classmates only if the institution allows it; staff can write to their students and colleagues.
 * Anyone can report a message, and reports go to the registry.
 */
class MessageController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Ids of everyone this person may start a conversation with. */
    private function allowed(User $u)
    {
        $tid = tenant_id();
        if ($u->can('users.manage')) {
            return null; // anyone in the institution
        }
        $myOfferings = DB::table('enrolments')->where('user_id', $u->id)->where('status', 'active')->pluck('course_offering_id')
            ->merge(DB::table('course_offering_staff')->where('user_id', $u->id)->pluck('course_offering_id'));
        $teachers = DB::table('course_offering_staff')->whereIn('course_offering_id', $myOfferings)->pluck('user_id');
        if ($u->user_type === 'staff') {
            $students = DB::table('enrolments')->whereIn('course_offering_id', $myOfferings)->where('status', 'active')->pluck('user_id');
            $staff = DB::table('users')->where('tenant_id', $tid)->where('user_type', 'staff')->where('status', 'active')->pluck('id');

            return $teachers->merge($students)->merge($staff)->unique();
        }
        $classmates = Policy::get('student_messaging') ? DB::table('enrolments')->whereIn('course_offering_id', $myOfferings)->where('status', 'active')->pluck('user_id') : collect();

        return $teachers->merge($classmates)->unique();
    }

    private function participant(Conversation $c, User $u): void
    {
        abort_unless(DB::table('conversation_participants')->where('conversation_id', $c->id)->where('user_id', $u->id)->exists(), 404);
    }

    public function index(Request $request): Response
    {
        $u = $request->user();
        $convs = Conversation::query()->join('conversation_participants as p', fn ($j) => $j->on('p.conversation_id', '=', 'conversations.id')->where('p.user_id', $u->id))
            ->orderByDesc('conversations.last_message_at')->limit(100)
            ->get(['conversations.*', 'p.last_read_at', 'p.muted']);
        $ids = $convs->pluck('id');
        $people = DB::table('conversation_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->whereIn('p.conversation_id', $ids)->where('p.user_id', '!=', $u->id)
            ->get(['p.conversation_id', 'u.name'])->groupBy('conversation_id');
        $last = DB::table('messages')->whereIn('id', DB::table('messages')->whereIn('conversation_id', $ids)->selectRaw('MAX(id)')->groupBy('conversation_id'))->get()->keyBy('conversation_id');

        return Inertia::render('learn/messages', [
            'conversations' => $convs->map(fn ($c) => [
                'id' => $c->id, 'subject' => $c->subject, 'with' => ($people[$c->id] ?? collect())->pluck('name')->join(', '), 'last_message_at' => $c->last_message_at,
                'preview' => mb_substr((string) ($last[$c->id]->body ?? ''), 0, 120), 'unread' => $c->last_message_at && (! $c->last_read_at || $c->last_message_at > $c->last_read_at), 'muted' => (bool) $c->muted,
            ]),
            'open' => $request->integer('c') ? $this->thread(Conversation::findOrFail($request->integer('c')), $u) : null,
        ]);
    }

    private function thread(Conversation $c, User $u): array
    {
        $this->participant($c, $u);
        DB::table('conversation_participants')->where('conversation_id', $c->id)->where('user_id', $u->id)->update(['last_read_at' => now()]);

        return ['id' => $c->id, 'subject' => $c->subject,
            'people' => DB::table('conversation_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.conversation_id', $c->id)->get(['u.id', 'u.name']),
            'messages' => DB::table('messages as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.conversation_id', $c->id)->orderBy('m.id')->limit(500)
                ->get(['m.id', 'm.body', 'm.created_at', 'u.name', 'm.user_id'])->map(fn ($m) => (array) $m + ['mine' => $m->user_id === $u->id])];
    }

    public function recipients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        abort_if(mb_strlen($q) < 2, 422);
        $allowed = $this->allowed($request->user());

        return response()->json(User::query()->whereNotNull('tenant_id')->where('status', 'active')->where('id', '!=', $request->user()->id)->where('user_type', '!=', 'sandbox')
            ->when($allowed !== null, fn ($w) => $w->whereIn('id', $allowed))
            ->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('identifier', 'like', addcslashes($q, '%_\\').'%'))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'identifier', 'user_type']));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'array', 'min:1', 'max:30'], 'to.*' => ['integer'], 'subject' => ['nullable', 'string', 'max:190'], 'body' => ['required', 'string', 'max:10000']]);
        $u = $request->user();
        $allowed = $this->allowed($u);
        $to = User::whereIn('id', $data['to'])->whereNotNull('tenant_id')->where('status', 'active')->where('id', '!=', $u->id)->pluck('id');
        abort_if($to->isEmpty() || ($allowed !== null && $to->diff($allowed)->isNotEmpty()), 403, 'You cannot message some of these people.');
        $c = DB::transaction(function () use ($data, $u, $to) {
            $c = Conversation::create(['subject' => $data['subject'] ?? null, 'created_by' => $u->id, 'last_message_at' => now()]);
            DB::table('conversation_participants')->insert($to->push($u->id)->unique()->map(fn ($id) => ['conversation_id' => $c->id, 'user_id' => $id, 'last_read_at' => $id === $u->id ? now() : null])->all());
            DB::table('messages')->insert(['conversation_id' => $c->id, 'user_id' => $u->id, 'body' => $data['body'], 'created_at' => now()]);

            return $c;
        });
        $this->notify($c, $u);

        return redirect()->route('messages.index', ['c' => $c->id]);
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $this->participant($conversation, $request->user());
        DB::table('messages')->insert(['conversation_id' => $conversation->id, 'user_id' => $request->user()->id, 'body' => $data['body'], 'created_at' => now()]);
        $conversation->update(['last_message_at' => now()]);
        DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)->update(['last_read_at' => now()]);
        $this->notify($conversation, $request->user());

        return back();
    }

    public function mute(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->participant($conversation, $request->user());
        DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)->update(['muted' => DB::raw('NOT muted')]);

        return back();
    }

    public function report(Request $request, int $message): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $m = DB::table('messages')->where('id', $message)->first() ?? abort(404);
        $this->participant(Conversation::findOrFail($m->conversation_id), $request->user());
        DB::table('message_reports')->insert(['tenant_id' => tenant_id(), 'message_id' => $m->id, 'reported_by' => $request->user()->id, 'reason' => $data['reason'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('message.reported', null, ['message' => $m->id]);

        return back()->with('success', 'Reported. The registry will look at it.');
    }

    /** One notification per conversation every 10 minutes, so busy threads do not flood anyone. */
    private function notify(Conversation $c, User $from): void
    {
        $users = User::whereIn('id', DB::table('conversation_participants')->where('conversation_id', $c->id)->where('user_id', '!=', $from->id)->where('muted', false)->pluck('user_id'))->get();
        foreach ($users as $u) {
            if (Cache::add("msgnote:{$c->id}:{$u->id}", 1, now()->addMinutes(10))) {
                $u->notify(new LmsNotice('New message from '.$from->name, $c->subject ?: 'You have a new message.', '/messages?c='.$c->id, category: 'messages'));
            }
        }
    }
}
