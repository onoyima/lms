<?php

namespace App\Http\Controllers\Conferencing;

use App\Conferencing\Attendance;
use App\Conferencing\BbbException;
use App\Conferencing\MeetingService;
use App\Conferencing\WebhookHandler;
use App\Http\Controllers\Controller;
use App\Models\ConferenceServer;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Support\Platform\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/** Calls from BigBlueButton servers: webhooks, the end-of-meeting callback, recording ready. No signed-in user; each is verified. */
class CallbackController extends Controller
{
    public function webhook(Request $request, ConferenceServer $server, WebhookHandler $hooks): JsonResponse
    {
        if (! $hooks->verify($request, $server)) {
            Log::warning('Conferencing webhook with a bad checksum', ['server' => $server->id, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid checksum.'], 401);
        }

        return response()->json(['stored' => $hooks->handle($request, $server)]);
    }

    private function meeting(string $uuid): Meeting
    {
        $m = Meeting::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
        app(TenantContext::class)->set(Tenant::find($m->tenant_id));

        return $m;
    }

    /** meta_endCallbackUrl: BigBlueButton calls it when the meeting ends. The address carries our signature. */
    public function ended(Request $request, string $meeting): JsonResponse
    {
        $m = $this->meeting($meeting);
        if ($m->status !== 'ended') {
            $m->update(['status' => 'ended', 'ended_at' => now()]);
        }
        app(Attendance::class)->rebuild($m->fresh());

        return response()->json(['ok' => true]);
    }

    /** meta_bbb-recording-ready-url: a recording was published. */
    public function recordingReady(Request $request, string $meeting, MeetingService $meetings): JsonResponse
    {
        $m = $this->meeting($meeting);
        try {
            $meetings->syncRecordings($m);
        } catch (BbbException $e) {
            Log::warning('Recording sync failed', ['meeting' => $m->uuid, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /** The institution's colours for the meeting screen (BigBlueButton loads it through userdata-bbb_custom_style_url). */
    public function theme(int $tenant): Response
    {
        $t = Tenant::find($tenant);
        $b = $t?->brandPayload() ?? PlatformSettings::brand();
        $c = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['brand'] ?? '')) ? $b['brand'] : '#1d3557';
        $fg = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['brand_foreground'] ?? '')) ? $b['brand_foreground'] : '#ffffff';
        $css = view('conferencing.theme', ['brand' => $c, 'fg' => $fg])->render();

        return response($css, 200, ['Content-Type' => 'text/css; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }
}
