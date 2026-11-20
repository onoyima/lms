<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExamLink extends Model
{
    use BelongsToTenant;

    public const AUDIENCES = ['roster', 'institution', 'anyone'];

    public const GUEST_TYPE = 'exam_guest';

    protected $fillable = ['tenant_id', 'exam_session_id', 'token', 'code_hash', 'code_hint', 'audience', 'ask', 'max_candidates', 'enabled', 'created_by'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['ask' => 'array', 'enabled' => 'boolean'];
    }

    public static function newToken(): string
    {
        return Str::random(32);
    }

    public function session(): BelongsTo { return $this->belongsTo(ExamSession::class, 'exam_session_id'); }
    public function entries(): HasMany { return $this->hasMany(ExamLinkEntry::class); }

    public function url(): string
    {
        return route('exam-link.show', $this->token);
    }
}
