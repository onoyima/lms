<?php

namespace App\Http\Controllers\Conferencing;

use App\Conferencing\BbbClient;
use App\Conferencing\BbbException;
use App\Http\Controllers\Controller;
use App\Models\ConferenceServer;
use App\Models\Meeting;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BigBlueButton servers. The platform console manages the shared ones (tenant_id NULL); an institution with
 * conferencing.manage can add its own, which its meetings then prefer. Secrets are write-only.
 */
class ServerController extends Controller
{
    public function __construct(private AuditLogger $audit, private TenantContext $context) {}

    private function scopeId(Request $request): ?int
    {
        return $request->is('platform/*') ? null : $this->context->id();
    }

    private function own(Request $request, int $id): ConferenceServer
    {
        $tid = $this->scopeId($request);

        return ConferenceServer::query()->when($tid === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tid))->findOrFail($id);
    }

    public function index(Request $request): Response
    {
        $tid = $this->scopeId($request);
        $servers = ConferenceServer::query()->when($tid === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tid))->orderBy('name')->get();
        $live = Meeting::withoutGlobalScopes()->where('status', 'live')->when($tid, fn ($q) => $q->where('tenant_id', $tid));
        $since = now()->subDays(30);
        $base = Meeting::withoutGlobalScopes()->when($tid, fn ($q) => $q->where('tenant_id', $tid));

        return Inertia::render($tid === null ? 'platform/conferencing' : 'admin/conferencing', [
            'servers' => $servers->map(fn ($s) => $s->only(['id', 'name', 'base_url', 'checksum_algo', 'enabled', 'weight', 'max_participants', 'status', 'version', 'live_meetings', 'live_participants', 'last_error', 'checked_at'])
                + ['webhook_url' => route('conferencing.webhook', $s->id)]),
            'shared' => $tid ? ConferenceServer::whereNull('tenant_id')->where('enabled', true)->count() : null,
            'stats' => [
                'live' => (clone $live)->count(),
                'in_meetings_now' => DB::table('meeting_attendance')->whereNull('left_at')->whereIn('meeting_id', (clone $live)->select('id'))->count(),
                'meetings_30d' => (clone $base)->where('starts_at', '>=', $since)->where('status', 'ended')->count(),
                'hours_30d' => round(DB::table('meeting_attendance')->whereIn('meeting_id', (clone $base)->where('starts_at', '>=', $since)->select('id'))->sum('seconds') / 3600, 1),
                'webhook_events_24h' => DB::table('meeting_events')->when($tid, fn ($q) => $q->where('tenant_id', $tid))->where('created_at', '>=', now()->subDay())->count(),
            ],
            'liveMeetings' => $tid ? (clone $live)->orderBy('started_at')->limit(50)->get(['uuid', 'title', 'type', 'started_at']) : [],
        ]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:120'], 'base_url' => ['required', 'url:https,http', 'max:255'],
            'secret' => [$creating ? 'required' : 'nullable', 'string', 'min:16', 'max:255'], 'checksum_algo' => ['required', Rule::in(['sha1', 'sha256', 'sha384', 'sha512'])],
            'enabled' => ['boolean'], 'weight' => ['nullable', 'integer', 'min:1', 'max:1000'], 'max_participants' => ['nullable', 'integer', 'min:2'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(true));
        $s = ConferenceServer::create($data + ['tenant_id' => $this->scopeId($request), 'weight' => $data['weight'] ?? 100]);
        $this->audit->record('conferencing.server_added', $s, ['name' => $s->name, 'url' => $s->base_url]);

        return back()->with('success', 'Server added. Check it, then register the webhook so attendance is recorded.');
    }

    public function update(Request $request, int $server): RedirectResponse
    {
        $s = $this->own($request, $server);
        $data = $request->validate($this->rules(false));
        if (empty($data['secret'])) {
            unset($data['secret']); // left blank: keep the stored secret
        }
        $s->update($data);
        $this->audit->record('conferencing.server_updated', $s, array_diff_key($data, ['secret' => 1]) + ['secret_changed' => isset($data['secret'])]);

        return back()->with('success', 'Server saved.');
    }

    public function destroy(Request $request, int $server): RedirectResponse
    {
        $s = $this->own($request, $server);
        abort_if(Meeting::withoutGlobalScopes()->where('conference_server_id', $s->id)->where('status', 'live')->exists(), 409, 'Meetings are running on this server. Switch it off instead, and remove it later.');
        $this->audit->record('conferencing.server_removed', $s, ['name' => $s->name]);
        $s->delete();

        return back()->with('success', 'Server removed.');
    }

    /** Ask the server how it is, and (optionally) register our webhook on it. */
    public function check(Request $request, int $server): RedirectResponse
    {
        $s = $this->own($request, $server);
        try {
            $h = BbbClient::for($s)->health();
            $s->update(['status' => 'online', 'version' => $h['version'], 'live_meetings' => $h['meetings'], 'live_participants' => $h['participants'], 'last_error' => null, 'checked_at' => now()]);
            if ($request->boolean('register_webhook')) {
                BbbClient::for($s)->registerHook(route('conferencing.webhook', $s->id));
            }
        } catch (BbbException $e) {
            $s->update(['status' => 'offline', 'last_error' => mb_substr($e->getMessage(), 0, 255), 'checked_at' => now()]);

            return back()->with('error', $s->name.': '.$e->getMessage());
        }

        return back()->with('success', $s->name.' is online'.($h['version'] ? " (BigBlueButton {$h['version']})" : '').": {$h['meetings']} meeting(s), {$h['participants']} people.".($request->boolean('register_webhook') ? ' Webhook registered.' : ''));
    }
}
