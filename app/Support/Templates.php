<?php

namespace App\Support;

/**
 * Sample templates for every import and integration (people, courses, questions, candidates,
 * SIS pushes, attendance terminals, plagiarism and mail webhooks). Each one is filled with
 * realistic sample rows that import cleanly into the demo institution, so a registry officer can
 * open it, replace the rows and upload. Downloaded from Help > Templates or next to each import.
 */
class Templates
{
    /** key => [title, what it is for, filename, permission or null (any staff)] */
    public const ALL = [
        'people' => ['People (staff and students)', 'Administration > People > Import. Columns: name, email, identifier (matric or staff number), type (student|staff), roles (separated by ;).', 'people-import.csv', 'users.manage'],
        'courses' => ['Courses', 'Academic structure > Courses > Import from CSV. Columns: code, title, credit_units, level, department_code.', 'courses-import.csv', 'academics.manage'],
        'questions_docx' => ['Questions (Word)', 'Question bank > Import. Numbered questions, lettered options, * before the right option or an "Answer:" line, optional "Mark:" and "Topic:" lines. Word equations and chemistry are kept.', 'question-import-sample.docx', 'questions.create'],
        'questions_text' => ['Questions (text / Markdown)', 'Question bank > Import, same layout as the Word sample, with $…$ for equations and \\ce{…} for chemistry.', 'question-import-sample.md', 'questions.create'],
        'candidates' => ['Exam candidates', 'Exam > Sittings > Add candidates. One matriculation number per line (commas and spaces also work).', 'exam-candidates.txt', 'exams.manage'],
        'sis_students' => ['SIS: students', 'POST /api/v1/sis/students with a key that has sis.write.', 'sis-students.json', 'integrations.manage'],
        'sis_courses' => ['SIS: courses', 'POST /api/v1/sis/courses with a key that has sis.write.', 'sis-courses.json', 'integrations.manage'],
        'sis_registrations' => ['SIS: course registrations', 'POST /api/v1/sis/registrations with a key that has sis.write. Use the semester id shown in Academic structure.', 'sis-registrations.json', 'integrations.manage'],
        'attendance' => ['Attendance terminals', 'POST /api/v1/attendance with a key that has attendance.write. Up to 2,000 records per call.', 'attendance.json', 'integrations.manage'],
        'plagiarism' => ['Plagiarism service exchange', 'What the platform sends to your plagiarism service and the reply or callback it expects. Signed with X-Platform-Signature.', 'plagiarism-exchange.json', 'integrations.manage'],
        'mail_bounce' => ['Mail bounce report', 'What your mail service posts to /webhooks/mail, signed with X-Platform-Signature.', 'mail-bounce.json', 'integrations.manage'],
    ];

