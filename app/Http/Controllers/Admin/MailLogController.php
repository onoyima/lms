<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** E-mail delivery status for the institution (LMS 188), with bounce reports from the mail service. */
class MailLogController extends Controller
{
    public function index(Request $request): Response
    {
        $q = MailLog::where('tenant_id', tenant_id());
        $status = $request->query('status');
        $search = trim((string) $request->query('q'));

        return Inertia::render('admin/mail-log', [
            'rows' => (clone $q)->when($status, fn ($w) => $w->where('status', $status))
                ->when($search !== '', fn ($w) => $w->where(fn ($x) => $x->where('to', 'like', "%$search%")->orWhere('subject', 'like', "%$search%")))
                ->latest('id')->limit(300)->get(['id', 'to', 'subject', 'kind', 'status', 'error', 'created_at', 'updated_at'])
                ->map(fn ($r) => array_merge($r->toArray(), ['kind' => class_basename((string) $r->kind)])),
            'counts' => (clone $q)->where('created_at', '>', now()->subDays(7))->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status'),
            'filters' => ['status' => $status, 'q' => $search],
        ]);
    }

    /**
     * The mail service reports bounces and complaints here, signed with PLATFORM_MAIL_WEBHOOK_SECRET
     * (HMAC-SHA256 of the body in X-Platform-Signature). Body: {"message_id": "...", "event": "bounced", "reason": "..."}
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('platform.mail_webhook_secret');
        $sig = (string) $request->header('X-Platform-Signature');
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $data = $request->validate(['message_id' => ['required', 'string', 'max:250'], 'event' => ['required', 'in:delivered,bounced,complained,deferred'], 'reason' => ['nullable', 'string', 'max:480']]);
        $status = ['delivered' => 'sent', 'bounced' => 'bounced', 'complained' => 'bounced', 'deferred' => 'sending'][$data['event']];
        $n = MailLog::where('message_id', trim($data['message_id'], '<>'))->orWhere('message_id', $data['message_id'])->update(['status' => $status, 'error' => $data['reason'] ?? null]);

        return response()->json(['updated' => $n]);
    }
}
