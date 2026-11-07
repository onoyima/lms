<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VunaAccCateFlat extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'vuna_accomodation_category_id',
        'vuna_acc_cate_room_id',
        'vu_session_id',
        'flat',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('vuna_acc_cate_flat')
            ->dontSubmitEmptyLogs();
    }

    public function vuna_accomodation_category()
    {
        return $this->belongsTo(VunaAccomodationCategory::class);
    }

    public function vuna_acc_cate_room()
    {
        return $this->belongsTo(VunaAccCateRoom::class);
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
