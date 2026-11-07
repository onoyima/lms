<?php

use App\Models\Exam;
use App\Models\Tenant;
use App\Services\Analytics\ExamAnalytics;
use App\Services\Evidence\EvidenceStore;
use App\Services\Exams\AttemptService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Closes attempts whose server deadline has passed, even if the candidate's computer died (Target 22, 98, 190)
Artisan::command('exams:sweep', function (AttemptService $attempts) {
    $this->info($attempts->sweepExpired().' expired attempts submitted.');
})->purpose('Auto-submit attempts past their deadline');

// Retention: securely delete evidence past its retention date (Target 91, 180)
Artisan::command('evidence:purge', function (EvidenceStore $store) {
    $this->info($store->purgeExpired().' evidence files deleted.');
})->purpose('Delete proctoring evidence past its retention date');

// Integrity check of stored evidence (Target 39)
Artisan::command('evidence:verify {--limit=1000}', function (EvidenceStore $store) {
    $bad = 0;
    \App\Models\EvidenceFile::query()->withoutGlobalScopes()->latest('id')->limit((int) $this->option('limit'))->get()
        ->each(function ($f) use ($store, &$bad) {
            if (! $store->verify($f)) {
                $bad++;
                $this->error("Evidence #{$f->id} failed its integrity check.");
            }
        });
    $this->info($bad ? "$bad files failed." : 'All checked evidence is intact.');

    return $bad ? 1 : 0;
})->purpose('Re-hash stored evidence and report tampering');

// Item statistics for closed exams (Target 66-67)
Artisan::command('analytics:items', function (ExamAnalytics $analytics, TenantContext $ctx) {
    foreach (Tenant::all() as $tenant) {
        $ctx->run($tenant, function () use ($analytics) {
            Exam::whereIn('status', ['closed', 'results_published'])->each(fn ($e) => $analytics->itemAnalysis($e));
        });
    }
})->purpose('Recompute item analysis');

// System health (Target 101, 184-185): database round trip, queue backlog, storage, open attempts
Artisan::command('system:health', function () {
    $t = microtime(true);
    DB::select('select 1');
    $dbMs = round((microtime(true) - $t) * 1000, 1);
    $free = @disk_free_space(storage_path()) ?: 0;
    $this->table(['check', 'value'], [
        ['database round trip', "$dbMs ms"],
        ['queued jobs', DB::table('jobs')->count()],
        ['failed jobs', DB::table('failed_jobs')->count()],
        ['open attempts', DB::table('attempts')->whereIn('status', ['in_progress', 'paused', 'locked'])->count()],
        ['free disk', round($free / 1073741824, 1).' GB'],
    ]);
})->purpose('Show platform health');

Schedule::command('exams:sweep')->everyMinute()->withoutOverlapping();

// Wipe hall-computer approvals once each sitting is over, so the next exam starts with a clean list (rows stay as history)
Artisan::command('exams:clear-computers', function () {
    $n = 0;
    foreach (\App\Models\Tenant::query()->where('status', 'active')->get() as $tenant) {
        $n += app(\App\Support\Tenancy\TenantContext::class)->run($tenant, fn () => app(\App\Services\Exams\ExamComputers::class)->autoClear());
    }
    $this->info("$n approvals cleared.");
})->purpose('Clear exam computer approvals after sittings end');
Schedule::command('exams:clear-computers')->everyFiveMinutes()->withoutOverlapping();

