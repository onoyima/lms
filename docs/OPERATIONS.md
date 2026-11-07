# Exams & LMS platform — operations guide

For the ICT team. Everything here runs on the institution's own machines; nothing connects to an outside database.

## 1. Install (Windows + XAMPP, campus server)

Requirements: PHP 8.3+ (XAMPP 8.3), Composer, Node 22, MySQL/MariaDB (XAMPP), `pandoc` (for importing Word/PDF questions with equations), PHP extensions `sodium`, `zip`, `gd`, `intl`, `pdo_mysql`.

```bat
cd C:\xampp\htdocs\EXAMS\platform
composer install --no-dev --optimize-autoloader
npm ci && npm run build
copy .env.example .env
php artisan key:generate
```

Edit `.env`:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://exams.example.edu.ng        ; or http://10.x.x.x on the campus LAN
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1                           ; this computer only
DB_DATABASE=exams_platform
DB_USERNAME=exams_app                     ; NOT root
DB_PASSWORD=<long random>
CACHE_STORE=redis                           ; or file if Redis is not installed (database also works, slower)
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true                  ; when served over https
SEED_ADMIN_PASSWORD=<choose one>
HEALTH_TOKEN=<long random>
BACKUP_PATH=D:\platform-backups              ; a different disk from the database
BACKUP_PASSPHRASE=<long random, kept in the safe>
BACKUP_MYSQLDUMP=C:\xampp\mysql\bin\mysqldump.exe
BACKUP_MYSQL=C:\xampp\mysql\bin\mysql.exe
```

Database account (least privilege; the second grant lets the weekly restore test use a scratch database and nothing else):

```sql
CREATE DATABASE exams_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'exams_app'@'localhost' IDENTIFIED BY '<long random>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON exams_platform.* TO 'exams_app'@'localhost';
GRANT ALL PRIVILEGES ON `exams_platform_restore_test`.* TO 'exams_app'@'localhost';
```

Then:

```bat
php artisan migrate --force
php artisan db:seed --force          :: plans and the platform administrator (SEED_INSTITUTION_* adds a first institution)
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Point Apache's DocumentRoot (or an Alias) at `platform\public` only. Enable OPcache with `opcache.validate_timestamps=0` in production (restart Apache after each update).

## 2. Background processes (must always run)

| What | Command | How on Windows |
|---|---|---|
| Scheduler (auto-submit at deadline, proctor-offline alerts, reminders, retention purge, upload clean-up, backups, role expiry, health heartbeat) | `php artisan schedule:run` every minute | Task Scheduler, every 1 minute |
| Queue worker (notifications, emails) | `php artisan queue:work --tries=3 --max-time=3600` | NSSM service, or Task Scheduler at start-up |

`/api/health` reports `degraded` if the scheduler has not run in 3 minutes.

## 3. Moving the old portal across (objective, practical, viva)

1. Copy the old database to **this** computer (phpMyAdmin → Export on the old server, Import here into e.g. `exams_portal`). The importer refuses any host that is not this computer.
2. Dry run, then the real import (safe to repeat; nothing is duplicated):

```bat
php artisan legacy:import --database=exams_portal --uploads=C:\xampp\htdocs\EXAMS\uploads --dry-run
php artisan legacy:import --database=exams_portal --uploads=C:\xampp\htdocs\EXAMS\uploads --publish-visible
```

What comes across: students (matric numbers and existing passwords keep working), examiners (admin → exam officer, examiner → lecturer), objective exams with questions, images and equations, "questions to show", score policy, attempts and scores; practical exams with their criteria and allowed score steps (e.g. `0,0.5,1`); viva exams with the "General Viva Performance" mark out of the total; every examiner's marks (results use the average of examiners, as before); enrolments and examiner assignments. `--publish-visible` publishes results where the old portal let students see their score.

## 4. Student information system (push API)

The SIS sends records to the platform; the platform never connects to the SIS database.

