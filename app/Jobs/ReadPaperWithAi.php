<?php

namespace App\Jobs;

use App\Models\QuestionImport;
use App\Models\Tenant;
use App\Services\Ai\PaperReader;
use App\Services\Questions\Import\QuestionImportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reads an uploaded paper with AI in the background; the review page shows the questions when done. */
class ReadPaperWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $importId, public int $tenantId) {}

    public function handle(QuestionImportService $imports): void
    {
        app(TenantContext::class)->run(Tenant::findOrFail($this->tenantId), function () use ($imports) {
            $import = QuestionImport::find($this->importId);
            if (! $import || $import->status !== 'reading') {
                return;
            }
            try {
                $imports->readWithAi($import, app(PaperReader::class));
            } catch (\Throwable $e) {
                report($e);
                $import->update(['status' => 'failed', 'warnings' => [mb_substr($e->getMessage(), 0, 500)]]);
            }
        });
    }
}
