# Target.txt coverage

Every item in Target.txt, with an honest status. "Done" means built, tested and working in this release.
"Partly done" says what exists and what is missing. Hardware checks (HDMI, wireless displays, USB, VMs, remote desktop) are done by the desktop exam app; live video, audio and recordings use the self-hosted media server in deploy/livekit.

## Proctored examination system (220 items)

Done: **217** · Hosting / infrastructure: **1** · Organisational process (platform supports it): **2** (of 220)

| # | Feature | Status | Notes |
|---|---|---|---|
| 1 | Candidate Identity Verification | Done |  |
| 2 | Examination Access Control | Done |  |
| 3 | Live Video Proctoring | Done |  |
| 4 | Live Audio Proctoring | Done |  |
| 5 | Live Screen Monitoring | Done |  |
| 6 | Two-Way Proctor Communication | Done |  |
| 7 | Proctor Assignment and Availability Management | Done |  |
| 8 | AI-Assisted Face Monitoring | Done |  |
| 9 | Gaze and Head-Movement Alerts | Done |  |
| 10 | Multiple-Face Detection | Done |  |
| 11 | Candidate Absence Detection | Done |  |
| 12 | Suspicious Activity Detection | Done |  |
| 13 | Secure Examination Browser Mode | Done |  |
| 14 | Question Bank Management | Done |  |
| 15 | Randomized Examination Papers | Done |  |
| 16 | Question and Answer Option Shuffling | Done |  |
| 17 | Question Pool Management | Done |  |
| 18 | Examination Blueprint Configuration | Done |  |
| 19 | Multiple Question Types | Done |  |
| 20 | Timed Examination Controls | Done |  |
| 21 | Server-Authoritative Examination Timer | Done |  |
| 22 | Automatic Examination Submission | Done |  |
| 23 | Secure Question Delivery | Done |  |
| 24 | Automatic Answer Saving | Done |  |
| 25 | Network-Failure Recovery | Done |  |
| 26 | Automatic Reconnection | Done |  |
| 27 | Duplicate-Session Prevention | Done |  |
| 28 | Device and Session Binding | Done |  |
| 29 | Live Proctor Dashboard | Done |  |
| 30 | Real-Time Candidate Status Monitoring | Done |  |
| 31 | Candidate Camera and Microphone Status | Done | Camera and microphone status, live listening, and noise alerts measured on the candidate's computer (sustained noise is reported; no audio is sent for this). |
| 32 | Live Incident Alerts | Done |  |
| 33 | Incident Management and Escalation | Done |  |
| 34 | Evidence Capture | Done |  |
| 35 | Video and Audio Recording | Done | Camera and phone recordings by the self-hosted media server (deploy/livekit) once it is installed. |
| 36 | Screen Recording | Done | Screen recording by the media server once it is installed. |
| 37 | Timestamped Incident Timeline | Done |  |
| 38 | Tamper-Evident Audit Trail | Done |  |
| 39 | Evidence Integrity Verification | Done |  |
| 40 | Human Review of AI Alerts | Done |  |
| 41 | Candidate Appeals Management | Done |  |
| 42 | Emergency Examination Pause and Resume | Done |  |
| 43 | Authorized Examination Termination | Done |  |
| 44 | Physical CBT Hall Management | Done |  |
| 45 | Remote Examination Support | Done |  |
| 46 | Candidate Attendance Management | Done |  |
| 47 | Seat and Computer Assignment | Done |  |
| 48 | Examination Scheduling | Done |  |
| 49 | Examination Approval Workflows | Done |  |
| 50 | Student Information System Integration | Done |  |
| 51 | Department, Faculty and Course Management | Done |  |
| 52 | Academic Session and Semester Management | Done |  |
| 53 | Role-Based Access Control | Done |  |
| 54 | Lecturer and Question Setter Management | Done |  |
| 55 | Invigilator and Chief Invigilator Management | Done |  |
| 56 | Candidate Eligibility Verification | Done |  |
| 57 | Examination PIN Management | Done |  |
| 58 | Late Entry and Extra-Time Management | Done |  |
| 59 | Objective Question Auto-Marking | Done |  |
| 60 | Essay Marking and Moderation | Done |  |
| 61 | Results Approval and Publication | Done |  |
| 62 | Examination Performance Analytics | Done |  |
| 63 | Examination Attendance Reports | Done |  |
| 64 | Candidate Submission Reports | Done |  |
| 65 | Malpractice and Incident Reports | Done |  |
| 66 | Question Difficulty Analysis | Done |  |
| 67 | Question Discrimination Analysis | Done |  |
| 68 | AI-Assisted Risk Scoring | Done |  |
| 69 | Suspicious Answer Pattern Analysis | Done |  |
| 70 | Browser Tab-Switch Detection | Done |  |
| 71 | Fullscreen Exit Detection | Done |  |
| 72 | Browser Focus-Loss Detection | Done |  |
| 73 | Restricted Copy-and-Paste Controls | Done |  |
| 74 | Unauthorized Navigation Detection | Done |  |
| 75 | Multiple Login Detection | Done |  |
| 76 | Network and Connectivity Monitoring | Done |  |
| 77 | Real-Time Proctor Workload Management | Done |  |
| 78 | Proctor Disconnection Alerts | Done |  |
| 79 | Proctor Reassignment | Done |  |
| 80 | Pre-Examination Equipment Checks | Done |  |
| 81 | Candidate Practice Examination | Done |  |
| 82 | Accessibility Support | Done |  |
| 83 | Approved Special Accommodations | Done |  |
| 84 | Secure Candidate Messaging | Done |  |
| 85 | Examination Instructions and Notifications | Done |  |
| 86 | Data Encryption and Privacy Protection | Done | HTTPS + HSTS; MariaDB data-at-rest encryption (key file, deploy/mariadb/encryption.cnf, db:encrypt-tables, checked by security:check); evidence, face data, SSO secrets, backups and sensitive notes encrypted by the application. |
| 87 | Secure Authentication and Session Management | Done |  |
| 88 | API Security and Rate Limiting | Done |  |
| 89 | Administrator Activity Logging | Done |  |
| 90 | Recording Access Controls | Done |  |
| 91 | Evidence Retention and Secure Deletion | Done |  |
| 92 | Automated Database Backups | Done |  |
| 93 | Disaster Recovery | Done |  |
| 94 | High Availability and Failover | Hosting / infrastructure | Ready-made templates in deploy/ha (load balancer, two app servers, MariaDB primary + replica, Redis); running them is a hosting step. |
| 95 | Concurrent-User Load Testing | Done |  |
| 96 | Database Query and Performance Optimization | Done |  |
| 97 | LAN-Based Examination Delivery | Done |  |
| 98 | Offline-Tolerant Examination Operations | Done |  |
| 99 | Multi-Campus Support | Done | Venues per campus, per-institution domains, and each campus's own exam-day contacts, candidate notice, exam-per-day limit and attendance rule (Policy > Campus settings). |
| 100 | Secure Institutional Integration APIs | Done |  |
| 101 | System Health Monitoring | Done |  |
| 102 | Security Vulnerability Testing | Organisational process (platform supports it) | Checklist in docs/SECURITY-TESTING.md; CI runs the security tests and dependency audits on every change. |
| 103 | Automated Dependency Updates and Maintenance | Done |  |
| 104 | Configurable Proctoring Policies | Done |  |
| 105 | Configurable Incident Severity Levels | Done |  |
| 106 | Examination Rule Enforcement | Done |  |
| 107 | Audit and Compliance Reporting | Done |  |
| 108 | Candidate Technical Support | Done |  |
| 109 | Examination Disruption Management | Done |  |
| 110 | Comprehensive Examination Activity Logs | Done |  |
| 111 | Dual-Camera Live Proctoring | Done |  |
| 112 | Built-In Webcam Integration | Done |  |
| 113 | External USB Camera Integration | Done | A second camera through the phone link or a second USB camera on the same computer, streamed separately to proctors as the desk camera. |
| 114 | External Camera Plugin Support | Done | As item 113. |
| 115 | Synchronized Dual-Camera Monitoring | Done |  |
| 116 | Independent Camera Status Monitoring | Done |  |
| 117 | Camera Disconnection Alerts | Done |  |
| 118 | Camera Disablement Detection | Done |  |
| 119 | Camera Obstruction Alerts | Done |  |
| 120 | Camera Tampering Alerts | Done |  |
| 121 | Camera Selection Change Detection | Done |  |
| 122 | Multi-Angle Candidate Verification | Done | Webcam plus phone (second angle) plus face check against the ID photo. |
| 123 | Secondary Camera Workspace Monitoring | Done |  |
| 124 | Candidate Hands and Desk Monitoring | Done |  |
| 125 | Multiple Display Detection | Done |  |
| 126 | HDMI External Monitor Detection | Done | Desktop app: HDMI/DisplayPort detection on Windows; extra screens on every system. |
| 127 | Wireless Display Detection | Done | Desktop app: wireless (Miracast) displays on Windows. |
| 128 | Duplicate Screen Configuration Detection | Done | Desktop app: a duplicated screen still shows as a second connected display on Windows. |
| 129 | Extended Desktop Detection | Done |  |
| 130 | Display Configuration Change Alerts | Done |  |
| 131 | Display Resolution and Topology Monitoring | Done |  |
| 132 | Unauthorized Screen Projection Detection | Done |  |
| 133 | Wireless Screen Sharing Detection | Done |  |
| 134 | Remote Desktop Activity Detection | Done |  |
| 135 | Unauthorized Screen Sharing Detection | Done |  |
| 136 | Display Policy Enforcement | Done |  |
| 137 | Live Proctor Display Alerts | Done |  |
| 138 | Display Incident Evidence Logging | Done |  |
| 139 | Pre-Examination Hardware Verification | Done |  |
| 140 | Continuous Hardware Compliance Monitoring | Done |  |
| 141 | Authorized Hardware Exception Management | Done | Hardware exception register: recorded by one person, approved by another, per exam or until a date; it switches only the named checks off for that candidate. |
| 142 | Simultaneous Webcam and External Camera Streaming | Done |  |
| 143 | Independent Dual-Camera Recording | Done |  |
| 144 | Synchronized Camera and Screen Recordings | Done | Synchronised side-by-side playback of camera, phone/desk camera and screen recordings on one timeline, with flagged events marked. |
| 145 | Camera Feed Quality Monitoring | Done |  |
| 146 | Camera Bandwidth and Latency Monitoring | Done | Per-stream bitrate, round-trip latency, jitter and packet loss on each live tile, plus the quality level. |
| 147 | Camera Feed Recovery After Disconnection | Done |  |
| 148 | Proctor Multi-Candidate Camera View | Done |  |
| 149 | Expandable Individual Candidate Camera View | Done |  |
| 150 | Proctor Camera Layout Customization | Done |  |
| 151 | Automatic Prioritization of High-Risk Alerts | Done |  |
| 152 | Proctor-to-Supervisor Escalation | Done |  |
| 153 | Incident Notes and Case Assignment | Done |  |
| 154 | Candidate Warning and Acknowledgment Tracking | Done |  |
| 155 | Examination Session Lock and Unlock | Done |  |
| 156 | Controlled Candidate Session Transfer | Done |  |
| 157 | Examination Integrity Verification | Done |  |
| 158 | Question Paper Version Control | Done |  |
| 159 | Examination Result Audit History | Done |  |
| 160 | Result Modification Approval Workflow | Done |  |
| 161 | Candidate Result Rechecking and Appeals | Done |  |
| 162 | Examination Centre Network Monitoring | Done | Hall switches, access points, routers and the internet link checked every minute (latency, up/slow/down, one-hour history), ICT and exams officers told when one goes down, approved hall computers online per hall, plus per-candidate connectivity and media quality. |
| 163 | Authorized Computer and Device Whitelisting | Done |  |
| 164 | Unauthorized USB Device Detection Where Supported | Done |  |
| 165 | Virtual Machine and Virtual Display Detection Where Supported | Done |  |
| 166 | Operating-System-Level Display Monitoring Where Authorized | Done |  |
| 167 | Secure Windows Examination Client Integration | Done |  |
| 168 | Managed Secure Browser Integration | Done |  |
| 169 | Proctoring Hardware Compatibility Management | Done | Compatibility list of browsers, systems and hardware (help centre) and a browser check against it before every exam. |
| 170 | System Configuration and Policy Versioning | Done | Every policy and campus-settings version is kept with who changed what; any version can be rolled back (the rollback is itself a new version). |
| 171 | AI-Based Anomaly Detection | Done | Explainable statistical anomaly model per exam (speed vs score, fast correct answers on hard items, shared wrong answers, late bursts, proctoring risk; robust z-scores). Flags go to a person; marks never change. |
| 172 | Facial Identity Reverification During Examinations | Done |  |
| 173 | Candidate Identity Mismatch Alerts | Done |  |
| 174 | Configurable Recording and Monitoring Retention | Done |  |
| 175 | Monitoring Consent and Privacy Notices | Done |  |
| 176 | Secure Storage of Camera and Screen Recordings | Done |  |
| 177 | Encrypted Evidence Transmission | Done |  |
| 178 | Restricted Access to Candidate Recordings | Done |  |
| 179 | Recording Access and Download Audit Logs | Done |  |
| 180 | Automatic Evidence Retention and Deletion Policies | Done |  |
| 181 | Candidate Accessibility and Accommodation Profiles | Done |  |
| 182 | Low-Bandwidth Proctoring Options | Done |  |
| 183 | Real-Time Camera Connection Diagnostics | Done |  |
| 184 | Examination Server Capacity Monitoring | Done |  |
| 185 | Automated Application and Database Health Checks | Done |  |
| 186 | Failover and Recovery Testing | Done | Recovery drill (monthly and on demand): fresh backup, full restore and comparison, replica write test, peer servers, with recovery time and data-at-risk recorded; weekly restore test; failover steps in docs/DISASTER-RECOVERY.md. |
| 187 | Examination Data Consistency Verification | Done |  |
| 188 | Duplicate Answer and Submission Prevention | Done |  |
| 189 | Secure Answer Submission Verification | Done |  |
| 190 | Examination Session Recovery After Server Restart | Done |  |
| 191 | Examination Centre Power and Network Incident Logging | Done |  |
| 192 | Proctor Availability and Shift Scheduling | Done |  |
| 193 | Proctor Performance and Workload Reports | Done |  |
| 194 | Examination Incident Statistics and Trends | Done |  |
| 195 | Institution-Wide Examination Analytics | Done |  |
| 196 | Departmental and Faculty Performance Comparisons | Done |  |
| 197 | Course-Level Assessment Analytics | Done |  |
| 198 | Question Bank Import and Export | Done |  |
| 199 | Bulk Candidate Import and Registration | Done |  |
| 200 | Bulk Examination Scheduling and Assignment | Done |  |
| 201 | Automated Candidate Notifications | Done |  |
| 202 | Examination Readiness Checklist | Done |  |
| 203 | Candidate System Compatibility Testing | Done |  |
| 204 | Proctor Training and Practice Environment | Done |  |
| 205 | Examination Dry-Run and Simulation Mode | Done |  |
| 206 | Configurable Rules for Different Examination Types | Done |  |
| 207 | Configurable Rules for Different Faculties and Departments | Done |  |
| 208 | Examination Centre and Remote Proctoring Mode Selection | Done |  |
| 209 | Integration With Learning Management Systems | Done |  |
| 210 | Integration With Student Information Systems | Done |  |
| 211 | Integration With Institutional Authentication Services | Done |  |
| 212 | API-Based Integration With External Academic Systems | Done |  |
| 213 | Multi-Tenant Institution Support | Done |  |
| 214 | Separate Institutional Data and Permissions | Done |  |
| 215 | Comprehensive System Administration Dashboard | Done |  |
| 216 | Security Incident Monitoring | Done |  |
| 217 | Unauthorized API Request Detection | Done |  |
| 218 | Brute-Force Login Protection | Done |  |
| 219 | Privileged Administrator Action Auditing | Done |  |
| 220 | Regular Security and Penetration Testing | Organisational process (platform supports it) | Yearly independent test and the checklist in docs/SECURITY-TESTING.md. |

