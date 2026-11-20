<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reading passage, case study or data set shown with several questions. */
class QuestionPassage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['course_id', 'title', 'body_html', 'created_by'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'passage_id');
    }
}
