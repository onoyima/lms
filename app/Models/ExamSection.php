<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ExamSection extends Model
{
    protected $fillable = ['exam_id', 'title', 'instructions', 'position', 'blueprint', 'answer_any'];

    protected function casts(): array
    {
        return [
            'blueprint' => 'array',
        ];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function questions(): BelongsToMany { return $this->belongsToMany(Question::class, 'exam_section_questions')->withPivot(['mark', 'position'])->orderByPivot('position'); }
}
