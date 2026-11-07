<?php

namespace App\Bridges\Vuna\Console;

use App\Bridges\Vuna\Oidc\Tokens;
use App\Bridges\Vuna\Portal;
use Illuminate\Console\Command;

/** Checks the bridge's set-up without changing anything: database on this computer, readable tables, who is active now. */
class BridgeCheck extends Command
{
    protected $signature = 'vuna:bridge-check';

    protected $description = 'Check the portal bridge: local database, tables it reads, active semester(s) and how many students and staff are active';

    public function handle(Portal $portal): int
    {
        $this->line('Bridge address (issuer): '.Tokens::issuer());
        $this->line('Switched on: '.(config('vuna_bridge.enabled') ? 'yes' : 'no (VUNA_BRIDGE_ENABLED=false)'));
        if ($reason = config('vuna_bridge.blocked')) {
            $this->error($reason.' Set VUNA_DB_HOST to 127.0.0.1 (or localhost) and copy the portal database to this computer.');

            return self::FAILURE;
        }
        $db = (array) config('vuna_bridge.db');
        if (! \App\Bridges\Vuna\VunaBridgeServiceProvider::isLocal($db)) {
            $this->warn("Reading a REMOTE portal database at {$db['host']} (VUNA_DB_ALLOW_REMOTE=true). The bridge only reads: the session is read-only and every statement is checked.");
        }
        try {
            $portal->db()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot open the portal database: '.$e->getMessage());
            if (! \App\Bridges\Vuna\VunaBridgeServiceProvider::isLocal($db)) {
                $this->line('For a hosted database (e.g. SiteGround: Site Tools > Site > MySQL > Remote) allow this computer\'s public IP address, and check host, port, user and password.');
            }

            return self::FAILURE;
        }

        $tables = ['staff', 'students', 'users', 'student_academics', 'course_regs', 'courses', 'vu_semesters', 'academic_sessions', 'semesters', 'faculties', 'departments',
            'course_studies', 'staff_work_profiles', 'staff_assigned_roles', 'roles', 'appointment_terminations', 'assigned_courses', 'course_assigneds', 'applicant_bio_data'];
        $this->table(['Table', 'Found'], array_map(fn ($t) => [$t, $portal->has($t) ? 'yes' : 'no'], $tables));
        foreach (['staff', 'students', 'student_academics', 'courses', 'vu_semesters'] as $required) {
            if (! $portal->has($required)) {
                $this->error("The portal table \"$required\" is missing — is VUNA_DB_DATABASE the portal database?");

                return self::FAILURE;
            }
        }

        $semesters = $portal->activeSemesters();
        $semesters->isEmpty()
            ? $this->warn('No semester is marked active (vu_semesters.status = '.config('vuna_bridge.rules.active_semester_status').'): registrations and teaching will be empty.')
            : $this->table(['Semester id', 'Session', 'Semester', 'Programme', 'Shown as'], $semesters->map(fn ($s) => [$s['id'], $s['session'], $s['semester'], $s['programme'] ?? '—', $s['running'] ? 'current' : 'previous'])->all());
        foreach ($portal->counts() as $k => $v) {
            $this->line(str_pad(str_replace('_', ' ', $k), 20).": $v");
        }
        $client = config('vuna_bridge.clients.0');
        if (empty($client['secret'])) {
            $this->warn('VUNA_BRIDGE_CLIENT_SECRET is empty: no application can use the bridge until it is set.');
        }
        if (empty($client['redirect_uris'])) {
            $this->warn('VUNA_BRIDGE_REDIRECT_URIS is empty: sign-in cannot return anywhere until it is set.');
        }
        $this->info('The bridge can read the portal.');

        return self::SUCCESS;
    }
}
