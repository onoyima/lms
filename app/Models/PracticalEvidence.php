<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Work a candidate handed in for a practical (code, photos, a report), or a photo an examiner took of it. */
class PracticalEvidence extends Model
{
    use BelongsToTenant;

    protected $table = 'practical_evidence';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
