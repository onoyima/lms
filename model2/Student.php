<?php

namespace App\Models;

use Attribute;
use App\Models\RrrTuitionFee;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;


//status: 1 = Good Standing; 2 = Probation;

class Student extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, LogsActivity;

    public static $customCauser = null;

    public static $causerDepartment = null;

    protected $fillable = [
        'title_id',
        'user_id',
        'user_type',
        'student_role_id',
        'reg_no',
        'matric_no',
        'fname',
        'mname',
        'lname',
        'email',
        'password',
        'username',
        'state_id',
        'country_id',
        'gender',
        'dob',
        'lga_name',
        'city',
        'religion',
        'marital_status',
        'address',
        'phone',
        'passport',
        'signature',
        'nin',
        'hobbies',
        'is_hostel',
        'status',
    ];


    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
            'reg_no',
            'matric_no',
            'fname',
            'mname',
            'lname',
            'email',
            'gender',
            'dob',
            'phone',
            'username',
            'state_id',
            'country_id',
            'lga_name',
            'city',
            'religion',
            'marital_status',
            'address',
        ])
            ->logOnlyDirty() // Log only changes to attributes
            ->useLogName('student') // Specify log name
            ->dontSubmitEmptyLogs(); // Prevent empty logs from being submitted
    }



    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function activity_logs()
    {
        return $this->hasMany(ActivityLog::class, 'subject_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course_regs()
    {
        return $this->hasMany(CourseReg::class);
    }

    public function registration_process()
    {
        return $this->hasMany(RegistrationProcess::class);
    }

    public function waiver_student()
    {
        return $this->hasOne(WaiverStudent::class);
    }

    public function transfer_student()
    {
        return $this->hasOne(TransferStudent::class);
    }

    public function scholarship_student()
    {
        return $this->hasOne(ScholarshipStudent::class);
    }

    public function student_contact()
    {
        return $this->hasOne(StudentContact::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function remitas()
    {
        return $this->hasMany(Remita::class);
    }

    public function student_academic()
    {
        return $this->hasOne(StudentAcademic::class);
    }

    public function student_medical()
    {
        return $this->hasOne(StudentMedical::class);
    }

    public function student_wallet()
    {
        return $this->hasOne(StudentWallet::class);
    }

    public function result_correction()
    {
        return $this->hasMany(ResultCorrection::class);
    }

    public function correction_requests()
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function auxiliary_staff()
    {
        return $this->hasMany(AuxiliaryStaff::class, 'user_id', 'user_id');
    }

    public function student_role()
    {
        return $this->belongsTo(StudentRole::class);
    }

    public function rrr_tuition_fees()
    {
        return $this->hasMany(RrrTuitionFee::class);
    }

    public function rrr_other_fees()
    {
        return $this->hasMany(RrrTuitionFee::class);
    }

/*     public function vuna_accomodation_history()
    {
        return $this->hasOne(VunaAccomodationHistory::class);
    } */

    public function vuna_accomodation_history()
    {
        return $this->hasMany(VunaAccomodationHistory::class);
    }

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function acceptance_fees()
    {
        return $this->hasMany(AcceptanceFee::class);
    }

    public function tuition_fee_pgs()
    {
        return $this->hasMany(TuitionFeePg::class);
    }

    /**
     * PG admissions types (PGD/Masters/PhD range used in the student portal).
     */
    public function isPostgraduateApplicantType(): bool
    {
        $typeId = (int) optional($this->student_academic)->admissions_type_id;

        return $typeId >= 4 && $typeId <= 7;
    }

    public function paymentHistoryRouteName(): string
    {
        return $this->isPostgraduateApplicantType()
            ? 'student.regular.payments.payment-history-pg'
            : 'student.regular.payments.payment-history';
    }

    public function paystackHistoryRouteName(): string
    {
        return $this->isPostgraduateApplicantType()
            ? 'student.regular.payments.paystack-history-pg'
            : 'student.regular.payments.paystack-history';
    }

    public function other_fee_trans()
    {
        return $this->hasMany(OtherFeeTran::class);
    }

    public function summer_payment()
    {
        return $this->hasOne(SummerPayment::class);
    }

}
