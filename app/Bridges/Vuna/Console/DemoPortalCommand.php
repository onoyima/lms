<?php

namespace App\Bridges\Vuna\Console;

use App\Bridges\Vuna\Demo\DemoPortal;
use App\Bridges\Vuna\VunaBridgeServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates a small demonstration copy of the portal's tables with sample people, so the bridge can be
 * tried without the real portal data. Refuses anything that could be a real portal database.
 */
class DemoPortalCommand extends Command
{
    protected $signature = 'vuna:demo-portal {--force : Rebuild the demo tables if they already exist}';

    protected $description = 'Fill a LOCAL database whose name ends in _demo with sample portal data for trying the bridge';

    public function handle(): int
    {
        $db = (array) config('vuna_bridge.db');
        if (! VunaBridgeServiceProvider::isLocal($db)) {
            $this->error('Refusing: the portal database must be on this computer.');

            return self::FAILURE;
        }
        $name = (string) ($db['database'] ?? '');
        if ($db['driver'] !== 'sqlite' && ! str_ends_with($name, '_demo')) {
            $this->error("Refusing: \"$name\" does not end in _demo. Demo data is only ever written to a database named like vuna_portal_demo.");

            return self::FAILURE;
        }
        unset($db['options']); // the bridge's own connection is read-only; this one writes the demo tables
        config(['database.connections.vuna_demo_writer' => $db]);
        $writer = DB::connection('vuna_demo_writer');
        if ($writer->getSchemaBuilder()->hasTable('students') && ! $this->option('force')) {
            $this->error('Demo tables already exist. Use --force to rebuild them.');

            return self::FAILURE;
        }
        DemoPortal::build($writer);
        DB::purge('vuna_demo_writer');
        $this->info("Demo portal data written to $name.");
        $this->line('Students sign in with password '.DemoPortal::STUDENT_PASSWORD.' (e.g. VUG/ICH/25/001), staff with '.DemoPortal::STAFF_PASSWORD.' (e.g. ngozi.okeke@vuna.test), applicants with '.DemoPortal::APPLICANT_PASSWORD.' (grace.obi@example.com).');

        return self::SUCCESS;
    }
}
