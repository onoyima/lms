<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VunaAccomodation extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'gender',
        'room_capacity',
        'price',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('vuna_accomodation')
            ->dontSubmitEmptyLogs();
    }

    public function vuna_accomodation_categories()
    {
        return $this->hasMany(VunaAccomodationCategory::class);
    }

    public function vuna_accomodation_histories()
    {
        return $this->hasMany(VunaAccomodationHistory::class);
    }

    public function vuna_acc_cate_bunks()
    {
        return $this->hasMany(VunaAccCateBunk::class);
    }
}
