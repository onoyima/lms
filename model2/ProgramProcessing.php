<?php


// This table help keep track of course of study that have been screened
//user_id this is the id of staff that carried out the process
//'course_study_id', course of study processed
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramProcessing extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_study_id',
        'process_year',
        'processing_status'
    ];

    public function course_study()
    {
        return $this->belongsTo(CourseStudy::class);
    }
}