// Live classes: check every meeting server, close rooms left "live" after the server forgot them, fetch new recordings
Artisan::command('conferencing:check', function () {
    $online = 0;
    $servers = \App\Models\ConferenceServer::withoutGlobalScopes()->where('enabled', true)->get();
    foreach ($servers as $s) {
        try {
            $h = \App\Conferencing\BbbClient::for($s)->health();
            $s->update(['status' => 'online', 'version' => $h['version'] ?? $s->version, 'live_meetings' => $h['meetings'], 'live_participants' => $h['participants'], 'last_error' => null, 'checked_at' => now()]);
            $online++;
        } catch (\Throwable $e) {
            $s->update(['status' => 'offline', 'last_error' => mb_substr($e->getMessage(), 0, 255), 'checked_at' => now()]);
        }
    }
    $closed = 0;
    $synced = 0;
    foreach (\App\Models\Tenant::query()->where('status', 'active')->get() as $tenant) {
        app(\App\Support\Tenancy\TenantContext::class)->run($tenant, function () use (&$closed, &$synced) {
            if (! app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(app(\App\Support\Tenancy\TenantContext::class)->get(), 'conferencing')) {
                return;
            }
            foreach (\App\Models\Meeting::with('server')->where('status', 'live')->where('starts_at', '<', now()->subMinutes(30))->get() as $m) {
                if ($m->starts_at->copy()->addMinutes($m->duration_minutes + 30)->isFuture() || ! $m->server || $m->server->status !== 'online') {
                    continue;
                }
                try {
                    if (! \App\Conferencing\BbbClient::for($m->server)->isRunning($m->bbb_meeting_id)) {
                        $m->update(['status' => 'ended', 'ended_at' => $m->ended_at ?? now()]);
                        app(\App\Conferencing\Attendance::class)->rebuild($m);
                        $closed++;
                    }
                } catch (\Throwable) {
                }
            }
            $recent = \App\Models\Meeting::with('server')->where('status', 'ended')->where('ended_at', '>', now()->subDays(3))->get()
                ->filter(fn ($m) => $m->setting('record') || $m->setting('auto_record'));
            foreach ($recent as $m) {
                if ($m->server?->status !== 'online') {
                    continue;
                }
                try {
                    $synced += app(\App\Conferencing\MeetingService::class)->syncRecordings($m);
                } catch (\Throwable) {
                }
            }
        });
    }
    $this->info("{$online}/{$servers->count()} servers online, {$closed} stale rooms closed, {$synced} recordings refreshed.");
})->purpose('Check meeting servers, close stale rooms, refresh recordings');
Schedule::command('conferencing:check')->everyFiveMinutes()->withoutOverlapping();
// built-in rooms: connection details are only needed while people connect; keep a day for troubleshooting
Schedule::call(fn () => \Illuminate\Support\Facades\DB::table('meeting_signals')->where('created_at', '<', now()->subDay())->delete())->dailyAt('03:40')->name('meeting-signals-purge');

// Hosting without always-on background processes (shared hosting such as SiteGround): the queue is worked
// from the scheduler each minute instead. Set QUEUE_VIA_SCHEDULER=true; the only cron job needed is
// `* * * * * php artisan schedule:run`.
if (config('queue.default') !== 'sync' && config('platform.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')->everyMinute()->withoutOverlapping(5)->name('queue-from-scheduler');
}
Schedule::command('evidence:purge')->dailyAt('02:30');
Schedule::command('analytics:items')->dailyAt('03:00');
Schedule::command('evidence:verify --limit=5000')->weekly();

/*
 * Load-test fixture (local/staging only): one exam, N candidates with started attempts.
 * Writes their attempt tokens and question ids to a JSON file for tools/loadtest.mjs.
 */
