# Security testing checklist

Run the automated checks on every change. Run the manual checks before each exam period, and commission an independent penetration test once a year (Target 102, 220).

## Automated (every change, in CI)

- [ ] `php artisan test`: the feature tests include security cases, such as:
  - cross-institution data isolation;
  - permission checks on every admin route;
  - students cannot reach incidents, evidence or other people's photos;
  - forged, expired, misdirected and replayed SSO tokens are refused;
  - desktop-app signatures, and the rule that a desktop exam stays on its computer;
  - CSRF protection;
  - phone links work once;
  - recordings cannot be read outside their folder;
  - spreadsheet formula injection in exports.
- [ ] `composer audit` and `npm audit`: no known vulnerable packages. Dependabot raises updates.
- [ ] `npx tsc --noEmit` and ESLint pass.

## Manual (before each exam period)

**Authentication**
- [ ] Wrong passwords lock the account after the set number of tries.
- [ ] Two-factor sign-in is required for admin roles.
- [ ] Password reset links expire.
- [ ] Single sign-on refuses a test account from a different identity provider.

**Authorisation**
- [ ] With a student account, try admin URLs (`/admin/...`, `/exams/...`, `/proctor/...`, `/admin/identity`). Each must return 403 or 404.
- [ ] A lecturer cannot open a course they do not teach.
- [ ] A proctor cannot open another sitting's console or evidence.

**Exam integrity**
- [ ] A copied exam token from one browser does not work in another once the exam moves. A desktop-app exam does not continue in a browser.
- [ ] Exam API responses never contain answer keys (inspect the network tab).
- [ ] Submitting after the deadline is refused (the server clock decides).

**Input handling**
- [ ] Paste `<script>alert(1)</script>` into questions, forum posts, messages, help articles and names. Nothing runs.
- [ ] Upload a `.php` or `.exe` file as an assignment. It is refused.
- [ ] Upload a 600 MB file. It is refused.

**Headers and transport**
- [ ] The site only answers over https, with HSTS.
- [ ] Check https://securityheaders.com: the Content-Security-Policy is present and nothing extra is allowed.

**Secrets**
- [ ] `.env` is not reachable from the web.
- [ ] `APP_DEBUG=false` in production.
- [ ] Backups are encrypted, and copies are kept off-site.

**Media server**
- [ ] A candidate token cannot subscribe to others.
- [ ] The webhook rejects unsigned calls.

**Desktop app**
- [ ] Developer tools, new windows and other sites are blocked.
- [ ] Screenshots of the exam window come out black.
- [ ] A second monitor, a remote-access tool and a USB drive each appear in the proctor timeline.

## Independent test (yearly)

Give the testers:
- a staging copy with test data;
- one account for each role;
- this checklist;
- `docs/OPERATIONS.md`.

Ask for OWASP ASVS level 2 coverage, plus the exam-specific abuse cases above. Track every finding in the support queue as a **security** ticket until it is fixed and re-tested.
