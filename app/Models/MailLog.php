<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** E-mail delivery status (LMS 188). Written by mail events, so it is not tenant-scoped on write. */
class MailLog extends Model
{
    protected $fillable = ['tenant_id', 'to', 'subject', 'kind', 'status', 'message_id', 'error'];
}
