<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's face template for identity checks. Biometric data: encrypted, never sent to browsers. */
class FaceProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'descriptor', 'photo_path', 'id_descriptor', 'id_photo_path', 'id_distance', 'status', 'note', 'consent_at', 'enrolled_at', 'verified_by', 'verified_at'];

    protected $hidden = ['descriptor', 'id_descriptor', 'photo_path', 'id_photo_path'];

    protected function casts(): array
    {
        return ['descriptor' => 'encrypted:array', 'id_descriptor' => 'encrypted:array', 'id_distance' => 'float',
            'consent_at' => 'datetime', 'enrolled_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