```bat
php artisan apikey:create "University SIS" --scopes=sis.write,results.read --ips=10.0.5.20
```

The key is printed once. Endpoints (JSON, `Authorization: Bearer vk_…`, optional `Idempotency-Key` header):

| Method | Path | Body |
|---|---|---|
| POST | `/api/v1/sis/students` | `{"data":[{"matric_no","name","email","programme_code","level","status":"active|suspended|withdrawn|graduated|deferred"}]}` |
| POST | `/api/v1/sis/courses` | `{"data":[{"code","title","units","level","department_code"}]}` |
| POST | `/api/v1/sis/registrations` | `{"data":[{"matric_no","course_code","semester_id","category","status":"active|dropped"}]}` |
| GET | `/api/v1/sis/runs` | last 50 syncs with per-row errors (reconciliation) |
| GET | `/api/v1/results?after=<id>` | released course results, 500 per page |

Up to 1,000 rows per call. Good rows are saved, bad rows are returned with the reason. Withdrawn/suspended/graduated students lose access automatically. Revoke: `php artisan apikey:revoke <prefix>`.

## 5. Backups and restore tests

- Daily 01:30: database dump + private files (question media, evidence, submissions), gzip, AES-256-GCM encrypted when `BACKUP_PASSPHRASE` is set, SHA-256 recorded, old copies removed after `BACKUP_KEEP_DAYS`.
- Weekly (Sunday 04:00): the newest backup is decrypted, checksum-verified, restored into `exams_platform_restore_test`, row counts compared with live, scratch database dropped. Result in `backup_runs` and `/api/health`.
- Manually: `php artisan backup:run` and `php artisan backup:run --restore-test`.
- Copy `BACKUP_PATH` off-site (another building or cloud storage) every day. **Without the passphrase, encrypted backups cannot be restored.**

Full restore: `php artisan backup:decrypt D:\platform-backups\db-YYYYMMDD-HHMMSS.sql.gz.enc restore.sql.gz` (asks for nothing; uses `BACKUP_PASSPHRASE`), un-gzip, `mysql exams_platform < restore.sql` into an empty database, then decrypt and unzip `files-*.zip.enc` into `storage/app/private`.

## 6. Monitoring

`GET /api/health` → `{"status":"ok|degraded|down"}`. With header `X-Health-Token: <HEALTH_TOKEN>` it shows database latency, cache, scheduler, queue, failed jobs, last backup, last restore test, disk, load, open attempts. Point Uptime Kuma / Zabbix / Nagios at it; alert on anything but `ok`. `php artisan system:health` shows the same on the console. `php artisan audit:verify` checks the tamper-evident audit chain.

## 7. Capacity (measured)

Load test on a **2-core** container (application, MariaDB and the load generator on the same 2 cores; Apache + mod_php, OPcache, Redis):

| Scenario | Requests | Failed | p50 | p95 | p99 |
|---|---|---|---|---|---|
| 1,000 candidates, one answer every ~20 s, heartbeats every 15 s, all submit at the deadline | 23,054 | 0 | 12 ms (answers) | 35 ms (answers), 463 ms (submit) | 164 ms |
| 1,000 candidates, one answer every ~5 s (stress) | 36,684 | 139 (client time-outs while 1,000 submits queued; all recovered by retry / auto-submit) | 117 ms | 738 ms | 45 s |

| Re-run Nov 2026, 500 candidates, one answer every ~5 s, cache in the **database** (no Redis) | 11,425 | 0 | 560 ms | 1.6 s | 47 s |
| Same, cache in **files** | 14,097 | 256 (Apache hit `MaxRequestWorkers` 150 with 500 open connections) | 160 ms | 0.9 s | 18 s |

The cache driver matters most: with `CACHE_STORE=database` every rate-limit and session check writes to MariaDB, which then competes with answer saves. Use **Redis** for `CACHE_STORE` and `SESSION_DRIVER` during exams, and raise Apache's `MaxRequestWorkers` (or use PHP-FPM) above the number of candidates sitting at once.

