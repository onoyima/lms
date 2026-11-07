<?php

namespace App\Services\Integrations;

use App\Models\AssignmentSubmission;
use App\Models\PlagiarismCheck;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends a submission to the institution's plagiarism service (Turnitin-style) and records the
 * similarity score it returns (LMS 222). The protocol is plain signed JSON so any provider, or a
 * small adapter in front of one, can implement it:
 *
 *   → POST {url}   X-Platform-Signature: hmac_sha256(body, secret)
 *     {"reference", "callback_url", "submission": {"id", "course", "assignment", "filename", "mime", "content_base64" | "text"}}
 *   ← 200 {"id": "...", "status": "queued|complete", "score": 12.5, "report_url": "https://..."}
 *   ← later: POST callback_url, same signature, {"status": "complete", "score": 12.5, "report_url": "..."}
 */
class PlagiarismConnector
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private Connectors $connectors) {}

    public function enabled(): bool
    {
        $c = $this->connectors->get('plagiarism');

        return $c['enabled'] && $c['url'] && $c['secret'];
    }

    public function send(AssignmentSubmission $sub, ?User $by = null): PlagiarismCheck
    {
        $c = $this->connectors->get('plagiarism');
        abort_unless($this->enabled(), 422, 'The plagiarism service is not set up. An administrator can connect it under Integrations.');
        abort_unless(Connectors::safeUrl($c['url']), 422, 'The plagiarism service address is not allowed.');
        $check = PlagiarismCheck::create(['assignment_submission_id' => $sub->id, 'provider' => $c['name'] ?: 'Plagiarism service', 'token' => Str::random(40),
            'status' => 'queued', 'requested_by' => $by?->id]);
        $item = $sub->item()->with('offering.course:id,code')->first();
        $payload = ['reference' => $check->token, 'callback_url' => route('api.plagiarism.callback', $check->token),
            'submission' => ['id' => $sub->id, 'course' => $item?->offering?->course?->code, 'assignment' => $item?->title, 'filename' => $sub->file_name, 'mime' => $sub->file_mime]];
        if ($sub->file_path && Storage::disk('local')->exists($sub->file_path) && Storage::disk('local')->size($sub->file_path) <= self::MAX_BYTES) {
            $payload['submission']['content_base64'] = base64_encode(Storage::disk('local')->get($sub->file_path));
        } else {
            $payload['submission']['text'] = (string) $sub->text;
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        try {
            $res = Http::timeout(30)->withHeaders(['X-Platform-Signature' => Connectors::sign($body, $c['secret']), 'Content-Type' => 'application/json'])
                ->withBody($body, 'application/json')->post($c['url']);
            if (! $res->successful()) {
                $check->update(['status' => 'failed', 'error' => 'The service answered '.$res->status().'.']);

                return $check;
            }
            $this->apply($check, (array) $res->json() + ['status' => 'sent']);
        } catch (\Throwable $e) {
            $check->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480)]);
        }

        return $check->fresh();
    }

    /** A result from the provider, by direct reply or by signed callback. */
    public function apply(PlagiarismCheck $check, array $r): void
    {
        $score = isset($r['score']) && is_numeric($r['score']) ? max(0, min(100, (float) $r['score'])) : null;
        $url = isset($r['report_url']) && is_string($r['report_url']) && str_starts_with($r['report_url'], 'https://') ? Str::limit($r['report_url'], 490, '') : null;
        $status = in_array($r['status'] ?? null, ['complete', 'failed'], true) ? $r['status'] : ($score !== null ? 'complete' : 'sent');
        $check->update(array_filter(['status' => $status, 'score' => $score, 'report_url' => $url, 'external_id' => isset($r['id']) ? Str::limit((string) $r['id'], 250, '') : null,
            'error' => $status === 'failed' ? Str::limit((string) ($r['error'] ?? 'The service could not check this file.'), 480) : null], fn ($v) => $v !== null));
    }
}
