<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_form_an_unbroken_chain(): void
    {
        $audit = app(AuditLogger::class);
        $a = $audit->record('exam.published', null, ['exam' => 1]);
        $b = $audit->record('result.changed', null, ['from' => 40, 'to' => 55]);

        $this->assertNull($a->prev_hash);
        $this->assertSame($a->hash, $b->prev_hash);
        $this->assertNull($audit->verify($this->tenant->id));
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_editing_a_past_entry_is_detected(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('result.changed', null, ['to' => 55]);
        $second = $audit->record('result.changed', null, ['to' => 60]);
        $audit->record('result.changed', null, ['to' => 70]);

        // someone edits the database directly
        DB::table('audit_logs')->where('id', $second->id)->update(['data' => json_encode(['to' => 99])]);

        $this->assertSame($second->id, $audit->verify($this->tenant->id));
        $this->artisan('audit:verify')->assertFailed();
    }

    public function test_the_model_refuses_updates_and_deletes(): void
    {
        $log = app(AuditLogger::class)->record('x');
        $log->action = 'changed';

        $this->assertFalse($log->save());
        $this->assertFalse($log->delete());
        $this->assertSame('x', AuditLog::find($log->id)->action);
    }
}