The stress run saturated the 2 cores at ~190 requests/s. A real exam hall (one save per answer change, 10 s autosave batching) needs roughly 0.2 requests/s per candidate. Sizing guide: **4 cores / 8 GB for 1,000 concurrent candidates, 8 cores + separate database server for 3,000+**. Re-run before a big exam period:

```bat
php artisan exams:load-fixture --candidates=1000      :: never on production
node tools/loadtest.mjs http://127.0.0.1 storage/app/loadtest.json 240 20
```

Why it stays fast: each candidate's paper is frozen once and keys stay on the server; answers are one idempotent upsert keyed by (attempt, question) with a sequence number; the exam API is stateless (signed token, no session or cookie lookups); the institution lookup and each attempt's question map are cached; proctoring events are written in batches; every hot query uses an index; the deadline is enforced by the server and a minute-by-minute sweeper.

## 8. Security notes

- Content-Security-Policy with nonces, `frame-ancestors 'none'`, HSTS on https, `nosniff`; uploads are served through permission-checked routes, never from a public folder.
- All rich text (questions, notes, forum posts, feedback) is sanitised on the server.
- Rate limits: login, exam start, exam API (per attempt), evidence, submissions, forum posts, integration keys.
- Separation of duties: exam papers need a second person to approve; course results need the HOD/Dean (not the course lecturer) to approve before release; result changes after publication need a second officer; released results are locked.
- Failed and locked-out sign-ins, rejected exam tokens and API keys, 401/403/429 responses go to `security_events`; sign-in history is on People → Sign-ins.
- Temporary roles end automatically (`roles:expire`, every 5 minutes). Admins cannot grant a role holding permissions they lack.
- CI (`.github/workflows/ci.yml`) runs the tests and `composer audit` / `npm audit` on every change; Dependabot opens weekly update pull requests.

## 9. Live video, audio and screens (media server)

Live video runs on your own LiveKit server, so no outside proctoring service is used.

1. On a Linux server with a public or campus IP, copy `deploy/livekit`.
2. Set `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` (40 or more random characters) and `REDIS_PASSWORD`.
3. In `livekit.yaml`, put your platform address in `webhook.urls`.
4. Run `docker compose up -d`.
5. Put TLS in front of port 7880, as `wss://media.<your-domain>`, and open 7881/tcp and 50000–60000/udp.
6. In the platform's `.env`, set `LIVEKIT_URL=wss://media.<your-domain>`, `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET`. Set `LIVEKIT_RECORDINGS_LOCAL` if the recordings folder is shared with the web server, so proctors can play recordings.
7. For each exam, turn on *Live video*, *Record camera*, *Record screen*, *Microphone* or *Second camera* (phone) in **Rules & proctoring**.

Without a media server, exams still run: snapshots, events, identity checks and the desktop app's checks carry on. The console's **Watch live** button says live video is not set up.

## 10. Desktop exam app

See `desktop/README.md`.
- Build the installer with `npm run dist:win`, and sign it with the institution's certificate.
- Install it on hall computers.
- Approve each computer in **Halls & devices**.
- Choose *desktop exam app* as an allowed channel for an exam.

The app reports extra screens, HDMI and wireless displays, USB drives, virtual machines, remote-access tools, capture apps and virtual cameras. An exam started in the app can only continue on that computer.

## 11. Single sign-on and face identity

- **Single sign-on:** go to **Admin → Single sign-on**.
  - Add Microsoft Entra ID, Google or Keycloak (OpenID Connect), or ADFS or Shibboleth (SAML 2.0).
  - Give the URLs shown on that page to the identity-provider administrator.
  - Turn the provider on: a button then appears on the sign-in page.
