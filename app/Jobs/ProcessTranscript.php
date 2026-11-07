<?php

namespace App\Jobs;

use App\Conferencing\Transcription;
use App\Models\MeetingTranscript;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Makes a transcript (speech to text) or a translation of one, in the background. */
class ProcessTranscript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(public int $transcriptId, public int $tenantId) {}

    public function handle(Transcription $service): void
    {
        app(TenantContext::class)->run(Tenant::findOrFail($this->tenantId), function () use ($service) {
            $t = MeetingTranscript::findOrFail($this->transcriptId);
            try {
                $t->kind === 'translation' ? $service->translate($t) : $service->transcribe($t);
            } catch (\Throwable $e) {
                $t->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
            }
        });
    }
}
