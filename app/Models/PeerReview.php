<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeerReview extends Model
{
    protected $fillable = ['assignment_submission_id', 'reviewer_id', 'score', 'comment', 'is_self'];

    protected function casts(): array
    {
        return ['is_self' => 'boolean'];
    }
}
