<?php

namespace Database\Seeders;

use App\Models\Attempt;
use App\Models\Course;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\Result;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * At least one realistic record for every feature, so every screen of the platform has
 * something to show and every feature can be tried at once: LMS, exams, proctoring, results,
 * live classes, billing, support, security and the platform console. Runs after DemoSeeder
 * and ScenarioSeeder (php artisan db:seed --class=DemoSeeder runs all three). Local/demo use only.
 * Safe to run twice: each block only adds what is missing.
 */
class FeatureCoverageSeeder extends Seeder
{
    private Tenant $tenant;

    private User $admin;

    private User $student;

    private User $lecturer;

    public function run(): void
    {
        $this->tenant = Tenant::where('slug', 'veritas')->firstOrFail();
        $this->platform();

        app(TenantContext::class)->run($this->tenant, function () {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
            $this->admin = User::where('email', 'admin@veritas.local')->firstOrFail();
            $this->student = User::where('email', 'student@veritas.local')->firstOrFail();
            $this->lecturer = User::where('email', 'lecturer@veritas.local')->firstOrFail();
            auth()->setUser($this->admin);

            $this->institution();
            $this->people();
            $this->lms();
            $this->exams();
            $this->proctoring();
            $this->results();
            $this->conferencing();
            $this->support();

            auth()->forgetUser();
        });

        $this->command?->info('Every feature has demo data.');
    }

    /** Insert one row into a table unless the "unique" columns already match. */
    private function once(string $table, array $unique, array $values = []): int
    {
        $row = DB::table($table)->where($unique)->first();
        if ($row) {
            return (int) ($row->id ?? 0);
        }
        $data = $unique + $values;
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        foreach (['created_at', 'updated_at'] as $ts) {
            if (in_array($ts, $columns, true) && ! array_key_exists($ts, $data)) {
                $data[$ts] = now();
            }
        }

        return in_array('id', $columns, true) ? (int) DB::table($table)->insertGetId($data) : (int) DB::table($table)->insert($data);
    }

    // ── platform console ─────────────────────────────────────────────────────

    private function platform(): void
    {
        $platformAdmin = User::withoutGlobalScopes()->whereNull('tenant_id')->where('is_platform_admin', true)->first();
        \App\Support\Platform\PlatformSettings::put(['brand_tagline' => 'Exams & learning', 'support_email' => 'support@platform.test'], $platformAdmin?->id);

        $coupon = $this->once('coupons', ['code' => 'WELCOME10'], ['description' => '10 % off the first year', 'kind' => 'percent', 'value' => 10, 'currency' => 'NGN', 'cycles' => json_encode(['yearly']), 'max_redemptions' => 50, 'redeemed' => 1, 'active' => true, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear()]);
        $invoice = DB::table('invoices')->where('tenant_id', $this->tenant->id)->value('id');
        $this->once('coupon_redemptions', ['coupon_id' => $coupon, 'tenant_id' => $this->tenant->id], ['invoice_id' => $invoice, 'amount_off' => 25000, 'created_at' => now()]);

        $this->once('platform_announcements', ['title' => 'Planned upgrade this Sunday'], ['body' => 'The platform will be upgraded on Sunday from 02:00 to 03:00. Exams are not affected.', 'level' => 'info', 'audience' => 'all',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'dismissible' => true, 'created_by' => $platformAdmin?->id]);
        $this->once('maintenance_windows', ['message' => 'Database upgrade'], ['tenant_id' => null, 'starts_at' => now()->addDays(10)->setTime(2, 0), 'ends_at' => now()->addDays(10)->setTime(3, 0), 'blocks_exams' => true, 'created_by' => $platformAdmin?->id]);
        $this->once('tenant_modules', ['tenant_id' => $this->tenant->id, 'module' => 'conferencing'], ['enabled' => true, 'reason' => 'Demo: live classes switched on for this institution', 'granted_by' => $platformAdmin?->id]);
        $this->once('tenant_addons', ['tenant_id' => $this->tenant->id, 'limit_key' => 'students'], ['quantity' => 500, 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear(), 'note' => 'Extra 500 student seats', 'created_by' => $platformAdmin?->id]);
        $this->once('support_sessions', ['tenant_id' => $this->tenant->id, 'reason' => 'Help the exams office set up a hall'], ['platform_user_id' => $platformAdmin?->id, 'target_user_id' => User::withoutGlobalScopes()->where('email', 'admin@veritas.local')->value('id'),
            'ip' => '127.0.0.1', 'started_at' => now()->subDays(2), 'expires_at' => now()->subDays(2)->addMinutes(30), 'ended_at' => now()->subDays(2)->addMinutes(12)]);
        $this->once('backup_runs', ['kind' => 'database', 'status' => 'succeeded'], ['path' => 'backups/demo.sql.gz', 'bytes' => 1048576, 'sha256' => hash('sha256', 'demo'), 'message' => 'Demo backup']);
        // a shared live-class server run by the platform team (an example address: replace it with your own)
        if (! \App\Models\ConferenceServer::withoutGlobalScopes()->whereNull('tenant_id')->exists()) {
            \App\Models\ConferenceServer::withoutGlobalScopes()->forceCreate(['tenant_id' => null, 'name' => 'Shared server (example)', 'base_url' => 'https://bbb.example.test/bigbluebutton/',
                'secret' => Str::random(40), 'checksum_algo' => 'sha256', 'enabled' => false, 'weight' => 100, 'status' => 'unknown', 'last_error' => 'Example server: enter a real BigBlueButton address and secret.']);
        }
    }

