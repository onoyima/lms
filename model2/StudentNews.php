<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StudentNews extends Model
{
    use HasFactory, LogsActivity;

    public const TYPE_NORMAL = 0;
    public const TYPE_POPUP = 1;

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    protected $table = 'student_news';

    protected $fillable = [
        'title',
        'message',
        'type',
        'end_date',
        'author',
        'status',
    ];

    protected $casts = [
        'end_date' => 'date',
        'type' => 'integer',
        'status' => 'integer',
        'author' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('student_news')
            ->dontSubmitEmptyLogs();
    }

    public function authorStaff()
    {
        return $this->belongsTo(Staff::class, 'author', 'id');
    }

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function scopePopup($query)
    {
        return $query->where('type', self::TYPE_POPUP);
    }

    public function scopeNormal($query)
    {
        return $query->where('type', self::TYPE_NORMAL);
    }

    public function isPopup(): bool
    {
        return (int) $this->type === self::TYPE_POPUP;
    }

    public function isEnabled(): bool
    {
        return (int) $this->status === self::STATUS_ENABLED;
    }

    /**
     * Enabled popup that should still show as a login modal (end_date today or later).
     */
    public static function activePopupNews(): ?self
    {
        return static::query()
            ->enabled()
            ->popup()
            ->whereDate('end_date', '>=', Carbon::today()->toDateString())
            ->with('authorStaff')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Last 5 enabled items for the student dashboard, with an enabled popup
     * always included (prepended) even if it falls outside the latest five.
     */
    public static function dashboardNews(int $limit = 5): Collection
    {
        $items = static::query()
            ->enabled()
            ->with('authorStaff')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $popup = static::query()
            ->enabled()
            ->popup()
            ->with('authorStaff')
            ->orderByDesc('id')
            ->first();

        if ($popup && ! $items->contains('id', $popup->id)) {
            $items = $items->prepend($popup);
        }

        return $items->values();
    }

    /**
     * Disable every other enabled popup so only one can be active.
     */
    public static function disableOtherPopups(?int $exceptId = null): void
    {
        $query = static::query()
            ->popup()
            ->where('status', self::STATUS_ENABLED);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->get()->each(function (self $news) {
            $news->status = self::STATUS_DISABLED;
            $news->save();
        });
    }
}
