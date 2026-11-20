<?php

namespace App\Services\Exams;

use App\Models\AccommodationProfile;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\User;
use App\Notifications\ExamScheduled;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Adds students to a sitting: from the course's enrolment, a resit's parent exam, or a typed list. */
class CandidateRoster
{
    public function __construct(private Eligibility $eligibility, private AuditLogger $audit) {}

    /**
     * @param  array{identifiers?: string|null, from_enrolment?: bool, from_parent?: bool, from_programme?: array{programme_id: int, level?: int|null}}  $data
     * @return array{added: int, missing: list<string>}
     */
    public function add(ExamSession $session, array $data): array
    {
        $exam = $session->exam;
        $users = collect();

        if (! empty($data['from_enrolment'])) {
            abort_unless($exam->course_id && $exam->semester_id, 422, 'Set the exam\'s course and semester first.');
            $offering = CourseOffering::where('course_id', $exam->course_id)->where('semester_id', $exam->semester_id)->first();
            abort_unless($offering, 422, 'This course has no offering in that semester.');
            $users = User::whereIn('id', Enrolment::where('course_offering_id', $offering->id)->where('status', 'active')->pluck('user_id'))->get();
        }

        if (! empty($data['from_parent'])) {
            $users = $users->merge(User::whereIn('id', $this->eligibility->parentCandidates($exam))->get());
        }
        [$found, $missing] = $this->resolve($data);
        $users = $users->merge($found);

        $added = DB::transaction(function () use ($users, $session, $exam) {
            $added = 0;
            $verdicts = $this->eligibility->evaluate($exam, $users->unique('id'));
            $profiles = AccommodationProfile::whereIn('user_id', $users->pluck('id'))->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))->get()->keyBy('user_id');

            foreach ($users->unique('id') as $user) {
                [$eligibility, $reason] = $verdicts[$user->id];
                $profile = $profiles[$user->id] ?? null;
                $candidate = ExamCandidate::firstOrCreate(
                    ['exam_session_id' => $session->id, 'user_id' => $user->id],
                    [
                        'eligibility' => $eligibility, 'eligibility_reason' => $reason,
                        'extra_time_minutes' => $profile ? (int) ceil($exam->duration_minutes * $profile->extra_time_percent / 100) : 0,
                        'accommodations' => $profile?->settings,
                    ]
                );
                $added += $candidate->wasRecentlyCreated ? 1 : 0;
            }

            return $added;
        });

        if (empty($data['quiet'])) {
            Notification::send($users->unique('id'), new ExamScheduled($session));
        }
        $this->audit->record('session.candidates_added', $session, ['added' => $added, 'not_found' => count($missing)]);

        return ['added' => $added, 'missing' => array_values($missing)];
    }

    public static function message(array $r): string
    {
        $missing = $r['missing'];

        return "{$r['added']} candidates added.".($missing ? ' Not found: '.implode(', ', array_slice($missing, 0, 20)).(count($missing) > 20 ? '…' : '') : '');
    }

    /**
     * Students named by a programme (and level), a pasted list of matric numbers or e-mails, or ids.
     *
     * @return array{0: \Illuminate\Support\Collection<int, User>, 1: list<string>} the students, and what was not found
     */
    public function resolve(array $data): array
    {
        $users = collect();
        if (! empty($data['from_programme']['programme_id'])) {
            // every active student of a programme (and level), from the student records
            $ids = \Illuminate\Support\Facades\DB::table('student_profiles')->where('programme_id', (int) $data['from_programme']['programme_id'])
                ->when(! empty($data['from_programme']['level']), fn ($q) => $q->where('level', (int) $data['from_programme']['level']))
                ->pluck('user_id');
            $users = $users->merge(User::whereIn('id', $ids)->where('user_type', 'student')->where('status', 'active')->get());
        }

        if (! empty($data['user_ids'])) {
            $users = $users->merge(User::whereIn('id', array_map('intval', (array) $data['user_ids']))->get());
        }

        $missing = [];
        if (! empty($data['identifiers'])) {
            $ids = collect(preg_split('/[\s,;]+/', $data['identifiers']))->map(fn ($v) => trim($v))->filter()->unique()->values();
            foreach ($ids->chunk(500) as $chunk) {
                $found = User::where(fn ($q) => $q->whereIn('identifier', $chunk)->orWhereIn('email', $chunk))->get();
                $users = $users->merge($found);
                $missing = array_merge($missing, $chunk->diff($found->pluck('identifier'))->diff($found->pluck('email'))->all());
            }
        }

        return [$users->unique('id')->values(), array_values($missing)];
    }
}