    // ── the institution ──────────────────────────────────────────────────────

    private function institution(): void
    {
        $this->once('sso_providers', ['tenant_id' => $this->tenant->id, 'slug' => 'demo-oidc'], ['type' => 'oidc', 'name' => 'University Microsoft 365 (example, switched off)', 'enabled' => false,
            'config' => encrypt(json_encode(['issuer' => 'https://login.example.test/', 'client_id' => 'demo-client', 'client_secret' => 'demo-secret'])),
            'auto_create' => false, 'default_user_type' => 'student', 'link_by_email' => true, 'trust_idp_mfa' => false]);
        $provider = DB::table('sso_providers')->where('tenant_id', $this->tenant->id)->where('slug', 'demo-oidc')->value('id');
        $this->once('sso_identities', ['sso_provider_id' => $provider, 'user_id' => $this->lecturer->id], ['subject' => 'ngozi.okeke@example.test', 'last_login_at' => now()->subDays(3)]);

        $this->once('security_events', ['user_id' => $this->student->id, 'type' => 'login'], ['tenant_id' => $this->tenant->id, 'ip' => '10.10.4.21', 'path' => 'login', 'data' => json_encode(['ua' => 'Chrome on Windows']), 'created_at' => now()->subHour()]);
        $this->once('security_events', ['user_id' => $this->student->id, 'type' => 'login_failed'], ['tenant_id' => $this->tenant->id, 'ip' => '10.10.4.21', 'path' => 'login', 'data' => json_encode(['email' => 'student@veritas.local']), 'created_at' => now()->subHours(2)]);
        $this->once('access_reviews', ['tenant_id' => $this->tenant->id, 'started_by' => $this->admin->id], ['decisions' => json_encode([['user_id' => $this->lecturer->id, 'decision' => 'keep']]), 'completed_at' => now()->subDays(20)]);
        $apiKey = DB::table('api_keys')->where('tenant_id', $this->tenant->id)->value('id');
        $this->once('sync_runs', ['tenant_id' => $this->tenant->id, 'kind' => 'students'], ['api_key_id' => $apiKey, 'idempotency_key' => 'demo-sync-1', 'received' => 3, 'created' => 1, 'updated' => 2, 'failed' => 0]);
        $this->once('export_requests', ['tenant_id' => $this->tenant->id, 'report' => 'students'], ['user_id' => $this->lecturer->id, 'format' => 'csv', 'rows' => 1200, 'reason' => 'Accreditation visit',
            'status' => 'pending', 'expires_at' => now()->addDays(7)]);
        $this->once('reminder_log', ['user_id' => $this->student->id, 'key' => 'demo:assignment-due'], ['created_at' => now()->subDay()]);

        $this->student->notifyNow(new \App\Notifications\LmsNotice('Problem set 1 is due in 5 days', 'CHM 101 · Submit your working as a PDF.', '/learn/courses', false));
        $this->admin->notifyNow(new \App\Notifications\LmsNotice('Invoice due soon', 'Your renewal invoice is due in 14 days.', '/institution/billing', false, category: 'billing'));
        $announcement = DB::table('platform_announcements')->where('title', 'Planned upgrade this Sunday')->value('id');
        $this->once('announcement_dismissals', ['announcement_id' => $announcement, 'user_id' => $this->lecturer->id], ['created_at' => now()]);
    }

