<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionImport extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'course_id', 'question_bank_id', 'options', 'original_name', 'stored_path', 'format', 'status', 'question_count', 'warnings'];

    protected function casts(): array
    {
        return ['warnings' => 'array', 'options' => 'array'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
