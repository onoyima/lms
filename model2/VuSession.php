<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VuSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session',
        'start_date',
        'end_date',
        'is_adm_processed',
        'is_hostel_processed',
    ];

    /**
     * Active tuition / payment session (new session), not student_academics.
     * Matches how UG tuition payments resolve vu_session_id.
     *
     * @return int
     */
    public static function currentPaymentSessionId(): int
    {
        $id = static::query()
            ->where('is_hostel_processed', 1)
            ->where('status', 1)
            ->orderByDesc('id')
            ->value('id');

        if (! $id) {
            $id = static::query()
                ->where('is_tuition_fee', 1)
                ->where('status', 1)
                ->orderByDesc('id')
                ->value('id');
        }

        if (! $id) {
            $id = static::query()
                ->where('status', 1)
                ->orderByDesc('id')
                ->value('id');
        }

        return (int) ($id ?: 0);
    }

    public function dept_transfers()
    {
        return $this->hasMany(DeptTransfer::class);
    }

    public function set_admission()
    {
        return $this->hasOne(SetAdmissions::class);
    }

    public function other_fee_trans()
    {
        return $this->hasMany(OtherFeeTran::class);
    }

    public function vuna_admission_pgs()
    {
        return $this->hasMany(VunaAdmissionPg::class);
    }

    public function academic_sessions()
    {
        return $this->hasMany(AcademicSession::class);
    }

    public function tuition_percentage()
    {
        return $this->hasMany(TuitionPercentage::class);
    }

    public function assigned_course()
    {
        return $this->hasOne(AssignedCourse::class);
    }

    public function scholarship_students()
    {
        return $this->hasMany(ScholarshipStudent::class);
    }

    public function vuna_accomodation_histories()
    {
        return $this->hasMany(VunaAccomodationHistory::class);
    }

    public function acceptance_fees()
    {
        return $this->hasMany(AcceptanceFee::class);
    }

    public function vuna_applicant_fees()
    {
        return $this->hasMany(VunaApplicantFee::class);
    }

    public function vuna_applicant_fee_pgs()
    {
        return $this->hasMany(VunaApplicantFeePg::class);
    }

    public function tuition_fees()
    {
        return $this->hasMany(TuitionFee::class);
    }

    public function tuition_fee_pgs()
    {
        return $this->hasMany(TuitionFeePg::class);
    }

    public function manage_course_reg()
    {
        return $this->hasMany(ManageCourseReg::class);
    }

    public function manage_course_reg_studies()
    {
        return $this->hasMany(ManageCourseRegStudy::class);
    }
    public function waiver_students()
    {
        return $this->hasMany(WaiverStudent::class);
    }
    public function transfer_students()
    {
        return $this->hasMany(TransferStudent::class);
    }
}
