---
title: Integrations and the API
section: Settings
order: 110
access: perm:integrations.manage
---
Institution settings > Integrations.

## API keys

Create a key for each system, with only the scopes it needs and the addresses it calls from. The key is shown once. Revoke it at any time.

| Scope | Endpoint | Template |
|---|---|---|
| `sis.write` | `POST /api/v1/sis/students`, `/courses`, `/registrations` | SIS templates |
| `results.read` | `GET /api/v1/results` | — |
| `attendance.write` | `POST /api/v1/attendance` | Attendance terminals |
| `analytics.read` | `GET /api/v1/analytics?dataset=enrolments,results,attendance,exams,engagement` | — |

Send the key as `Authorization: Bearer vk_…`.

## Connected services

- **Institution e-mail**: send all e-mail from your own server and address (Microsoft 365, Google Workspace, campus server). Use *Send me a test*, then check *E-mail delivery*.
- **Notification service**: a signed copy of notices to your SMS gateway, Teams/Slack bridge or campus app.
- **Plagiarism service** and **Analytics feed**: see the templates for the exact messages.

Every outgoing message carries `X-Platform-Signature: hmac_sha256(body, shared secret)` so the receiver can check it came from the platform.
