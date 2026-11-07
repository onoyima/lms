<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionImport extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'course_id', 'original_name', 'stored_path', 'format', 'status', 'question_count', 'warnings'];

    protected function casts(): array
    {
        return ['warnings' => 'array'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
