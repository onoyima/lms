<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A notice from the platform team, shown as a banner to every institution or to chosen ones. */
class PlatformAnnouncement extends Model
{
    protected $fillable = ['title', 'body', 'level', 'audience', 'tenant_ids', 'starts_at', 'ends_at', 'dismissible', 'created_by'];

    protected function casts(): array
    {
        return ['tenant_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'dismissible' => 'boolean'];
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** What this person should see now (never another institution's targeted notice). @return list<array> */
    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $tenantId = $user->tenant_id;
        $dismissed = DB::table('announcement_dismissals')->where('user_id', $user->id)->pluck('announcement_id')->all();
        $privileged = $tenantId && ! $user->isPlatformAdmin() && $user->getAllPermissions()->pluck('name')->intersect(config('roles.privileged'))->isNotEmpty();

        return self::live()->latest('id')->limit(20)->get()
            ->filter(fn ($a) => ! in_array($a->id, $dismissed, true))
            ->filter(fn ($a) => $a->tenant_ids === null || ($tenantId && in_array($tenantId, array_map('intval', $a->tenant_ids), true)))
            ->filter(fn ($a) => match ($a->audience) {
                'admins' => $privileged || $user->isPlatformAdmin(),
                'staff' => $user->user_type === 'staff' || $user->isPlatformAdmin(),
                'students' => $user->user_type === 'student',
                default => true,
            })
            ->take(3)->map(fn ($a) => $a->only(['id', 'title', 'body', 'level', 'dismissible', 'ends_at']))->values()->all();
    }
}