Artisan::command('exams:load-fixture {--candidates=500} {--questions=50} {--out=storage/app/loadtest.json} {--tenant=}', function (AttemptService $attempts) {
    abort_if(app()->isProduction(), 1, 'Not on production.');
    $tenant = \App\Models\Tenant::where('slug', $this->option('tenant') ?: config('platform.default_tenant'))->firstOrFail();
    app(\App\Support\Tenancy\TenantContext::class)->set($tenant);
    $n = (int) $this->option('candidates');
    $tag = 'LOAD'.now()->format('His');

    $exam = \App\Models\Exam::create(['title' => "Load test $tag", 'type' => 'cbt', 'status' => 'scheduled', 'duration_minutes' => 120, 'total_mark' => 100,
        'settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'require_check_in' => false], 'proctoring' => ['require_consent' => false, 'violation_threshold' => 1000], 'channels' => ['web']]);
    $section = $exam->sections()->create(['title' => 'A', 'position' => 0]);
    for ($i = 0; $i < (int) $this->option('questions'); $i++) {
        $q = \App\Models\Question::create(['type' => 'single_choice', 'stem_html' => "<p>Q$i: \\(x^2 + $i\\)</p>", 'mark' => 1, 'status' => 'approved', 'topic' => 'Load']);
        foreach (['A', 'B', 'C', 'D'] as $j => $l) {
            $q->options()->create(['label' => $l, 'body_html' => "Option $l", 'is_correct' => $j === 1, 'position' => $j]);
        }
        $section->questions()->attach($q->id, ['position' => $i]);
    }
    $session = $exam->sessions()->create(['mode' => 'hall', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHours(3), 'late_entry_minutes' => 180, 'status' => 'open']);

    $hash = \Illuminate\Support\Facades\Hash::make('load-test-only');
    foreach (array_chunk(range(1, $n), 500) as $chunk) {
        \Illuminate\Support\Facades\DB::table('users')->insert(array_map(fn ($i) => ['tenant_id' => $tenant->id, 'name' => "Load $i", 'email' => strtolower($tag)."-$i@load.invalid",
            'identifier' => "$tag/$i", 'user_type' => 'student', 'password' => $hash, 'created_at' => now(), 'updated_at' => now()], $chunk));
    }
    $users = \App\Models\User::where('identifier', 'like', "$tag/%")->pluck('id');
    \Illuminate\Support\Facades\DB::table('exam_candidates')->insert($users->map(fn ($u) => ['tenant_id' => $tenant->id, 'exam_session_id' => $session->id, 'user_id' => $u, 'created_at' => now(), 'updated_at' => now()])->all());

    $out = [];
    $bar = $this->output->createProgressBar($n);
    foreach (\App\Models\ExamCandidate::where('exam_session_id', $session->id)->cursor() as $c) {
        $request = \Illuminate\Http\Request::create('/my/exams/'.$c->id.'/start', 'POST', server: ['REMOTE_ADDR' => '10.0.'.intdiv($c->id, 250) % 250 .'.'.($c->id % 250), 'HTTP_USER_AGENT' => 'loadtest']);
        [$attempt, $token] = $attempts->start($c, $request, null, true);
        $out[] = ['token' => $token, 'questions' => \Illuminate\Support\Facades\DB::table('attempt_questions')->where('attempt_id', $attempt->id)->pluck('question_id')];
        $bar->advance();
    }
    $bar->finish();
    file_put_contents(base_path($this->option('out')), json_encode(['exam' => $exam->id, 'candidates' => $out]));
    $options = \Illuminate\Support\Facades\DB::table('question_options')->whereIn('question_id', $section->questions()->pluck('questions.id'))->get(['id', 'question_id'])->groupBy('question_id');
    file_put_contents(base_path($this->option('out')).'.options', json_encode($options->map(fn ($g) => $g->pluck('id'))));
    $this->newLine();
    $this->info("Exam {$exam->id}: $n candidates started. Tokens written to {$this->option('out')}");
})->purpose('Create a load-test exam with started attempts (never on production)');

/*
 * Integration API keys (shown once; only a hash is stored).
 *   php artisan apikey:create "University SIS" --scopes=sis.write,results.read --ips=10.0.0.0/8
 */
Artisan::command('apikey:create {name} {--scopes=sis.write} {--ips=} {--days=365} {--tenant=}', function () {
    $tenant = Tenant::where('slug', $this->option('tenant') ?: config('platform.default_tenant'))->firstOrFail();
    $prefix = \Illuminate\Support\Str::random(8);
    $secret = \Illuminate\Support\Str::random(40);
    $scopes = array_values(array_intersect(array_map('trim', explode(',', $this->option('scopes'))), array_keys(\App\Http\Controllers\Admin\IntegrationController::SCOPES)));
    DB::table('api_keys')->insert(['tenant_id' => $tenant->id, 'name' => $this->argument('name'), 'prefix' => $prefix, 'key_hash' => hash('sha256', $secret),
        'scopes' => json_encode($scopes), 'allowed_ips' => $this->option('ips') ? json_encode(array_map('trim', explode(',', $this->option('ips')))) : null,
        'expires_at' => now()->addDays((int) $this->option('days')), 'created_at' => now(), 'updated_at' => now()]);
    $this->info('Key (copy it now, it is not shown again):');
    $this->line("vk_{$prefix}_{$secret}");
    $this->line('Scopes: '.implode(', ', $scopes));
})->purpose('Create an integration API key');

Artisan::command('apikey:revoke {prefix}', function () {
    $n = DB::table('api_keys')->where('prefix', $this->argument('prefix'))->update(['revoked_at' => now()]);
    $this->info($n ? 'Revoked.' : 'No such key.');
})->purpose('Revoke an integration API key');

Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put('health:scheduler', now()->getTimestamp(), 600))->everyMinute()->name('health-heartbeat');
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:run --restore-test')->weeklyOn(0, '04:00')->withoutOverlapping();

Artisan::command('exams:clashes {from} {to} {--tenant=}', function (\App\Services\Exams\TimetableClashes $clashes) {
    app(TenantContext::class)->set(Tenant::where('slug', $this->option('tenant') ?: config('platform.default_tenant'))->firstOrFail());
    $r = $clashes->between($this->argument('from'), $this->argument('to'));
    $this->table(['sitting A', 'sitting B', 'candidates in both'], $r['candidates']->map(fn ($c) => (array) $c)->all());
    $this->table(['sitting A', 'sitting B', 'venue'], $r['venues']->map(fn ($c) => (array) $c)->all());
})->purpose('List candidate and venue clashes in an examination period');

Artisan::command('roles:expire', function () {
    $n = 0;
    foreach (DB::table('role_grants')->whereNull('revoked_at')->whereNotNull('expires_at')->where('expires_at', '<=', now())->get() as $g) {
        \App\Http\Controllers\Admin\PeopleController::endGrant($g);
        $n++;
    }
    $this->info("$n temporary roles ended.");
})->purpose('Remove delegated roles whose time is up');
Schedule::command('roles:expire')->everyFiveMinutes();

Artisan::command('backup:decrypt {file} {out}', function () {
    $pass = (string) config('platform.backup.passphrase');
    abort_if($pass === '', 1, 'Set BACKUP_PASSPHRASE first.');
    app(\App\Console\Commands\Backup::class)->decrypt($this->argument('file'), $this->argument('out'), $pass);
    $this->info('Decrypted and verified: '.$this->argument('out'));
})->purpose('Decrypt and integrity-check an encrypted backup file');

// Proctor disconnection alerts (Target 78)
Artisan::command('proctors:watch', function (TenantContext $ctx) {
    foreach (Tenant::all() as $tenant) {
        $ctx->run($tenant, fn () => app(\App\Services\Proctoring\ProctorWatch::class)->run());
    }
})->purpose('Alert chief invigilators when a proctor goes offline during a sitting');
Schedule::command('proctors:watch')->everyMinute()->withoutOverlapping();

// LMS reminders: work due, exams and shifts tomorrow, registration opening/closing (LMS 99-101)
Artisan::command('lms:reminders', function (TenantContext $ctx) {
    $n = 0;
    foreach (Tenant::all() as $tenant) {
        $n += $ctx->run($tenant, fn () => app(\App\Services\Lms\Reminders::class)->run());
    }
    $this->info("$n reminders sent.");
})->purpose('Send due-date, exam, shift and registration reminders');
Schedule::command('lms:reminders')->everyThirtyMinutes()->withoutOverlapping();
Artisan::command('uploads:purge', fn () => $this->info(app(\App\Services\Lms\Uploads::class)->purge().' expired uploads removed.'))->purpose('Delete unfinished and used upload chunks');
Schedule::command('uploads:purge')->hourly();

// Platform billing: renewal invoices, past-due, suspension after the grace period
Artisan::command('billing:run', function () {
    $r = app(\App\Services\Billing\Billing::class)->run();
    $this->info("Invoiced {$r['invoiced']}, past due {$r['past_due']}, suspended {$r['suspended']}.");
})->purpose('Run the subscription lifecycle');
Schedule::command('billing:run')->dailyAt('06:10');

Artisan::command('help:standard {--tenant=}', function (\App\Services\Lms\StandardArticles $articles) {
    $tenants = $this->option('tenant') ? Tenant::where('slug', $this->option('tenant'))->get() : Tenant::all();
    foreach ($tenants as $t) {
        $this->line($t->slug.': '.$articles->ensure($t).' article(s) added or refreshed');
    }
})->purpose('Add the standard help-centre articles (procedures, device compatibility) to institutions');

Artisan::command('analytics:push', function (\App\Services\Integrations\AnalyticsFeed $feed, \App\Services\Integrations\Connectors $connectors) {
    foreach (Tenant::where('status', 'active')->get() as $t) {
        $r = app(\App\Support\Tenancy\TenantContext::class)->run($t, fn () => $feed->push($connectors));
        $this->line($t->slug.': '.$r);
    }
})->purpose('Send the daily course-level figures to each institution\'s analytics endpoint');
Schedule::command('analytics:push')->dailyAt('04:40')->withoutOverlapping();

Artisan::command('exams:anomalies {--hours=48}', function (\App\Services\Analytics\AnomalyModel $model) {
    foreach (Tenant::where('status', 'active')->get() as $t) {
        app(\App\Support\Tenancy\TenantContext::class)->run($t, function () use ($model) {
            $exams = \App\Models\Exam::whereHas('sessions', fn ($q) => $q->whereBetween('ends_at', [now()->subHours((int) $this->option('hours')), now()]))->get();
            foreach ($exams as $e) {
                $r = $model->run($e);
                $this->line("{$e->title}: ".($r['skipped'] ?? "{$r['flagged']} of {$r['attempts']} flagged"));
            }
        });
    }
})->purpose('Statistical review of recently finished exams (flags for people to look at; never changes marks)');
Schedule::command('exams:anomalies')->hourlyAt(25)->withoutOverlapping();

Artisan::command('academics:rollover', function (\App\Services\Academics\SemesterRollover $rollover) {
    foreach (Tenant::where('status', 'active')->get() as $t) {
        $r = app(\App\Support\Tenancy\TenantContext::class)->run($t, fn () => $rollover->auto());
        $this->line($t->slug.': '.json_encode($r));
    }
})->purpose('Close ended semesters, start the next one and carry courses over (institutions that switch it on)');
Schedule::command('academics:rollover')->dailyAt('00:20')->withoutOverlapping();

Artisan::command('security:check {--json}', function (\App\Services\Security\SecuritySelfCheck $check) {
    $r = $check->run();
    if ($this->option('json')) {
        $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->table(['Area', 'Check', 'Result', 'Detail'], array_map(fn ($x) => [$x['area'], $x['check'], strtoupper($x['status']), $x['status'] === 'pass' ? '' : $x['detail']], $r));
    }
    $fails = count(array_filter($r, fn ($x) => $x['status'] === 'fail'));
    $warns = count(array_filter($r, fn ($x) => $x['status'] === 'warn'));
    $this->line("$fails failed, $warns to look at, ".(count($r) - $fails - $warns).' passed.');

    return $fails ? 1 : 0;
})->purpose('Security self-check: settings, accounts, keys, backups and audit trails (changes nothing)');
Schedule::command('security:check')->weeklyOn(1, '05:30');

Artisan::command('metrics:collect', function (\App\Services\Monitoring\Metrics $metrics, \App\Services\Monitoring\Alerts $alerts) {
    $d = $metrics->store();
    $opened = $alerts->evaluate();
    $this->line(json_encode($d).($opened ? ' alerts: '.implode(',', $opened) : ''));
})->purpose('Record this minute\'s server and traffic figures and raise or clear alerts');
Schedule::command('metrics:collect')->everyMinute()->withoutOverlapping();

Artisan::command('network:probe', function (\App\Services\Monitoring\NetworkMonitor $monitor) {
    $this->line($monitor->probeAll().' device(s) checked');
})->purpose('Check exam-hall network equipment (switches, access points, internet link)');
Schedule::command('network:probe')->everyMinute()->withoutOverlapping();

Artisan::command('dr:drill', function (\App\Services\Monitoring\RecoveryDrill $drill) {
    $r = $drill->run();
    foreach ($r['steps'] as $s) {
        $this->line(($s['ok'] === null ? 'SKIP' : ($s['ok'] ? 'PASS' : 'FAIL'))."  {$s['step']} ({$s['seconds']} s) {$s['detail']}");
    }
    $this->line("Recovery time {$r['rto_seconds']} s; data at risk ".($r['rpo_seconds'] ?? '?').' s.');

    return $r['passed'] ? 0 : 1;
})->purpose('Failover and recovery drill: backup, restore and compare, replica write, peer servers');
Schedule::command('dr:drill')->monthlyOn(1, '03:30')->withoutOverlapping();

Artisan::command('db:encryption-key {--path=}', function () {
    $path = $this->option('path') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\keys\\platform.key' : '/etc/mysql/keys/platform.key');
    abort_if(str_starts_with(realpath(dirname($path)) ?: dirname($path), base_path()), 1, 'Keep the key outside the application folder.');
    if (is_file($path)) {
        $this->error("$path already exists; it is not replaced (data encrypted with it would become unreadable).");

        return 1;
    }
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, '1;'.bin2hex(random_bytes(32)).PHP_EOL);
    @chmod($path, 0400);
    $this->info("Key written to $path. Point file_key_management_filename at it (deploy/mariadb/encryption.cnf), restart MariaDB, then run db:encrypt-tables.");

    return 0;
})->purpose('Create the MariaDB data-at-rest encryption key file (Target 86)');