- **Face identity:**
  - Students enrol under **Face identity**.
  - The registry uploads ID photos in bulk under **Identity checks**. Name each file after the matric number, with dashes in place of slashes.
  - Clear matches are verified automatically; the rest wait for a person.
  - Turn on *Identity check* per exam. Mismatches alert a proctor and never fail a candidate by themselves.

## 12. High availability, disaster recovery and incidents

- `deploy/ha`: a load balancer, two app servers, a queue worker, a scheduler, a MariaDB primary with a replica, and Redis.
- `docs/DISASTER-RECOVERY.md`: recovery targets, backups, fail-over steps and drills.
- `docs/INCIDENT-RESPONSE.md`: what to do during exam disruptions and security incidents.
- `docs/SECURITY-TESTING.md`: automated and manual checks, and the yearly independent test.
- **Planned maintenance** (**Admin → Maintenance**): announced on every page two days ahead. No exam can start during a window, and no sitting can be booked into one.
- After each exam period, run **Reports → Post-exam review**.


## 13. Platform operator and institutions (who sees what)

| Who | Signs in to | Can do | Cannot do |
|---|---|---|---|
| Platform administrator (platform team) | `/platform` | Approve applications, add institutions, plans and prices, modules per institution, invoices and payments, suspend/restore, run the security check | Open any institution's people, papers, marks, results or courses (they are redirected; the console loads counts only) |
| Institution administrator | the institution's address | Everything inside the institution, plus **Subscription & billing** (pay invoices) | Change their own plan or price |
| ICT admin, registry, exams officer, dean, HOD, lecturer, proctor | the institution's address | What their roles allow; the menu shows only those pages | Billing; other institutions |
| Student | the institution's address | Courses, exams, results, requests & exceptions | Staff pages |

- New institutions apply from the public website (`/#apply`). A platform administrator checks the details and approves; the institution's administrator receives a link to set a password and a 30-day trial starts.
- Billing runs daily (`billing:run`, 06:10): renewal invoices 14 days before, past-due after the due date, suspension after 14 more days. Paying (Paystack or a recorded bank transfer) restores access at once. A suspension for misuse is not lifted by paying.
- While suspended, only the institution administrator can sign in, and only to billing.

## 14. Integrations

Institution settings > **Integrations** (needs `integrations.manage`):

- **API keys** with scopes `sis.write`, `results.read`, `attendance.write`, `analytics.read`, an optional IP allow-list and an expiry. The key is shown once.
- **Attendance terminals**: `POST /api/v1/attendance` with `{"records":[{"course_code","identifier","held_at","status","device"}]}` (up to 2,000 per call).
- **Analytics**: `GET /api/v1/analytics?dataset=enrolments|results|attendance|exams|engagement`, or a signed nightly push (04:40) to your warehouse. Course-level figures only.
- **Plagiarism service**: signed JSON to your provider (or an adapter in front of it); replies or callbacks to `/api/v1/plagiarism/callback/{reference}`. Every request carries `X-Platform-Signature: hmac_sha256(body, shared secret)`. Addresses must be public https.
- **E-mail delivery**: Operations > E-mail delivery shows sent, failed and bounced e-mails. Point your mail service's bounce webhook at `/webhooks/mail` signed with `PLATFORM_MAIL_WEBHOOK_SECRET`.

## 15. Exams office procedures

- The written procedure for flags, false positives, appeals and accessibility exceptions is in every institution's help centre (`/help/exam-incidents-procedure`, staff) with a student version (`/help/exam-flags-and-appeals`). Run `php artisan help:standard` after upgrading to add them to existing institutions; articles you have edited are never overwritten.
- **Exam-day contacts** (Policy, and per campus) appear on the proctoring console and every incident.
- **Hardware exceptions** (Administration > Exceptions) need two people: one records, another approves.
- **Statistical review** runs hourly for exams that just finished (`exams:anomalies`); see the exam's *Integrity review* tab. Flags never change marks.
- **Security self-check**: `php artisan security:check` (also every Monday, and from Platform > Security check). Run it before each exam season and after every upgrade; fix every FAIL.
- **Settings history**: every Policy and campus-settings change is versioned; roll back from Policy > History.
- **Automatic semester rollover** (Policy switch, `academics:rollover` at 00:20): closes ended semesters once results are released, starts the next, carries courses over.

