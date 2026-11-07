<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'programme_id', 'level', 'academic_status', 'entry_year', 'external_id', 'synced_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function programme(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