    private function people(): void
    {
        $dept = DB::table('departments')->where('tenant_id', $this->tenant->id)->where('code', 'CHM')->first();
        $this->once('staff_profiles', ['tenant_id' => $this->tenant->id, 'user_id' => $this->lecturer->id], ['faculty_id' => $dept?->faculty_id, 'department_id' => $dept?->id, 'title' => 'Dr.', 'designation' => 'Senior Lecturer']);
        $this->once('role_grants', ['tenant_id' => $this->tenant->id, 'user_id' => $this->lecturer->id, 'role' => 'chief_invigilator'], ['reason' => 'Covering the Hall A chief invigilator this week',
            'expires_at' => now()->addDays(7), 'granted_by' => $this->admin->id]);
        if (! $this->lecturer->hasRole('chief_invigilator')) {
            $this->lecturer->assignRole('chief_invigilator');
        }
        $other = User::where('email', 'student02@veritas.local')->first() ?? $this->student;
        \App\Models\AccommodationProfile::firstOrCreate(['user_id' => $other->id], ['extra_time_percent' => 25, 'settings' => ['font_scale' => 1.25, 'high_contrast' => true],
            'approved_reference' => 'DSO/2026/014', 'approved_by' => $this->admin->id, 'valid_until' => now()->addYear()]);
        \App\Models\FaceProfile::firstOrCreate(['user_id' => $this->student->id], ['status' => 'pending', 'note' => 'Waiting for the student to enrol their face before the first proctored exam.']);
    }

    // ── LMS ──────────────────────────────────────────────────────────────────

    private function lms(): void
    {
        $o = CourseOffering::whereHas('course', fn ($q) => $q->where('code', 'CHM 101'))->firstOrFail();
        $students = User::where('user_type', 'student')->orderBy('id')->limit(6)->get();
        foreach ($students as $s) {
            \App\Models\Enrolment::firstOrCreate(['course_offering_id' => $o->id, 'user_id' => $s->id], ['status' => 'active', 'category' => 'core']);
        }

        $group = $this->once('course_groups', ['tenant_id' => $this->tenant->id, 'course_offering_id' => $o->id, 'name' => 'Practical group A']);
        foreach ($students->take(3) as $s) {
            $this->once('course_group_members', ['course_group_id' => $group, 'user_id' => $s->id]);
        }
        $this->once('class_schedules', ['tenant_id' => $this->tenant->id, 'course_offering_id' => $o->id, 'weekday' => 2], ['starts_at' => '09:00:00', 'ends_at' => '11:00:00', 'kind' => 'lecture', 'venue' => 'LT1', 'valid_from' => now()->startOfMonth(), 'valid_until' => now()->addMonths(3)]);
        $mth = Course::where('code', 'MTH 101')->first();
        $phy = Course::where('code', 'PHY 101')->first();
        if ($mth && $phy) {
            $this->once('course_prerequisites', ['course_id' => $phy->id, 'prerequisite_id' => $mth->id]);
        }

        $notes = CourseItem::where('course_offering_id', $o->id)->where('type', 'page')->orderBy('id')->first();
        $assignment = CourseItem::where('course_offering_id', $o->id)->where('type', 'assignment')->first();
        $forum = CourseItem::where('course_offering_id', $o->id)->where('type', 'forum')->first();
        if ($notes) {
            $this->once('item_completions', ['course_item_id' => $notes->id, 'user_id' => $this->student->id], ['completed_at' => now()->subDay()]);
            $this->once('course_item_revisions', ['course_item_id' => $notes->id, 'version' => 1], ['snapshot' => json_encode(['title' => $notes->title, 'body_html' => $notes->body_html]), 'edited_by' => $this->lecturer->id, 'created_at' => now()->subDays(3)]);
        }
        if ($assignment) {
            $this->once('deadline_extensions', ['course_item_id' => $assignment->id, 'user_id' => $students[1]->id ?? $this->student->id], ['due_at' => now()->addDays(9), 'reason' => 'Hospital admission (letter seen)', 'granted_by' => $this->lecturer->id]);
            $sub = \App\Models\AssignmentSubmission::firstOrCreate(['course_item_id' => $assignment->id, 'user_id' => $this->student->id, 'attempt' => 1],
                ['anon_code' => strtoupper(Str::random(5)), 'status' => 'submitted', 'submitted_at' => now()->subHours(5), 'body_html' => '<p>\\(\\ce{CH4 + 2O2 -> CO2 + 2H2O}\\); 8 g CH₄ = 0.5 mol, so 0.5 mol CO₂ = 22 g.</p>']);
            $peer = $students[2] ?? $this->lecturer;
            $this->once('peer_reviews', ['assignment_submission_id' => $sub->id, 'reviewer_id' => $peer->id], ['score' => 18, 'comment' => 'Clear working; give units on every line.', 'is_self' => false]);
        }
        if ($forum) {
            $thread = $this->once('forum_threads', ['tenant_id' => $this->tenant->id, 'course_item_id' => $forum->id, 'title' => 'Is the molar mass of CO₂ 44 or 44.01?'],
                ['user_id' => $this->student->id, 'posts_count' => 2, 'last_post_at' => now()->subHours(2)]);
            $first = $this->once('forum_posts', ['tenant_id' => $this->tenant->id, 'forum_thread_id' => $thread, 'user_id' => $this->student->id], ['body_html' => '<p>Which value should we use in the exam?</p>', 'created_at' => now()->subHours(3)]);
            $this->once('forum_posts', ['tenant_id' => $this->tenant->id, 'forum_thread_id' => $thread, 'user_id' => $this->lecturer->id], ['parent_id' => $first, 'body_html' => '<p>Use 44 g/mol unless the question gives more decimals.</p>', 'is_answer' => true, 'created_at' => now()->subHours(2)]);
        }

        // gradebook: a column, a score and the course grade
        $category = DB::table('grade_categories')->where('course_offering_id', $o->id)->value('id');
        if ($category) {
            $item = $this->once('grade_items', ['tenant_id' => $this->tenant->id, 'course_offering_id' => $o->id, 'title' => 'Test 1'], ['grade_category_id' => $category, 'source' => 'manual', 'max_score' => 20]);
            foreach ($students as $i => $s) {
                $this->once('grade_entries', ['grade_item_id' => $item, 'user_id' => $s->id], ['score' => 10 + $i, 'updated_by' => $this->lecturer->id]);
            }
        }
        $this->once('course_grades', ['tenant_id' => $this->tenant->id, 'course_offering_id' => $o->id, 'user_id' => $this->student->id], ['total' => 67.5, 'grade' => 'B', 'breakdown' => json_encode(['Test 1' => 10, 'Exam' => 57.5]), 'status' => 'draft']);

        // messages and announcements
        $conv = $this->once('conversations', ['tenant_id' => $this->tenant->id, 'subject' => 'Problem set 1 question'], ['created_by' => $this->student->id, 'course_offering_id' => $o->id, 'last_message_at' => now()->subHour()]);
        $this->once('conversation_participants', ['conversation_id' => $conv, 'user_id' => $this->student->id], ['last_read_at' => now()]);
        $this->once('conversation_participants', ['conversation_id' => $conv, 'user_id' => $this->lecturer->id], ['last_read_at' => now()->subHours(2)]);
        $msg = $this->once('messages', ['conversation_id' => $conv, 'user_id' => $this->student->id], ['body' => 'Good afternoon ma, may I submit the problem set as a photo?', 'created_at' => now()->subHours(2)]);
        $this->once('messages', ['conversation_id' => $conv, 'user_id' => $this->lecturer->id], ['body' => 'Yes, as long as it is clear. A PDF is better.', 'created_at' => now()->subHour()]);
        $this->once('message_reports', ['tenant_id' => $this->tenant->id, 'message_id' => $msg], ['reported_by' => $this->lecturer->id, 'reason' => 'Demo report (to show the moderation queue)', 'status' => 'open']);
        $this->once('announcements', ['tenant_id' => $this->tenant->id, 'title' => 'CHM 101 test moved to Thursday'], ['scope' => 'course', 'scope_id' => $o->id,
            'body_html' => '<p>The test is now on Thursday at 10:00 in LT1.</p>', 'urgent' => false, 'publish_at' => now()->subDay(), 'author_id' => $this->lecturer->id]);

        // a verifiable document
        $this->once('issued_documents', ['tenant_id' => $this->tenant->id, 'user_id' => $this->student->id, 'type' => 'result_statement'], ['code' => strtoupper(Str::random(10)),
            'content_hash' => hash('sha256', 'demo-statement'), 'summary' => 'Statement of result · 2026/2027 First semester', 'issued_by' => $this->admin->id]);
    }