## 16. Monitoring, alerts, recovery drills and encryption

- `metrics:collect` (every minute) records traffic and server figures; `network:probe` (every minute) checks hall equipment; `exams:anomalies` (hourly), `academics:rollover` (00:20), `analytics:push` (04:40), `dr:drill` (1st of the month 03:30) and `security:check` (Mondays) run from the scheduler. Keep `php artisan schedule:work` running (section 2).
- Platform > System health: graphs, alerts (also e-mailed to platform administrators and sent to `PLATFORM_ALERT_WEBHOOK`), recovery drills. Institutions see exam-day performance and their hall network under Operations > System health.
- **Database encryption at rest**: `php artisan db:encryption-key`, add `deploy/mariadb/encryption.cnf` to MariaDB's `my.ini`, restart MariaDB, `php artisan db:encrypt-tables`. Keep the key file backed up separately.
- Queued jobs carry the institution they were queued for, so notifications and e-mail from the queue worker use the right institution, permissions and mail server.

## 17. Documentation and templates

- In-app documentation: `/docs` for institutions (each person sees the pages their roles allow) and `/platform/docs` for the platform team. Pages are Markdown files in `resources/docs/institution` and `resources/docs/platform`; edit them and they update on the next page load.
- Import templates with sample rows: `/templates` (also linked beside each import). People, courses, questions (Word and text), exam candidates, SIS students/courses/registrations, attendance terminals, plagiarism exchange, mail bounce.
- Demo data for every scenario: `php artisan db:seed --class=DemoSeeder` (it runs `ScenarioSeeder`). It creates an example institution (Veritas University) where every role signs in as `<role>@veritas.local` (examofficer, ict, registry, dean, hod, setter, moderator, chief, invigilator, proctor, lecturer, student, student02 … student21); the platform team as `platform@platform.local` and `finance@platform.local`. Demo only — never on a live server.

## 18. Portal bridge (Veritas University portal, "model2")

A separate, read-only system that sits beside the university portal's database and gives the platform:

- **sign-in** with portal accounts: staff from `staff`, students from `students`, applicants from `users` (type 7) — an OpenID Connect provider with PKCE, single-use codes and RS256-signed ID tokens;
- a **directory API** (contract `directory-bridge/1`): active students (`student_academics` studentship normal, `students.status` good standing/probation), active staff (no `appointment_terminations`), functional roles (`staff_assigned_roles` with status 1), the running semester(s) (`vu_semesters.status = 1`), courses, registrations (`course_regs.is_course_reg = 2`) and teaching (`assigned_courses`, `course_assigneds`) in the running semester(s), and applicants of the active admission session.

Code: `app/Bridges/Vuna` (routes under `/bridge/vuna`), config `config/vuna_bridge.php`. Only tables and columns that exist are read, so older or newer portal versions work.

**Safety.** The bridge never writes to the portal: the database session is opened READ ONLY, and every statement is checked before it is sent — anything that is not a read is refused. By default it only opens a database on the same computer (`localhost`, `127.0.0.1`, `::1` or a socket); to read the portal's database on another server set `VUNA_DB_ALLOW_REMOTE=true` (optionally `VUNA_DB_SSL_CA` for an encrypted link) and allow this server's IP address in the host's remote MySQL settings. Give it a read-only MySQL user anyway:

```sql
CREATE USER 'vuna_bridge'@'localhost' IDENTIFIED BY '<long random>';
GRANT SELECT ON portal_db.* TO 'vuna_bridge'@'localhost';
```

**Install** (on the portal server, as part of this application):

