<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VunaAccCateBunk extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'vuna_accomodation_id',
        'vuna_accomodation_category_id',
        'vuna_acc_cate_room_id',
        'vuna_acc_cate_flat_id',
        'vu_session_id',
        'bunk',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('vuna_acc_cate_bunk')
            ->dontSubmitEmptyLogs();
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

    public function vuna_accomodation_histories()
    {
        return $this->hasMany(VunaAccomodationHistory::class);
    }

}
