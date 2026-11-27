<?php

namespace App\Support\Exams;

use App\Models\Attempt;
use App\Models\ExamCandidate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The exams a candidate can go into right now: the sitting has opened, they may still start (or have one
 * under way), and they have not finished. Used to send students straight to their exams after signing in,
 * and for emergency exams, which are then the only thing they see.
 */
class OpenExams
{
    private const FINISHED = ['submitted', 'auto_submitted', 'terminated', 'expired', 'marked', 'force_submitted', 'void'];

    private const UNDER_WAY = ['in_progress', 'paused', 'locked', 'ending', 'open', 'investigating'];

    /** @return Collection<int, array{candidate_id:int, title:string, emergency:bool, starts_at:string, ends_at:string, duration:int, venue:?string, under_way:bool}> */
    public static function for(?User $user): Collection
    {
        if (! $user || $user->user_type !== 'student' || ! tenant() || ! app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(tenant(), 'question_bank')) {
            return collect();
        }

        $now = now();

        return ExamCandidate::with(['session.exam', 'session.venue:id,name'])
            ->where('user_id', $user->id)
            ->whereIn('eligibility', ['eligible', 'override'])
            ->whereHas('session', fn ($q) => $q->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->where('status', '!=', 'closed'))
            ->get()
            ->filter(function (ExamCandidate $c) use ($now) {
                $s = $c->session;
                $exam = $s?->exam;

                if (! $exam || ! $exam->isOnScreen() || (! $s->is_practice && ! in_array($exam->status, ['scheduled', 'live'], true))) {
                    return false;
                }

                $statuses = Attempt::where('exam_candidate_id', $c->id)->pluck('status');
                $underWay = $statuses->contains(fn ($st) => in_array($st, self::UNDER_WAY, true));
                $finished = $statuses->filter(fn ($st) => in_array($st, self::FINISHED, true))->count();

                if ($underWay) {
                    return true;
                }

                return $finished < max(1, (int) $exam->max_attempts) && ExamSettings::lastStart($exam, $s)->gte($now);
            })
            ->map(fn (ExamCandidate $c) => [
                'candidate_id' => $c->id,
                'title' => $c->session->exam->title,
                'emergency' => (bool) data_get($c->session->exam->settings, 'emergency', false),
                'starts_at' => $c->session->starts_at->toIso8601String(),
                'ends_at' => $c->session->ends_at->toIso8601String(),
                'duration' => (int) $c->session->exam->duration_minutes + (int) $c->extra_time_minutes,
                'venue' => $c->session->venue?->name,
                'under_way' => Attempt::where('exam_candidate_id', $c->id)->whereIn('status', self::UNDER_WAY)->exists(),
            ])
            ->sortBy('starts_at')->values();
    }

    /** Only the emergency exams open now. */
    public static function emergency(?User $user): Collection
    {
        return self::for($user)->where('emergency', true)->values();
    }
}
