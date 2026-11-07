<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TuitionPercentage extends Model
{
    use HasFactory;
    protected $fillable = [
        'vu_session_id',
        'course_study_id',
        'percentage',
        'category',
    ];

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }

    /**
     * Active plans for a programme, falling back to the default rows
     * (course_study_id null or 0) when the programme has none of its own.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TuitionPercentage>
     */
    public static function plansFor($courseStudyId, string $category)
    {
        $courseStudyId = (int) $courseStudyId;

        if ($courseStudyId > 0) {
            $plans = static::where('course_study_id', $courseStudyId)
                ->where('category', $category)
                ->where('status', 1)
                ->orderBy('percentage', 'DESC')
                ->get();

            if ($plans->isNotEmpty()) {
                return $plans;
            }
        }

        return static::where(function ($query) {
                $query->whereNull('course_study_id')->orWhere('course_study_id', 0);
            })
            ->where('category', $category)
            ->where('status', 1)
            ->orderBy('percentage', 'DESC')
            ->get();
    }
}
