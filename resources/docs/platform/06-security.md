---
title: Security check and encryption
section: Operations
order: 60
---
**Security check** (Team > Security check, `php artisan security:check`, and every Monday): debug off, encryption key, https, secure cookies, two-factor enforcement and use, API keys limited and in use, payment and media secrets, backups and restore tests, **database encryption at rest**, scheduler and queue, recovery drills, open critical alerts, and for each institution its audit chain, password policy, exam-day contacts and access review. Fix every *Failed* before an exam season.

**Database encryption at rest**:

1. `php artisan db:encryption-key` — creates the key file outside the web folder.
2. Add the settings in `deploy/mariadb/encryption.cnf` to MariaDB (XAMPP: `my.ini`) and restart it.
3. `php artisan db:encrypt-tables`.
4. Keep a copy of the key file somewhere safe and separate: without it the database cannot be read.

Sensitive notes (accessibility arrangements, exception reasons and decisions) are additionally encrypted by the application.

`PLATFORM_ALLOW_PRIVATE_TARGETS` must be **off** on shared hosting, so institutions cannot point mail or network checks at the host's internal network.
