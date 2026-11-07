<?php

namespace App\Models;

use App\Payments\Support\PaystackLedgerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaystackReference extends Model
{
    use HasFactory;

    /**
     * fee_type values (Student Regular today):
     * tuition | debt | top_up | other | pg_tuition
     * Applicant flows can add their own (e.g. acceptance, form_fee) later via user_id.
     */
    protected $fillable = [
        'reference',
        'student_id',
        'user_id',
        'fee_type',
        'paystack_id',
        'amount_kobo',
        'amount',
        'currency',
        'status',
        'gateway_response',
        'channel',
        'customer_email',
        'customer_code',
        'authorization_code',
        'card_type',
        'last4',
        'bank',
        'fees',
        'ip_address',
        'paid_at',
        'fulfilled_at',
        'metadata',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'metadata' => 'array',
        'raw_response' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Still needs attention on student Paystack History (pending / incomplete only).
     * Fulfilled / abandoned payments must not appear.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsAttention($query)
    {
        return $query->whereNull('fulfilled_at')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['abandoned', 'cancelled']);
            });
    }

    /**
     * Abandoned but not yet fulfilled — recoverable on staff Paystack recovery screens.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAbandonedUnfulfilled($query)
    {
        return $query->whereNull('fulfilled_at')
            ->where('status', PaystackLedgerStatus::ABANDONED);
    }

    /**
     * @return bool
     */
    public function isFulfilled()
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * @return bool
     */
    public function isGatewaySuccess()
    {
        return in_array(strtolower((string) $this->status), ['success', 'paid'], true);
    }
}