```ini
VUNA_BRIDGE_ENABLED=true
VUNA_BRIDGE_NAME="Veritas University portal"
VUNA_BRIDGE_ISSUER=https://portal.veritas.edu.ng/bridge/vuna
VUNA_DB_HOST=127.0.0.1
VUNA_DB_DATABASE=portal_db
VUNA_DB_USERNAME=vuna_bridge
VUNA_DB_PASSWORD=<long random>
VUNA_BRIDGE_CLIENT_ID=veritas-exams-lms
VUNA_BRIDGE_CLIENT_SECRET=<openssl rand -hex 32>
VUNA_BRIDGE_REDIRECT_URIS=https://exams.veritas.edu.ng/sso/portal/callback
```

Then `php artisan config:clear && php artisan vuna:bridge-check` — it lists the tables it found, the running semester and how many students and staff are active. The signing key is created on first use at `storage/app/bridges/vuna-oidc.pem` (owner-only); back it up with the rest of `storage/`.

**Semesters.** Students see the running semester's registered courses as current courses and the semester before it as previous courses (`VUNA_PREVIOUS_SEMESTERS`, default 1). When no semester is marked running, the latest semester with registrations is used as the current one. Lecturers assigned in `assigned_courses` / `course_assigneds` are shown on each course.

**Rules you may need to adjust** (all in `.env`): `VUNA_ACTIVE_STUDENTSHIPS` (default `1`), `VUNA_ACTIVE_STUDENT_STATUSES` (`1,2`), `VUNA_ACTIVE_SEMESTER_STATUS` (`1`), `VUNA_REGISTERED_FLAGS` (`2`), `VUNA_INACTIVE_STAFF_STATUSES` (empty), `VUNA_APPLICANT_USER_TYPE` (`7`), `VUNA_APPLICANTS_CURRENT_ONLY` (`true`).

**Connect the platform:** Institution settings > Integrations > University portal: bridge address (the issuer above), client ID and secret; tick "People sign in on the normal sign-in page with their portal ID and password". Veritas then checks portal details through the bridge (`POST /api/v1/authenticate`, limited per login) for anyone who is not a local account; no button is added to the sign-in page. Syncs run hourly (`directory:sync`, scheduled at :17) and on "Sync now".

**Local testing with `php artisan serve`:** it answers one request at a time, so the platform cannot call a bridge on the same server. Run the bridge on a second one (`php artisan serve --port=8001`, issuer `http://127.0.0.1:8001/bridge/vuna`) or use Apache.

**Trying it without real data:** point `VUNA_DB_DATABASE` at an empty local database whose name ends in `_demo` and run `php artisan vuna:demo-portal` (refuses any other name or a remote host). Demo passwords: students `Portal!2026` (e.g. `VUG/ICH/25/001`), staff `Staff!2026` (e.g. `ngozi.okeke@vuna.test`), applicants `Apply!2026` (`grace.obi@example.com`).

**Other institutions** are built differently: they can import people from CSV, push them through the SIS API (`/api/v1/sis/*`), or put their own bridge in front of their records system that speaks the same `directory-bridge/1` contract (`/token` client credentials, `/api/v1/{term,faculties,departments,programmes,courses,students,staff,applicants,registrations,teaching,statuses}`) and the same OpenID Connect endpoints.

## 19. Subscriptions, entitlements and security hardening

**Where things are controlled (no code changes needed)**
- *Platform → Plans & prices*: each package's modules, capacities (students, staff, administrators, faculties, departments, campuses, storage MB, largest upload MB, API requests per month, exam/quiz attempts per month, optional modules), prices for monthly / quarterly / 6-month / yearly, free-trial and grace days, and the price of extra capacity blocks. A blank capacity means unlimited. Changes apply at once.
- *Platform → Institutions → (institution)*: usage meters, own (enterprise) capacities per institution, extra capacity without an invoice, extend validity, cancel / end / undo, temporary module access with an end date, support sign-in, subscription history.
- *Platform → Billing rules*: default trial and grace days, when renewal invoices go out, reminder days, default upload size, the name/address/email printed on invoices and receipts, request limits (per person, per address, per API key, per institution), maintenance mode.
- *Platform → Discount codes*, *Announcements*, *Audit trail*.
- *Institution → Subscription & billing*: usage meters, pay, discount code, change package or period (credit for unused time), buy extra capacity, download invoice/receipt PDFs, do not renew, history.

