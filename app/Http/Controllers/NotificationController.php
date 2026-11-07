<?php

namespace App\Http\Controllers;

use App\Support\NotificationCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification centre: everything sent to this person, by kind, read / unread, with mark-as-read,
 * mark-all-read and delete. Only the signed-in person's own notifications are ever touched.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $category = (string) $request->query('category', '');
        $unread = $request->boolean('unread');
        $query = $user->notifications()->latest()
            ->when($category !== '', fn ($q) => $category === 'course'
                ? $q->where(fn ($w) => $w->where('data->category', 'course')->orWhereNull('data->category'))
                : $q->where('data->category', $category))
            ->when($unread, fn ($q) => $q->whereNull('read_at'));
        $counts = $user->unreadNotifications()->get(['data'])->groupBy(fn ($n) => $n->data['category'] ?? 'course')->map->count();

        return Inertia::render('learn/notifications', [
            'notifications' => $query->paginate(30)->withQueryString()->through(fn ($n) => [
                'id' => $n->id, 'data' => $n->data + ['category' => $n->data['category'] ?? 'course'], 'read' => $n->read_at !== null, 'created_at' => $n->created_at]),
            'categories' => collect(NotificationCategories::ALL)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'unread' => (int) ($counts[$k] ?? 0)])->values(),
            'filters' => ['category' => $category ?: null, 'unread' => $unread],
            'unread' => $counts->sum(),
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->markAsRead();

        return $request->boolean('open') && ($url = $n->data['url'] ?? null) && str_starts_with($url, '/') ? redirect($url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $q = $request->user()->unreadNotifications();
        if ($c = $request->input('category')) {
            $q->where('data->category', $c);
        }
        $n = $q->update(['read_at' => now()]);

        return back()->with('success', $n ? "$n marked as read." : 'Nothing unread.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->delete() || abort(404);

        return back();
    }

    /** Delete everything already read. */
    public function clear(Request $request): RedirectResponse
    {
        $n = $request->user()->readNotifications()->delete();

        return back()->with('success', $n ? "$n read notifications deleted." : 'Nothing to delete.');
    }

    public function preferences(Request $request): Response
    {
        $prefs = (array) ($request->user()->notification_preferences ?? []);

        return Inertia::render('settings/notifications', [
            'categories' => collect(NotificationCategories::ALL)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'about' => $c[1], 'mandatory' => $c[2],
                'app' => $c[2] ? true : (bool) ($prefs[$k]['app'] ?? true), 'mail' => $c[2] ? true : (bool) ($prefs[$k]['mail'] ?? true)])->values(),
        ]);
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate(['prefs' => ['required', 'array'], 'prefs.*.app' => ['boolean'], 'prefs.*.mail' => ['boolean']]);
        $out = [];
        foreach (NotificationCategories::ALL as $k => [, , $mandatory]) {
            if (! $mandatory && isset($data['prefs'][$k])) {
                $out[$k] = ['app' => (bool) ($data['prefs'][$k]['app'] ?? true), 'mail' => (bool) ($data['prefs'][$k]['mail'] ?? true)];
            }
        }
        $request->user()->forceFill(['notification_preferences' => $out])->save();

        return back()->with('success', 'Notification preferences saved. Security alerts always reach you.');
    }

    /** Hide a platform announcement banner for this person. */
    public function dismissAnnouncement(Request $request, int $id): RedirectResponse
    {
        $a = \App\Models\PlatformAnnouncement::where('dismissible', true)->findOrFail($id);
        DB::table('announcement_dismissals')->insertOrIgnore(['announcement_id' => $a->id, 'user_id' => $request->user()->id, 'created_at' => now()]);

        return back();
    }

    /** For the bell in the top bar. */
    public static function unreadCount(?\App\Models\User $user): int
    {
        return $user ? (int) DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->whereNull('read_at')->count() : 0;
    }
}
