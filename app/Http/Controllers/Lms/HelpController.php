<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Questions\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Help centre and support desk (Target 108, 234; LMS 96-98, 230): searchable answers written by
 * the institution, and tickets that route to the right office with staff-only notes.
 */
class HelpController extends Controller
{
    public const CATEGORIES = ['general', 'technical', 'exam', 'registration', 'results', 'account'];

    public function __construct(private AuditLogger $audit, private RichTextSanitizer $sanitizer) {}

    private function isDesk(User $u): bool
    {
        return $u->canAny(['users.manage', 'exams.manage', 'institution.manage']);
    }

    public function index(Request $request): Response
    {
        $u = $request->user();
        $q = trim((string) $request->query('q'));
        $audience = $u->user_type === 'student' ? ['all', 'students'] : ['all', 'staff', 'students'];
        $articles = HelpArticle::where('published', true)->whereIn('audience', $audience)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('body_html', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->orderBy('category')->orderByDesc('views')->limit(200)->get(['id', 'title', 'slug', 'category', 'audience', 'views']);
        $desk = $this->isDesk($u);

        return Inertia::render('learn/help', [
            'articles' => $articles, 'q' => $q, 'desk' => $desk,
            'tickets' => SupportTicket::query()->when(! $desk || ! $request->boolean('queue'), fn ($w) => $w->where('user_id', $u->id))
                ->when($desk && $request->boolean('queue'), fn ($w) => $w->whereIn('status', ['open', 'pending']))
                ->with('user:id,name,identifier')->orderByDesc('last_activity_at')->limit(100)->get()
                ->map(fn ($t) => ['id' => $t->id, 'subject' => $t->subject, 'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status,
                    'from' => $t->user?->name, 'identifier' => $t->user?->identifier, 'last_activity_at' => $t->last_activity_at, 'assigned_to' => $t->assigned_to]),
            'queue' => $desk && $request->boolean('queue'),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function article(Request $request, string $slug): Response
    {
        $a = HelpArticle::where('slug', $slug)->where('published', true)->firstOrFail();
        abort_if($a->audience === 'staff' && $request->user()->user_type === 'student', 404);
        $a->increment('views');

        return Inertia::render('learn/help-article', ['article' => $a->only(['id', 'title', 'slug', 'category', 'body_html', 'updated_at']), 'canEdit' => $this->isDesk($request->user())]);
    }

    public function feedback(Request $request, HelpArticle $article): RedirectResponse
    {
        $article->increment($request->boolean('helpful') ? 'helpful' : 'not_helpful');

        return back()->with('success', 'Thanks for the feedback.');
    }

    public function saveArticle(Request $request, ?HelpArticle $article = null): RedirectResponse
    {
        abort_unless($this->isDesk($request->user()), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'category' => ['required', 'string', 'max:60'], 'audience' => ['required', 'in:all,students,staff'],
            'body_html' => ['required', 'string', 'max:100000'], 'published' => ['boolean']]);
        $article ??= new HelpArticle(['slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(4))]);
        $article->fill(['body_html' => $this->sanitizer->clean($data['body_html']), 'updated_by' => $request->user()->id] + $data)->save();
        $this->audit->record('help.article_saved', $article);

        return redirect()->route('help.article', $article->slug)->with('success', 'Saved.');
    }

    public function storeTicket(Request $request): RedirectResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:190'], 'category' => ['required', Rule::in(self::CATEGORIES)], 'body' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', 'in:low,normal,high,urgent']]);
        $u = $request->user();
        abort_if(SupportTicket::where('user_id', $u->id)->where('created_at', '>', now()->subHour())->count() >= 5, 429, 'You have opened several requests already. Please wait for a reply.');
        // students cannot mark their own request urgent; an exam in progress is urgent automatically
        $inExam = DB::table('attempts')->where('user_id', $u->id)->whereIn('status', ['in_progress', 'paused', 'locked'])->exists();
        $priority = $inExam ? 'urgent' : ($u->user_type === 'student' ? 'normal' : ($data['priority'] ?? 'normal'));
        $t = DB::transaction(function () use ($data, $u, $priority) {
            $t = SupportTicket::create(['user_id' => $u->id, 'subject' => $data['subject'], 'category' => $data['category'], 'priority' => $priority, 'status' => 'open', 'last_activity_at' => now()]);
            DB::table('ticket_messages')->insert(['support_ticket_id' => $t->id, 'user_id' => $u->id, 'body' => $data['body'], 'internal' => false, 'created_at' => now()]);

            return $t;
        });
        // the right office hears about it
        $perm = ['exam' => 'exams.manage', 'results' => 'results.publish', 'registration' => 'academics.manage', 'account' => 'users.manage', 'technical' => 'institution.manage'][$data['category']] ?? 'institution.manage';
        $desk = User::permission($perm)->where('status', 'active')->limit(20)->get();
        \Illuminate\Support\Facades\Notification::send($desk, new LmsNotice('Support request: '.$data['subject'], ucfirst($priority).' · '.$u->name, '/help/tickets/'.$t->id, $priority === 'urgent', category: 'requests'));

        return redirect()->route('help.ticket', $t)->with('success', 'Request sent. You will be notified when someone replies.');
    }

    public function ticket(Request $request, SupportTicket $ticket): Response
    {
        $u = $request->user();
        $desk = $this->isDesk($u);
        abort_unless($ticket->user_id === $u->id || $desk, 404);

        return Inertia::render('learn/ticket', [
            'ticket' => $ticket->only(['id', 'subject', 'category', 'priority', 'status', 'created_at', 'assigned_to']) + ['from' => User::find($ticket->user_id)?->only(['name', 'identifier', 'email'])],
            'messages' => DB::table('ticket_messages as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.support_ticket_id', $ticket->id)
                ->when(! $desk, fn ($q) => $q->where('m.internal', false))->orderBy('m.id')->get(['m.id', 'm.body', 'm.internal', 'm.created_at', 'u.name', 'm.user_id']),
            'desk' => $desk,
            'staff' => $desk ? User::where('user_type', 'staff')->where('status', 'active')->orderBy('name')->limit(300)->get(['id', 'name']) : [],
        ]);
    }

    public function replyTicket(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $u = $request->user();
        $desk = $this->isDesk($u);
        abort_unless($ticket->user_id === $u->id || $desk, 404);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:10000'], 'internal' => ['boolean'], 'status' => ['nullable', 'in:open,pending,resolved,closed'],
            'assigned_to' => ['nullable', 'integer'], 'priority' => ['nullable', 'in:low,normal,high,urgent']]);
        $internal = $desk && ($data['internal'] ?? false);
        if (! empty($data['body'])) {
            DB::table('ticket_messages')->insert(['support_ticket_id' => $ticket->id, 'user_id' => $u->id, 'body' => $data['body'], 'internal' => $internal, 'created_at' => now()]);
        }
        $changes = ['last_activity_at' => now()];
        if ($desk) {
            foreach (['status', 'priority'] as $k) {
                ! empty($data[$k]) && $changes[$k] = $data[$k];
            }
            array_key_exists('assigned_to', $data) && $changes['assigned_to'] = $data['assigned_to'] ? User::where('user_type', 'staff')->findOrFail($data['assigned_to'])->id : null;
        } elseif ($ticket->status !== 'open') {
            $changes['status'] = 'open'; // the person wrote back
        }
        isset($changes['status']) && in_array($changes['status'], ['resolved', 'closed'], true) && $changes['resolved_at'] = now();
        $ticket->update($changes);
        if (! empty($data['body']) && ! $internal && $ticket->user_id !== $u->id) {
            User::find($ticket->user_id)?->notify(new LmsNotice('Reply to your request', $ticket->subject, '/help/tickets/'.$ticket->id, category: 'requests'));
        }

        return back();
    }
}
