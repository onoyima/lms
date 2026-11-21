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
        'questions_all_txt' => ['Questions: every type (text)', 'Upload into an exam\'s question bank. The old exam system\'s layout: a TYPE line (MCQ, MULTI, TF, MTF, ESSAY, SHORT, NUMERIC, BLANK, MATCHING, ORDER, UPLOAD), MARK, the question, options A. B. C., ANSWER, and a blank line between questions. Plain questions with only an ANSWER line still work.', 'questions-every-type.txt', 'questions.create|exams.manage'],
        'questions_all_docx' => ['Questions: every type (Word)', 'The same layout typed in Word. Insert › Equation and pictures in the question are kept. Save a PDF of it to upload a PDF.', 'questions-every-type.docx', 'questions.create|exams.manage'],
        'questions_all_pdf' => ['Questions: every type (PDF)', 'What an uploadable PDF looks like: typed text (not a scan) in the same layout. For scans and photos, use Read with AI.', 'questions-every-type.pdf', 'questions.create|exams.manage'],
        'questions_simple_txt' => ['Questions: simple objective (text)', 'Plain objective questions: the question, options A. to D., ANSWER: and a blank line. Exactly the old system\'s Aiken file.', 'questions-simple.txt', 'questions.create|exams.manage'],
        'questions_mtf_txt' => ['Questions: multiple true/false (text)', 'Statements a) b) c) marked [T] or [F], or one line ANSWER: T F T T F. SCORING: ALL gives the mark only when every statement is right.', 'questions-multiple-true-false.txt', 'questions.create|exams.manage'],
        'questions_csv' => ['Questions: every type (spreadsheet CSV)', 'One question per row. Columns: Type, Question, Option A to Option F, Answer, Mark, Topic, Difficulty, Explanation, Scoring. Save from Excel as "CSV UTF-8".', 'questions-every-type.csv', 'questions.create|exams.manage'],
        'questions_csv_simple' => ['Questions: simple objective (CSV)', 'The old system\'s simple sheet: Question, Option A, B, C, D, Correct Option, Mark.', 'questions-simple.csv', 'questions.create|exams.manage'],
        'questions_csv_pictures' => ['Questions with pictures (CSV)', 'The old system\'s sheet with a picture for the question and each option. Upload the pictures with the sheet (one by one or in a .zip); the names must match.', 'questions-with-pictures.csv', 'questions.create|exams.manage'],
        'questions_pictures_zip' => ['Pictures for the CSV with pictures', 'Sample pictures named as in the CSV with pictures, to show how the names match.', 'question-pictures.zip', 'questions.create|exams.manage'],
        'practical_checklist' => ['Practical / OSCE checklist', 'Exam › Paper (practical or viva) › Upload checklist. Columns: Step, Score values (for example 0,1,2), Maximum (optional).', 'practical-checklist.csv', 'exams.manage'],
        'exam_students' => ['Students for an exam or category', 'Exam › Sittings › Add students › Upload a list (or the category page). Columns as in the old system: firstname, middlename, lastname, email, matric_no, password. Students without an account get one after you confirm.', 'exam-students.csv', 'exams.manage'],
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
            'questions_all_txt' => str_replace("\n", "\r\n", file_get_contents(resource_path('templates/question-upload-all-types.txt'))),
            'questions_all_docx' => file_get_contents(resource_path('templates/question-upload-all-types.docx')),
            'questions_all_pdf' => self::pdf(file_get_contents(resource_path('templates/question-upload-all-types.txt'))),
            'questions_simple_txt' => implode("\r\n", ['What is the recommended angle of needle insertion for an intramuscular injection?', 'A. 15 degrees', 'B. 30 degrees', 'C. 45 degrees', 'D. 90 degrees', 'ANSWER: D', '',
                'Which site is the safest for an intramuscular injection in an adult?', 'A. Dorsogluteal', 'B. Ventrogluteal', 'C. Vastus lateralis', 'D. Deltoid', 'ANSWER: B', '']),
            'questions_mtf_txt' => implode("\r\n", ['TYPE: MTF', 'MARK: 4', 'Concerning the human heart:', 'a) It has four chambers. [T]', 'b) The right ventricle pumps blood into the aorta. [F]', 'c) The mitral valve has two cusps. [T]', 'd) The SA node is located in the left atrium. [F]', '',
                'TYPE: MTF', 'MARK: 5', 'SCORING: ALL', 'Regarding malaria:', 'a) It is caused by a protozoan parasite.', 'b) It is transmitted by the male Anopheles mosquito.', 'c) Plasmodium falciparum causes the most severe form.', 'd) Artemisinin combination therapy is first-line treatment.', 'e) It is spread through contaminated water.', 'ANSWER: T F T T F', '']),
            'questions_csv' => self::csv(['Type', 'Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Option E', 'Option F', 'Answer', 'Mark', 'Topic', 'Difficulty', 'Explanation', 'Scoring'], [
                ['MCQ', 'What is the default port of a MySQL server?', '80', '443', '3306', '8080', '', '', 'C', 1, 'Databases', 'easy', 'MySQL listens on 3306 unless changed.', ''],
                ['MULTI', 'Which of the following are prime numbers?', '2', '4', '7', '9', '', '', 'A, C', 2, 'Numbers', 'medium', '', 'PARTIAL'],
                ['TF', 'Water boils at 100 °C at sea level.', '', '', '', '', '', '', 'TRUE', 1, 'Physics', '', '', ''],
                ['MTF', 'Concerning the liver:', 'It is the largest gland in the body.', 'It produces insulin.', 'It stores glycogen.', 'It is in the left iliac fossa.', '', '', 'TFTF', 4, 'Anatomy', '', '', 'ALL'],
                ['ESSAY', 'Discuss the causes of the Nigerian civil war.', '', '', '', '', '', '', '', 10, 'History', 'hard', '', ''],
                ['SHORT', 'What is the chemical symbol for sodium?', '', '', '', '', '', '', 'Na', 1, 'Chemistry', '', '', ''],
                ['NUMERIC', 'g in m/s², to one decimal place.', '', '', '', '', '', '', '9.8', 2, 'Physics', '', '', ''],
                ['BLANK', 'The powerhouse of the cell is the _____.', '', '', '', '', '', '', 'mitochondrion|mitochondria', 1, 'Biology', '', '', ''],
                ['ORDER', 'Put the stages of mitosis in order.', 'Prophase', 'Metaphase', 'Anaphase', 'Telophase', '', '', '', 2, 'Biology', '', '', ''],
                ['UPLOAD', 'Upload a photo of your labelled diagram of the heart.', '', '', '', '', '', '', '', 5, 'Anatomy', '', '', ''],
            ]),
            'questions_csv_simple' => self::csv(['Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Option', 'Mark'], [
                ['What does OSCE stand for?', 'Objective Structured Clinical Examination', 'Open Source Certified Engineer', 'Online System Competency Evaluation', 'Organised Student Competency Exam', 'A', '2'],
                ['Which muscle is preferred for IM injection in infants?', 'Deltoid', 'Dorsogluteal', 'Vastus lateralis', 'Ventrogluteal', 'C', '1'],
            ]),
            'questions_csv_pictures' => self::csv(['Question', 'Question_Image', 'Option A', 'Option_A_Image', 'Option B', 'Option_B_Image', 'Option C', 'Option_C_Image', 'Option D', 'Option_D_Image', 'Correct Option (A-D)', 'Mark'], [
                ['Identify the muscle highlighted in the diagram.', 'muscles_diagram.png', 'Deltoid', '', 'Biceps', '', 'Triceps', '', 'Quadriceps', '', 'A', '2.5'],
                ['Which graph shows an isothermal expansion?', 'isothermal_question.png', 'Graph A', 'graph_a.png', 'Graph B', 'graph_b.png', 'Graph C', 'graph_c.png', 'Graph D', 'graph_d.png', 'C', '3'],
            ]),
            'questions_pictures_zip' => self::pictureZip(['muscles_diagram.png', 'isothermal_question.png', 'graph_a.png', 'graph_b.png', 'graph_c.png', 'graph_d.png']),
            'practical_checklist' => self::csv(['Step', 'Score values', 'Maximum'], [
                ['Performs hand hygiene before starting the procedure.', '0,1,2', ''],
                ['Confirms the patient\'s identity and explains the procedure.', '0,1', ''],
                ['Locates the landmark and inserts the needle safely.', '0,1,2,3', ''],
                ['Disposes of clinical waste in the right bin.', '0,1', ''],
                ['Overall professionalism (any score up to the maximum).', '', '5'],
            ]),
            'exam_students' => self::csv(['firstname', 'middlename', 'lastname', 'email', 'matric_no', 'password'], [
                ['Jane', 'Elizabeth', 'Doe', 'jane.doe@example.edu.ng', 'MAT/2026/001', 'Pass123'],
                ['John', 'Robert', 'Smith', '', 'MAT/2026/002', ''],
            ]),
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
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'pdf' => 'application/pdf', 'zip' => 'application/zip', default => 'application/octet-stream',
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

    /** A typed PDF of a text template (shows what an uploadable PDF looks like). */
    private static function pdf(string $text): string
    {
        $body = '';
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $body .= $line === '' ? '<p class="gap">&nbsp;</p>' : '<p'.(str_starts_with($line, '//') ? ' class="note"' : '').'>'.e($line).'</p>';
        }
        $pdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml('<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans",sans-serif;font-size:11pt}p{margin:0 0 2pt}p.gap{margin:0 0 6pt}.note{color:#666;font-style:italic}</style></head><body>'.$body.'</body></html>');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** Small labelled sample pictures in a .zip. */
    private static function pictureZip(array $names): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pz');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($names as $i => $name) {
            if (function_exists('imagecreatetruecolor')) {
                $im = imagecreatetruecolor(320, 180);
                imagefill($im, 0, 0, imagecolorallocate($im, 230 - $i * 12, 238, 250));
                imagestring($im, 5, 16, 80, $name, imagecolorallocate($im, 20, 40, 90));
                ob_start();
                imagepng($im);
                $zip->addFromString($name, (string) ob_get_clean());
            } else {
                $zip->addFromString($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
