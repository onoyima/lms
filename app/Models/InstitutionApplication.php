<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-owned record (not scoped to an institution's data). */
class InstitutionApplication extends Model
{
    protected $fillable = ['institution', 'slug', 'contact_name', 'contact_email', 'contact_phone', 'country', 'students', 'plan_id', 'cycle', 'message', 'status', 'tenant_id', 'reviewed_by', 'reviewed_at', 'review_note', 'ip'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
