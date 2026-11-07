# Disaster recovery and exam continuity

Targets for a live examination period (agree these with management):

| | Target | How it is met |
|---|---|---|
| **Recovery point (data you can lose)** | 0 answers; at most 5 minutes of other data | Answers are kept on the candidate's device and resent until saved. The replica holds a live copy. Nightly encrypted backups. |
| **Recovery time (back in service)** | 15 minutes | Second app server, standby database (`deploy/ha`), restore drill done weekly. |

## What protects an exam that is running

- **Answers survive outages.** Every answer is stored in the browser and in the desktop app, and sent again when the network returns. Each answer is numbered, so a resent answer is never applied twice.
- **Time is fair.** If the whole sitting is paused (*Emergency pause* in the console), every clock stops and the paused time is given back. An invigilator can give one candidate extra time.
- **Nothing depends on one app server.** Sessions, cache and queues are in Redis, so a failed app server is skipped by the load balancer.
- **Tokens move with the candidate.** If a computer fails, the candidate signs in on another one. The old window stops, and the exam resumes where it was, with the same deadline. Desktop-app exams must continue on an approved computer.

## Backups

- `php artisan backup:run` runs nightly at 01:30. It saves the database and the uploaded files, encrypted with AES-256-GCM, to `storage/app/private/backups` (or `BACKUP_PATH`).
- `php artisan backup:run --restore-test` runs weekly. It restores the newest backup into a scratch database and checks the row counts. Its result is shown on the dashboard (*Last backup*) and by the health check.
- **Copy backups off the server every night** (another building, or object storage). A backup on the same disk is not a backup.
- Backups only ever go to a local database host; the commands refuse remote hosts.

## Scenarios

### 1. An app server dies
Nothing to do during the exam: the load balancer stops sending it traffic. Afterwards, replace it and run `docker compose up -d app1`.

### 2. The database primary fails
1. In the proctor console, select **Emergency pause** for running sittings. Clocks stop.
2. Promote the replica:
   ```
   docker compose exec mariadb-replica mariadb -uroot -p -e "STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only = OFF;"
   ```
3. Point the apps at it: set `DB_HOST=mariadb-replica` in `.env`, then run `docker compose up -d app1 app2 worker scheduler`.
4. Check: `curl https://…/api/health` should show `"database": "ok"`.
5. **Resume** the sittings. Candidates' devices resend anything that was not saved.
6. Rebuild the old primary as the new replica before the next exam day.

### 3. Total loss of the server
1. Build a new server from `deploy/ha` (or follow the XAMPP install in `OPERATIONS.md`).
2. Copy back the latest off-site backup.
3. Restore it: `php artisan backup:decrypt <file> restore.sql.gz` (use the same `APP_KEY`, which is kept in your password vault), then import it into the local database.
4. Restore `storage/app` from the files backup.
5. Run `php artisan migrate --force` and `php artisan optimize:clear`.
6. Re-schedule any sitting that was interrupted. Use the **resit** or **supplementary** flow if candidates could not finish.

### 4. The media server (live video) fails
Exams continue. Candidates' rooms show *Video connection lost — retrying*. Snapshots, events, identity checks and the desktop app's checks still run. Restart the media server with `docker compose up -d` in `deploy/livekit`; clients reconnect on their own.

### 5. The campus network or internet fails
Candidates keep answering offline, and answers are saved on each device. When the network returns, everything is sent. If the outage is long, pause the sitting so the clocks stop.

## Drills

- **Weekly:** automatic restore test (above).
- **Before each exam period:** run a practice sitting with `exams:load-fixture` and `tools/loadtest.mjs` at the expected number of candidates. Stop one app server during the test, and confirm nobody loses answers.
- **Each semester:** a full fail-over drill of scenario 2 on the staging copy. Write down how long each step took.
