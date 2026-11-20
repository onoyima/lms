<?php
// Creates one candidate and an open, unproctored objective exam for tests/e2e/offline.mjs.
// Local test databases only: refuses to run in production.
//   php tests/e2e/_offline_fixture.php            → prints JSON {email, password, candidate}
//   php tests/e2e/_offline_fixture.php short      → the same with a one-minute exam
//   php tests/e2e/_offline_fixture.php check ID   → prints the attempt and its saved answers as JSON
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->isProduction()) {
    fwrite(STDERR, "Not on production.\n");
    exit(1);
}
$tenant = App\Models\Tenant::where('slug', config('platform.default_tenant'))->first() ?? App\Models\Tenant::orderBy('id')->firstOrFail();
app(App\Support\Tenancy\TenantContext::class)->set($tenant);

if (($argv[1] ?? '') === 'check') {
    $a = App\Models\Attempt::where('exam_candidate_id', (int) $argv[2])->latest('id')->first();
    echo json_encode([
        'status' => $a?->status, 'reason' => $a?->submit_reason, 'answered' => $a?->answered_count, 'device_off_seconds' => $a?->device_off_seconds, 'violations' => $a?->violation_count,
        'answers' => $a ? Illuminate\Support\Facades\DB::table('attempt_answers')->where('attempt_id', $a->id)->whereNotNull('response')->count() : 0,
        'events' => $a ? App\Models\ProctoringEvent::where('attempt_id', $a->id)->pluck('type')->countBy() : [],
    ]);
    exit;
}

$tag = 'OFF'.now()->format('His');
$short = ($argv[1] ?? '') === 'short'; // a one-minute exam, to reach time-up while offline
$exam = App\Models\Exam::create(['title' => "Offline safety $tag", 'type' => 'cbt', 'status' => 'scheduled', 'duration_minutes' => $short ? 1 : 60, 'total_mark' => 6,
    'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false, 'power_cut_minutes' => 30, 'autosave_seconds' => 3, 'heartbeat_seconds' => 6],
    'proctoring' => ['require_consent' => false, 'violation_threshold' => 5, 'violation_action' => 'lock', 'detect_focus_loss' => true, 'detect_tab_switch' => true, 'pre_checks' => []],
    'channels' => ['web']]);
$section = $exam->sections()->create(['title' => 'Section A', 'position' => 0]);
foreach (range(1, 6) as $i) {
    $q = App\Models\Question::create(['type' => 'single_choice', 'stem_html' => "<p>Question $i: which is $i?</p>", 'mark' => 1, 'status' => 'approved', 'topic' => 'Offline']);
    foreach (['A', 'B', 'C'] as $j => $l) {
        $q->options()->create(['label' => $l, 'body_html' => $j === 1 ? "$i" : "Not $i ($l)", 'is_correct' => $j === 1, 'position' => $j]);
    }
    $section->questions()->attach($q->id, ['position' => $i]);
}
$session = $exam->sessions()->create(['mode' => 'remote', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHours(3), 'late_entry_minutes' => 120, 'status' => 'open']);
$user = App\Models\User::create(['name' => "Offline Candidate $tag", 'email' => strtolower($tag).'@offline.invalid', 'identifier' => "$tag/1", 'user_type' => 'student',
    'password' => Illuminate\Support\Facades\Hash::make('Offline!2026'), 'email_verified_at' => now()]);
$c = App\Models\ExamCandidate::create(['exam_session_id' => $session->id, 'user_id' => $user->id]);
echo json_encode(['email' => $user->email, 'password' => 'Offline!2026', 'candidate' => $c->id]);
