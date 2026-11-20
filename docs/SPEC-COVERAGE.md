# Master specification (20 Nov 2026) — what the platform already does and what is being added

Decisions taken with the owner (20 Nov):
- A web page cannot hide the operating system's notifications; exams that must keep them out allow only the desktop exam app and Safe Exam Browser.
- No Moodle code in this project. Moodle, Canvas, Open edX and other LMSs connect through LTI 1.3; the platform runs on its own without any LMS. A "managed Moodle" offering (spec §19) is not built here.
- AI tools use any provider an administrator configures (Claude, OpenAI-compatible, or a self-hosted model). They are off until a key is set, and teachers always review AI output before it is used.
- Owner's top priorities: thousands of candidates without interruption, video recording, proctoring, and an exam ready and running in 2–4 clicks.

Legend: ✅ already in the platform · 🔨 being built in this round · ⏳ later round

| Spec | Area | Status |
|---|---|---|
| 2.1 | Exam creation: drafts, approval, publish, archive, scheduling, duration, attempts, pass/total marks, negative and partial marks, sections, shuffling, extra time, result release | ✅ |
| 2.1 | One exam with several papers (objective, theory, practical, viva, defence), students and examiners per paper, ready in a few clicks | ✅ |
| 2.1 | Access codes and secure exam links; open (no-account) exams | ✅ |
| 2.1 | Exam templates, duplicate exam, question pools / random draw | ✅ / 🔨 duplicate on the exam page |
| 2.2, 12 | Test series, packages, cart, vouchers, candidate payments, receipts | ✅ (`/shop`, `/admin/shop`; prices, currency and access days set by the institution; payment to the institution's own Paystack) |
| 2.3, 4.1 | Word (incl. Equation 3.0 / MathType) and spreadsheet import | ✅ |
| 2.3, 4.1 | PDF / scanned / photographed papers via AI extraction with teacher review | ✅ (needs an AI key) |
| 3.1 | Single, multiple, true/false, matching, numeric (tolerance), short answer, essay | ✅ |
| 3.1 | Fill-in-the-blanks, ordering, file upload, passage groups, instruction blocks, audio/video in questions, multiple true/false | ✅ |
| 3.2, 3.3 | Question bank: topics, difficulty, outcomes, status, history, shared/private | ✅ |
| 4.2–4.4 | AI question generation, AI-assisted grading (suggestion stored apart from final mark), AI summaries | ✅ generation and marking suggestions (needs an AI key) · summaries ⏳ |
| 5 | Student portal, autosave, resume after network loss, flags, timer, auto-submit, history | ✅ |
| 5, 21 | Offline-safe exams: answers kept on the computer (disk-durable), red notice, page reopens offline, sync when back, answers written in time accepted after the end, attempt held for disconnected computers, time given back after a power cut | ✅ (tested by cutting the network and killing the browser) |
| 5 | Pre-exam checklist chosen per exam (all / some / none): browser, network, computer, power, screen, full screen, keyboard, camera, face, microphone, sound, screen share, secure app, room scan | ✅ |
| 7.2 | Strikes: one warning per departure from the exam screen, "Warning N of 5", lock until an administrator unlocks | ✅ |
| 7.2 | Safe Exam Browser: settings file with school SEB password (encrypted), notifications kept out (Windows separate desktop, Apple assessment mode), installers served from the institution's own server | ✅ |
| 7.2 | Desktop exam app keeps system notifications off during the exam and restores them | ✅ |
| 17 | Runs entirely on a local network without internet (no external assets) | ✅ (OPERATIONS.md §20) |
| 5.1 | OTP and Google sign-in | ✅ (one-time codes by email or SMS; Google via institution SSO) |
| 6 | Lecturer, examiner, invigilator portals and scoped access | ✅ |
| 7.1 | Identity: photo, live capture, face match, ID review | ✅ |
| 7.2 | Snapshots, face/gaze detection, tab/focus/fullscreen, multiple displays, secure desktop app, IP/device approval, incidents, risk score | ✅ |
| 7.2 | Live video watching and recordings | ✅ with a media server (LiveKit) |
| 7.2 | Camera and screen recordings with **no** media server (works on shared hosting) | ✅ |
| 7.3 | Consent, access limits, retention, audit | ✅ |
| 8 | Auto + manual marking, double marking, moderation, appeals/remarks, rescale, exam board | ✅ |
| 8, 9.1 | Configurable grading scales / grade boundaries (A–F, points) | ✅ (several named scales per institution, chosen per exam) |
| 9 | Gradebook, results, reports, item difficulty/discrimination/distractors, Excel/PDF/CSV/JSON | ✅ · Word report from the institution's template ⏳ (template to come) |
| 10, 11 | Students, bulk import, faculties, departments, programmes, sessions, semesters, courses, enrolment | ✅ |
| 11.2 | Learning materials, assignments, certificates and badges | ✅ · SCORM / H5P ⏳ |
| 13 | Institution subscriptions, plans, limits, quotas, invoices, Paystack | ✅ |
| 14 | Email, in-app, reminders, announcements, help centre, SMS | ✅ |
| 15 | Roles, custom roles, 2FA, login history, audit | ✅ |
| 16 | LTI 1.3 tool (launch, roster, grade passback) for Moodle, Canvas, etc. | ⏳ |
| 17, 18 | Multi-tenant SaaS, branding, custom domains; self-hosting docs, backups, recovery drills | ✅ |
| 17 | Three designs (Sapphire, Forest & gold, Plum) each institution can switch at any time; app, sign-in pages, landing page and error pages follow it | ✅ |
| 20 | Timetable, venues, CBT labs, seats, invigilators, attendance, eligibility, malpractice | ✅ |
| 21 | Server timer, atomic idempotent submit, durable autosave, recovery | ✅ · proven at 500 · 🔨 load test at thousands |

## Hosting and "thousands of candidates"

Shared hosting (SiteGround GrowBig/GoGeek) limits how many PHP processes run at once, so it suits a few hundred candidates at a time. For thousands at once the platform needs a VPS or cloud server (see OPERATIONS.md §20 for sizing) — the code is the same; only the server changes.
