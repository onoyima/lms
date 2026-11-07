<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailService extends Model
{
    use HasFactory;

    const TYPE_UG_ADMITTED = 'UG Admitted';

    const TYPE_PG_ADMITTED = 'PG Admitted';

    const STATUS_PENDING = 0;

    const STATUS_SENT = 1;

    const STATUS_FAILED = 2;

    protected $fillable = [
        'user_id',
        'student_id',
        'type',
        'status',
    ];
}