Artisan::command('db:encrypt-tables', function () {
    $c = config('database.connections.'.config('database.default'));
    abort_unless(in_array($c['driver'], ['mysql', 'mariadb'], true), 1, 'Only for MariaDB/MySQL.');
    abort_unless(in_array($c['host'], ['127.0.0.1', 'localhost', '::1'], true), 1, 'Runs only against the local database server.');
    $plugin = DB::selectOne("SELECT PLUGIN_STATUS s FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'file_key_management'");
    if (($plugin->s ?? null) !== 'ACTIVE') {
        $this->error('The file_key_management plugin is not active. Follow deploy/mariadb/encryption.cnf first.');

        return 1;
    }
    $tables = collect(DB::select('SELECT TABLE_NAME t, CREATE_OPTIONS o FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$c['database'], 'BASE TABLE']));
    foreach ($tables as $t) {
        if (! str_contains(strtolower((string) $t->o), 'encrypted=yes')) {
            DB::statement("ALTER TABLE `{$t->t}` ENCRYPTED=YES");
            $this->line("encrypted {$t->t}");
        }
    }
    $this->info('Every table is encrypted at rest.');

    return 0;
})->purpose('Encrypt every platform table at rest (after the key plugin is active)');

Artisan::command('directory:sync {--tenant= : One institution by its short name} {--force : Switch off missing people even when there are unusually many}', function (\App\Services\Directory\DirectorySync $sync, TenantContext $ctx) {
    $tenants = \App\Models\Tenant::query()->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get()
        ->filter(fn ($t) => (bool) data_get($t->settings, 'integrations.directory.enabled') && $t->isActive());
    foreach ($tenants as $tenant) {
        $ctx->run($tenant, function () use ($sync, $tenant) {
            try {
                $r = $sync->run((bool) $this->option('force'));
                $this->line("{$tenant->slug}: ".($r['ok'] ? 'ok' : 'stopped: '.$r['error'])." — {$r['created']} new, {$r['updated']} updated, {$r['enrolments']} registrations, {$r['switched_off']} switched off, {$r['failed']} problems");
                foreach ($r['warnings'] as $w) {
                    $this->warn("  $w");
                }
            } catch (\App\Services\Directory\DirectoryException $e) {
                $this->warn("{$tenant->slug}: ".$e->getMessage());
            }
        });
    }
    $tenants->isEmpty() && $this->line('No institution has the directory connection switched on.');
})->purpose('Bring people, courses and registrations in from each institution\'s directory bridge');
Schedule::command('directory:sync')->hourlyAt(17)->withoutOverlapping();