    public static function content(string $key): string
    {
        return match ($key) {
            'people' => self::csv(['name', 'email', 'identifier', 'type', 'roles'], [
                ['Chiamaka Obi', 'chiamaka.obi@example.edu.ng', 'ABC/CHM/26/0101', 'student', 'student'],
                ['Abdullahi Sani', 'abdullahi.sani@example.edu.ng', 'ABC/CHM/26/0102', 'student', 'student'],
                ['Oluwaseun Ajayi', 'oluwaseun.ajayi@example.edu.ng', 'ABC/PHY/26/0103', 'student', 'student'],
                ['Dr. Halima Yusuf', 'halima.yusuf@example.edu.ng', 'STAFF/0201', 'staff', 'lecturer'],
                ['Dr. Peter Okon', 'peter.okon@example.edu.ng', 'STAFF/0202', 'staff', 'lecturer;question_setter'],
                ['Mr. Kunle Ade', 'kunle.ade@example.edu.ng', 'STAFF/0203', 'staff', 'invigilator'],
            ]),
            'courses' => self::csv(['code', 'title', 'credit_units', 'level', 'department_code'], [
                ['CHM 102', 'General Chemistry II', 3, 100, 'CHM'], ['CHM 201', 'Physical Chemistry I', 3, 200, 'CHM'],
                ['CHM 211', 'Organic Chemistry I', 3, 200, 'CHM'], ['CHM 291', 'Laboratory Practicals I', 2, 200, 'CHM'],
                ['GST 102', 'Communication in English II', 2, 100, ''],
            ]),
            'questions_docx' => file_get_contents(resource_path('templates/question-import-sample.docx')),
            'questions_text' => file_get_contents(resource_path('templates/question-import-sample.md')),
            'candidates' => "VUG/CHM/26/0001\nVUG/CHM/26/0002\nVUG/CHM/26/0003\nVUG/CHM/26/0004\nVUG/CHM/26/0005\n",
            'sis_students' => self::json(['data' => [
                ['matric_no' => 'ABC/CHM/26/0101', 'name' => 'Chiamaka Obi', 'email' => 'chiamaka.obi@example.edu.ng', 'programme_code' => 'BSC-CHM', 'level' => 100, 'status' => 'active', 'entry_year' => 2026],
                ['matric_no' => 'ABC/CHM/25/0044', 'name' => 'Ifeanyi Nwachukwu', 'email' => 'ifeanyi.n@example.edu.ng', 'programme_code' => 'BSC-CHM', 'level' => 200, 'status' => 'active', 'entry_year' => 2025],
                ['matric_no' => 'ABC/CHM/22/0007', 'name' => 'Rukayat Bello', 'email' => 'rukayat.b@example.edu.ng', 'programme_code' => 'BSC-CHM', 'level' => 400, 'status' => 'graduated', 'entry_year' => 2022],
            ]]),
            'sis_courses' => self::json(['data' => [
                ['code' => 'CHM 102', 'title' => 'General Chemistry II', 'units' => 3, 'level' => 100, 'department_code' => 'CHM', 'description' => 'Chemical kinetics, equilibrium, acids and bases.'],
                ['code' => 'CHM 201', 'title' => 'Physical Chemistry I', 'units' => 3, 'level' => 200, 'department_code' => 'CHM'],
            ]]),
            'sis_registrations' => self::json(['data' => [
                ['matric_no' => 'ABC/CHM/26/0101', 'course_code' => 'CHM 101', 'semester_id' => 1, 'category' => 'core', 'status' => 'active'],
                ['matric_no' => 'ABC/CHM/26/0101', 'course_code' => 'GST 101', 'semester_id' => 1, 'category' => 'elective', 'status' => 'active'],
                ['matric_no' => 'ABC/CHM/25/0044', 'course_code' => 'CHM 101', 'semester_id' => 1, 'category' => 'carryover', 'status' => 'active'],
            ]]),
            'attendance' => self::json(['records' => [
                ['course_code' => 'CHM 101', 'identifier' => 'ABC/CHM/26/0001', 'held_at' => '2026-11-03T09:02:00+01:00', 'status' => 'present', 'device' => 'Gate 3 reader'],
                ['course_code' => 'CHM 101', 'identifier' => 'ABC/CHM/26/0002', 'held_at' => '2026-11-03T09:14:00+01:00', 'status' => 'late', 'device' => 'Gate 3 reader'],
                ['course_code' => 'CHM 101', 'identifier' => 'ABC/CHM/26/0003', 'held_at' => '2026-11-03T09:00:00+01:00', 'status' => 'excused', 'device' => 'Registry'],
            ]]),
            'plagiarism' => self::json([
                'request_sent_to_your_service' => ['reference' => 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d', 'callback_url' => url('/api/v1/plagiarism/callback/aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d'),
                    'submission' => ['id' => 4182, 'course' => 'CHM 101', 'assignment' => 'Problem set 1', 'filename' => 'problem-set-1.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'content_base64' => 'UEsDBBQABgAIAAAAIQ…']],
                'immediate_reply' => ['id' => 'ext-55120', 'status' => 'queued'],
                'callback_body' => ['status' => 'complete', 'score' => 23.5, 'report_url' => 'https://plagiarism.example.edu.ng/report/55120'],
                'signature' => 'X-Platform-Signature: hex(hmac_sha256(raw body, shared secret))',
            ]),
            'mail_bounce' => self::json(['message_id' => '<9f8e7d6c5b4a@example.edu.ng>', 'event' => 'bounced', 'reason' => '550 5.2.2 Mailbox full']),
            default => abort(404),
        };
    }

    public static function mime(string $file): string
    {
        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json', 'md' => 'text/markdown; charset=UTF-8', 'txt' => 'text/plain; charset=UTF-8',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', default => 'application/octet-stream',
        };
    }

    private static function csv(array $head, array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $head);
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        rewind($h);

        return "\u{FEFF}".stream_get_contents($h); // BOM so Excel opens it as UTF-8
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
