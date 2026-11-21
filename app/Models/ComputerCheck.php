<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A computer check before an exam: lab computers open its link and are tested automatically. */
class ComputerCheck extends Model
{
    use BelongsToTenant;

    /** What can be tested; the basics always run, the rest when the exam needs them. */
    public const TESTS = [
        'browser' => 'Browser is supported',
        'storage' => 'Answers can be kept on this computer',
        'server' => 'Exam server reachable',
        'speed' => 'Connection fast enough',
        'clock' => 'Clock is right',
        'screen' => 'Screen is large enough',
        'fullscreen' => 'Full screen works',
        'camera' => 'Camera works',
        'microphone' => 'Microphone works',
        'secure_app' => 'Safe Exam Browser or the exam app',
    ];

    public const BASIC = ['browser', 'storage', 'server', 'speed', 'clock', 'screen'];

    protected $fillable = ['name', 'code', 'exam_session_id', 'requirements', 'opens_at', 'closes_at', 'created_by'];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ComputerReport::class);
    }

    public function isOpen(): bool
    {
        return (! $this->opens_at || $this->opens_at->isPast()) && (! $this->closes_at || $this->closes_at->isFuture());
    }

    /** The tests to run: the basics, plus what was chosen (or what the linked exam needs). */
    public function tests(): array
    {
        return array_values(array_unique(array_merge(self::BASIC, array_intersect((array) $this->requirements, array_keys(self::TESTS)))));
    }
}
