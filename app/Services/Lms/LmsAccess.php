<?php

namespace App\Services\Lms;

use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what in a course (LMS 2, 40, 203):
 *  - staff on the offering (coordinator, lecturer, assistant) teach it; assistants cannot release grades;
 *  - students with an active enrolment learn in it;
 *  - HOD / dean / exam officers / institution admins can oversee it (read and approve).
 * Students only ever see items that are visible, released and whose prerequisite they completed.
 */
class LmsAccess
{
    /** @var array<string, string|null> */
    private array $roles = [];

    public function role(User $user, CourseOffering $offering): ?string
    {
        return $this->roles[$user->id.':'.$offering->id] ??= DB::table('course_offering_staff')
            ->where('course_offering_id', $offering->id)->where('user_id', $user->id)->value('role');
    }

    public function teaches(User $user, CourseOffering $offering): bool
    {
        return $this->role($user, $offering) !== null || $this->oversees($user);
    }

    public function canGrade(User $user, CourseOffering $offering): bool
    {
        return in_array($this->role($user, $offering), ['coordinator', 'lecturer'], true) || $this->oversees($user);
    }

    public function oversees(User $user): bool
    {
        return $user->can('results.approve') || $user->can('academics.manage') || $user->can('institution.manage');
    }

    public function learns(User $user, CourseOffering $offering): bool
    {
        return DB::table('enrolments')->where('course_offering_id', $offering->id)->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    public function authorizeTeach(User $user, CourseOffering $offering): void
    {
        abort_unless($this->teaches($user, $offering), 403, 'You do not teach this course.');
    }

    public function authorizeView(User $user, CourseOffering $offering): void
    {
        abort_unless($this->teaches($user, $offering) || $this->learns($user, $offering), 403, 'You are not registered for this course.');
    }

    /** Ids of items this student has completed in the offering. */
    public function completedIds(User $user, CourseOffering $offering): array
    {
        return DB::table('item_completions')->join('course_items', 'course_items.id', '=', 'item_completions.course_item_id')
            ->where('course_items.course_offering_id', $offering->id)->where('item_completions.user_id', $user->id)
            ->pluck('course_items.id')->all();
    }

    /** Whether a student may open this item now, and if not, why (LMS 32-33). */
    public function availability(CourseItem $item, array $completed): array
    {
        $section = $item->relationLoaded('section') ? $item->section : $item->section()->first();
        if (! $item->visible || ($section && ! $section->visible)) {
            return [false, 'hidden'];
        }
        $release = max($item->release_at?->getTimestamp() ?? 0, $section?->release_at?->getTimestamp() ?? 0);
        if ($release > now()->getTimestamp()) {
            return [false, 'Opens '.date('j M Y, H:i', $release)];
        }
        if ($item->prerequisite_item_id && ! in_array($item->prerequisite_item_id, $completed, true)) {
            return [false, 'Complete "'.($item->prerequisite?->title ?? 'the previous activity').'" first'];
        }

        return [true, null];
    }

    public function authorizeOpen(User $user, CourseItem $item): void
    {
        $offering = $item->offering;
        if ($this->teaches($user, $offering)) {
            return;
        }
        abort_unless($this->learns($user, $offering), 403, 'You are not registered for this course.');
        [$open, $why] = $this->availability($item, $this->completedIds($user, $offering));
        abort_unless($open, 403, $why === 'hidden' ? 'This item is not available.' : $why);
    }
}
