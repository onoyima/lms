<?php
// Ids of real records for the page crawlers (tests/e2e/responsive.mjs). Local test databases only.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->isProduction()) {
    exit(1);
}
$t = App\Models\Tenant::where('slug', 'veritas')->first() ?? App\Models\Tenant::orderBy('id')->firstOrFail();
app(App\Support\Tenancy\TenantContext::class)->set($t);
$student = App\Models\User::where('email', 'student@veritas.local')->first();
echo json_encode([
    'exam' => App\Models\Exam::orderBy('id')->value('id'),
    'group' => App\Models\ExamGroup::orderBy('id')->value('id'),
    'session' => App\Models\ExamSession::orderByDesc('id')->value('id'),
    'course' => App\Models\Course::orderBy('id')->value('id'),
    'question' => App\Models\Question::orderBy('id')->value('id'),
    'meeting' => Illuminate\Support\Facades\DB::table('meetings')->where('tenant_id', $t->id)->orderByDesc('id')->value('id'),
    'tenant' => $t->id,
    'offering' => Illuminate\Support\Facades\DB::table('course_offerings')->orderBy('id')->value('id'),
    'candidate' => $student ? App\Models\ExamCandidate::where('user_id', $student->id)->orderByDesc('id')->value('id') : null,
]);
