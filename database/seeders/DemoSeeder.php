<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Demo content for a local install: one CBT paper with chemistry, maths and physics
 * notation, one practical and one viva in the same format as the old portal, and a
 * demo student enrolled on all three. Local/demo use only: `php artisan db:seed --class=DemoSeeder`.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoInstitutionSeeder::class); // the sample institution (Veritas University)
        $tenant = Tenant::where('slug', 'veritas')->firstOrFail();
        app(TenantContext::class)->set($tenant);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $password = env('SEED_ADMIN_PASSWORD') ?: 'ChangeMe!2026';
        $officer = User::where('email', 'admin@veritas.local')->firstOrFail();
        $student = User::firstOrNew(['email' => 'student@veritas.local']);
        $student->forceFill(['name' => 'Ada Student', 'password' => $password, 'user_type' => 'student', 'identifier' => 'VUG/CHM/26/0001', 'email_verified_at' => now()])->save();
        $student->syncRoles(['student']);

        $q = function (string $type, string $stem, array $options = [], float $mark = 1, ?array $numeric = null) {
            $question = Question::create(['type' => $type, 'stem_html' => $stem, 'mark' => $mark, 'status' => 'approved', 'numeric_answer' => $numeric, 'topic' => 'Demo']);
            foreach ($options as $i => [$html, $correct]) {
                $question->options()->create(['label' => chr(65 + $i), 'body_html' => $html, 'is_correct' => $correct, 'position' => $i]);
            }

            return $question;
        };

        $questions = [
            $q('single_choice', '<p>Which coefficient balances \(\ce{2H2 + O2 -> \,?\,H2O}\)?</p>', [['1', false], ['2', true], ['3', false], ['4', false]]),
            $q('single_choice', '<p>The roots of \(ax^2+bx+c=0\) are</p>', [['\(x=\dfrac{-b\pm\sqrt{b^2-4ac}}{2a}\)', true], ['\(x=\dfrac{b\pm\sqrt{b^2+4ac}}{2a}\)', false], ['\(x=-\dfrac{c}{b}\)', false]]),
            $q('multiple_choice', '<p>Which of these are noble gases?</p>', [['\(\ce{Ne}\)', true], ['\(\ce{N2}\)', false], ['\(\ce{Ar}\)', true], ['\(\ce{Cl2}\)', false]], 2),
            $q('numeric', '<p>A body falls from rest for \(t = 2\,\text{s}\) with \(g = 9.8\,\text{m s}^{-2}\). Using \(s=\tfrac12 g t^2\), find \(s\) in metres.</p>', [], 2, ['value' => 19.6, 'tolerance' => 0.05]),
            $q('single_choice', '<p>Evaluate \(\displaystyle\int_0^1 3x^2\,dx\).</p>', [['0', false], ['1', true], ['3', false]]),
            $q('essay', '<p>Explain why \(\ce{NaCl(aq)}\) conducts electricity but \(\ce{NaCl(s)}\) does not.</p>', [], 5),
        ];

        $base = ['created_by' => $officer->id, 'status' => 'scheduled', 'max_attempts' => 1];

        $cbt = Exam::create($base + [
            'title' => 'CHM 101 · Demo CBT', 'type' => 'mixed', 'duration_minutes' => 30, 'total_mark' => 100,
            'instructions' => "Answer all questions.\nYour answers save automatically.",
            'settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'show_score' => true, 'require_check_in' => false],
            'proctoring' => ['require_consent' => true, 'detect_tab_switch' => true, 'require_fullscreen' => false, 'block_copy_paste' => true, 'violation_threshold' => 5, 'violation_action' => 'lock'],
            'channels' => ['web', 'desktop', 'seb'],
        ]);
        $section = $cbt->sections()->create(['title' => 'Section A', 'position' => 0, 'instructions' => 'Choose the best answer. Equations render exactly as typed in Word.']);
        foreach ($questions as $i => $question) {
            $section->questions()->attach($question->id, ['position' => $i]);
        }

        // Practical, exactly like the old portal: criteria with allowed score steps, marked per examiner
        $practical = Exam::create($base + ['title' => 'CHM 102 · Titration practical', 'type' => 'practical', 'duration_minutes' => 120, 'total_mark' => 20, 'marking_policy' => 'average']);
        foreach ([['Burette reading recorded to 2 d.p.', [0, 0.5, 1]], ['Correct end-point colour', [0, 1, 2]], ['Concordant titres', [0, 1, 2, 3]], ['Calculation of molarity', [0, 1, 2, 3, 4]]] as $i => [$title, $steps]) {
            $practical->criteria()->create(['text' => $title, 'position' => $i, 'allowed_scores' => $steps, 'max_score' => max($steps)]);
        }

        // Viva, exactly like the old portal: one overall criterion out of the exam total
        $viva = Exam::create($base + ['title' => 'CHM 499 · Project viva', 'type' => 'viva', 'duration_minutes' => 20, 'total_mark' => 100, 'marking_policy' => 'average']);
        $viva->criteria()->create(['text' => 'General Viva Performance', 'position' => 0, 'max_score' => 100]);

        foreach ([$cbt, $practical, $viva] as $exam) {
            $session = $exam->sessions()->create(['mode' => 'remote', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addDays(7), 'late_entry_minutes' => 60 * 24 * 7, 'status' => 'open']);
            ExamCandidate::firstOrCreate(['exam_session_id' => $session->id, 'user_id' => $student->id]);
            $exam->examiners()->syncWithoutDetaching([$officer->id => ['role' => 'examiner']]);
        }

        $this->lms($officer, $student, $password, $questions);
        $this->call(ScenarioSeeder::class);
        $this->call(FeatureCoverageSeeder::class);

        $this->command?->info("Demo student: student@veritas.local / {$password}");
        $this->command?->info("Demo lecturer: lecturer@veritas.local / {$password}");
    }

    /** A small LMS: one semester, a course with notes, an assignment, a forum and a quiz. */
    private function lms(User $officer, User $student, string $password, array $questions): void
    {
        $lecturer = User::firstOrNew(['email' => 'lecturer@veritas.local']);
        $lecturer->forceFill(['name' => 'Dr. Ngozi Okeke', 'password' => $password, 'user_type' => 'staff', 'identifier' => 'STAFF/0042', 'email_verified_at' => now()])->save();
        $lecturer->syncRoles(['lecturer']);

        $faculty = \App\Models\Faculty::firstOrCreate(['code' => 'NAS'], ['name' => 'Natural and Applied Sciences']);
        $dept = \App\Models\Department::firstOrCreate(['code' => 'CHM'], ['name' => 'Chemistry', 'faculty_id' => $faculty->id]);
        $programme = \App\Models\Programme::firstOrCreate(['code' => 'BSC-CHM'], ['name' => 'B.Sc. Industrial Chemistry', 'department_id' => $dept->id]);
        $session = \App\Models\AcademicSession::firstOrCreate(['name' => '2026/2027'], ['is_current' => true]);
        $semester = \App\Models\Semester::firstOrCreate(['academic_session_id' => $session->id, 'name' => 'First semester'], ['is_current' => true, 'starts_on' => '2026-10-05']);
        \App\Models\StudentProfile::updateOrCreate(['user_id' => $student->id], ['programme_id' => $programme->id, 'level' => 100, 'academic_status' => 'active']);
        \App\Models\RegistrationWindow::updateOrCreate(['semester_id' => $semester->id], ['opens_at' => now()->subWeek(), 'closes_at' => now()->addWeeks(2), 'min_units' => 3, 'max_units' => 24]);

        $offerings = [];
        foreach ([['CHM 101', 'General Chemistry I', 3, 'core'], ['MTH 101', 'Elementary Mathematics I', 3, 'core'], ['PHY 101', 'General Physics I', 3, 'core'], ['GST 101', 'Use of English', 2, 'elective']] as [$code, $title, $units, $cat]) {
            $course = \App\Models\Course::firstOrCreate(['code' => $code], ['title' => $title, 'credit_units' => $units, 'level' => 100, 'department_id' => $dept->id]);
            \Illuminate\Support\Facades\DB::table('programme_courses')->updateOrInsert(['programme_id' => $programme->id, 'course_id' => $course->id], ['tenant_id' => $course->tenant_id, 'level' => 100, 'category' => $cat]);
            $offerings[$code] = \App\Models\CourseOffering::firstOrCreate(['course_id' => $course->id, 'semester_id' => $semester->id], ['status' => 'open', 'capacity' => 300]);
            $offerings[$code]->staff()->syncWithoutDetaching([$lecturer->id => ['role' => 'coordinator']]);
        }
        \App\Models\Enrolment::firstOrCreate(['course_offering_id' => $offerings['CHM 101']->id, 'user_id' => $student->id], ['status' => 'active', 'category' => 'core']);

        $o = $offerings['CHM 101'];
        $o->update(['outline_html' => '<p><strong>Aims.</strong> Atomic structure, stoichiometry, gases and chemical equilibrium.</p><p><strong>Assessment.</strong> Continuous assessment 30 %, examination 70 %.</p>']);
        $content = app(\App\Services\Lms\CourseContentService::class);
        $w1 = \App\Models\CourseSection::firstOrCreate(['course_offering_id' => $o->id, 'title' => 'Week 1 · Atoms and moles'], ['position' => 0, 'summary' => 'Atomic structure and the mole concept.']);
        $w2 = \App\Models\CourseSection::firstOrCreate(['course_offering_id' => $o->id, 'title' => 'Week 2 · Stoichiometry'], ['position' => 1]);
        if (! \App\Models\CourseItem::where('course_offering_id', $o->id)->exists()) {
            $notes = $content->saveItem($o, ['course_section_id' => $w1->id, 'type' => 'page', 'title' => 'Lecture 1: The mole',
                'body_html' => '<p>One mole contains \\(N_A = 6.022\\times10^{23}\\) particles. Amount of substance: $$n = \\frac{m}{M}$$</p><p>Combustion of methane: \\(\\ce{CH4 + 2O2 -> CO2 + 2H2O}\\)</p>'], null, $lecturer);
            $content->saveItem($o, ['course_section_id' => $w1->id, 'type' => 'assignment', 'title' => 'Problem set 1', 'prerequisite_item_id' => $notes->id,
                'body_html' => '<p>Calculate the mass of \\(\\ce{CO2}\\) produced when 8 g of methane burns completely. Show your working.</p>',
                'due_at' => now()->addDays(5), 'cutoff_at' => now()->addDays(8), 'settings' => ['max_score' => 20, 'late_penalty_pct' => 10, 'rubric' => [
                    ['criterion' => 'Balanced equation', 'levels' => [['label' => 'Missing', 'points' => 0], ['label' => 'Correct', 'points' => 5]]],
                    ['criterion' => 'Mole ratio and working', 'levels' => [['label' => 'Weak', 'points' => 0], ['label' => 'Partial', 'points' => 5], ['label' => 'Complete', 'points' => 10]]],
                    ['criterion' => 'Final answer with units', 'levels' => [['label' => 'Wrong', 'points' => 0], ['label' => 'Right', 'points' => 5]]]]]], null, $lecturer);
            $content->saveItem($o, ['course_section_id' => $w1->id, 'type' => 'forum', 'title' => 'Questions and answers'], null, $lecturer);
            $quiz = $content->saveItem($o, ['course_section_id' => $w2->id, 'type' => 'quiz', 'title' => 'Quiz 1: Stoichiometry', 'due_at' => now()->addDays(10), 'settings' => ['duration' => 15, 'max_score' => 10]], null, $lecturer);
            \App\Models\Exam::find($quiz->exam_id)->sections()->first()->questions()->attach(collect($questions)->take(3)->mapWithKeys(fn ($q, $i) => [$q->id => ['position' => $i]])->all());
            $content->publishQuiz($quiz, $lecturer);
            $content->saveItem($o, ['course_section_id' => $w2->id, 'type' => 'page', 'title' => 'Lecture 3 notes (released next week)', 'release_at' => now()->addWeek(), 'body_html' => '<p>Limiting reagents.</p>'], null, $lecturer);
        }
        app(\App\Services\Lms\GradebookService::class)->ensureDefaults($o);
    }
}
