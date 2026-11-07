<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\MarkingCriterion;
use App\Models\Question;
use App\Models\Tenant;
use App\Services\Exams\Marker;
use App\Services\Questions\RichTextSanitizer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the old EXAMS portal into the platform, keeping its three exam formats:
 *
 *   objective  → CBT exam: questions A–D (with images), duration, open/close window, attempts,
 *                score policy, shuffling, "questions to show", proctoring switches, attempts & scores
 *   practical  → panel-marked exam: each question becomes a marking criterion with the same
 *                allowed score steps (e.g. "0,0.5,1"); every examiner's marks are kept
 *   viva       → panel-marked exam with one criterion, "General Viva Performance", out of the total mark
 *
 * Students, examiners, enrolments (exam_students) and examiner assignments (exam_examiners)
 * come across as well. Results are recomputed with the platform's own rules (score policy,
 * scaling to total mark, average of examiners) so they match what the old portal showed.
 *
 * SAFETY: this command only ever connects to a database on THIS computer (localhost,
 * 127.0.0.1 or ::1). It does not read the old portal's .env or any remote setting, and it
 * refuses any other host. It is safe to run more than once: rows already imported are
 * updated, never duplicated (see the legacy_map table).
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import
        {--database=exams_portal : Name of the old portal database on this computer}
        {--host=127.0.0.1 : Must be localhost, 127.0.0.1 or ::1}
        {--port=3306}
        {--username=root}
        {--password= : Leave empty for XAMPP\'s default root account}
        {--tenant= : Institution to import into (default: the default institution)}
        {--uploads= : Path to the old portal\'s uploads folder, to copy question images}
        {--only= : Comma list of steps: people,exams,questions,enrolments,marks,attempts,results}
        {--publish-visible : Publish results of exams where the old portal let students see their score}
        {--dry-run : Run everything, report the counts, then undo all changes}';

    protected $description = 'Import the old EXAMS portal (objective, practical, viva) from a LOCAL database';

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private Connection $legacy;

    private Tenant $tenant;

    private array $counts = [];

    /** @var array<string, array<string, int>> kind => legacy id => new id */
    private array $map = [];

    public function handle(RichTextSanitizer $sanitizer, Marker $marker): int
    {
        $host = strtolower(trim((string) $this->option('host')));
        if (! in_array($host, self::LOCAL_HOSTS, true)) {
            $this->error("Refusing to connect to \"{$host}\". legacy:import only reads a database on this computer (localhost, 127.0.0.1 or ::1).");
            $this->line('Copy the old database to this computer first (phpMyAdmin → Export, then Import into local MySQL).');

            return self::FAILURE;
        }

        $this->tenant = Tenant::where('slug', $this->option('tenant') ?: config('platform.default_tenant'))->first()
            ?? tap(null, fn () => $this->error('Unknown institution: '.$this->option('tenant')));
        if (! $this->tenant) {
            return self::FAILURE;
        }
        app(TenantContext::class)->set($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        config(['database.connections.legacy_local' => [
            'driver' => 'mysql', 'host' => $host, 'port' => (int) $this->option('port'),
            'database' => $this->option('database'), 'username' => $this->option('username'),
            'password' => (string) $this->option('password'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => false,
        ]]);
        $this->legacy = DB::connection('legacy_local');

        try {
            $this->legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not open the local database: '.$e->getMessage());

            return self::FAILURE;
        }

        $steps = $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : ['people', 'exams', 'questions', 'enrolments', 'marks', 'attempts', 'results'];
        $this->loadMap();
        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[dry run] ' : '')."Importing {$this->option('database')}@{$host} into {$this->tenant->name}");

        DB::beginTransaction();
        try {
            in_array('people', $steps) && $this->people();
            in_array('exams', $steps) && $this->exams();
            in_array('questions', $steps) && $this->questions($sanitizer, $dry);
            in_array('enrolments', $steps) && $this->enrolments();
            in_array('marks', $steps) && $this->marks();
            in_array('attempts', $steps) && $this->attempts();
            in_array('results', $steps) && $this->results($marker);
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import stopped, nothing was changed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->table(['What', 'Count'], collect($this->counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info($dry ? 'Dry run finished; no changes were kept.' : 'Import finished.');

        return self::SUCCESS;
    }

    // ── People ──────────────────────────────────────────────────────────────

    private function people(): void
    {
        $roles = DB::table('roles')->where('tenant_id', $this->tenant->id)->pluck('id', 'name');

        $this->legacyRows('examiners', function ($rows) use ($roles) {
            foreach ($rows as $e) {
                $identifier = trim((string) ($e->username ?: 'examiner-'.$e->id));
                $email = filter_var($e->email ?? '', FILTER_VALIDATE_EMAIL) ? strtolower($e->email) : Str::slug($identifier, '.').'@staff.legacy.invalid';
                $id = $this->upsertUser('examiner', $e->id, [
                    'name' => trim((string) $e->name) ?: $identifier, 'email' => $email, 'identifier' => $identifier,
                    'user_type' => 'staff', 'status' => $e->deleted_at ? 'inactive' : 'active',
                ], $e->password);
                $role = ($e->role ?? '') === 'admin' ? 'exam_officer' : 'lecturer';
                DB::table('model_has_roles')->insertOrIgnore(['role_id' => $roles[$role], 'model_type' => \App\Models\User::class, 'model_id' => $id, 'tenant_id' => $this->tenant->id]);
                $this->bump('examiners');
            }
        });

        $this->legacyRows('students', function ($rows) use ($roles) {
            foreach ($rows as $s) {
                $identifier = trim((string) ($s->matric_no ?: $s->username ?: 'student-'.$s->id));
                $name = trim((string) $s->name) ?: trim(preg_replace('/\s+/', ' ', "{$s->firstname} {$s->middlename} {$s->lastname}"));
                $email = filter_var($s->email ?? '', FILTER_VALIDATE_EMAIL) ? strtolower($s->email) : Str::slug($identifier, '.').'@students.legacy.invalid';
                $id = $this->upsertUser('student', $s->id, [
                    'name' => $name ?: $identifier, 'email' => $email, 'identifier' => $identifier,
                    'user_type' => 'student', 'status' => $s->deleted_at ? 'inactive' : 'active',
                ], $s->password);
                DB::table('model_has_roles')->insertOrIgnore(['role_id' => $roles['student'], 'model_type' => \App\Models\User::class, 'model_id' => $id, 'tenant_id' => $this->tenant->id]);
                $this->bump('students');
            }
        });
    }

    /** Keeps bcrypt/argon hashes as they are (old logins keep working); hashes plain-text passwords. */
    private function upsertUser(string $kind, int $legacyId, array $attrs, ?string $password): int
    {
        $existing = $this->map[$kind][(string) $legacyId] ?? DB::table('users')->where('tenant_id', $this->tenant->id)
            ->where(fn ($q) => $q->where('identifier', $attrs['identifier'])->orWhere('email', $attrs['email']))->value('id');

        $password = (string) $password;
        $hash = preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $password) ? $password
            : ($password !== '' ? Hash::make($password, ['rounds' => 10]) : Hash::make(Str::password(24)));
        if ($password === '') {
            $this->bump('accounts needing a password reset');
        }

        $now = now();
        if ($existing) {
            DB::table('users')->where('id', $existing)->update($attrs + ['updated_at' => $now]);
        } else {
            // Another account may already use this email (e.g. a student who is also staff)
            if (DB::table('users')->where('tenant_id', $this->tenant->id)->where('email', $attrs['email'])->exists()) {
                $attrs['email'] = Str::before($attrs['email'], '@').'+'.$kind.$legacyId.'@'.Str::after($attrs['email'], '@');
            }
            if (in_array($attrs['user_type'] ?? null, ['student', 'staff'], true) && ($attrs['status'] ?? 'active') === 'active') {
                // the subscription's capacity applies to imported people too
                app(\App\Services\Billing\Entitlements::class)->check($attrs['user_type'] === 'student' ? 'students' : 'staff', 1, $this->tenant);
            }
            $existing = DB::table('users')->insertGetId($attrs + ['tenant_id' => $this->tenant->id, 'password' => $hash, 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->remember($kind, $legacyId, $existing);

        return $existing;
    }

    // ── Exams ───────────────────────────────────────────────────────────────

    private function exams(): void
    {
        $sessions = $this->legacy->table('academic_sessions')->pluck('session_name', 'id');
        $officer = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.tenant_id', $this->tenant->id)->where('roles.name', 'institution_admin')->value('model_id');

        foreach ($this->legacy->table('exams')->orderBy('id')->get() as $old) {
            $type = match ($old->type) { 'practical' => 'practical', 'viva' => 'viva', default => 'cbt' };
            $opens = $this->date($old->open_at ?? null) ?? $this->date($old->start_time ?? null) ?? $this->date($old->created_at) ?? CarbonImmutable::now();
            $closes = $this->date($old->close_at ?? null) ?? $this->date($old->end_time ?? null) ?? $opens->addDay();
            if ($closes <= $opens) {
                $closes = $opens->addMinutes(max(60, (int) $old->duration_minutes));
            }
            $finished = $closes->isPast();
            $hasMarks = $this->legacy->table($type === 'cbt' ? 'objective_attempts' : 'answers')->where('exam_id', $old->id)->exists();

            $attrs = [
                'title' => $old->name,
                'type' => $type,
                'instructions' => $old->description,
                'status' => $old->deleted_at ? 'archived' : ($finished && $hasMarks ? 'closed' : ($old->is_active ? 'scheduled' : 'draft')),
                'total_mark' => $old->total_mark ?: null,
                'duration_minutes' => max(1, (int) ($old->duration_minutes ?: 60)),
                'max_attempts' => max(1, (int) ($old->max_attempts ?: 1)),
                'score_policy' => in_array($old->score_policy, ['highest', 'first', 'last'], true) ? $old->score_policy : 'highest',
                'marking_policy' => 'average',
                'created_by' => $officer,
                'legacy_ref' => 'exams:'.$old->id,
                'settings' => [
                    'shuffle_questions' => (bool) $old->shuffle_objective,
                    'shuffle_options' => (bool) $old->shuffle_options,
                    'show_score' => (bool) $old->student_can_view_score,
                    'require_check_in' => false,
                    'legacy' => ['academic_session' => $sessions[$old->session_id] ?? null, 'grading_method' => $old->grading_method],
                ],
                'proctoring' => [
                    'require_fullscreen' => (bool) $old->proctor_fullscreen,
                    'detect_tab_switch' => (bool) $old->proctor_tab_lock,
                    'detect_focus_loss' => (bool) $old->proctor_tab_lock,
                    'block_copy_paste' => (bool) $old->proctor_kb_lock,
                    'block_shortcuts' => (bool) $old->proctor_kb_lock,
                    'webcam' => (bool) $old->proctor_webcam,
                    'live_video' => (bool) $old->proctor_live_video,
                    'single_session' => (bool) $old->is_strict,
                    'violation_threshold' => 5,                       // old portal locked after 5 violations
                    'violation_action' => $old->proctor_auto_submit ? 'submit' : 'lock',
                ],
                // "Browser check" on the old portal meant: only the secure browser
                'channels' => $old->proctor_browser_check ? ['seb', 'desktop'] : ['web', 'desktop', 'seb'],
            ];

            $exam = $this->model('exam', $old->id, Exam::class, $attrs);

            // One sitting per exam, covering the old open/close window
            $this->model('sitting', $old->id, ExamSession::class, [
                'exam_id' => $exam->id, 'name' => 'Imported sitting', 'mode' => 'remote',
                'starts_at' => $opens, 'ends_at' => $closes,
                'late_entry_minutes' => (int) min(65535, max(0, $opens->diffInMinutes($closes))),
                'status' => $finished ? 'closed' : ($opens->isPast() ? 'open' : 'scheduled'),
            ]);

            if ($type === 'cbt') {
                $section = $exam->sections()->firstOrCreate(['position' => 0], ['title' => 'Section A']);
                $section->update(['blueprint' => $old->questions_to_show > 0 ? [['draw' => (int) $old->questions_to_show]] : null]);
            }
            $this->bump("exams ({$old->type})");
        }
    }

    // ── Questions and marking criteria ──────────────────────────────────────

    private function questions(RichTextSanitizer $sanitizer, bool $dry): void
    {
        // Objective: A–D options, one correct, optional images on the stem and each option
        $this->legacyRows('objective_questions', function ($rows) use ($sanitizer, $dry) {
            foreach ($rows as $old) {
                $examId = $this->map['exam'][(string) $old->exam_id] ?? null;
                if (! $examId) {
                    continue;
                }
                $stem = $this->html($old->question_text, $old->question_image, $sanitizer, $dry);
                $question = $this->model('objective_question', $old->id, Question::class, [
                    'type' => 'single_choice', 'stem_html' => $stem, 'mark' => $old->mark ?: 1,
                    'status' => $old->deleted_at ? 'retired' : 'approved', 'topic' => 'Imported',
                ]);
                $question->options()->delete();
                $position = 0;
                foreach (['a', 'b', 'c', 'd'] as $letter) {
                    $text = $old->{'option_'.$letter};
                    $image = $old->{'option_'.$letter.'_image'};
                    if (trim((string) $text) === '' && ! $image) {
                        continue;
                    }
                    $question->options()->create([
                        'label' => strtoupper($letter), 'position' => $position++,
                        'body_html' => $this->html($text, $image, $sanitizer, $dry),
                        'is_correct' => strtolower((string) $old->correct_option) === $letter,
                    ]);
                }
                if (! $old->deleted_at) {
                    $section = Exam::find($examId)->sections()->orderBy('position')->first();
                    DB::table('exam_section_questions')->updateOrInsert(
                        ['exam_section_id' => $section->id, 'question_id' => $question->id],
                        ['position' => $old->id, 'mark' => null]
                    );
                }
                $this->bump('objective questions');
            }
        });

        // Practical and viva: questions linked by exam_questions (or questions.exam_id)
        $links = $this->legacy->table('exam_questions')->select('exam_id', 'question_id')->get()
            ->concat($this->legacy->table('questions')->whereNotNull('exam_id')->select('exam_id', 'id as question_id')->get())
            ->unique(fn ($r) => $r->exam_id.':'.$r->question_id);
        $questions = $this->legacy->table('questions')->get()->keyBy('id');
        $types = $this->legacy->table('exams')->pluck('type', 'id');
        $totals = $this->legacy->table('exams')->pluck('total_mark', 'id');

        foreach ($links->groupBy('exam_id') as $legacyExam => $group) {
            $examId = $this->map['exam'][(string) $legacyExam] ?? null;
            if (! $examId || ! in_array($types[$legacyExam] ?? null, ['practical', 'viva'], true)) {
                continue;
            }
            foreach ($group->values() as $i => $link) {
                $q = $questions[$link->question_id] ?? null;
                if (! $q || $q->deleted_at) {
                    continue;
                }
                $steps = collect(explode(',', (string) $q->score_values))->map(fn ($v) => trim($v))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v)->unique()->sort()->values();
                $isViva = $types[$legacyExam] === 'viva';
                // Viva "0" means: any mark from 0 to the exam total (scored dynamically on the old portal)
                $allowed = $isViva || $steps->count() < 2 ? null : $steps->all();
                $max = $allowed ? max($allowed) : (float) ($totals[$legacyExam] ?: max(1, $steps->max() ?? 1));
                $this->model('criterion', $legacyExam.':'.$q->id, MarkingCriterion::class, [
                    'exam_id' => $examId, 'text' => Str::limit(trim((string) $q->text) ?: 'Criterion', 490), 'allowed_scores' => $allowed,
                    'max_score' => $max, 'position' => $i,
                ]);
                $this->bump($isViva ? 'viva criteria' : 'practical criteria');
            }
        }
    }

    // ── Enrolments and examiners ────────────────────────────────────────────

    private function enrolments(): void
    {
        $this->legacyRows('exam_students', function ($rows) {
            foreach ($rows as $r) {
                $session = $this->map['sitting'][(string) $r->exam_id] ?? null;
                $user = $this->map['student'][(string) $r->student_id] ?? null;
                if ($session && $user) {
                    ExamCandidate::firstOrCreate(['exam_session_id' => $session, 'user_id' => $user], ['attendance' => 'expected']);
                    $this->bump('candidates');
                }
            }
        });

        foreach ($this->legacy->table('exam_examiners')->get() as $r) {
            $exam = $this->map['exam'][(string) $r->exam_id] ?? null;
            $user = $this->map['examiner'][(string) $r->examiner_id] ?? null;
            if ($exam && $user) {
                DB::table('exam_examiners')->updateOrInsert(['exam_id' => $exam, 'user_id' => $user], ['role' => 'examiner', 'updated_at' => now(), 'created_at' => now()]);
                $this->bump('examiner assignments');
            }
        }
    }

    // ── Practical / viva marks (one row per examiner, as on the old portal) ──

    private function marks(): void
    {
        $this->legacyRows('answers', function ($rows) {
            $batch = [];
            foreach ($rows as $a) {
                $criterion = $this->map['criterion'][$a->exam_id.':'.$a->question_id] ?? null;
                $user = $this->map['student'][(string) $a->student_id] ?? null;
                $examiner = $this->map['examiner'][(string) $a->examiner_id] ?? null;
                $exam = $this->map['exam'][(string) $a->exam_id] ?? null;
                if (! $criterion || ! $user || ! $examiner || $a->score === null) {
                    $this->bump('marks skipped (unknown student/examiner/question)');

                    continue;
                }
                $batch[] = [
                    'tenant_id' => $this->tenant->id, 'exam_id' => $exam, 'marking_criterion_id' => $criterion, 'user_id' => $user,
                    'examiner_id' => $examiner, 'score' => $a->score, 'created_at' => $a->created_at ?? now(), 'updated_at' => $a->updated_at ?? now(),
                ];
            }
            if ($batch) {
                DB::table('criterion_marks')->upsert($batch, ['marking_criterion_id', 'user_id', 'examiner_id'], ['score', 'updated_at']);
                $this->bump('criterion marks', count($batch));
            }
        });
    }

    // ── Objective attempts and answers ──────────────────────────────────────

    private function attempts(): void
    {
        $optionIds = DB::table('question_options')->join('legacy_map', function ($j) {
            $j->on('legacy_map.new_id', '=', 'question_options.question_id')->where('legacy_map.kind', 'objective_question')->where('legacy_map.tenant_id', $this->tenant->id);
        })->get(['legacy_map.legacy_id', 'question_options.label', 'question_options.id'])
            ->mapWithKeys(fn ($r) => [$r->legacy_id.':'.$r->label => $r->id]);
        $maxScore = $this->legacy->table('objective_questions')->whereNull('deleted_at')->groupBy('exam_id')->selectRaw('exam_id, SUM(COALESCE(NULLIF(mark,0),1)) total, COUNT(*) n')->get()->keyBy('exam_id');
        $toShow = $this->legacy->table('exams')->pluck('questions_to_show', 'id');

        $this->legacyRows('objective_attempts', function ($rows) use ($optionIds, $maxScore, $toShow) {
            foreach ($rows as $a) {
                $exam = $this->map['exam'][(string) $a->exam_id] ?? null;
                $session = $this->map['sitting'][(string) $a->exam_id] ?? null;
                $user = $this->map['student'][(string) $a->student_id] ?? null;
                if (! $exam || ! $user) {
                    continue;
                }
                $candidate = ExamCandidate::firstOrCreate(['exam_session_id' => $session, 'user_id' => $user], ['attendance' => 'present']);
                $started = $this->date($a->started_at) ?? CarbonImmutable::now();
                $finished = $this->date($a->finished_at);
                $info = $maxScore[$a->exam_id] ?? null;
                $max = $info ? (float) $info->total : 0;
                if ($info && ($toShow[$a->exam_id] ?? 0) > 0 && $toShow[$a->exam_id] < $info->n) {
                    $max = round($max / $info->n * $toShow[$a->exam_id], 2); // average mark × questions shown
                }

                $attrs = [
                    'tenant_id' => $this->tenant->id, 'exam_id' => $exam, 'exam_session_id' => $session, 'exam_candidate_id' => $candidate->id,
                    'user_id' => $user, 'attempt_no' => max(1, (int) $a->attempt_no), 'channel' => 'web',
                    'status' => $finished ? 'submitted' : ($a->is_locked ? 'terminated' : 'auto_submitted'),
                    'submit_reason' => 'legacy_import',
                    'started_at' => $started, 'deadline_at' => $finished ?? $started->addMinutes(60), 'submitted_at' => $finished ?? $this->date($a->last_active) ?? $started,
                    'violation_count' => (int) $a->violation_count, 'score_raw' => $a->score ?? 0, 'score_max' => $max,
                    'needs_marking' => false, 'updated_at' => now(),
                ];
                $id = $this->map['attempt'][(string) $a->id] ?? null;
                if ($id) {
                    DB::table('attempts')->where('id', $id)->update($attrs);
                } else {
                    $id = DB::table('attempts')->insertGetId($attrs + ['created_at' => now()]);
                    $this->remember('attempt', $a->id, $id);
                }

                // The answers the student chose, for review and appeals
                $answers = $this->legacy->table('objective_answers')->where('exam_id', $a->exam_id)->where('student_id', $a->student_id)
                    ->where(fn ($q) => $q->where('attempt_no', $a->attempt_no)->orWhereNull('attempt_no'))->get();
                $rows = [];
                foreach ($answers as $ans) {
                    $question = $this->map['objective_question'][(string) $ans->question_id] ?? null;
                    $option = $optionIds[$ans->question_id.':'.strtoupper((string) $ans->selected_option)] ?? null;
                    if ($question) {
                        $rows[] = ['attempt_id' => $id, 'question_id' => $question, 'response' => $option ? json_encode(['options' => [$option]]) : null,
                            'client_seq' => 1, 'flagged' => false, 'answered_at' => $this->date($ans->submitted_at) ?? $started];
                    }
                }
                if ($rows) {
                    DB::table('attempt_answers')->upsert($rows, ['attempt_id', 'question_id'], ['response', 'answered_at']);
                }
                DB::table('attempts')->where('id', $id)->update(['answered_count' => count(array_filter($rows, fn ($r) => $r['response'] !== null))]);
                $this->bump('objective attempts');
            }
        });
    }

    // ── Results, recomputed by the platform's rules ─────────────────────────

    private function results(Marker $marker): void
    {
        $visible = $this->legacy->table('exams')->where('student_can_view_score', 1)->pluck('id')->map(fn ($id) => $this->map['exam'][(string) $id] ?? null)->filter()->all();

        foreach ($this->map['exam'] ?? [] as $examId) {
            $exam = Exam::find($examId);
            $users = $exam->isPanelMarked()
                ? DB::table('criterion_marks')->where('exam_id', $examId)->distinct()->pluck('user_id')
                : DB::table('attempts')->where('exam_id', $examId)->distinct()->pluck('user_id');
            foreach ($users as $userId) {
                $result = $marker->refreshResult($exam, $userId);
                if ($result && $this->option('publish-visible') && in_array($examId, $visible, true) && ! $result->locked && $result->status !== 'pending_marking') {
                    $result->forceFill(['status' => 'published', 'published_at' => now(), 'locked' => true])->save();
                    $this->bump('results published');
                }
                $result && $this->bump('results');
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Converts old text (plain or HTML, with $…$ maths) plus an optional image into safe HTML. */
    private function html(?string $text, ?string $image, RichTextSanitizer $sanitizer, bool $dry): string
    {
        $text = (string) $text;
        $html = $text !== strip_tags($text) ? $text : '<p>'.nl2br(e($text), false).'</p>';
        $html = preg_replace_callback('#(<img[^>]+src=")(?!https?:|data:|/)([^"]+)"#i', fn ($m) => $m[1].$this->image($m[2], $dry).'"', $html) ?? $html;
        if ($image) {
            $html .= '<p><img src="'.e($this->image($image, $dry)).'" alt=""></p>';
        }
        $clean = $sanitizer->clean($html);

        return $clean !== '' ? $clean : '<p></p>';
    }

    /** Copies one image from the old uploads folder into public storage; returns its new URL. */
    private function image(string $path, bool $dry): string
    {
        $dir = $this->option('uploads');
        $name = basename(str_replace('\\', '/', $path));
        $source = $dir ? rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name : null;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! $source || ! is_file($source) || ! in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) || ! @getimagesize($source)) {
            $this->bump('images not found (re-run with --uploads=…)');

            return '/storage/question-media/missing.png';
        }
        $target = 'question-media/legacy/'.sha1_file($source).'.'.$ext;
        if (! $dry && ! Storage::disk('public')->exists($target)) {
            Storage::disk('public')->put($target, file_get_contents($source));
        }
        $this->bump('images copied');

        return '/storage/'.$target;
    }

    /** @template T of \Illuminate\Database\Eloquent\Model @param class-string<T> $class @return T */
    private function model(string $kind, int|string $legacyId, string $class, array $attrs)
    {
        $id = $this->map[$kind][(string) $legacyId] ?? null;
        $model = $id ? $class::find($id) : null;
        if ($model) {
            $model->update($attrs);
        } else {
            $model = $class::create($attrs);
            $this->remember($kind, $legacyId, $model->getKey());
        }

        return $model;
    }

    private function legacyRows(string $table, callable $each): void
    {
        $this->legacy->table($table)->orderBy('id')->chunk(500, fn ($rows) => $each($rows));
    }

    private function loadMap(): void
    {
        DB::table('legacy_map')->where('tenant_id', $this->tenant->id)->orderBy('id')
            ->each(function ($r) {
                $this->map[$r->kind][$r->legacy_id] = (int) $r->new_id;
            });
    }

    private function remember(string $kind, int|string $legacyId, int $newId): void
    {
        $this->map[$kind][(string) $legacyId] = $newId;
        DB::table('legacy_map')->upsert(
            [['tenant_id' => $this->tenant->id, 'kind' => $kind, 'legacy_id' => (string) $legacyId, 'new_id' => $newId, 'imported_at' => now()]],
            ['tenant_id', 'kind', 'legacy_id'], ['new_id', 'imported_at']
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! $value || str_starts_with((string) $value, '0000')) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function bump(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }
}