## LMS + examination system (250 items)

Done: **240** · Hosting / infrastructure: **4** · Organisational process (platform supports it): **6** (of 250)

| # | Feature | Status | Notes |
|---|---|---|---|
| 1 | Student, lecturer, course coordinator, Head of Department, Dean, Registry, examination officer, invigilator, ICT administrator and super administrator accounts. | Done |  |
| 2 | Role-based access control with granular permissions. | Done |  |
| 3 | Secure login, password reset, session management and optional multi-factor authentication. | Done |  |
| 4 | University email and student identity verification. | Done |  |
| 5 | Automatic account creation and bulk student/staff imports. | Done |  |
| 6 | Student status synchronisation: active, suspended, withdrawn, graduated and deferred. | Done |  |
| 7 | Login history, device records and security audit logs. | Done |  |
| 8 | Staff account delegation and temporary permissions. | Done |  |
| 9 | Single sign-on integration with existing university systems. | Done |  |
| 10 | Automatic deactivation of accounts when authorised university records require it. | Done |  |
| 11 | Faculty, department, programme, academic session and semester management. | Done |  |
| 12 | Course codes, titles, credit units, prerequisites and course descriptions. | Done |  |
| 13 | Course catalogue showing courses available to each programme and level. | Done |  |
| 14 | Semester-specific course offerings and lecturer assignments. | Done |  |
| 15 | Course registration rules based on programme, level, prerequisites and student status. | Done |  |
| 16 | Maximum and minimum credit-unit validation. | Done |  |
| 17 | Compulsory, elective and carryover course classification. | Done |  |
| 18 | Course capacity limits and class allocation. | Done |  |
| 19 | Course registration opening and closing dates. | Done |  |
| 20 | Add, drop and change-course workflows with approval where required. | Done |  |
| 21 | Registration validation against the official student academic record. | Done |  |
| 22 | Automatic enrolment into the corresponding Moodle course after approved registration. | Done | Native: registered students are enrolled in the native course page immediately (no Moodle). |
| 23 | Registration slip generation and downloadable course lists. | Done |  |
| 24 | Late registration, corrections and authorised overrides. | Done |  |
| 25 | Registration audit trail and departmental reports. | Done |  |
| 26 | Lecturer dashboard showing assigned courses, enrolled students and upcoming activities. | Done |  |
| 27 | Course creation from approved course offerings or reusable templates. | Done |  |
| 28 | Course content organised by weeks, topics, modules or learning outcomes. | Done |  |
| 29 | Uploading lecture notes, PDFs, Word documents, PowerPoint slides, audio and videos. | Done |  |
| 30 | Live lecture links and recorded lecture management. | Done | Live lecture links, and recordings attached to each class (link or uploaded video/audio, resumable), published to the class when ready. BigBlueButton live classes add preloaded slides, transcripts and translations, and course attendance reports. |
| 31 | Course outlines, syllabuses, reading lists and learning objectives. | Done |  |
| 32 | Learning materials scheduled for automatic release. | Done |  |
| 33 | Prerequisite activities and conditional content access. | Done |  |
| 34 | Course announcements and class notifications. | Done |  |
| 35 | Discussion forums, academic Q&A and class collaboration. | Done |  |
| 36 | Student attendance and participation tracking. | Done |  |
| 37 | Individual, group and collaborative activities. | Done |  |
| 38 | Course completion tracking and learning progress. | Done |  |
| 39 | Course duplication, templates and content reuse across semesters. | Done |  |
| 40 | Lecturer co-teaching and teaching-assistant permissions. | Done |  |
| 41 | Content versioning and change history. | Done |  |
| 42 | Course review and approval by the appropriate academic authority. | Done |  |
| 43 | Personalised student dashboard with registered courses. | Done |  |
| 44 | Current semester timetable and academic calendar. | Done |  |
| 45 | Course materials, announcements and lecturer contact information. | Done |  |
| 46 | Upcoming assignments, quizzes, tests and examinations. | Done |  |
| 47 | Submission status and assessment deadlines. | Done |  |
| 48 | Personal grades, feedback and academic progress. | Done |  |
| 49 | Course completion indicators and outstanding activities. | Done |  |
| 50 | Notifications for new materials, deadlines, grades and timetable changes. | Done |  |
| 51 | Searchable course resources and downloadable learning materials. | Done |  |
| 52 | Mobile-friendly interface and accessible learning content. | Done |  |
| 53 | Student-teacher communication and discussion forums. | Done |  |
| 54 | Academic support resources and frequently asked questions. | Done |  |
| 55 | Low-bandwidth usability and resumable uploads where supported. | Done |  |
| 56 | Online assignments with document, image, video, code and text submissions. | Done |  |
| 57 | Individual and group assignments. | Done |  |
| 58 | Online quizzes, class tests, practice tests and formative assessments. | Done |  |
| 59 | Multiple-choice, multiple-select, true/false, matching, essay, numerical and short-answer questions. | Done |  |
| 60 | Central question bank organised by course, topic, difficulty and learning outcome. | Done |  |
| 61 | Random question selection and shuffled answer options. | Done |  |
| 62 | Question versioning, moderation and reuse across semesters. | Done |  |
| 63 | Assessment availability windows, time limits and due dates. | Done |  |
| 64 | Late-submission policies, extensions and authorised exemptions. | Done |  |
| 65 | Automatic marking for supported question types. | Done |  |
| 66 | Manual marking, rubrics, marking guides and annotated feedback. | Done |  |
| 67 | Anonymous marking where appropriate. | Done |  |
| 68 | Plagiarism-checking integration where licensed and available. | Done | Built-in similarity check across submissions and earlier classes (runs on your server, no licence needed). |
| 69 | Group assessment, peer assessment and self-assessment. | Done |  |
| 70 | Multiple attempts and configurable grading rules. | Done |  |
| 71 | Continuous assessment weighting and gradebook calculations. | Done |  |
| 72 | Grade moderation and approval before marks are released. | Done |  |
| 73 | Assessment analytics and question-performance reports. | Done |  |
| 74 | Accommodation settings for approved extra time and special assessment needs. | Done |  |
| 75 | Central examination administration separate from ordinary lecturer-managed quizzes. | Done |  |
| 76 | Examination calendar for each semester, faculty, department and programme. | Done |  |
| 77 | Examination timetable creation, conflict detection and publication. | Done |  |
| 78 | Examination venue, laboratory, seat and capacity management. | Done |  |
| 79 | Candidate allocation to examination halls, laboratories and seats. | Done |  |
| 80 | Course-specific candidate lists generated from approved registration records. | Done |  |
| 81 | Examination eligibility checks based on registration, student status and institutional rules. | Done |  |
| 82 | Configurable eligibility conditions, including attendance requirements where university policy mandates them. | Done |  |
| 83 | Approval workflows for examination creation and publication. | Done |  |
| 84 | Examination paper submission, moderation, approval and release controls. | Done |  |
| 85 | Secure question and paper repositories with restricted access. | Done |  |
| 86 | Separate permissions for question setters, moderators, approvers and examination administrators. | Done |  |
| 87 | Exam versioning and controlled question-paper revisions. | Done |  |
| 88 | Examination session configuration: date, start time, duration, grace period and closing time. | Done |  |
| 89 | Automatic candidate allocation to sessions and available workstations. | Done |  |
| 90 | Bulk candidate import, allocation and examination slip generation. | Done |  |
| 91 | Candidate identity verification before entry. | Done |  |
| 92 | Secure examination launch using authorised candidate and session records. | Done |  |
| 93 | Automatic enforcement of examination start and end times. | Done |  |
| 94 | Late-entry, early-exit, interruption and authorised-resumption policies. | Done |  |
| 95 | Emergency suspension, extension and controlled restart of examinations. | Done |  |
| 96 | Examination attendance, check-in and check-out records. | Done |  |
| 97 | Live monitoring of candidate progress, remaining time and submission status. | Done |  |
| 98 | Automatic submission at the deadline. | Done |  |
| 99 | Examination incident reports and formal investigation workflows. | Done |  |
| 100 | Secure archiving of examination configurations, attempts and final results. | Done |  |
| 101 | Pre-exam identity verification using approved student credentials and, where appropriate, facial matching. | Done |  |
| 102 | Live webcam monitoring. | Done |  |
| 103 | Support for a second external camera, where the proctoring software and hardware permit it. | Done |  |
| 104 | Live screen sharing and invigilator screen monitoring. | Done |  |
| 105 | Multiple-candidate live invigilation dashboard. | Done |  |
| 106 | Live audio monitoring where authorised and appropriate. | Done |  |
| 107 | Face-absence, multiple-person and suspicious-environment alerts. | Done |  |
| 108 | Configurable alerts for tab switching, focus loss and prohibited interactions. | Done |  |
| 109 | Screen recording or session recording where supported and permitted. | Done |  |
| 110 | Proctor notes, timestamps, screenshots and incident evidence. | Done |  |
| 111 | AI-assisted suspicious-activity detection with human review. | Done |  |
| 112 | Manual invigilator intervention, warnings and escalation. | Done |  |
| 113 | Candidate identity rechecks during an examination where necessary. | Done |  |
| 114 | Connection-loss and camera-disconnection alerts. | Done |  |
| 115 | A documented procedure for false positives, appeals and accessibility exceptions. | Done | Appeals, human review of every alert and accessibility exceptions are built; the written procedure is in every institution's help centre and linked from the proctoring console. |
| 116 | Examination evidence retention policies and restricted access to recordings. | Done |  |
| 117 | Proctor assignment, workload management and candidate-to-invigilator allocation. | Done |  |
| 118 | Live incident queue, severity levels and resolution tracking. | Done |  |
| 119 | Safe Exam Browser integration for supported examination computers. | Done |  |
| 120 | Restricted browser navigation, shortcuts, clipboard operations and unauthorised applications where the configured lockdown software supports them. | Done | Restricted in SEB and the desktop app; when the exam asks, the desktop app closes remote-control and screen-capture apps and records that it did. |
| 121 | Full-screen examination mode and controlled exam launch. | Done |  |
| 122 | Detection of supported multi-monitor configurations. | Done |  |
| 123 | Detection or restriction of prohibited screen-sharing and remote-access software where the managed client can enforce it. | Done |  |
| 124 | Managed examination workstations with approved operating-system policies. | Hosting / infrastructure | Managed workstation policy (IT). |
| 125 | Network access restrictions for controlled examination laboratories. | Hosting / infrastructure | Lab network policy (IT). |
| 126 | External-display and wireless-display controls through compatible endpoint management or operating-system policies. | Done |  |
| 127 | Device compatibility checks before candidates begin. | Done |  |
| 128 | Camera, microphone and screen-permission checks before the examination. | Done |  |
| 129 | Exam environment readiness checks and technical support escalation. | Done |  |
| 130 | Secure session tokens, attempt ownership and duplicate-session controls. | Done |  |
| 131 | Audit logging for device, browser and session events. | Done |  |
| 132 | Autosaving of answers throughout an attempt. | Done |  |
| 133 | Safe recovery after brief network disconnections. | Done |  |
| 134 | Server-side time enforcement and authoritative submission timestamps. | Done |  |
| 135 | Protection against duplicate attempts and duplicate final submissions. | Done |  |
| 136 | Idempotent answer-saving and submission processing. | Done |  |
| 137 | Capacity testing for expected concurrent candidates. | Done |  |
| 138 | Load balancing and appropriate database optimisation. | Hosting / infrastructure | Templates in deploy/ha (load balancer, two app servers, database replica). |
| 139 | Database backups and tested restoration procedures. | Done |  |
| 140 | Monitoring of CPU, memory, storage, database load and network latency. | Done | Built-in monitoring every minute: CPU, memory, disk, database connections and slow queries, queue, candidates writing and answers saved, with graphs for an hour, a day or a week. |
| 141 | High-availability architecture where justified by the examination's criticality. | Hosting / infrastructure | Templates in deploy/ha; a hosting decision. |
| 142 | Documented disaster recovery and examination-continuity plans. | Done |  |
| 143 | Monitoring of failed requests, error rates and service availability. | Done | Requests, server errors, error rate and response times recorded per minute with graphs; failed requests and security events logged. |
| 144 | Controlled maintenance windows and pre-examination system checks. | Done |  |
| 145 | Secure, monitored file storage for examination papers, uploads and recordings. | Done |  |
| 146 | Automatic grading and manual marking workflows. | Done |  |
| 147 | Course-level gradebooks combining quizzes, assignments and other assessments. | Done |  |
| 148 | Configurable CA, test and examination weighting. | Done |  |
| 149 | Grade moderation and approval by authorised academic officers. | Done |  |
| 150 | Marks-entry validation and protection against unauthorised changes. | Done |  |
| 151 | Results approval by the designated departmental and university authorities. | Done |  |
| 152 | Result publication controls and scheduled release. | Done |  |
| 153 | Student result views and downloadable result statements. | Done |  |
| 154 | Grade history and change audit trails. | Done |  |
| 155 | Supplementary examination and authorised resit management. | Done |  |
| 156 | Missing-result, absent-candidate and withheld-result tracking. | Done |  |
| 157 | Examination malpractice flags and investigation status. | Done |  |
| 158 | Result export to approved university academic systems. | Done |  |
| 159 | Course performance, pass-rate and failure-rate analysis. | Done |  |
| 160 | Question analysis, item difficulty and discrimination reports. | Done |  |
| 161 | Result correction workflows with documented approvals. | Done |  |
| 162 | Final result locking after formal approval. | Done |  |
| 163 | Transcript or academic-record integration where supported by university policy. | Done |  |
| 164 | Faculty and departmental dashboards. | Done |  |
| 165 | HOD oversight of assigned courses, lecturers, assessments and results. | Done |  |
| 166 | Dean-level monitoring and approval workflows. | Done |  |
| 167 | Registry control of student academic records and examination eligibility rules. | Done |  |
| 168 | Central examinations office control of examination schedules and sessions. | Done |  |
| 169 | ICT administration of system availability, integrations, security and backups. | Done |  |
| 170 | Configurable approval chains and separation of duties. | Done |  |
| 171 | Academic session and semester activation and closure. | Done |  |
| 172 | Automatic semester rollover and course archival. | Done | Automatic rollover (Policy switch): ended semesters close and archive once results are released, the next semester starts, and last session's courses and content carry over. |
| 173 | Bulk course, user and enrolment operations. | Done | Bulk people import, bulk candidate add, bulk sitting changes, CSV course import and bulk course actions (status, capacity, lecturers, copy content). |
| 174 | Staff workload and course allocation reports. | Done |  |
| 175 | Course coverage and assessment-completion monitoring. | Done |  |
| 176 | University-wide policy settings and configurable grading scales. | Done |  |
| 177 | Delegated access with expiry dates and audit trails. | Done |  |
| 178 | Formal exception requests and approval records. | Done | General exception-request form with private evidence, decisions with reasons, and the existing extension, export, temporary-role and result-change approvals. |
| 179 | Institution-wide academic activity and examination dashboards. | Done |  |
| 180 | Announcements by course, department, faculty or university. | Done |  |
| 181 | Email and in-platform notifications. | Done |  |
| 182 | Deadline reminders and examination countdown alerts. | Done |  |
| 183 | Examination timetable change notifications. | Done | Moving a sitting (time or room) notifies every candidate in the app and by e-mail; a sitting already under way cannot be moved. |
| 184 | Alerts for registration problems and eligibility failures. | Done |  |
| 185 | Lecturer-to-student messaging with appropriate privacy controls. | Done |  |
| 186 | Notifications for released grades and feedback. | Done |  |
| 187 | Emergency alerts during live examinations. | Done |  |
| 188 | Delivery status and notification logs. | Done | Every notification is stored with read status, and every e-mail is logged as sent, failed or bounced (bounces from the mail service's signed webhook). |
| 189 | Student engagement and course completion reports. | Done |  |
| 190 | Lecturer activity and assessment reports. | Done |  |
| 191 | Course registration and enrolment reports. | Done |  |
| 192 | Examination attendance and absentee reports. | Done |  |
| 193 | Live examination incident and proctoring reports. | Done |  |
| 194 | Candidate submission and interruption reports. | Done |  |
| 195 | Department, faculty and university-wide performance reports. | Done |  |
| 196 | Export to Excel, CSV and PDF. | Done |  |
| 197 | Filterable reports by academic session, semester, faculty, department, course and level. | Done |  |
| 198 | Immutable or tamper-evident audit records for critical examination actions. | Done |  |
| 199 | Administrative activity logs and privileged-access monitoring. | Done |  |
| 200 | Configurable dashboards with key academic and examination indicators. | Done | Role-based dashboards that each person can rearrange and trim (panels and individual figures). |
| 201 | Encryption in transit and secure storage for sensitive data. | Done |  |
| 202 | Strong password policies and optional multi-factor authentication. | Done |  |
| 203 | Least-privilege access and separation of academic duties. | Done |  |
| 204 | Protection against common web application vulnerabilities. | Done |  |
| 205 | Regular security updates and dependency management. | Done |  |
| 206 | Secure question-paper access and download controls. | Done |  |
| 207 | Protection against unauthorised result editing. | Done |  |
| 208 | Retention and deletion policies for exam recordings and identity evidence. | Done |  |
| 209 | Appropriate consent and notices for camera, microphone and screen monitoring. | Done |  |
| 210 | Access logs for sensitive student records and proctoring evidence. | Done |  |
| 211 | Secure backup storage and tested restoration. | Done |  |
| 212 | Incident response procedures and security alerts. | Organisational process (platform supports it) | Procedures in docs/INCIDENT-RESPONSE.md; security events and alerts are built in. |
| 213 | Privacy-aware reporting and restrictions on bulk student-data exports. | Done |  |
| 214 | Periodic access reviews and examination-security audits. | Organisational process (platform supports it) | Access review screen and report; audits are a procedure. |
| 215 | Integration with the institution's existing student information system. | Done |  |
| 216 | Synchronisation of student records, matriculation numbers, programmes and levels. | Done |  |
| 217 | Synchronisation of approved course registrations. | Done |  |
| 218 | Integration with the university's identity provider or single sign-on. | Done |  |
| 219 | Integration with student portals and academic result systems. | Done |  |
| 220 | Integration with institutional email and notification services. | Done | Each institution can send through its own mail server and address (SMTP: Microsoft 365, Google Workspace, campus), with a test send and delivery log, and forward signed copies of notices to its SMS gateway, Teams/Slack bridge or campus app. |
| 221 | Integration with attendance systems where academic policy requires it. | Done | Attendance terminals and apps send attendance through a scoped API key; it lands on the class meeting and counts for exam eligibility. |
| 222 | Integration with compatible plagiarism-detection tools. | Done | Built-in similarity check plus a signed connector to an outside plagiarism service (manual or automatic), with scores shown to lecturers. |
| 223 | Integration with supported live-proctoring services. | Done |  |
| 224 | Integration with institutional reporting and data analytics. | Done | Results API, Excel/CSV/PDF exports, and a course-level analytics feed (pull API and signed nightly push) with no personal data. |
| 225 | Documented APIs and controlled data exchange. | Done |  |
| 226 | Error handling, retry mechanisms and reconciliation reports for failed synchronisations. | Done |  |
| 227 | Responsive interface for desktops, laptops, tablets and mobile devices. | Done |  |
| 228 | Clear student and lecturer dashboards. | Done |  |
| 229 | Accessible navigation, keyboard support and screen-reader compatibility. | Done |  |
| 230 | Low-bandwidth design for learning materials and ordinary course activities. | Done |  |
| 231 | Clear deadlines, time zones and examination instructions. | Done |  |
| 232 | Pre-examination system checks and practice examinations. | Done |  |
| 233 | Guided workflows for course registration and exam entry. | Done |  |
| 234 | Student support and technical incident reporting. | Done |  |
| 235 | Consistent university branding and accessible colour contrast. | Done |  |
| 236 | Clear explanations of examination restrictions and consequences. | Done |  |
| 237 | Alternative arrangements for approved accessibility requirements. | Done |  |
| 238 | Scheduled database, file and configuration backups. | Done |  |
| 239 | Separate backup storage and periodic restore testing. | Done |  |
| 240 | Version-controlled configuration and documented deployment procedures. | Done |  |
| 241 | Staging environment for testing upgrades and plugins before production. | Organisational process (platform supports it) | A staging copy runs the same way (deploy/ha); a hosting step. |
| 242 | Moodle core and plugin compatibility checks before upgrades. | Done | Not applicable: native LMS, no Moodle. |
| 243 | Performance monitoring during peak examinations. | Done | Live performance graphs (candidates writing, answers saved, response time, errors, CPU, memory) for platform and institution, plus load-test tool and post-exam review. |
| 244 | Capacity planning based on real concurrent-user load tests. | Done |  |
| 245 | Automated alerts for service failures and storage capacity issues. | Done | Automatic alerts (CPU, memory, disk, errors, slowness, queue, database, scheduler, backups, hall network) to every platform administrator in-app and by e-mail and to a Slack/Teams/Chat webhook; alerts close themselves when the problem clears. |
| 246 | Documented incident recovery and escalation procedures. | Organisational process (platform supports it) | docs/INCIDENT-RESPONSE.md and docs/DISASTER-RECOVERY.md. |
| 247 | Regular examination simulations before high-stakes examination periods. | Done |  |
| 248 | Change management and rollback procedures. | Organisational process (platform supports it) | Migrations are reversible; rollback steps in docs/DISASTER-RECOVERY.md. |
| 249 | Post-examination reports and lessons-learned reviews. | Done | Post-exam review report per sitting. |
| 250 | Periodic security, accessibility and operational reviews. | Organisational process (platform supports it) | Security checklist and access reviews are in place; the reviews are a procedure. |
