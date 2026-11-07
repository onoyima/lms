<?php

namespace App\Bridges\Vuna\Demo;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * A small copy of the portal's tables (same names and columns as the portal's models) filled with a
 * realistic mix of people: active, on probation, deferred and suspended students; staff with HOD, Dean
 * and Exam Officer roles, one with a removed role and one whose appointment ended; current and
 * past-session applicants; registrations in the running and in an old semester.
 *
 * Used by the automated tests and by `vuna:demo-portal` for a local demonstration. It is never run
 * against a real portal database (see the command's checks).
 */
class DemoPortal
{
    public const STUDENT_PASSWORD = 'Portal!2026';

    public const STAFF_PASSWORD = 'Staff!2026';

    public const APPLICANT_PASSWORD = 'Apply!2026';

    public static function build(Connection $db): void
    {
        $s = $db->getSchemaBuilder();
        $tables = ['faculties', 'departments', 'programmes', 'course_studies', 'levels', 'semesters', 'vu_sessions', 'academic_sessions', 'vu_semesters', 'users',
            'student_roles', 'students', 'student_academics', 'staff_types', 'staff_positions', 'staff', 'staff_work_profiles', 'roles', 'staff_assigned_roles',
            'appointment_terminations', 'course_categories', 'courses', 'course_regs', 'assigned_courses', 'course_assigneds', 'applicant_bio_data'];
        foreach (array_reverse($tables) as $t) {
            $s->dropIfExists($t);
        }

        $s->create('faculties', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('abb', 20)->nullable(); $t->string('code')->nullable(); $t->timestamps(); });
        $s->create('departments', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('abb', 20)->nullable(); $t->unsignedBigInteger('faculty_id')->nullable(); $t->timestamps(); });
        $s->create('programmes', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        $s->create('course_studies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('abb', 20)->nullable(); $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedTinyInteger('duration')->nullable(); $t->timestamps(); });
        $s->create('levels', function (Blueprint $t) { $t->id(); $t->string('level', 10); $t->timestamps(); });
        $s->create('semesters', function (Blueprint $t) { $t->id(); $t->string('semester', 40); $t->timestamps(); });
        $s->create('vu_sessions', function (Blueprint $t) { $t->id(); $t->string('session', 20); $t->date('start_date')->nullable(); $t->date('end_date')->nullable(); $t->tinyInteger('status')->default(0); $t->timestamps(); });
        $s->create('academic_sessions', function (Blueprint $t) { $t->id(); $t->string('session', 20); $t->string('batch', 10)->nullable(); $t->unsignedBigInteger('programme_id')->nullable(); $t->unsignedBigInteger('vu_session_id')->nullable(); $t->timestamps(); });
        $s->create('vu_semesters', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('academic_session_id'); $t->unsignedBigInteger('semester_id'); $t->tinyInteger('status')->default(0); $t->timestamps(); });
        $s->create('users', function (Blueprint $t) { $t->id(); $t->string('fname')->nullable(); $t->string('mname')->nullable(); $t->string('lname')->nullable(); $t->string('name')->nullable(); $t->string('email'); $t->tinyInteger('user_type'); $t->string('password'); $t->timestamps(); });
        $s->create('student_roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('fullname')->nullable(); $t->timestamps(); });
        $s->create('students', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('student_role_id')->nullable(); $t->string('reg_no')->nullable(); $t->string('matric_no')->nullable();
            $t->string('fname'); $t->string('mname')->nullable(); $t->string('lname'); $t->string('email')->nullable(); $t->string('password'); $t->string('username')->nullable();
            $t->string('gender', 10)->nullable(); $t->string('phone', 30)->nullable(); $t->tinyInteger('status')->nullable(); $t->timestamps();
        });
        $s->create('student_academics', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('student_id'); $t->string('matric_no')->nullable(); $t->unsignedBigInteger('course_study_id')->nullable(); $t->string('level', 10)->nullable();
            $t->unsignedBigInteger('academic_session_id')->nullable(); $t->unsignedBigInteger('vu_semester_id')->nullable(); $t->unsignedBigInteger('faculty_id')->nullable(); $t->unsignedBigInteger('department_id')->nullable();
            $t->tinyInteger('studentship')->nullable(); $t->unsignedBigInteger('studentship_id')->nullable(); $t->timestamps();
        });
        $s->create('staff_types', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        $s->create('staff_positions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        $s->create('staff', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->string('title', 20)->nullable(); $t->string('fname'); $t->string('mname')->nullable(); $t->string('lname');
            $t->string('email')->nullable(); $t->string('p_email')->nullable(); $t->string('phone', 30)->nullable(); $t->string('gender', 10)->nullable(); $t->tinyInteger('user_type')->default(3);
            $t->string('password'); $t->timestamps();
        });
        $s->create('staff_work_profiles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('staff_id'); $t->string('staff_no')->nullable(); $t->unsignedBigInteger('staff_type_id')->nullable(); $t->unsignedBigInteger('faculty_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedBigInteger('staff_position_id')->nullable(); $t->timestamps();
        });
        $s->create('roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('fullname')->nullable(); $t->string('role_category')->nullable(); $t->timestamps(); });
        $s->create('staff_assigned_roles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('staff_id'); $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedBigInteger('faculty_id')->nullable();
            $t->string('level', 10)->nullable(); $t->tinyInteger('status')->default(1); $t->date('removed_date')->nullable(); $t->timestamps();
        });
        $s->create('appointment_terminations', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('staff_id'); $t->unsignedBigInteger('termination_id')->nullable(); $t->date('date_terminated')->nullable(); $t->timestamps(); });
        $s->create('course_categories', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
        $s->create('courses', function (Blueprint $t) {
            $t->id(); $t->string('title'); $t->string('code', 20); $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedBigInteger('course_study_id')->nullable(); $t->unsignedTinyInteger('credit_load')->nullable();
            $t->unsignedBigInteger('course_category_id')->nullable(); $t->string('level', 10)->nullable(); $t->tinyInteger('semester_offered')->nullable(); $t->tinyInteger('status')->default(1); $t->timestamps();
        });
        $s->create('course_regs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('course_id'); $t->string('level', 10)->nullable(); $t->unsignedBigInteger('academic_session_id')->nullable();
            $t->unsignedBigInteger('vu_semester_id')->nullable(); $t->tinyInteger('is_course_reg')->default(2); $t->tinyInteger('is_carryover')->nullable(); $t->tinyInteger('status')->nullable(); $t->timestamps();
        });
        $s->create('assigned_courses', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('staff_id'); $t->string('staff_type', 30)->nullable(); $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('academic_session_id')->nullable(); $t->unsignedBigInteger('vu_semester_id')->nullable(); $t->timestamps();
        });
        $s->create('course_assigneds', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('staff_id'); $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('academic_session_id')->nullable(); $t->unsignedBigInteger('vu_semester_id')->nullable();
            $t->tinyInteger('status')->nullable(); $t->timestamps();
        });
        $s->create('applicant_bio_data', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('student_id')->nullable(); $t->string('reg_no')->nullable(); $t->string('fname')->nullable(); $t->string('lname')->nullable();
            $t->string('phone_no', 30)->nullable(); $t->string('gender', 10)->nullable(); $t->unsignedBigInteger('course_study_id')->nullable(); $t->unsignedBigInteger('vu_session_id')->nullable(); $t->timestamps();
        });

        self::fill($db);
    }

    private static function fill(Connection $db): void
    {
        $now = now();
        $ins = function (string $table, array $rows) use ($db, $now) {
            $db->table($table)->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $rows));
        };
        $student = password_hash(self::STUDENT_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $staff = password_hash(self::STAFF_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $applicant = password_hash(self::APPLICANT_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

        $ins('faculties', [['id' => 1, 'name' => 'Faculty of Natural and Applied Sciences', 'abb' => 'NAS'], ['id' => 2, 'name' => 'Faculty of Humanities', 'abb' => 'HUM']]);
        $ins('departments', [['id' => 1, 'name' => 'Chemistry', 'abb' => 'CHM', 'faculty_id' => 1], ['id' => 2, 'name' => 'Computer Science', 'abb' => 'CSC', 'faculty_id' => 1],
            ['id' => 3, 'name' => 'English', 'abb' => 'ENG', 'faculty_id' => 2]]);
        $ins('programmes', [['id' => 1, 'name' => 'Undergraduate'], ['id' => 2, 'name' => 'Postgraduate']]);
        $ins('course_studies', [['id' => 1, 'name' => 'B.Sc. Industrial Chemistry', 'abb' => 'ICH', 'department_id' => 1, 'duration' => 4],
            ['id' => 2, 'name' => 'B.Sc. Computer Science', 'abb' => 'BCS', 'department_id' => 2, 'duration' => 4], ['id' => 3, 'name' => 'B.A. English', 'abb' => 'BEN', 'department_id' => 3, 'duration' => 4]]);
        $ins('levels', array_map(fn ($i) => ['id' => $i, 'level' => (string) ($i * 100)], range(1, 5)));
        $ins('semesters', [['id' => 1, 'semester' => 'First'], ['id' => 2, 'semester' => 'Second']]);
        $ins('vu_sessions', [['id' => 1, 'session' => '2024/2025', 'start_date' => '2024-10-01', 'end_date' => '2025-07-31', 'status' => 0],
            ['id' => 2, 'session' => '2025/2026', 'start_date' => '2025-10-06', 'end_date' => '2026-07-31', 'status' => 1]]);
        $ins('academic_sessions', [['id' => 1, 'session' => '2024/2025', 'batch' => 'A', 'programme_id' => 1, 'vu_session_id' => 1], ['id' => 2, 'session' => '2025/2026', 'batch' => 'A', 'programme_id' => 1, 'vu_session_id' => 2]]);
        $ins('vu_semesters', [['id' => 1, 'academic_session_id' => 1, 'semester_id' => 1, 'status' => 0], ['id' => 2, 'academic_session_id' => 1, 'semester_id' => 2, 'status' => 0],
            ['id' => 3, 'academic_session_id' => 2, 'semester_id' => 1, 'status' => 1], ['id' => 4, 'academic_session_id' => 2, 'semester_id' => 2, 'status' => 0]]);
        $ins('student_roles', [['id' => 1, 'name' => 'Class Representative', 'fullname' => 'Class Representative']]);

        // students: active, probation (still active), deferred, suspended, and one with only the newer studentship_id column filled
        $ins('students', [
            ['id' => 1, 'matric_no' => 'VUG/ICH/25/001', 'reg_no' => 'REG25001', 'fname' => 'Ada', 'mname' => 'N.', 'lname' => 'Okafor', 'email' => 'ada.okafor@students.vuna.test', 'username' => 'ada.okafor', 'password' => $student, 'gender' => 'Female', 'status' => 1, 'student_role_id' => 1],
            ['id' => 2, 'matric_no' => 'VUG/BCS/23/014', 'reg_no' => 'REG23014', 'fname' => 'Bola', 'mname' => null, 'lname' => 'Hassan', 'email' => 'bola.hassan@students.vuna.test', 'username' => 'bola.hassan', 'password' => $student, 'gender' => 'Male', 'status' => 2, 'student_role_id' => null],
            ['id' => 3, 'matric_no' => 'VUG/BEN/22/009', 'reg_no' => 'REG22009', 'fname' => 'Chidi', 'mname' => null, 'lname' => 'Eze', 'email' => 'chidi.eze@students.vuna.test', 'username' => null, 'password' => $student, 'gender' => 'Male', 'status' => 1, 'student_role_id' => null],
            ['id' => 4, 'matric_no' => 'VUG/ICH/23/020', 'reg_no' => 'REG23020', 'fname' => 'Dami', 'mname' => null, 'lname' => 'Musa', 'email' => 'dami.musa@students.vuna.test', 'username' => null, 'password' => $student, 'gender' => 'Female', 'status' => 1, 'student_role_id' => null],
            ['id' => 5, 'matric_no' => null, 'reg_no' => 'REG25101', 'fname' => 'Efe', 'mname' => null, 'lname' => 'Johnson', 'email' => null, 'username' => null, 'password' => $student, 'gender' => 'Male', 'status' => null, 'student_role_id' => null],
        ]);
        $ins('student_academics', [
            ['student_id' => 1, 'matric_no' => 'VUG/ICH/25/001', 'course_study_id' => 1, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'faculty_id' => 1, 'department_id' => 1, 'studentship' => 1, 'studentship_id' => null],
            ['student_id' => 2, 'matric_no' => 'VUG/BCS/23/014', 'course_study_id' => 2, 'level' => '3', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'faculty_id' => null, 'department_id' => 2, 'studentship' => 1, 'studentship_id' => null],
            ['student_id' => 3, 'matric_no' => 'VUG/BEN/22/009', 'course_study_id' => 3, 'level' => '300', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'faculty_id' => 2, 'department_id' => 3, 'studentship' => 2, 'studentship_id' => null],
            ['student_id' => 4, 'matric_no' => 'VUG/ICH/23/020', 'course_study_id' => 1, 'level' => '200', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'faculty_id' => 1, 'department_id' => 1, 'studentship' => 5, 'studentship_id' => null],
            ['student_id' => 5, 'matric_no' => 'VUG/BCS/25/101', 'course_study_id' => 2, 'level' => '100L', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'faculty_id' => 1, 'department_id' => 2, 'studentship' => null, 'studentship_id' => 1],
        ]);

        $ins('staff_types', [['id' => 1, 'name' => 'Academic'], ['id' => 2, 'name' => 'Non-Academic']]);
        $ins('staff_positions', [['id' => 1, 'name' => 'Senior Lecturer'], ['id' => 2, 'name' => 'Professor'], ['id' => 3, 'name' => 'Lecturer I'], ['id' => 4, 'name' => 'ICT Officer']]);
        $ins('staff', [
            ['id' => 1, 'title' => 'Dr.', 'fname' => 'Ngozi', 'mname' => null, 'lname' => 'Okeke', 'email' => 'ngozi.okeke@vuna.test', 'p_email' => null, 'phone' => '08030000001', 'gender' => 'Female', 'user_type' => 3, 'password' => $staff],
            ['id' => 2, 'title' => 'Prof.', 'fname' => 'Ibrahim', 'mname' => null, 'lname' => 'Musa', 'email' => 'ibrahim.musa@vuna.test', 'p_email' => null, 'phone' => null, 'gender' => 'Male', 'user_type' => 5, 'password' => $staff],
            ['id' => 3, 'title' => 'Mrs.', 'fname' => 'Funke', 'mname' => null, 'lname' => 'Adeyemi', 'email' => 'funke.adeyemi@vuna.test', 'p_email' => null, 'phone' => null, 'gender' => 'Female', 'user_type' => 3, 'password' => $staff],
            ['id' => 4, 'title' => 'Mr.', 'fname' => 'Tunde', 'mname' => null, 'lname' => 'Bakare', 'email' => 'tunde.bakare@vuna.test', 'p_email' => null, 'phone' => null, 'gender' => 'Male', 'user_type' => 3, 'password' => $staff],
            ['id' => 5, 'title' => 'Mr.', 'fname' => 'Segun', 'mname' => null, 'lname' => 'Ade', 'email' => null, 'p_email' => 'segun.ade@vuna.test', 'phone' => null, 'gender' => 'Male', 'user_type' => 6, 'password' => $staff],
        ]);
        $ins('staff_work_profiles', [
            ['staff_id' => 1, 'staff_no' => 'VUNA/ST/0042', 'staff_type_id' => 1, 'faculty_id' => 1, 'department_id' => 1, 'staff_position_id' => 1],
            ['staff_id' => 2, 'staff_no' => 'VUNA/ST/0007', 'staff_type_id' => 1, 'faculty_id' => 1, 'department_id' => 1, 'staff_position_id' => 2],
            ['staff_id' => 3, 'staff_no' => 'VUNA/ST/0110', 'staff_type_id' => 1, 'faculty_id' => null, 'department_id' => 2, 'staff_position_id' => 3],
            ['staff_id' => 4, 'staff_no' => 'VUNA/ST/0099', 'staff_type_id' => 1, 'faculty_id' => 1, 'department_id' => 2, 'staff_position_id' => 3],
            ['staff_id' => 5, 'staff_no' => 'VUNA/NS/0300', 'staff_type_id' => 2, 'faculty_id' => null, 'department_id' => null, 'staff_position_id' => 4],
        ]);
        $ins('roles', [['id' => 1, 'name' => 'admin', 'fullname' => 'System Admin'], ['id' => 2, 'name' => 'HOD', 'fullname' => 'Head of Department'], ['id' => 3, 'name' => 'Dean', 'fullname' => 'Dean of Faculty'],
            ['id' => 4, 'name' => 'Exam Officer', 'fullname' => 'Exam Officer'], ['id' => 5, 'name' => 'Level Adviser', 'fullname' => 'Level Adviser']]);
        $ins('staff_assigned_roles', [
            ['staff_id' => 1, 'role_id' => 2, 'department_id' => 1, 'faculty_id' => 1, 'level' => null, 'status' => 1, 'removed_date' => null],
            ['staff_id' => 2, 'role_id' => 3, 'department_id' => null, 'faculty_id' => 1, 'level' => null, 'status' => 1, 'removed_date' => null],
            ['staff_id' => 2, 'role_id' => 4, 'department_id' => 1, 'faculty_id' => 1, 'level' => null, 'status' => 0, 'removed_date' => '2025-01-15'], // no longer held
            ['staff_id' => 3, 'role_id' => 4, 'department_id' => 2, 'faculty_id' => 1, 'level' => null, 'status' => 1, 'removed_date' => null],
            ['staff_id' => 3, 'role_id' => 5, 'department_id' => 2, 'faculty_id' => 1, 'level' => '300', 'status' => 1, 'removed_date' => null],
        ]);
        $ins('appointment_terminations', [['staff_id' => 4, 'termination_id' => 1, 'date_terminated' => '2025-06-30']]);

        $ins('course_categories', [['id' => 1, 'name' => 'Compulsory'], ['id' => 2, 'name' => 'Elective'], ['id' => 3, 'name' => 'General Studies']]);
        $ins('courses', [
            ['id' => 1, 'code' => 'CHM 101', 'title' => 'General Chemistry I', 'department_id' => 1, 'course_study_id' => 1, 'credit_load' => 3, 'course_category_id' => 1, 'level' => '100', 'semester_offered' => 1, 'status' => 1],
            ['id' => 2, 'code' => 'CHM 201', 'title' => 'Physical Chemistry I', 'department_id' => 1, 'course_study_id' => 1, 'credit_load' => 3, 'course_category_id' => 1, 'level' => '2', 'semester_offered' => 1, 'status' => 1],
            ['id' => 3, 'code' => 'CSC 101', 'title' => 'Introduction to Computing', 'department_id' => 2, 'course_study_id' => 2, 'credit_load' => 3, 'course_category_id' => 1, 'level' => '100', 'semester_offered' => 1, 'status' => 1],
            ['id' => 4, 'code' => 'GST 101', 'title' => 'Use of English', 'department_id' => 3, 'course_study_id' => 1, 'credit_load' => 2, 'course_category_id' => 3, 'level' => '100', 'semester_offered' => 1, 'status' => 1],
            ['id' => 5, 'code' => 'CSC 301', 'title' => 'Data Structures', 'department_id' => 2, 'course_study_id' => 2, 'credit_load' => 3, 'course_category_id' => 1, 'level' => '300', 'semester_offered' => 1, 'status' => 1],
            ['id' => 6, 'code' => 'ENG 211', 'title' => 'Creative Writing', 'department_id' => 3, 'course_study_id' => 3, 'credit_load' => 2, 'course_category_id' => 2, 'level' => '200', 'semester_offered' => 1, 'status' => 1],
            ['id' => 7, 'code' => 'gst  101', 'title' => 'Use of English', 'department_id' => 3, 'course_study_id' => 2, 'credit_load' => 2, 'course_category_id' => 3, 'level' => '100', 'semester_offered' => 1, 'status' => 1], // same course, another programme
        ]);
        $ins('course_regs', [
            ['student_id' => 1, 'course_id' => 1, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null],
            ['student_id' => 1, 'course_id' => 4, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null],
            ['student_id' => 1, 'course_id' => 2, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 3, 'is_carryover' => null], // deleted
            ['student_id' => 1, 'course_id' => 3, 'level' => '100', 'academic_session_id' => 1, 'vu_semester_id' => 1, 'is_course_reg' => 2, 'is_carryover' => null], // an old semester
            ['student_id' => 2, 'course_id' => 5, 'level' => '300', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null],
            ['student_id' => 2, 'course_id' => 3, 'level' => '300', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => 2],
            ['student_id' => 2, 'course_id' => 6, 'level' => '300', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null],
            ['student_id' => 3, 'course_id' => 6, 'level' => '300', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null], // deferred student
            ['student_id' => 5, 'course_id' => 3, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null],
            ['student_id' => 5, 'course_id' => 7, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 3, 'is_course_reg' => 2, 'is_carryover' => null],
        ]);
        $ins('assigned_courses', [
            ['staff_id' => 1, 'staff_type' => 'Lecturer', 'course_id' => 1, 'department_id' => 1, 'academic_session_id' => 2, 'vu_semester_id' => 3],
            ['staff_id' => 3, 'staff_type' => 'Course Coordinator', 'course_id' => 5, 'department_id' => 2, 'academic_session_id' => 2, 'vu_semester_id' => 3],
            ['staff_id' => 4, 'staff_type' => 'Lecturer', 'course_id' => 3, 'department_id' => 2, 'academic_session_id' => 2, 'vu_semester_id' => 3], // appointment ended
            ['staff_id' => 1, 'staff_type' => 'Lecturer', 'course_id' => 2, 'department_id' => 1, 'academic_session_id' => 1, 'vu_semester_id' => 1], // an old semester
        ]);
        $ins('course_assigneds', [
            ['staff_id' => 1, 'course_id' => 4, 'academic_session_id' => 2, 'vu_semester_id' => 3, 'status' => 1],
            ['staff_id' => 3, 'course_id' => 3, 'academic_session_id' => 2, 'vu_semester_id' => 3, 'status' => 1],
        ]);

        $ins('users', [
            ['id' => 1, 'fname' => 'Ada', 'lname' => 'Okafor', 'name' => 'Ada Okafor', 'email' => 'ada.okafor@students.vuna.test', 'user_type' => 2, 'password' => $student],
            ['id' => 100, 'fname' => 'Grace', 'lname' => 'Obi', 'name' => 'Grace Obi', 'email' => 'grace.obi@example.com', 'user_type' => 7, 'password' => $applicant],
            ['id' => 101, 'fname' => 'Henry', 'lname' => 'Uche', 'name' => 'Henry Uche', 'email' => 'henry.uche@example.com', 'user_type' => 7, 'password' => $applicant], // last year's applicant
            ['id' => 102, 'fname' => 'Ada', 'lname' => 'Okafor', 'name' => 'Ada Okafor', 'email' => 'ada.applied@example.com', 'user_type' => 7, 'password' => $applicant], // admitted: now student 1
        ]);
        $ins('applicant_bio_data', [
            ['user_id' => 100, 'student_id' => null, 'reg_no' => 'APP/2025/0451', 'fname' => 'Grace', 'lname' => 'Obi', 'phone_no' => '08030000451', 'gender' => 'Female', 'course_study_id' => 2, 'vu_session_id' => 2],
            ['user_id' => 101, 'student_id' => null, 'reg_no' => 'APP/2024/0102', 'fname' => 'Henry', 'lname' => 'Uche', 'phone_no' => null, 'gender' => 'Male', 'course_study_id' => 1, 'vu_session_id' => 1],
            ['user_id' => 102, 'student_id' => 1, 'reg_no' => 'APP/2025/0007', 'fname' => 'Ada', 'lname' => 'Okafor', 'phone_no' => null, 'gender' => 'Female', 'course_study_id' => 1, 'vu_session_id' => 2],
        ]);
    }
}
