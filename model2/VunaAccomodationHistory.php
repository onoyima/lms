<?php
//is_free 0=Not Available 1=Available 2=Taken 3=Blocked 4=Overestimated
namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VunaAccomodationHistory extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [

        'user_id',
        'student_id',
        'vu_session_id',
        'vuna_accomodation_id',
        'vuna_accomodation_category_id',
        'vuna_acc_cate_room_id',
        'vuna_acc_cate_flat_id',
        'vuna_acc_cate_bunk_id',
        'tuition_fee_id',
        'bunk',
        'bunk_position',
        'is_free',
        'status',

    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('vuna_accomodation_history')
            ->dontSubmitEmptyLogs();
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function vuna_accomodation()
    {
        return $this->belongsTo(VunaAccomodation::class);
    }

    public function vuna_accomodation_category()
    {
        return $this->belongsTo(VunaAccomodationCategory::class);
    }

    public function vuna_acc_cate_room()
    {
        return $this->belongsTo(VunaAccCateRoom::class);
    }

    public function vuna_acc_cate_flat()
    {
        return $this->belongsTo(VunaAccCateFlat::class);
    }

    public function vuna_acc_cate_bunk()
    {
        return $this->belongsTo(VunaAccCateBunk::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function tuition_fee()
    {
        return $this->belongsTo(TuitionFee::class);
    }


}
