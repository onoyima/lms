# Incident response

Who to call, what to do first, and what to record. Keep the contacts at the end up to date.

## Severity

| Level | Examples | Response |
|---|---|---|
| **Critical** | Exam paper leaked; data breach; platform down during a sitting | Start now; inform the Registrar and ICT Director within 30 minutes |
| **High** | One sitting disrupted; many identity mismatches; proctors offline | Within 15 minutes, by the chief invigilator and the exams office |
| **Medium** | Single candidate malpractice case; media server down | The same day |
| **Low** | One help request, minor display issue | Normal support queue |

## During an exam

1. **Candidates are disrupted** (power, network, many offline in the console):
   - Select **Emergency pause** in the console. All clocks stop, and an incident is opened automatically.
   - Fix the cause, or move candidates to other computers. Their answers are safe, and they resume where they were.
   - Select **Resume**. If needed, give individual candidates extra time from their timeline.
2. **A proctor's console goes offline.** The chief invigilator is alerted automatically. Select **Give their candidates to online proctors**.
3. **Suspected malpractice:**
   - Open an incident from the candidate's timeline. Evidence such as photos, recordings and events is attached and kept.
   - **Do not accuse on an alert alone.** Alerts are for a person to review.
   - Lock the attempt only if the rules say so. Terminating is for the exams office only.
4. **Identity mismatch.** Compare the photos side by side in the timeline (ID photo, enrolled face, current photo). Check the ID card in the hall, or ask a remote candidate to show it to the camera. Record what you saw in the incident.

## Security incidents

1. **Contain:**
   - Suspend affected accounts in **People**.
   - Revoke API keys with `php artisan apikey:revoke <prefix>`.
   - Rotate secrets as needed: `APP_KEY` only with a re-encryption plan, plus the LiveKit and SSO client secrets.
2. **Preserve evidence:**
   - Do not delete logs.
   - Run `php artisan audit:verify` to prove the audit log is untampered.
   - Export the security events from Reports.
3. **Assess:** use the audit log to see who accessed which data and when. Evidence views, photo views and exports are all logged.
4. **Paper leak:** retire the affected questions in the **Question bank**, then build a fresh paper. Use **Create resit** if the sitting already took place.
5. **Notify:** the Data Protection Officer decides on notifying regulators and affected people. The Nigeria Data Protection Act requires notifying the Commission within 72 hours of a personal-data breach.
6. **Review:** hold a meeting within one week, and write down the cause, the fix and what changes.

## After every exam period

- Run **Reports → Post-exam review** for each sitting. It covers attendance, completion, help requests, disconnections, identity alerts, high-risk attempts, incidents and proctor alerts.
- Close every open incident with an outcome.
- Run **Access review** for privileged accounts.

## Contacts

| Role | Name | Phone |
|---|---|---|
| Exams Officer | | |
| ICT on-call | | |
| Chief Invigilator (per hall) | | |
| Data Protection Officer | | |