**Enforcement** is on the server in one place (`App\Services\Billing\Entitlements`): account creation/activation (students, staff), administrator roles, faculties, departments, campuses, every upload (size and storage), API keys (monthly count), exam/quiz attempts, module switches. A refusal comes back as a plain message; nothing is deleted.

**Must run in production**
- The scheduler (`php artisan schedule:run` every minute): daily billing run (renewal invoices, reminders, past-due, suspension after grace, expiries of extra capacity and temporary modules), backups and weekly restore test.
- A queue worker (`php artisan queue:work`): notifications and emails are queued.
- Recommended: `CACHE_STORE=redis` (rate limits, entitlement and settings caches, seat locks). The database cache works but costs about ten small queries per request.

**Optional external configuration**
- Paystack: `PAYSTACK_SECRET` (webhook URL `/webhooks/paystack`). Without it, institutions pay by bank transfer and the platform team records it.
- Antivirus: `CLAMAV_SOCKET=tcp://127.0.0.1:3310` (or `unix:///var/run/clamav/clamd.ctl`); `CLAMAV_FAIL_CLOSED=true` refuses uploads while the daemon is down. Built-in checks (blocked types, disguised programs, EICAR, PHP code) always run. `UPLOAD_BLOCKED_EXTENSIONS` overrides the blocked list.
- Mail (`MAIL_MAILER` etc.) for emailed notifications, invoices reminders and receipts notices.

**Support sign-in** lasts at most 30 minutes, needs a written reason, is written to the platform's and the institution's audit trails, notifies the institution's administrators, marks every action as support, and blocks password, two-factor, profile deletion, API keys and billing actions.

**Signing people out**: removing an administrator role, removing a privileged permission from a role, switching an account off, or resetting a password signs that person out on every device at once. Changing your own password signs out your other devices.


## 19. Hosting on shared hosting (e.g. SiteGround) and live meetings

**The platform** runs on ordinary PHP hosting: PHP 8.3, MySQL/MariaDB, SSH and cron.

- Build the screens on your own computer (`npm run build`) and upload `public/build`; Node is not needed on the server.
- Shared hosting does not allow always-on background processes. Set `QUEUE_VIA_SCHEDULER=true` in `.env`, and add one cron job: `* * * * * cd /path/to/platform && php artisan schedule:run`. The queue (e-mails, transcripts, exports) is then worked every minute by the scheduler.
- Large exam sittings (several hundred candidates writing at the same time) need a VPS. Shared hosting suits LMS use and smaller tests.

**Live meetings:**

- *Built-in rooms* need nothing extra: browsers connect directly, and the platform only passes connection details, checking in every 1.5 s. They hold up to `BUILTIN_MEETING_MAX` people (default 6) and are not recorded.
  - Public STUN servers are used to find each computer's address (`BUILTIN_STUN_URLS`).
  - For networks that block direct connections, add a TURN relay (for example coturn on any small VPS): `BUILTIN_TURN_URLS=turn:turn.example.edu:3478` and `BUILTIN_TURN_SECRET=<coturn static-auth-secret>`. The platform then hands out passwords that last 6 hours.
- *Full classes with recording* need a BigBlueButton server. It must run on its own Linux server with root access and open UDP ports, which shared and managed hosting such as SiteGround do not allow. Rent a small VPS for it, or use a hosted BigBlueButton service, then add it under Platform › Conferencing servers. The platform itself stays where it is.
- *Live proctoring video* (LiveKit) has the same requirement as BigBlueButton. Snapshot proctoring works without it.
