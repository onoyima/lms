<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VunaAccomodationCategory extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'vuna_accomodation_id',
        'vu_session_id',
        'floor',
        'wing',
        'flat',
        'rooms',
        'capacity',
        'vu_session_id',
        'is_processed'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('vuna_accomodation_category')
            ->dontSubmitEmptyLogs();
    }

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function vuna_allocate_hostels()
    {
        return $this->hasMany(VunaAllocateHostel::class);
    }

    public function vuna_accomodation()
    {
        return $this->belongsTo(VunaAccomodation::class);
    }

    public function vu_session()
    {
        return $this->belongsTo(VuSession::class);
    }

    public function vuna_acc_cate_rooms()
    {
        return $this->hasMany(VunaAccCateRoom::class);
    }

    public function vuna_acc_cate_flats()
    {
        return $this->hasMany(VunaAccCateFlat::class);
    }

    public function vuna_acc_cate_bunks()
    {
        return $this->hasMany(VunaAccCateBunk::class);
    }

    public function vuna_accomodation_histories()
    {
        return $this->hasMany(VunaAccomodationHistory::class);
    }
}
