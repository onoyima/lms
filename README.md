# Exams & LMS Platform

The new multi-institution examination and learning platform (Laravel 13, React, Inertia).
It runs beside the current portal in `EXAMS/` and replaces it module by module.

## What is in this release

| Area | What works now |
| --- | --- |
| Institutions & plans | Multi-tenant (every row carries `tenant_id`), plans as presets of modules, per-institution switches (**Plans & modules**). An "Exams only" school sees no LMS. |
| People & roles | 13 roles; **People** screen: create, bulk CSV import, roles, temporary roles with expiry (acting HOD), deactivate (signs out everywhere), sign-in history. Students sign in with email **or matric number**. |
| Question bank & equations | Word/PDF/text import with equations (Word equations → LaTeX, `\ce{}` chemistry), preview, moderation, versions, blueprints. MathJax served locally. |
| Exam formats | **Objective/CBT, essay, mixed, practical, viva** — practical and viva marked on criteria with allowed score steps by several examiners (average/highest/median), exactly like the old portal. |
| Exam delivery | Web exam room, desktop exam app protocol (Ed25519-signed requests), Safe Exam Browser (`.seb` files, Config Key / Browser Exam Key checks). Server-authoritative timer, autosave with offline queue, resume after reload, one active window per candidate, auto-submit, receipts. |
| Proctoring | Pre-checks, consent, tab/focus/fullscreen/clipboard/shortcut/multi-display detection, webcam snapshots, face presence, screen-share checks, risk scores, live proctor console, warnings with acknowledgement, pause/lock/terminate/extra time, incidents with encrypted evidence and access logs, retention purge. |
| Halls | Venues, approved devices, seats, check-in, PINs, invigilator allocation and load balancing, timetable clash detection. |
| Marking & results | Auto-marking (partial/negative/numeric tolerance), anonymous essay marking, panel marking, moderation → approval → publication with separation of duties, locked results, change requests needing a second officer, appeals, item analysis (difficulty, discrimination), similarity analysis, CSV export. |
| LMS (native, no Moodle) | Course registration with rules (window, units, prerequisites, capacity, core courses, status, approvals, overrides, slip); course pages by week/topic, notes, files, video, live links, release dates, prerequisites, progress, versions, copy from last semester, HOD review; assignments (files/text, groups, extensions, late penalties, cut-off, rubrics, anonymous and peer review); quizzes on the exam engine; forums; announcements; attendance by code; gradebook with CA/exam weighting, HOD approval and release; notifications. |
| Integration | Push API for the student information system (students, courses, registrations; idempotent, per-row errors, reconciliation log) and results export. Scoped, IP-restricted API keys. |
| Operations | Encrypted backups with weekly automated restore tests, health probe, tamper-evident audit log, security events, CI with dependency audits, load-test tool. See `docs/OPERATIONS.md`. |
| Legacy import | `php artisan legacy:import` brings objective, practical and viva exams, students, examiners, marks and attempts from the old portal — **local database only**. |
| Question editor | Write and edit every type (incl. matching and short answer) with live maths preview; versions; no editing while candidates answer; setters cannot approve their own questions; export to text (re-importable) and Excel. |
| Scheduling | Exam calendar with clash highlighting, automatic timetable (no student clashes, daily limit, seats per hall), bulk move/re-venue/cancel, exam slips (PDF, verifiable), resits and supplementaries, attendance-based eligibility, maintenance windows. |
| Proctor operations | Shift calendar and availability, automatic rostering, training sandbox with simulated candidates, offline-proctor alerts with one-click hand-over, camera layouts. |
| Identity | Face enrolment with consent, registry ID photos, server-side matching at entry and during the exam, looking-away alerts; single sign-on (SAML 2.0 / OpenID Connect). |
| Live media | Self-hosted LiveKit: live camera, audio and screen to proctors, phone as a second camera, automatic recordings, connection quality (`deploy/livekit`). |
| Desktop app | `desktop/`: locked kiosk window hidden from screen capture, approved computers only, signed requests, checks for screens/HDMI/wireless/USB/VMs/remote access/capture apps/virtual cameras. |
| LMS extras | Personal calendar with phone feed, class timetables with change alerts, search, private messages with rules, help centre and support tickets, resumable uploads, similarity check, reminders, participation scores. |
| Operations | HA templates (`deploy/ha`), `docs/DISASTER-RECOVERY.md`, `docs/INCIDENT-RESPONSE.md`, `docs/SECURITY-TESTING.md`, post-exam review report. |

What is still missing, item by item against Target.txt: `docs/COVERAGE.md`.

## Requirements (Windows / XAMPP)

- **PHP 8.3 or newer** (XAMPP 8.3+), with extensions: pdo_mysql, mbstring, openssl, fileinfo, sodium, zip, intl (enable in `php.ini`).
- MySQL/MariaDB from XAMPP, **local only**.
- Composer 2 and Node.js 20+.
- Pandoc for Word import: https://pandoc.org/installing.html

## First run

```bash
cd C:\xampp\htdocs\EXAMS\platform
composer install
copy .env.example .env
php artisan key:generate
# create the database "exams_platform" (utf8mb4_unicode_ci) in phpMyAdmin, then:
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
php artisan serve
```

For a real campus server (Apache, scheduler, queue worker, backups, monitoring) follow `docs/OPERATIONS.md`.

Open http://localhost:8000 and sign in as `platform@platform.local` (platform administrator; set
`SEED_PLATFORM_EMAIL` to choose another address) and add institutions from the platform console. Set
`SEED_ADMIN_PASSWORD` in `.env` before seeding, or copy the password the seeder prints. For a
single-institution install, `SEED_INSTITUTION_SLUG` / `SEED_INSTITUTION_NAME` also create that institution
and its administrator. Sample data for trying things out (an example institution, Veritas University, with
people in every role): `php artisan db:seed --class=DemoSeeder` — never on a live server.

For development with live reload: `composer dev`.

## Tests

```bash
php artisan test
```

120 tests (551 assertions) cover institution isolation, modules, permissions, the audit chain, equation import,
the exam lifecycle (timer, autosave, resume, single session, violations, auto-submit), marking and results workflows,
SEB and desktop channels, evidence, the legacy import guard, the LMS (registration, release rules, assignments,
gradebook, quizzes, forums, attendance), the integration API and people management.

Demo data for a local look around: `php artisan db:seed --class=DemoSeeder` (demo student, lecturer, a CBT with
equations, a practical, a viva and an LMS course). Never on a live server.

## Writing questions for import

```
1. Which equation is balanced?
A. $\ce{H2 + O2 -> H2O}$
*B. $\ce{2H2 + O2 -> 2H2O}$
C. $\ce{H2 + 2O2 -> H2O}$
Topic: Stoichiometry

2. Its kinetic energy $E_k = \frac{1}{2}mv^2$ is:
A. 3 J
B. 9 J
Answer: B
Mark: 2
```

In Word, use Insert → Equation for maths; numbered and lettered Word lists also work.

## Status

All 470 Target.txt items, item by item: `docs/COVERAGE.md`. What remains is either a hosting step (load balancer, managed workstations, lab network) or an institutional procedure; the few "partly done" items say exactly what is missing.