    // ── exams ────────────────────────────────────────────────────────────────

    private function exams(): void
    {
        $cbt = Exam::where('type', 'mixed')->orWhere('type', 'cbt')->orderBy('id')->firstOrFail();
        // a frozen version of the approved paper, and its approval trail
        if (! DB::table('exam_versions')->where('exam_id', $cbt->id)->exists()) {
            $snapshot = json_encode(['title' => $cbt->title, 'sections' => $cbt->sections()->with('questions:id')->get()->map(fn ($s) => ['title' => $s->title, 'questions' => $s->questions->pluck('id')])]);
            $this->once('exam_versions', ['exam_id' => $cbt->id, 'version' => 1], ['snapshot' => $snapshot, 'checksum' => hash('sha256', $snapshot), 'created_by' => $this->admin->id, 'created_at' => now()->subDays(2)]);
        }
        foreach ([['submission', 'approved', 'Ready for moderation'], ['approval', 'approved', 'Paper approved']] as [$step, $decision, $comment]) {
            $this->once('approvals', ['tenant_id' => $this->tenant->id, 'approvable_type' => Exam::class, 'approvable_id' => $cbt->id, 'step' => $step], ['user_id' => $this->admin->id, 'decision' => $decision, 'comment' => $comment, 'created_at' => now()->subDays(2)]);
        }

        // the demo student's exam in progress, built by the real paper builder (paper, question versions)
        $candidate = ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $cbt->id))->where('user_id', $this->student->id)->first();
        if ($candidate && ! Attempt::where('exam_candidate_id', $candidate->id)->exists()) {
            $session = $candidate->session;
            $attempt = Attempt::create(['exam_id' => $cbt->id, 'exam_session_id' => $session->id, 'exam_candidate_id' => $candidate->id, 'user_id' => $this->student->id,
                'attempt_no' => 1, 'status' => 'in_progress', 'channel' => 'web', 'started_at' => now()->subMinutes(5), 'deadline_at' => now()->addMinutes($cbt->duration_minutes), 'last_seen_at' => now(), 'ip' => '10.10.4.21']);
            app(\App\Services\Exams\PaperBuilder::class)->build($attempt);
            \App\Models\CandidateMessage::create(['attempt_id' => $attempt->id, 'direction' => 'to_proctor', 'kind' => 'help', 'body' => 'My mouse is not working well.', 'created_at' => now()->subMinutes(2)]);
            \App\Models\CandidateMessage::create(['attempt_id' => $attempt->id, 'sender_id' => $this->admin->id, 'direction' => 'to_candidate', 'kind' => 'message', 'body' => 'An invigilator is coming to your seat.', 'created_at' => now()->subMinute()]);
        }

        // a research exam scored by an examiner from a 0…max list
        $research = Exam::firstOrCreate(['title' => 'CHM 499 · Final year research project', 'type' => 'research'], ['status' => 'scheduled', 'category' => 'exam', 'total_mark' => 70,
            'duration_minutes' => 30, 'max_attempts' => 1, 'created_by' => $this->admin->id, 'settings' => \App\Support\Exams\ExamSettings::SETTINGS, 'proctoring' => [], 'channels' => ['web']]);
        $criterion = $research->criteria()->firstOrCreate(['text' => 'Research work and defence'], ['max_score' => 70, 'position' => 0]);
        $research->examiners()->syncWithoutDetaching([$this->lecturer->id => ['role' => 'examiner']]);
        $rSession = $research->sessions()->firstOrCreate(['name' => 'Project defence'], ['mode' => 'hall', 'starts_at' => now()->subDay()->setTime(10, 0), 'ends_at' => now()->subDay()->setTime(16, 0), 'status' => 'closed']);
        // an external examiner made only for this defence (username + password; it ended with the defence)
        if (! \App\Models\ExamPanelAccount::where('username', 'ext.okafor')->exists()) {
            \App\Support\Exams\ExamExaminers::create($research, ['first_name' => 'Chidi', 'last_name' => 'Okafor', 'username' => 'ext.okafor',
                'password' => env('SEED_ADMIN_PASSWORD') ?: 'ChangeMe!2026'], $this->lecturer);
        }
        ExamCandidate::firstOrCreate(['exam_session_id' => $rSession->id, 'user_id' => $this->student->id]);
        if (! \App\Models\CriterionMark::where('exam_id', $research->id)->exists()) {
            app(\App\Services\Exams\Marker::class)->recordCriterionMarks($research, $this->student->id, $this->lecturer->id, [$criterion->id => 58]);
        }
        // the viva and practical: one examiner's sheet each
        foreach (Exam::whereIn('type', ['viva', 'practical'])->get() as $panel) {
            $first = $panel->criteria()->get();
            if ($first->isNotEmpty() && ! \App\Models\CriterionMark::where('exam_id', $panel->id)->exists()) {
                app(\App\Services\Exams\Marker::class)->recordCriterionMarks($panel, $this->student->id, $this->admin->id,
                    $first->mapWithKeys(fn ($c) => [$c->id => $c->allowed_scores ? max($c->allowed_scores) : round($c->max_score * 0.7)])->all());
            }
        }

        // a paper uploaded with the science & maths reader (Word equations, symbols)
        if (! \App\Models\QuestionImport::exists()) {
            $path = tempnam(sys_get_temp_dir(), 'q').'.txt';
            file_put_contents($path, "1. What is the SI unit of pressure?\nA. Joule\n*B. Pascal\nC. Watt\nD. Newton\n\n2. Evaluate \\(\\int_0^1 x^2\\,dx\\).\nA. 1/2\n*B. 1/3\nC. 1\nD. 0\n");
            app(\App\Services\Questions\Import\QuestionImportService::class)->upload(new UploadedFile($path, 'physics-paper.txt', 'text/plain', null, true), $cbt->course_id, $this->lecturer->id, 'science');
        }

        // hall computers approved by IP, current list and a cleared one kept as history
        $venue = Venue::orderBy('id')->first();
        $this->once('exam_computers', ['tenant_id' => $this->tenant->id, 'ip' => '10.10.4.21', 'cleared_at' => null], ['status' => 'approved', 'label' => 'Hall A · PC 01', 'venue_id' => $venue?->id,
            'last_user_id' => $this->student->id, 'requests' => 3, 'first_seen_at' => now()->subMinutes(20), 'last_seen_at' => now(), 'decided_by' => $this->admin->id, 'decided_at' => now()->subMinutes(18)]);
        $this->once('exam_computers', ['tenant_id' => $this->tenant->id, 'ip' => '10.10.4.22', 'cleared_at' => null], ['status' => 'pending', 'venue_id' => $venue?->id, 'requests' => 1, 'first_seen_at' => now()->subMinutes(2), 'last_seen_at' => now()->subMinutes(2)]);
        $this->once('exam_computers', ['tenant_id' => $this->tenant->id, 'ip' => '10.10.4.30', 'clear_reason' => 'Cleared automatically after "CHM 101 mid-semester test" ended'],
            ['status' => 'rejected', 'requests' => 2, 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3), 'decided_by' => $this->admin->id, 'decided_at' => now()->subDays(3), 'cleared_at' => now()->subDays(3)->addHours(2)]);
        $this->tenant->forceFill(['settings' => array_replace_recursive((array) $this->tenant->settings, ['exam_network' => ['auto_clear' => true]])])->save();

        // seats in the first hall
        if ($venue && ! DB::table('seats')->where('venue_id', $venue->id)->exists()) {
            foreach (range(1, 10) as $n) {
                DB::table('seats')->insert(['venue_id' => $venue->id, 'label' => 'A'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]);
            }
        }

        // a timetable proposal waiting to be committed
        $this->once('timetable_runs', ['tenant_id' => $this->tenant->id, 'created_by' => $this->admin->id], ['status' => 'draft', 'params' => json_encode(['from' => now()->addWeeks(6)->toDateString(), 'days' => 10]),
            'proposal' => json_encode([['exam_id' => $cbt->id, 'starts_at' => now()->addWeeks(6)->setTime(9, 0)->toIso8601String(), 'venue_id' => $venue?->id]]), 'unplaced' => json_encode([])]);
    }

    // ── proctoring ───────────────────────────────────────────────────────────

    private function proctoring(): void
    {
        $session = ExamSession::where('mode', 'hall')->whereHas('attempts')->orderBy('id')->first();
        $proctor = User::where('email', 'proctor@veritas.local')->first() ?? $this->admin;
        if ($session) {
            $assignment = $this->once('proctor_assignments', ['exam_session_id' => $session->id, 'user_id' => $proctor->id], ['role' => 'proctor', 'max_candidates' => 30, 'last_seen_at' => now()->subDays(3)]);
            foreach (ExamCandidate::where('exam_session_id', $session->id)->limit(10)->pluck('id') as $cid) {
                $this->once('proctor_candidate', ['proctor_assignment_id' => $assignment, 'exam_candidate_id' => $cid]);
            }
            $this->once('proctor_trainings', ['tenant_id' => $this->tenant->id, 'user_id' => $proctor->id], ['tasks' => json_encode(['ack_help', 'warn', 'unlock']), 'completed_at' => now()->subWeek()]);
            $attempt = Attempt::where('exam_session_id', $session->id)->orderBy('id')->first();
            if ($attempt) {
                if (! \App\Models\EvidenceFile::where('attempt_id', $attempt->id)->exists()) {
                    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AN//Z');
                    $file = app(\App\Services\Evidence\EvidenceStore::class)->store($attempt, 'snapshot', $jpeg, 'image/jpeg', $attempt->started_at);
                    $this->once('evidence_access_logs', ['evidence_file_id' => $file->id, 'user_id' => $proctor->id], ['action' => 'view', 'ip' => '10.10.0.5', 'created_at' => now()->subDays(3)]);
                }
                $this->once('identity_checks', ['tenant_id' => $this->tenant->id, 'attempt_id' => $attempt->id], ['user_id' => $attempt->user_id, 'kind' => 'entry', 'result' => 'review', 'created_at' => $attempt->started_at]);
                // a recording of the whole attempt in two parts (the candidate's connection dropped once)
                foreach ([1, 2] as $part) {
                    $this->once('media_recordings', ['tenant_id' => $this->tenant->id, 'attempt_id' => $attempt->id, 'egress_id' => "EG_DEMO_{$attempt->id}_$part"], ['kind' => 'camera', 'status' => 'complete',
                        'location' => "/recordings/t{$this->tenant->id}/a{$attempt->id}/camera-$part.mp4", 'size' => 9_000_000, 'duration_seconds' => $part === 1 ? 1500 : 2100,
                        'started_at' => $attempt->started_at->copy()->addMinutes($part === 1 ? 0 : 26), 'ended_at' => $attempt->started_at->copy()->addMinutes($part === 1 ? 25 : 61)]);
                }
            }
        }
        $this->once('proctor_availability', ['tenant_id' => $this->tenant->id, 'user_id' => $proctor->id, 'date' => now()->addWeek()->toDateString()], ['from_time' => '08:00:00', 'to_time' => '14:00:00', 'available' => true, 'note' => 'Morning only']);
    }

    // ── results ──────────────────────────────────────────────────────────────

    private function results(): void
    {
        $result = Result::whereIn('status', ['published', 'approved', 'moderated', 'draft'])->whereHas('exam', fn ($q) => $q->whereNotIn('type', ['viva', 'practical', 'research']))->orderBy('id')->first();
        if (! $result) {
            return;
        }
        $this->once('result_changes', ['result_id' => $result->id], ['from_score' => $result->final_score, 'to_score' => min(100, (float) $result->final_score + 2), 'reason' => 'Question 7 had two correct options; both accepted.',
            'requested_by' => $this->admin->id, 'status' => 'pending']);
        $this->once('appeals', ['tenant_id' => $this->tenant->id, 'result_id' => $result->id], ['user_id' => $result->user_id, 'kind' => 'recheck', 'status' => 'submitted',
            'statement' => 'I believe my answer to question 4 was marked wrong although it matches the lecture notes.']);
        // item analysis for the finished exam
        $exam = $result->exam;
        try {
            app(\App\Services\Analytics\ExamAnalytics::class)->itemAnalysis($exam);
        } catch (\Throwable) {
        }
        if (! DB::table('item_statistics')->where('exam_id', $exam->id)->exists()) {
            $q = DB::table('exam_section_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_section_questions.exam_section_id')->where('exam_sections.exam_id', $exam->id)->value('question_id');
            $q && $this->once('item_statistics', ['exam_id' => $exam->id, 'question_id' => $q], ['responses' => 20, 'difficulty' => 0.65, 'discrimination' => 0.41, 'point_biserial' => 0.38, 'option_counts' => json_encode(['A' => 3, 'B' => 13, 'C' => 2, 'D' => 2]), 'computed_at' => now()]);
        }
    }

    // ── live classes (BigBlueButton) ─────────────────────────────────────────

    private function conferencing(): void
    {
        $o = CourseOffering::whereHas('course', fn ($q) => $q->where('code', 'CHM 101'))->firstOrFail();
        $server = \App\Models\ConferenceServer::withoutGlobalScopes()->whereNull('tenant_id')->first();
        $make = function (string $title, $startsAt, string $status) use ($o, $server) {
            $m = \App\Models\Meeting::where('title', $title)->first();
            if ($m) {
                return $m;
            }

            return \App\Models\Meeting::forceCreate(['uuid' => (string) Str::uuid(), 'bbb_meeting_id' => 'vt'.$this->tenant->id.'-'.Str::random(16), 'conference_server_id' => $server?->id,
                'course_offering_id' => $o->id, 'type' => 'class', 'title' => $title, 'description' => 'Weekly CHM 101 tutorial.', 'starts_at' => $startsAt, 'duration_minutes' => 60,
                'timezone' => config('app.timezone'), 'access' => 'course', 'settings' => \App\Models\Meeting::DEFAULT_SETTINGS, 'status' => $status,
                'attendee_pw' => Str::random(16), 'moderator_pw' => Str::random(16), 'created_by' => $this->lecturer->id,
                'started_at' => $status === 'ended' ? $startsAt : null, 'ended_at' => $status === 'ended' ? (clone $startsAt)->addHour() : null]);
        };
        $past = $make('CHM 101 tutorial · Week 5', now()->subDays(2)->setTime(14, 0), 'ended');
        $make('CHM 101 tutorial · Week 6', now()->addDays(5)->setTime(14, 0), 'scheduled');

        $this->once('meeting_participants', ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id, 'user_id' => $this->lecturer->id], ['role' => 'host', 'added_by' => $this->lecturer->id]);
        $this->once('meeting_participants', ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id, 'user_id' => $this->admin->id], ['role' => 'cohost', 'added_by' => $this->lecturer->id]);
        $start = $past->starts_at;
        $events = [['meeting-created', null, 0], ['user-joined', $this->student, 2], ['user-left', $this->student, 50], ['meeting-ended', null, 60]];
        foreach ($events as [$type, $who, $min]) {
            $this->once('meeting_events', ['event_key' => hash('sha256', "demo|{$past->id}|$type|$min")], ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id, 'type' => $type,
                'internal_user_id' => $who ? 'w_demo'.$who->id : null, 'user_id' => $who?->id, 'occurred_at' => $start->copy()->addMinutes($min), 'payload' => json_encode(['demo' => true]), 'created_at' => $start->copy()->addMinutes($min)]);
        }
        $this->once('meeting_attendance', ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id, 'internal_user_id' => 'w_demo'.$this->student->id], ['user_id' => $this->student->id,
            'name' => $this->student->name, 'role' => 'participant', 'joined_at' => $start->copy()->addMinutes(2), 'left_at' => $start->copy()->addMinutes(50), 'seconds' => 48 * 60, 'closed_by_meeting_end' => false]);
        if (! \App\Models\MeetingRecording::where('meeting_id', $past->id)->exists()) {
            \App\Models\MeetingRecording::forceCreate(['meeting_id' => $past->id, 'record_id' => 'demo-'.Str::random(12), 'state' => 'published', 'format' => 'presentation',
                'playback_url' => 'https://bbb.example.test/playback/presentation/2.3/demo', 'duration_seconds' => 3480, 'participants' => 14, 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes(58), 'visibility' => 'attendees']);
        }
        $rec = \App\Models\MeetingRecording::where('meeting_id', $past->id)->first();
        $segments = [['start' => 0, 'end' => 6.4, 'text' => 'Good morning, everyone. Today we look at the mole.'], ['start' => 6.4, 'end' => 14.0, 'text' => 'One mole holds Avogadro’s number of particles.']];
        // a built-in room with a guest who came through the guest link
        $this->once('meeting_guest_links', ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id], ['token' => Str::random(32), 'enabled' => true, 'created_by' => $this->lecturer->id]);
        $guest = $this->once('meeting_guests', ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id, 'name' => 'Prof. Chidi Okafor'], ['email' => 'external@example.edu.ng']);
        $peer = 'demo'.str_pad((string) $past->id, 20, '0', STR_PAD_LEFT);
        $this->once('meeting_peers', ['peer_id' => $peer], ['tenant_id' => $this->tenant->id, 'meeting_id' => $past->id, 'meeting_guest_id' => $guest, 'name' => 'Prof. Chidi Okafor (guest)',
            'role' => 'guest', 'state' => 'left', 'joined_at' => $start->copy()->addMinutes(5), 'last_seen_at' => $start->copy()->addMinutes(40), 'left_at' => $start->copy()->addMinutes(40)]);
        $this->once('meeting_signals', ['meeting_id' => $past->id, 'from_peer' => $peer, 'type' => 'chat'], ['payload' => json_encode(['name' => 'Prof. Chidi Okafor (guest)', 'text' => 'Thank you for having me.']), 'created_at' => $start->copy()->addMinutes(10)]);
        $main = $this->once('meeting_transcripts', ['tenant_id' => $this->tenant->id, 'meeting_recording_id' => $rec->id, 'kind' => 'transcript'], ['language' => 'en', 'status' => 'complete',
            'segments' => json_encode($segments), 'text' => implode(' ', array_column($segments, 'text')), 'provider' => 'demo', 'requested_by' => $this->lecturer->id]);
        $this->once('meeting_transcripts', ['tenant_id' => $this->tenant->id, 'meeting_recording_id' => $rec->id, 'kind' => 'translation', 'language' => 'fr'], ['source_id' => $main, 'status' => 'complete',
            'segments' => json_encode([['start' => 0, 'end' => 6.4, 'text' => 'Bonjour à tous. Aujourd’hui, nous étudions la mole.'], ['start' => 6.4, 'end' => 14.0, 'text' => 'Une mole contient le nombre d’Avogadro de particules.']]),
            'text' => 'Bonjour à tous. Aujourd’hui, nous étudions la mole. Une mole contient le nombre d’Avogadro de particules.', 'provider' => 'demo', 'requested_by' => $this->lecturer->id]);
    }

    // ── help desk ────────────────────────────────────────────────────────────

    private function support(): void
    {
        $ticket = $this->once('support_tickets', ['tenant_id' => $this->tenant->id, 'subject' => 'Cannot see my CHM 101 quiz'], ['user_id' => $this->student->id, 'category' => 'course', 'priority' => 'normal',
            'status' => 'open', 'last_activity_at' => now()->subHours(3)]);
        $this->once('ticket_messages', ['support_ticket_id' => $ticket, 'user_id' => $this->student->id], ['body' => 'The quiz does not show on my course page.', 'internal' => false, 'created_at' => now()->subHours(4)]);
        $this->once('ticket_messages', ['support_ticket_id' => $ticket, 'user_id' => $this->admin->id], ['body' => 'It opens tomorrow at 09:00; it will appear then.', 'internal' => false, 'created_at' => now()->subHours(3)]);
    }
}
