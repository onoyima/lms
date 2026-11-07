<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentshipHistory extends Model
{
    use HasFactory;

        protected $fillable = [
        'student_id',
        'start_semester',
        'duration',
        'description',
        'end_semester',
        'studentship_id'
    ];


    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function studentship()
    {
        return $this->belongsTo(Studentship::class);
    }

    public function vu_semester()
    {
        return $this->belongsTo(VuSemester::class, 'start_semester');
    }


}
