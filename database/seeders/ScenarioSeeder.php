<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\AssignmentSubmission;
use App\Models\Attempt;
use App\Models\ClassMeeting;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\Device;
use App\Models\Enrolment;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExceptionRequest;
use App\Models\HardwareException;
use App\Models\Incident;
use App\Models\InstitutionApplication;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Semester;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Services\Analytics\AnomalyModel;
use App\Services\Billing\Billing;
use App\Services\Settings\SettingsHistory;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Policy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A sample of every scenario, so each screen, report and export has something real-looking to show:
 * people in every role, two campuses and halls with network equipment, a finished exam with a
 * statistical flag and an incident, attendance (typed and imported), a lecture recording,
 * submissions with similarity and plagiarism scores, exception requests and hardware exceptions,
 * policy versions, e-mail delivery, API keys, a week of monitoring with alerts and a recovery drill,
 * and on the platform side paying, trial, overdue/suspended institutions and pending applications.
 * Local/demo only. Run after DemoSeeder (it calls this one).
 */
class ScenarioSeeder extends Seeder
{
    private string $password;

    public function run(): void
    {
        $this->password = env('SEED_ADMIN_PASSWORD') ?: 'ChangeMe!2026';
        $tenant = Tenant::where('slug', 'veritas')->firstOrFail();
        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $admin = User::where('email', 'admin@veritas.local')->firstOrFail();
            auth()->setUser($admin);
            $staff = $this->people();
            $venues = $this->campuses();
            $this->policy();
            $students = User::where('user_type', 'student')->orderBy('id')->get();
            $this->teaching($students, $staff);
            $this->finishedExam($students, $staff, $venues);
            $this->casework($students, $staff);
            $this->operations($tenant);
            auth()->forgetUser();
        });
        $this->platform();
        $this->monitoring();
        $this->command?->info('Scenario data seeded. Every role signs in as <role>@veritas.local with the demo password.');
    }

    private function user(string $email, string $name, string $type, ?string $identifier, array $roles): User
    {
        $u = User::firstOrNew(['email' => $email]);
        $u->forceFill(['name' => $name, 'password' => $this->password, 'user_type' => $type, 'identifier' => $identifier, 'email_verified_at' => now(), 'status' => 'active'])->save();
        $u->syncRoles($roles);

        return $u;
    }

    /** One person for every role, and a class of students. */
    private function people(): array
    {
        $roles = [
            'examofficer' => ['Mrs. Funke Adeyemi', 'exam_officer'], 'ict' => ['Mr. Tunde Bakare', 'ict_admin'], 'registry' => ['Mrs. Amina Bello', 'registry'],
            'dean' => ['Prof. Chidi Eze', 'dean'], 'hod' => ['Dr. Kemi Lawal', 'hod'], 'setter' => ['Dr. Ibrahim Musa', 'question_setter'],
            'moderator' => ['Dr. Grace Okafor', 'moderator'], 'chief' => ['Mr. Yusuf Danjuma', 'chief_invigilator'], 'invigilator' => ['Ms. Joy Nwosu', 'invigilator'],
            'proctor' => ['Mr. Segun Ade', 'proctor'],
        ];
        $staff = [];
        foreach ($roles as $key => [$name, $role]) {
            $staff[$key] = $this->user("$key@veritas.local", $name, 'staff', 'STAFF/'.str_pad((string) (100 + count($staff)), 4, '0', STR_PAD_LEFT), [$role]);
        }
        $first = ['Chinedu', 'Aisha', 'Tobi', 'Ngozi', 'Emeka', 'Halima', 'Femi', 'Zainab', 'Ifeoma', 'Musa', 'Bisi', 'Uche', 'Kelechi', 'Fatima', 'Dayo', 'Amaka', 'Sani', 'Yemi', 'Obinna', 'Hauwa'];
        $last = ['Okoro', 'Abubakar', 'Adeleke', 'Nnaji', 'Ogunleye', 'Suleiman', 'Ibe', 'Lawal', 'Eze', 'Danladi'];
        $programme = \App\Models\Programme::where('code', 'BSC-CHM')->first();
        foreach (range(2, 21) as $n) {
            $u = $this->user(sprintf('student%02d@veritas.local', $n), $first[$n - 2].' '.$last[$n % 10], 'student', sprintf('VUG/CHM/26/%04d', $n), ['student']);
            \App\Models\StudentProfile::updateOrCreate(['user_id' => $u->id], ['programme_id' => $programme?->id, 'level' => 100, 'academic_status' => 'active', 'entry_year' => 2026]);
        }

        return $staff;
    }

    /** Two campuses, three halls, approved exam computers and the network equipment they depend on. */
    private function campuses(): array
    {
        $a = Venue::firstOrCreate(['name' => 'CBT Hall A'], ['campus' => 'Main Campus', 'capacity' => 250, 'network_cidr' => '10.20.1.0/24']);
        $b = Venue::firstOrCreate(['name' => 'CBT Hall B'], ['campus' => 'Main Campus', 'capacity' => 120, 'network_cidr' => '10.20.2.0/24']);
        $c = Venue::firstOrCreate(['name' => 'Gwagwalada ICT Centre'], ['campus' => 'Gwagwalada Campus', 'capacity' => 150, 'network_cidr' => '10.30.1.0/24']);
        foreach ([[$a, 12, 11], [$b, 6, 6], [$c, 8, 5]] as [$venue, $total, $online]) {
            foreach (range(1, $total) as $i) {
                Device::firstOrCreate(['venue_id' => $venue->id, 'label' => Str::upper(Str::substr($venue->name, 0, 1)).'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)], [
                    'fingerprint_hash' => hash('sha256', $venue->id.'-'.$i), 'public_key' => 'demo-key-'.$venue->id.'-'.$i, 'status' => 'approved',
                    'last_seen_at' => $i <= $online ? now()->subMinute() : now()->subHours(3)]);
            }
        }
        $devices = [[$a, 'Hall A core switch', 'switch', '10.20.1.1', 443, 'up', 3], [$a, 'Hall A wireless access point', 'access_point', '10.20.1.5', 80, 'up', 8],
            [$b, 'Hall B switch', 'switch', '10.20.2.1', 443, 'slow', 420], [$c, 'Gwagwalada router', 'router', '10.30.1.1', 22, 'down', null],
            [null, 'Campus internet link (ISP gateway)', 'internet', '10.0.0.1', 443, 'up', 18]];
        foreach ($devices as [$venue, $name, $kind, $host, $port, $status, $ms]) {
            if (DB::table('network_devices')->where('tenant_id', tenant_id())->where('name', $name)->exists()) {
                continue;
            }
            $id = DB::table('network_devices')->insertGetId(['tenant_id' => tenant_id(), 'venue_id' => $venue?->id, 'name' => $name, 'kind' => $kind, 'host' => $host, 'port' => $port,
                'status' => $status, 'latency_ms' => $ms, 'fail_count' => $status === 'down' ? 7 : 0, 'last_checked_at' => now(), 'last_up_at' => $status === 'down' ? now()->subMinutes(7) : now(),
                'created_at' => now(), 'updated_at' => now()]);
            $rows = [];
            foreach (range(60, 1) as $m) {
                $ok = $status !== 'down' || $m > 7;
                $rows[] = ['network_device_id' => $id, 'ok' => $ok, 'latency_ms' => $ok ? max(1, (int) (($ms ?? 12) * (0.7 + mt_rand(0, 60) / 100))) : null, 'checked_at' => now()->subMinutes($m)];
            }
            DB::table('network_checks')->insert($rows);
        }

        return [$a, $b, $c];
    }

    /** Exam-day contacts, campus settings and two kept policy versions. */
    private function policy(): void
    {
        if (\App\Models\SettingsVersion::exists()) {
            return;
        }
        $h = app(SettingsHistory::class);
        $p = Policy::all();
        $h->save('policy', array_replace($p, ['max_exams_per_day' => 3]), 'Allow three papers a day during resit week');
        $h->save('policy', array_replace($p, [
            'max_exams_per_day' => 2,
            'incident_contacts' => [
                ['role' => 'Exams officer', 'name' => 'Mrs. Funke Adeyemi', 'phone' => '0803 000 1111', 'email' => 'examofficer@veritas.local', 'hours' => 'Exam days 7am–6pm'],
                ['role' => 'ICT on call', 'name' => 'Mr. Tunde Bakare', 'phone' => '0803 000 2222', 'email' => 'ict@veritas.local', 'hours' => 'Exam days 7am–8pm'],
                ['role' => 'Chief invigilator', 'name' => 'Mr. Yusuf Danjuma', 'phone' => '0803 000 3333', 'hours' => 'During sittings'],
            ],
            'exam_day_notice' => "Bring your ID card and exam slip.\nPhones and smart watches go in the bag area at the front of the hall.",
        ]), 'Exam-day contacts for the first semester');
        $h->save('campus:gwagwalada_campus', [
            'incident_contacts' => [['role' => 'Campus ICT', 'name' => 'Mr. Ahmed Garba', 'phone' => '0803 000 4444', 'hours' => 'Exam days 8am–5pm']],
            'exam_day_notice' => 'Enter through the north gate; the ICT Centre is behind the library.', 'max_exams_per_day' => null, 'min_attendance_pct' => 70,
        ], 'Settings for Gwagwalada Campus');
    }

    /** Class, attendance (typed and imported), a recording, submissions with similarity and an outside plagiarism score. */
    private function teaching($students, array $staff): void
    {
        $o = CourseOffering::whereHas('course', fn ($q) => $q->where('code', 'CHM 101'))->latest('id')->firstOrFail();
        foreach ($students as $s) {
            Enrolment::firstOrCreate(['course_offering_id' => $o->id, 'user_id' => $s->id], ['status' => 'active', 'category' => 'core']);
        }
        if (! ClassMeeting::where('course_offering_id', $o->id)->exists()) {
            foreach (range(1, 6) as $w) {
                $m = ClassMeeting::create(['course_offering_id' => $o->id, 'held_at' => now()->subWeeks(7 - $w)->setTime(9, 0), 'topic' => ['Atomic structure', 'The mole', 'Empirical formulae', 'Stoichiometry', 'Limiting reagents', 'Gas laws'][$w - 1]]);
                foreach ($students as $i => $s) {
                    $status = ($i + $w) % 9 === 0 ? 'absent' : (($i + $w) % 7 === 0 ? 'late' : 'present');
                    DB::table('attendance_records')->insert(['class_meeting_id' => $m->id, 'user_id' => $s->id, 'status' => $status, 'source' => $w >= 5 ? 'import' : 'staff', 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($w === 5) {
                    $m->forceFill(['recording_url' => 'https://media.veritas.edu.ng/lectures/chm101-limiting-reagents', 'recording_published' => true])->save();
                }
            }
        }
        $item = CourseItem::where('course_offering_id', $o->id)->where('type', 'assignment')->first();
        if ($item && ! AssignmentSubmission::where('course_item_id', $item->id)->exists()) {
            $subs = [];
            $answer = 'CH4 + 2O2 -> CO2 + 2H2O. 8 g of methane is 0.5 mol, so 0.5 mol of CO2 forms, which is 22 g.';
            foreach ($students->take(10) as $i => $s) {
                $sub = AssignmentSubmission::create(['course_item_id' => $item->id, 'user_id' => $s->id, 'attempt' => 1, 'anon_code' => strtoupper(Str::random(5)),
                    'text' => $i < 2 ? $answer : "Balanced equation first. n(CH4) = 8/16 = 0.5 mol; mole ratio 1:1 gives 0.5 mol CO2 × 44 g/mol = 22 g. ($i)",
                    'status' => $i < 6 ? 'marked' : 'submitted', 'submitted_at' => now()->subDays(2)->addMinutes($i * 7), 'is_late' => $i === 9,
                    'raw_score' => $i < 6 ? 12 + $i : null, 'score' => $i < 6 ? 12 + $i : null]);
                $subs[$i] = $sub;
                if ($i < 3) {
                    DB::table('plagiarism_checks')->insert(['tenant_id' => tenant_id(), 'assignment_submission_id' => $sub->id, 'provider' => 'TurnCheck (demo)', 'token' => Str::random(40),
                        'status' => 'complete', 'score' => [61.0, 58.5, 7.0][$i], 'report_url' => 'https://plagiarism.example.edu.ng/report/'.Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            // the first two handed in the same answer: the built-in similarity check pairs them
            foreach ([0 => 1, 1 => 0] as $a => $b) {
                $subs[$a]->forceFill(['similarity' => 92.5, 'similarity_matches' => [['percent' => 92.5, 'submission_id' => $subs[$b]->id, 'user_id' => $subs[$b]->user_id, 'item_id' => $item->id]]])->save();
            }
        }
    }

    /** A finished exam in a hall: 20 submitted attempts, one statistically unusual, and an incident. */
    private function finishedExam($students, array $staff, array $venues): void
    {
        if (Exam::where('title', 'CHM 101 · Mid-semester test')->exists()) {
            return;
        }
        $cbt = Exam::where('title', 'CHM 101 · Demo CBT')->firstOrFail();
        $exam = $cbt->replicate(['status'])->fill(['title' => 'CHM 101 · Mid-semester test', 'status' => 'closed']);
        $exam->save();
        $section = $exam->sections()->create(['title' => 'Section A', 'position' => 0]);
        $qids = $cbt->sections()->first()->questions()->pluck('questions.id')->all();
        foreach ($qids as $i => $qid) {
            $section->questions()->attach($qid, ['position' => $i]);
        }
        $session = $exam->sessions()->create(['mode' => 'hall', 'venue_id' => $venues[0]->id, 'starts_at' => now()->subDays(3)->setTime(10, 0), 'ends_at' => now()->subDays(3)->setTime(11, 0),
            'late_entry_minutes' => 15, 'status' => 'closed']);
        $start = now()->subDays(3)->setTime(10, 2);
        foreach ($students->take(20)->values() as $n => $s) {
            $c = ExamCandidate::create(['exam_session_id' => $session->id, 'user_id' => $s->id, 'attendance' => 'present', 'checked_in_at' => $start->copy()->subMinutes(5)]);
            $odd = $n === 7;
            $mins = $odd ? 5 : 22 + ($n % 6);
            $a = Attempt::create(['exam_id' => $exam->id, 'exam_session_id' => $session->id, 'exam_candidate_id' => $c->id, 'user_id' => $s->id, 'attempt_no' => 1, 'status' => 'submitted',
                'channel' => 'desktop', 'started_at' => $start, 'deadline_at' => $start->copy()->addMinutes(30), 'submitted_at' => $start->copy()->addMinutes($mins), 'submit_reason' => 'candidate',
                'score_raw' => $odd ? 9 : 3 + ($n % 5), 'score_max' => 9, 'risk_score' => $odd ? 38 : $n % 4, 'answered_count' => count($qids), 'last_seen_at' => $start->copy()->addMinutes($mins)]);
            foreach ($qids as $i => $qid) {
                DB::table('attempt_questions')->insert(['attempt_id' => $a->id, 'question_id' => $qid, 'position' => $i, 'mark' => 1]);
                DB::table('attempt_answers')->insert(['attempt_id' => $a->id, 'question_id' => $qid, 'response' => json_encode(['options' => [$i + 1]]),
                    'score' => $odd || ($n + $i) % 3 === 0 ? 1 : 0, 'answered_at' => $start->copy()->addMinutes($odd ? $mins : 3 * ($i + 1))]);
            }
            if ($odd) {
                foreach (['focus_lost', 'tab_hidden', 'screen_capture_app'] as $k => $type) {
                    DB::table('proctoring_events')->insert(['tenant_id' => tenant_id(), 'attempt_id' => $a->id, 'type' => $type, 'severity' => 3 + ($k > 1 ? 1 : 0),
                        'data' => json_encode(['source' => 'desktop']), 'created_at' => $start->copy()->addMinutes(1 + $k)]);
                }
                $inc = Incident::create(['exam_session_id' => $session->id, 'attempt_id' => $a->id, 'type' => 'malpractice', 'severity' => 'high', 'status' => 'investigating', 'source' => 'proctor',
                    'needs_human_review' => true, 'title' => 'Screen-capture app reported by the desktop app', 'description' => 'The desktop app reported a screen-capture program 3 minutes into the paper and closed it.',
                    'opened_by' => $staff['proctor']->id, 'assigned_to' => $staff['examofficer']->id]);
                \App\Models\IncidentNote::create(['incident_id' => $inc->id, 'user_id' => $staff['proctor']->id, 'body' => 'Candidate says the program was left open from a class presentation. Recording kept.', 'created_at' => now()->subDays(3)]);
            }
        }
        // results, so the results screen and its export have rows (the flagged candidate is withheld)
        foreach (Attempt::where('exam_id', $exam->id)->get() as $a) {
            $pct = round($a->score_raw / $a->score_max * 100, 2);
            $flagged = $a->risk_score > 30;
            \App\Models\Result::firstOrCreate(['exam_id' => $exam->id, 'user_id' => $a->user_id], ['attempt_id' => $a->id, 'raw_score' => $a->score_raw, 'max_score' => $a->score_max,
                'final_score' => $pct, 'grade' => Policy::grade($pct), 'status' => $flagged ? 'withheld' : 'approved', 'withheld_reason' => $flagged ? 'Incident under review' : null, 'version' => 1]);
        }
        app(AnomalyModel::class)->run($exam);
    }

    /** Exception requests (pending, approved, declined) and hardware exceptions. */
    private function casework($students, array $staff): void
    {
        if (ExceptionRequest::exists()) {
            return;
        }
        $exam = Exam::where('title', 'CHM 101 · Mid-semester test')->first();
        $rows = [
            [$students[3], 'missed_exam', 'Missed CHM 101 mid-semester test (hospital)', 'I was admitted to the university clinic with malaria on the morning of the test. The clinic letter is attached.', 'pending', null],
            [$students[4], 'deadline', 'Extension for Problem set 1', 'Our hostel had no power for three days this week, so I could not finish typing my working.', 'approved', 'Power outage confirmed by Works; two extra days given.'],
            [$students[5], 'registration', 'Add GST 101 after the deadline', 'I forgot to add GST 101 before registration closed.', 'rejected', 'Late registration closed on the published date; please register next semester.'],
            [$students[6], 'accessibility', 'Extra time for exams', 'I have dyslexia; the disability office has assessed me and recommends 25% extra time.', 'pending', null],
        ];
        foreach ($rows as [$s, $type, $subject, $reason, $status, $note]) {
            ExceptionRequest::create(['user_id' => $s->id, 'type' => $type, 'subject' => $subject, 'reason' => $reason, 'exam_id' => $type === 'missed_exam' ? $exam?->id : null,
                'status' => $status, 'decision_note' => $note, 'decided_by' => $note ? $staff['examofficer']->id : null, 'decided_at' => $note ? now()->subDay() : null]);
        }
        HardwareException::create(['user_id' => $students[8]->id, 'kind' => 'no_webcam', 'detail' => 'Uses a shared family laptop without a camera; disability office letter seen. Sits in Hall A where invigilators check identity.',
            'relax' => ['webcam', 'face_detection', 'identity_check'], 'status' => 'approved', 'requested_by' => $staff['examofficer']->id, 'decided_by' => $staff['hod']->id, 'decided_at' => now()->subDays(5), 'valid_until' => now()->addMonths(4)]);
        HardwareException::create(['user_id' => $students[9]->id, 'kind' => 'assistive_device', 'detail' => 'Screen reader (NVDA) needs the second display check switched off.',
            'relax' => ['detect_multiple_displays'], 'status' => 'pending', 'requested_by' => $staff['examofficer']->id]);
    }

    /** E-mail delivery, API keys, connectors, an earlier closed semester and the next one. */
    private function operations(Tenant $tenant): void
    {
        if (! DB::table('mail_logs')->where('tenant_id', $tenant->id)->exists()) {
            foreach ([['student02@veritas.local', 'Exam timetable published', 'sent', null], ['student03@veritas.local', 'Your request: approved', 'sent', null],
                ['student04@veritas.local', 'Problem set 1 marked', 'bounced', 'Mailbox full'], ['old.address@yahoo.example', 'Exam slip ready', 'failed', 'Connection to the mail server timed out']] as $i => [$to, $subj, $st, $err]) {
                DB::table('mail_logs')->insert(['tenant_id' => $tenant->id, 'to' => $to, 'subject' => $subj, 'kind' => 'App\\Notifications\\LmsNotice', 'status' => $st, 'error' => $err,
                    'message_id' => Str::random(24).'@veritas.local', 'created_at' => now()->subHours(6 - $i), 'updated_at' => now()->subHours(6 - $i)]);
            }
        }
        foreach ([['Main gate card readers', ['attendance.write'], ['10.20.0.0/16']], ['Registry data warehouse', ['analytics.read'], ['10.0.5.20']], ['Student information system', ['sis.write', 'results.read'], ['10.0.5.21']]] as [$name, $scopes, $ips]) {
            if (! DB::table('api_keys')->where('tenant_id', $tenant->id)->where('name', $name)->exists()) {
                DB::table('api_keys')->insert(['tenant_id' => $tenant->id, 'name' => $name, 'prefix' => Str::random(8), 'key_hash' => hash('sha256', Str::random(40)), 'scopes' => json_encode($scopes),
                    'allowed_ips' => json_encode($ips), 'last_used_at' => now()->subHours(2), 'expires_at' => now()->addYear(), 'created_at' => now()->subMonth(), 'updated_at' => now()]);
            }
        }
        $settings = (array) $tenant->fresh()->settings;
        $settings['integrations'] ??= [
            'plagiarism' => ['enabled' => false, 'name' => 'TurnCheck (demo)', 'url' => 'https://plagiarism.example.edu.ng/veritas', 'secret' => Crypt::encryptString(Str::random(40)), 'auto' => false],
            'analytics' => ['enabled' => false, 'url' => 'https://warehouse.example.edu.ng/veritas-feed', 'secret' => Crypt::encryptString(Str::random(40)), 'include_personal' => false],
            'mail' => ['enabled' => false, 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'exams@veritas.edu.ng', 'secret' => '', 'from_address' => 'exams@veritas.edu.ng', 'from_name' => 'Veritas Exams Office'],
            'notify' => ['enabled' => false, 'url' => 'https://sms.example.edu.ng/hooks/veritas', 'secret' => Crypt::encryptString(Str::random(40)), 'urgent_only' => true],
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        // last session's semester, closed and archived, and the next one waiting
        $prev = AcademicSession::firstOrCreate(['name' => '2025/2026'], ['is_current' => false]);
        $old = Semester::firstOrCreate(['academic_session_id' => $prev->id, 'name' => 'First semester'], ['starts_on' => '2025-10-06', 'ends_on' => '2026-02-20', 'is_current' => false]);
        $old->forceFill(['status' => 'closed', 'closed_at' => '2026-03-01'])->save();
        $course = \App\Models\Course::where('code', 'CHM 101')->first();
        CourseOffering::firstOrCreate(['course_id' => $course->id, 'semester_id' => $old->id], ['status' => 'archived', 'capacity' => 280]);
        $cur = AcademicSession::where('name', '2026/2027')->first();
        Semester::where('academic_session_id', $cur->id)->where('name', 'First semester')->update(['ends_on' => '2027-02-19']);
        Semester::firstOrCreate(['academic_session_id' => $cur->id, 'name' => 'Second semester'], ['starts_on' => '2027-03-08', 'ends_on' => '2027-07-16', 'is_current' => false]);
    }

    /** Paying, trialling and suspended institutions, applications, and a second platform administrator. */
    private function platform(): void
    {
        $billing = app(Billing::class);
        $prov = app(TenantProvisioner::class);
        $make = function (string $slug, string $name, string $plan, string $domain) use ($prov) {
            return Tenant::where('slug', $slug)->first() ?? $prov->provision(['slug' => $slug, 'name' => $name, 'domains' => [$domain], 'status' => 'active', 'approved_at' => now()->subMonths(4),
                'contact_name' => 'ICT Director', 'contact_email' => "admin@$domain"], $plan, ['name' => 'Institution Administrator', 'email' => "admin@$domain", 'password' => $this->password]);
        };
        $poly = $make('lasupoly', 'Lagos State Polytechnic', 'professional', 'lasupoly.test');
        if (! DB::table('subscriptions')->where('tenant_id', $poly->id)->exists()) {
            $sub = $billing->start($poly, Plan::where('key', 'professional')->first(), 'yearly', null, 0);
            $inv = Invoice::where('subscription_id', $sub->id)->first();
            $billing->pay($inv, 'paystack', 'VT'.$inv->id.'-'.bin2hex(random_bytes(6)), $inv->amount, null, ['channel' => 'card']);
        }
        $coe = $make('kwaracoe', 'Kwara College of Education', 'starter', 'kwaracoe.test');
        if (! DB::table('subscriptions')->where('tenant_id', $coe->id)->exists()) {
            $sub = $billing->start($coe, Plan::where('key', 'starter')->first(), 'monthly', null, 0);
            Invoice::where('subscription_id', $sub->id)->update(['due_at' => now()->subDays(20)]);
            $sub->update(['status' => 'past_due']);
            $coe->update(['status' => 'suspended', 'suspension_reason' => 'non_payment']);
        }
        $fed = $make('fedpolyede', 'Federal Polytechnic Ede', 'exams_only', 'fedpolyede.test');
        if (! DB::table('subscriptions')->where('tenant_id', $fed->id)->exists()) {
            $sub = $billing->start($fed, Plan::where('key', 'exams_only')->first(), 'yearly', null, 0);
            $inv = Invoice::where('subscription_id', $sub->id)->first();
            $billing->pay($inv, 'bank_transfer', 'BANK-GTB-'.random_int(100000, 999999), $inv->amount, null, [], 'Transfer confirmed on statement');
        }
        if (! InstitutionApplication::exists()) {
            InstitutionApplication::create(['institution' => 'Bells University of Technology', 'slug' => 'bellsuni', 'contact_name' => 'Dr. Tola Akin', 'contact_email' => 'registrar@bellsuni.test',
                'contact_phone' => '0809 111 2222', 'students' => 6500, 'plan_id' => Plan::where('key', 'professional')->value('id'), 'cycle' => 'yearly', 'status' => 'pending',
                'message' => 'We want to start with the Faculty of Engineering in January, then the whole university.', 'ip' => '127.0.0.1']);
            InstitutionApplication::create(['institution' => 'Ondo State School of Nursing', 'slug' => 'ondonursing', 'contact_name' => 'Mrs. Bisi Ola', 'contact_email' => 'ict@ondonursing.test',
                'students' => 800, 'plan_id' => Plan::where('key', 'exams_only')->value('id'), 'cycle' => 'monthly', 'status' => 'pending', 'ip' => '127.0.0.1']);
            InstitutionApplication::create(['institution' => 'Test Institute', 'slug' => 'testinst', 'contact_name' => 'A B', 'contact_email' => 'someone@gmail.example',
                'cycle' => 'yearly', 'status' => 'rejected', 'review_note' => 'Could not confirm the institution or the phone number.', 'reviewed_at' => now()->subWeek(), 'ip' => '127.0.0.1']);
        }
        $second = User::withoutGlobalScopes()->firstOrNew(['email' => 'finance@platform.local', 'tenant_id' => null]);
        $second->forceFill(['name' => 'Platform Finance', 'password' => $this->password, 'is_platform_admin' => true, 'user_type' => 'platform', 'email_verified_at' => now(), 'status' => 'active'])->save();
    }

    /** A week of monitoring with an exam-day peak, alerts and a passed recovery drill. */
    private function monitoring(): void
    {
        if (DB::table('system_metrics')->exists()) {
            return;
        }
        $rows = [];
        $now = now()->startOfMinute();
        for ($m = 7 * 24 * 60; $m >= 1; $m -= ($m > 120 ? 10 : 1)) {
            $t = $now->copy()->subMinutes($m);
            $hour = (int) $t->format('G');
            $examDay = $t->isWeekday() && $hour >= 9 && $hour < 12;
            $cand = $examDay ? 180 + (int) (120 * sin(($hour - 9 + $t->minute / 60) / 3 * M_PI)) : 0;
            $req = ($examDay ? 900 : 60) + mt_rand(0, 80) + ($hour >= 8 && $hour < 18 ? 150 : 0);
            $rows[] = ['minute' => $t, 'data' => json_encode(['requests' => $req, 'errors' => mt_rand(0, 2), 'error_pct' => round(mt_rand(0, 2) / $req * 100, 2), 'exam_requests' => $examDay ? (int) ($req * 0.8) : 0,
                'p95_ms' => $examDay ? 180 + mt_rand(0, 120) : 90 + mt_rand(0, 60), 'cpu_pct' => $examDay ? 45 + mt_rand(0, 25) : 8 + mt_rand(0, 10), 'memory_pct' => 52 + mt_rand(0, 8),
                'disk_free_pct' => 64.0, 'db_connections' => $examDay ? 60 + mt_rand(0, 30) : 8 + mt_rand(0, 5), 'db_slow_queries' => 0, 'queue_waiting' => mt_rand(0, $examDay ? 40 : 3),
                'failed_jobs_1h' => 0, 'candidates_writing' => max(0, $cand), 'answers_saved' => $examDay ? $cand * 2 + mt_rand(0, 40) : 0])];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('system_metrics')->insert($chunk);
        }
        DB::table('system_alerts')->insert([
            ['key' => 'slow', 'level' => 'warning', 'message' => 'Slow responses (95th percentile): 1340 ms', 'value' => 1340, 'opened_at' => now()->subDays(2)->setTime(10, 12), 'resolved_at' => now()->subDays(2)->setTime(10, 31), 'acknowledged_at' => now()->subDays(2)->setTime(10, 15), 'acknowledged_by' => null],
            ['key' => 'network', 'level' => 'warning', 'message' => '1 exam-hall network device(s) not answering.', 'value' => 1, 'opened_at' => now()->subMinutes(7), 'resolved_at' => null, 'acknowledged_at' => null, 'acknowledged_by' => null],
        ]);
        DB::table('dr_drills')->insert(['status' => 'passed', 'rto_seconds' => 46, 'rpo_seconds' => 3, 'started_at' => now()->subDays(9), 'finished_at' => now()->subDays(9)->addMinutes(2), 'steps' => json_encode([
            ['step' => 'Take a fresh backup', 'ok' => true, 'seconds' => 21.4, 'detail' => 'Backup written and encrypted.'],
            ['step' => 'Restore it into a scratch database and compare', 'ok' => true, 'seconds' => 46.0, 'detail' => 'Row counts match the live database.'],
            ['step' => 'Replica receives a new write', 'ok' => true, 'seconds' => 2.6, 'detail' => 'Copied to the replica in 2.6 s.'],
            ['step' => 'Peer server answers: https://app2.platform.local', 'ok' => true, 'seconds' => 0.3, 'detail' => 'status 200 ok'],
        ])]);
    }
}
